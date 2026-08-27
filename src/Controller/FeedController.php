<?php

namespace App\Controller;

use App\Entity\Post;
use App\Entity\User;
use App\Pagination\Cursor;
use App\Repository\PostRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class FeedController extends AbstractController
{
    public function __construct(
        private readonly PostRepository $posts,
    ) {
    }

    #[Route('/api/feed', name: 'api_feed', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $me */
        $me = $this->getUser();

        $limit = min(50, max(1, $request->query->getInt('limit', 20)));

        $cursor = null;
        $rawCursor = $request->query->get('cursor');

        if ($rawCursor !== null && $rawCursor !== '') {
            try {
                $cursor = Cursor::decode($rawCursor);
            } catch (\InvalidArgumentException) {
                // A bad cursor is the client's fault, so 400. Silently
                // ignoring it and returning page 1 would be worse — the
                // client would loop forever thinking it was paging.
                return new JsonResponse(
                    ['message' => 'Invalid cursor.'],
                    Response::HTTP_BAD_REQUEST,
                );
            }
        }

        $results = $this->posts->findFeedFor($me, $cursor, $limit);

        // We asked for limit+1. More than limit means another page exists.
        $hasMore = count($results) > $limit;

        // Drop the probe row before returning.
        $items = $hasMore ? array_slice($results, 0, $limit) : $results;

        // The next cursor points at the LAST item on this page, so the
        // next request asks for everything older than it.
        $nextCursor = null;

        if ($hasMore && $items !== []) {
            /** @var Post $last */
            $last = $items[array_key_last($items)];
            $nextCursor = (new Cursor($last->getCreatedAt(), $last->getId()))->encode();
        }

        return $this->json([
            'items' => $items,
            // Null when there is nothing more — clients stop paging when
            // this is null rather than guessing from an item count.
            'nextCursor' => $nextCursor,
            'hasMore' => $hasMore,
        ], 200, [], [
            'groups' => ['post:list', 'user:public'],
        ]);
    }
}
