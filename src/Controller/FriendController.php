<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\FriendshipRepository;
use App\Service\FriendshipService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class FriendController extends AbstractController
{
    public function __construct(
        private readonly FriendshipRepository $friendships,
        private readonly FriendshipService $service,
    ) {
    }

    #[Route('/api/friends', name: 'api_friends_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        /** @var User $me */
        $me = $this->getUser();

        // Clamp the page size. Without a max, ?limit=1000000 is a cheap
        // way to make your server do a lot of work.
        $limit = min(100, max(1, $request->query->getInt('limit', 50)));
        $offset = max(0, $request->query->getInt('offset', 0));

        $friends = $this->friendships->findFriendsOf($me, $limit, $offset);

        return new JsonResponse([
            'total' => $this->friendships->countFriendsOf($me),
            'limit' => $limit,
            'offset' => $offset,
            'items' => array_map(
                static fn ($f) => [
                    'id' => $f->getFriend()->getId(),
                    'displayName' => $f->getFriend()->getDisplayName(),
                    'bio' => $f->getFriend()->getBio(),
                    'friendsSince' => $f->getCreatedAt()->format(\DATE_ATOM),
                ],
                $friends
            ),
        ]);
    }

    /**
     * Unfriend someone.
     *
     * No Voter here: the rule is simply "you may remove your own
     * friendships", which needs no object-level check — the current user
     * is one half of the pair by construction.
     */
    #[Route('/api/friends/{id}', name: 'api_friends_remove', methods: ['DELETE'])]
    public function remove(User $id): JsonResponse
    {
        /** @var User $me */
        $me = $this->getUser();

        // The param is named {id} but type-hinted User, so Symfony loads
        // the entity. Naming it $id is confusing — rename to {friendId}
        // and $friend if you prefer clarity over brevity.
        $friend = $id;

        if (!$this->friendships->areFriends($me, $friend)) {
            return new JsonResponse(
                ['message' => 'You are not friends with this user.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        $this->service->remove($me, $friend);

        return new JsonResponse(['message' => 'Friendship removed.']);
    }
}
