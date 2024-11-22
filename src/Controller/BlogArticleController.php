<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\BlogArticle;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use OpenApi\Annotations as OA;
use Nelmio\ApiDocBundle\Annotation\Model;
use Nelmio\ApiDocBundle\Annotation\Security;

#[Route('/api', name: 'api_')]
class BlogArticleController extends AbstractController
{

    private $em;
    private $validator;
    private $slugger;

    public function __construct(
        EntityManagerInterface $em,
        ValidatorInterface $validator,
        SluggerInterface $slugger
    ) {
        $this->em = $em;
        $this->validator = $validator;
        $this->slugger = $slugger;
    }

    /**
     * Create a new blog article
     *
     * @OA\Post(
     *     path="/api/blog-articles",
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 type="object",
     *                 required={"title", "content", "authorId"},
     *                 @OA\Property(property="authorId", type="integer", example=1),
     *                 @OA\Property(property="title", type="string", example="My Blog Post"),
     *                 @OA\Property(property="content", type="string", example="Content of the blog post"),
     *                 @OA\Property(property="keywords", type="string", example="[\"symfony\", \"api\"]"),
     *                 @OA\Property(property="status", type="string", enum={"draft", "published"}, example="draft"),
     *                 @OA\Property(property="publicationDate", type="string", format="date-time"),
     *                 @OA\Property(property="coverPicture", type="string", format="binary")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Article created successfully",
     *         @OA\JsonContent(
     *             type="object",
     *             @OA\Property(property="id", type="integer"),
     *             @OA\Property(property="message", type="string")
     *         )
     *     ),
     *     @OA\Response(
     *         response=400,
     *         description="Invalid input"
     *     )
     * )
     */
    #[Route('/blog-articles', name: 'blog_article_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        // Check if the request is multipart/form-data
        $contentType = $request->headers->get('Content-Type');
        if (str_contains($contentType, 'multipart/form-data')) {
            // Handle form data
            $data = [
                'authorId' => $request->request->get('authorId'),
                'title' => $request->request->get('title'),
                'content' => $request->request->get('content'),
                'keywords' => json_decode($request->request->get('keywords', '[]'), true),
                'status' => $request->request->get('status', 'draft'),
                'publicationDate' => $request->request->get('publicationDate')
            ];
        } else {
            // Handle JSON data
            $data = json_decode($request->getContent(), true);
        }

        // Validate required fields
        if (empty($data['title']) || empty($data['content']) || empty($data['authorId'])) {
            return new JsonResponse([
                'error' => 'Missing required fields. Please provide title, content, and authorId.'
            ], 400);
        }

        $article = new BlogArticle();
        $article->setAuthorId((int)$data['authorId']);
        $article->setTitle($data['title']);
        $article->setContent($data['content']);
        $article->setKeywords($data['keywords'] ?? []);
        $article->setStatus($data['status'] ?? 'draft');

