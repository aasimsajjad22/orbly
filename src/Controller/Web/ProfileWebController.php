<?php

namespace App\Controller\Web;

use App\Entity\Post;
use App\Entity\User;
use App\Repository\FriendshipRepository;
use App\Repository\PostRepository;
use App\Security\Voter\PostVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ProfileWebController extends AbstractController
{
    public function __construct(
        private readonly PostRepository $posts,
        private readonly FriendshipRepository $friendships,
    ) {
    }

    /**
     * The User type-hint makes Symfony load the entity from {id} and
     * throw a 404 if there is no such row.
     */
    #[Route('/users/{id}', name: 'app_profile', methods: ['GET'])]
    public function show(User $id): Response
    {
        $profileUser = $id;

        /** @var User $me */
        $me = $this->getUser();

        // Fetch, then filter through the Voter — so private posts and
        // friends-only posts obey exactly the same rules as the API.
        $posts = array_values(array_filter(
            $this->posts->findByAuthor($profileUser, 50),
            fn (Post $p) => $this->isGranted(PostVoter::VIEW, $p)
        ));

        return $this->render('profile/show.html.twig', [
            'profileUser' => $profileUser,
            'posts' => $posts,
            'isMe' => $profileUser->getId() === $me->getId(),
            'friendCount' => $this->friendships->countFriendsOf($profileUser),
        ]);
    }
}
