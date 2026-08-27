<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\PostRepository;
use App\Security\Voter\PostVoter;
use App\Service\PostInteractionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class PostLikeController extends AbstractController
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly PostInteractionService $interactions,
    ) {
    }

    #[Route('/api/posts/{id}/like', name: 'api_post_like', methods: ['POST'])]
    public function like(int $id): JsonResponse
    {
        $post = $this->loadVisiblePost($id);

        /** @var User $me */
        $me = $this->getUser();

        $created = $this->interactions->like($post, $me);

        // The service ran a DQL UPDATE, which changes the database without
        // touching the $post object in memory — the identity map still
        // holds the old count. Refresh before reading it.
        $this->posts->refresh($post);

        return $this->json([
            // false when they had already liked it. The operation is
            // idempotent, so this is information rather than an error.
            'liked' => true,
            'created' => $created,
            'likeCount' => $post->getLikeCount(),
        ]);
    }

    #[Route('/api/posts/{id}/like', name: 'api_post_unlike', methods: ['DELETE'])]
    public function unlike(int $id): JsonResponse
    {
        $post = $this->loadVisiblePost($id);

        /** @var User $me */
        $me = $this->getUser();

        $removed = $this->interactions->unlike($post, $me);

        $this->posts->refresh($post);

        return $this->json([
            'liked' => false,
            'removed' => $removed,
            'likeCount' => $post->getLikeCount(),
        ]);
    }

    /**
     * Load a post the current user is allowed to see, or 404.
     *
     * 404 rather than 403, matching PostController::show(): if you cannot
     * see a post, you should not be able to learn it exists by trying to
     * like it.
     */
    private function loadVisiblePost(int $id): \App\Entity\Post
    {
        $post = $this->posts->findLive($id);

        if ($post === null || !$this->isGranted(PostVoter::VIEW, $post)) {
            throw $this->createNotFoundException('Post not found.');
        }

        return $post;
    }
}