        // Handle publication date
        try {
            $pubDate = !empty($data['publicationDate'])
                ? new \DateTime($data['publicationDate'])
                : new \DateTime('now');
            $article->setPublicationDate($pubDate);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Invalid publication date format'
            ], 400);
        }

        $article->setSlug($this->slugger->slug($data['title'])->lower());

        // Handle cover picture upload
        if ($request->files->has('coverPicture')) {
            $coverPicture = $request->files->get('coverPicture');

            if ($coverPicture) {
                $originalFilename = pathinfo($coverPicture->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $this->slugger->slug($originalFilename);
                $newFilename = $safeFilename . '-' . uniqid() . '.' . $coverPicture->guessExtension();

                try {
                    $coverPicture->move(
                        $this->getParameter('pictures_directory'),
                        $newFilename
                    );
                    $article->setCoverPictureRef($newFilename);
                } catch (FileException $e) {
                    return new JsonResponse([
                        'error' => 'Error uploading file: ' . $e->getMessage()
                    ], 400);
                }
            }
        }

        // Validate the article
        $errors = $this->validator->validate($article);
        if (count($errors) > 0) {
            $errorMessages = [];
            foreach ($errors as $error) {
                $errorMessages[] = $error->getMessage();
            }
            return new JsonResponse(['errors' => $errorMessages], 400);
        }

        try {
            $this->em->persist($article);
            $this->em->flush();

            return new JsonResponse([
                'id' => $article->getId(),
                'message' => 'Article created successfully'
            ], 201);
        } catch (\Exception $e) {
            return new JsonResponse([
                'error' => 'Error saving article: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * List all blog articles
     *
     * @OA\Get(
     *     path="/api/blog-articles",
     *     @OA\Response(
     *         response=200,
     *         description="Returns the list of blog articles",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(ref=@Model(type=BlogArticle::class))
     *         )
     *     )
     * )
     */

    #[Route('/blog-articles', name: 'blog_article_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        $articles = $this->em->getRepository(BlogArticle::class)->findBy(
            ['status' => ['draft', 'published']]
        );

        $data = [];
        foreach ($articles as $article) {
            $data[] = [
                'id' => $article->getId(),
                'title' => $article->getTitle(),
                'publicationDate' => $article->getPublicationDate()->format('Y-m-d H:i:s'),
                'status' => $article->getStatus(),
                'slug' => $article->getSlug(),
            ];
        }

        return new JsonResponse($data);
    }

    /**
     * Get a specific blog article
     *
     * @OA\Get(
     *     path="/api/blog-articles/{id}",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the blog article",
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Returns the blog article details",
     *         @OA\JsonContent(ref=@Model(type=BlogArticle::class))
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Article not found"
     *     )
     * )
     */

    #[Route('/blog-articles/{id}', name: 'blog_article_show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $article = $this->em->getRepository(BlogArticle::class)->find($id);

        if (!$article) {
            return new JsonResponse(['error' => 'Article not found'], 404);
        }

        return new JsonResponse([
            'id' => $article->getId(),
            'authorId' => $article->getAuthorId(),
            'title' => $article->getTitle(),
            'content' => $article->getContent(),
            'publicationDate' => $article->getPublicationDate()->format('Y-m-d H:i:s'),
            'creationDate' => $article->getCreationDate()->format('Y-m-d H:i:s'),
            'keywords' => $article->getKeywords(),
            'status' => $article->getStatus(),
            'slug' => $article->getSlug(),
            'coverPictureRef' => $article->getCoverPictureRef(),
        ]);
    }

    /**
     * Update a blog article
     *
     * @OA\Patch(
     *     path="/api/blog-articles/{id}",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\RequestBody(
     *         @OA\MediaType(
     *             mediaType="multipart/form-data",
     *             @OA\Schema(
     *                 type="object",
     *                 @OA\Property(property="title", type="string"),
     *                 @OA\Property(property="content", type="string"),
     *                 @OA\Property(property="keywords", type="string"),
     *                 @OA\Property(property="status", type="string"),
     *                 @OA\Property(property="publicationDate", type="string"),
     *                 @OA\Property(property="coverPicture", type="string", format="binary")
     *             )
     *         )
     *     ),
     *     @OA\Response(
     *         response=204,
     *         description="Article updated successfully"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Article not found"
     *     )
     * )
     */
    #[Route('/blog-articles/{id}', name: 'blog_article_update', methods: ['PATCH'])]
    public function update(Request $request, int $id): JsonResponse
    {
        $article = $this->em->getRepository(BlogArticle::class)->find($id);

        if (!$article) {
            return new JsonResponse(['error' => 'Article not found'], 404);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['title'])) {
            $article->setTitle($data['title']);
            $article->setSlug($this->slugger->slug($data['title'])->lower());
        }
        if (isset($data['content'])) {
            $article->setContent($data['content']);
        }
        if (isset($data['keywords'])) {
            $article->setKeywords($data['keywords']);
        }
        if (isset($data['status'])) {
            $article->setStatus($data['status']);
        }
        if (isset($data['publicationDate'])) {
            $article->setPublicationDate(new \DateTime($data['publicationDate']));
        }

        // Handle cover picture update
        if ($request->files->has('coverPicture')) {
            $coverPicture = $request->files->get('coverPicture');
            $originalFilename = pathinfo($coverPicture->getClientOriginalName(), PATHINFO_FILENAME);
            $safeFilename = $this->slugger->slug($originalFilename);
            $newFilename = $safeFilename.'-'.uniqid().'.'.$coverPicture->guessExtension();

            try {
                $coverPicture->move(
                    $this->getParameter('pictures_directory'),
                    $newFilename
                );
                // Delete old cover picture if exists
                if ($article->getCoverPictureRef()) {
                    @unlink($this->getParameter('pictures_directory').'/'.$article->getCoverPictureRef());
                }
                $article->setCoverPictureRef($newFilename);
            } catch (FileException $e) {
                return new JsonResponse(['error' => 'Error uploading file'], 400);
            }
        }

        $errors = $this->validator->validate($article);
        if (count($errors) > 0) {
            return new JsonResponse(['errors' => (string) $errors], 400);
        }

        $this->em->flush();

        return new JsonResponse(null, 204);
    }

    /**
     * Delete a blog article (soft delete)
     *
     * @OA\Delete(
     *     path="/api/blog-articles/{id}",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(
     *         response=204,
     *         description="Article deleted successfully"
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Article not found"
     *     )
     * )
     */

    #[Route('/blog-articles/{id}', name: 'blog_article_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $article = $this->em->getRepository(BlogArticle::class)->find($id);

        if (!$article) {
            return new JsonResponse(['error' => 'Article not found'], 404);
        }

        $article->setStatus('deleted');
        $this->em->flush();

        return new JsonResponse(null, 204);
    }





}
