<?php

namespace App\Controller;

use App\Dto\CreatePostRequest;
use App\Dto\UpdatePostRequest;
use App\Entity\Post;
use App\Entity\User;
use App\Repository\PostRepository;
use App\Security\Voter\PostVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

class PostController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PostRepository $posts,
    ) {
    }

    #[Route('/api/posts', name: 'api_post_create', methods: ['POST'])]
    public function create(#[MapRequestPayload] CreatePostRequest $payload): JsonResponse
    {
        /** @var User $me */
        $me = $this->getUser();

        $post = new Post($me, $payload->content, $payload->visibility);

        $this->em->persist($post);
        $this->em->flush();

        // post:read plus user:public — the nested author object is
        // serialized with the user groups active in this context.
        return $this->json($post, Response::HTTP_CREATED, [], [
            'groups' => ['post:read', 'user:public'],
        ]);
    }

    #[Route('/api/posts/{id}', name: 'api_post_show', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        // findLive() returns null for soft-deleted posts, so deleted
        // content can never reach the Voter or the response.
        $post = $this->posts->findLive($id);

        // Deliberately NOT denyAccessUnlessGranted(): that returns 403,
        // which would confirm the post exists. For posts we hide existence
        // entirely — a private post you cannot see is indistinguishable
        // from one that was never written.
        if ($post === null || !$this->isGranted(PostVoter::VIEW, $post)) {
            throw $this->createNotFoundException('Post not found.');
        }

        return $this->json($post, 200, [], [
            'groups' => ['post:read', 'user:public'],
        ]);
    }

    #[Route('/api/posts/{id}', name: 'api_post_update', methods: ['PATCH'])]
    public function update(int $id, #[MapRequestPayload] UpdatePostRequest $payload): JsonResponse
    {
        $post = $this->posts->findLive($id);

        if ($post === null) {
            throw $this->createNotFoundException('Post not found.');
        }

        // 403 is correct HERE, unlike show(). To reach this point the caller
        // knows the post's id and it is visible to them; refusing the edit
        // reveals nothing new.
        $this->denyAccessUnlessGranted(PostVoter::EDIT, $post);

        // Null means "not supplied" — PATCH updates only what was sent.
        if ($payload->content !== null) {
            $post->setContent($payload->content);
        }

        if ($payload->visibility !== null) {
            $post->setVisibility($payload->visibility);
        }

        // The #[ORM\PreUpdate] callback sets updatedAt, which makes
        // isEdited() true — so clients can show "edited".
        $this->em->flush();

        return $this->json($post, 200, [], [
            'groups' => ['post:read', 'user:public'],
        ]);
    }

    #[Route('/api/posts/{id}', name: 'api_post_delete', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $post = $this->posts->findLive($id);

        if ($post === null) {
            throw $this->createNotFoundException('Post not found.');
        }

        $this->denyAccessUnlessGranted(PostVoter::DELETE, $post);

        // Soft delete: the row stays, marked. Comments on it remain
        // meaningful, and the content can be restored if needed.
        $post->softDelete();
        $this->em->flush();

        // 204 No Content is the conventional response to a successful
        // DELETE with nothing to return.
        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/users/{id}/posts', name: 'api_user_posts', methods: ['GET'])]
    public function byUser(User $id): JsonResponse
    {
        $author = $id;

        $posts = $this->posts->findByAuthor($author, 50);

        // Filter through the Voter per post. Not the most efficient
        // approach — it fetches 50 then discards some — but it guarantees
        // the visibility rules are applied consistently in exactly one
        // place. Chunk 4 pushes the filtering into SQL for the feed, where
        // volume actually matters.
        $visible = array_values(array_filter(
            $posts,
            fn (Post $p) => $this->isGranted(PostVoter::VIEW, $p)
        ));

        return $this->json(['items' => $visible], 200, [], [
            'groups' => ['post:list', 'user:public'],
        ]);
    }
}
