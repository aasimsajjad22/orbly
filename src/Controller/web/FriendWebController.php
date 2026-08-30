<?php

namespace App\Controller\Web;

use App\Entity\User;
use App\Repository\FriendshipRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class FriendWebController extends AbstractController
{
    public function __construct(
        private readonly FriendshipRepository $friendships,
    ) {
    }

    #[Route('/friends', name: 'app_friends', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $me */
        $me = $this->getUser();

        // Reuses the repository the API uses — the addSelect eager-load
        // is already in there, so no N+1 from the template touching
        // each friend's displayName.
        return $this->render('friends/index.html.twig', [
            'friendships' => $this->friendships->findFriendsOf($me, 50),
        ]);
    }
}
