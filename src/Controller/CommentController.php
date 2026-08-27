<?php

namespace App\Controller;

use App\Dto\CreateCommentRequest;
use App\Entity\Comment;
use App\Entity\Post;
use App\Entity\User;
use App\Repository\CommentRepository;
use App\Repository\PostRepository;
use App\Security\Voter\CommentVoter;
use App\Security\Voter\PostVoter;
use App\Service\PostInteractionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

class CommentController extends AbstractController
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly CommentRepository $comments,
        private readonly PostInteractionService $interactions,
    ) {
    }

    #[Route('/api/posts/{id}/comments', name: 'api_comment_create', methods: ['POST'])]
    public function create(int $id, #[MapRequestPayload] CreateCommentRequest $payload): JsonResponse
    {
        $post = $this->loadVisiblePost($id);

        /** @var User $me */
        $me = $this->getUser();

        $comment = $this->interactions->comment($post, $me, $payload->content);

        return $this->json($comment, Response::HTTP_CREATED, [], [
            'groups' => ['comment:read', 'user:public'],
        ]);
    }

    #[Route('/api/posts/{id}/comments', name: 'api_comment_list', methods: ['GET'])]
    public function list(int $id, Request $request): JsonResponse
    {
        $post = $this->loadVisiblePost($id);

        // Offset pagination is fine here, unlike the feed. Comment threads
        // are short and read oldest-first, so a new comment appended at the
        // end does not shift the pages you have already read.
        $limit = min(100, max(1, $request->query->getInt('limit', 50)));
        $offset = max(0, $request->query->getInt('offset', 0));

        return $this->json([
            'total' => $this->comments->countForPost($post),
            'limit' => $limit,
            'offset' => $offset,
            'items' => $this->comments->findForPost($post, $limit, $offset),
        ], 200, [], [
            'groups' => ['comment:read', 'user:public'],
        ]);
    }

    #[Route('/api/comments/{id}', name: 'api_comment_delete', methods: ['DELETE'])]
    public function delete(Comment $id): JsonResponse
    {
        $comment = $id;

        // The Voter allows EITHER the comment's author or the post's
        // author. 403 is right here — to have the comment's id you must
        // already have seen the thread.
        $this->denyAccessUnlessGranted(CommentVoter::DELETE, $comment);

        $this->interactions->deleteComment($comment);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function loadVisiblePost(int $id): Post
    {
        $post = $this->posts->findLive($id);

        if ($post === null || !$this->isGranted(PostVoter::VIEW, $post)) {
            throw $this->createNotFoundException('Post not found.');
        }

        return $post;
    }
}
