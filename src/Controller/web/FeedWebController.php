<?php

namespace App\Controller\Web;

use App\Entity\User;
use App\Pagination\Cursor;
use App\Repository\PostRepository;
use App\Repository\SubscriptionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class FeedWebController extends AbstractController
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly SubscriptionRepository $subscriptions,
    ) {
    }

    #[Route('/', name: 'app_feed', methods: ['GET'])]
    public function index(Request $request): Response
    {
        /** @var User $me */
        $me = $this->getUser();

        // Reuse the SAME repository method the API uses. This is the
        // point of keeping logic in services and repositories rather than
        // controllers — the web UI is a second consumer, not a rewrite.
        //
        // Note we do NOT call our own /api/feed over HTTP. That would add
        // a network round trip, lose the transaction, and make error
        // handling worse.
        $cursor = null;
        $raw = $request->query->get('cursor');

        if ($raw !== null && $raw !== '') {
            try {
                $cursor = Cursor::decode($raw);
            } catch (\InvalidArgumentException) {
                // A bad cursor in a URL is not worth a 400 in the UI —
                // just show the first page.
                $cursor = null;
            }
        }

        $limit = 10;

        // findFeedFor asks for limit+1 so we can tell whether more exists
        // without a COUNT query.
        $results = $this->posts->findFeedFor($me, $cursor, $limit);

        $hasMore = count($results) > $limit;
        $posts = $hasMore ? array_slice($results, 0, $limit) : $results;

        $nextCursor = null;

        if ($hasMore && $posts !== []) {
            $last = $posts[array_key_last($posts)];
            $nextCursor = (new Cursor($last->getCreatedAt(), $last->getId()))->encode();
        }

        // The Pro limit, so the composer can show the right character cap.
        $subscription = $this->subscriptions->findOneByUserId((int) $me->getId());
        $isPro = $subscription?->isPro() ?? false;

        return $this->render('feed/index.html.twig', [
            'posts' => $posts,
            'nextCursor' => $nextCursor,
            'characterLimit' => $isPro ? 10000 : 2000,
            'isPro' => $isPro,
        ]);
    }
}
