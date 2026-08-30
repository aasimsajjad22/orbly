<?php

namespace App\Controller\Web;

use App\Entity\User;
use App\Enum\FriendRequestStatus;
use App\Repository\FriendRequestRepository;
use App\Repository\FriendshipRepository;
use App\Repository\UserRepository;
use App\Security\Voter\FriendRequestVoter;
use App\Service\FriendRequestException;
use App\Service\FriendRequestService;
use App\Service\FriendshipService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class FriendWebController extends AbstractController
{
    public function __construct(
        private readonly FriendshipRepository $friendships,
        private readonly FriendRequestRepository $requests,
        private readonly FriendRequestService $requestService,
        private readonly FriendshipService $friendshipService,
        private readonly UserRepository $users,
    ) {
    }

    #[Route('/friends', name: 'app_friends', methods: ['GET'])]
    public function index(): Response
    {
        /** @var User $me */
        $me = $this->getUser();

        return $this->render('friends/index.html.twig', [
            'friendships' => $this->friendships->findFriendsOf($me, 50),
            // Both directions, so one page covers "who wants to be my
            // friend" and "who have I asked".
            'incoming' => $this->requests->findForUser($me, 'incoming', 'pending'),
            'outgoing' => $this->requests->findForUser($me, 'outgoing', 'pending'),
        ]);
    }

    #[Route('/friend-requests/{id}/respond', name: 'app_friend_request_respond', methods: ['POST'])]
    public function respond(int $id, Request $request): Response
    {
        $friendRequest = $this->requests->find($id);

        if ($friendRequest === null) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('respond', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Your session expired. Please try again.');

            return $this->redirectToRoute('app_friends');
        }

        // The SAME Voter the API uses. Only the recipient may respond.
        $this->denyAccessUnlessGranted(FriendRequestVoter::RESPOND, $friendRequest);

        if (!$friendRequest->isPending()) {
            $this->addFlash('error', 'That request has already been answered.');

            return $this->redirectToRoute('app_friends');
        }

        // 'accept' or 'decline', from the button the user clicked.
        if ($request->request->get('action') === 'accept') {
            $this->requestService->accept($friendRequest);
            $this->addFlash('success', 'You are now friends.');
        } else {
            $this->requestService->decline($friendRequest);
            $this->addFlash('success', 'Request declined.');
        }

        return $this->redirectToRoute('app_friends');
    }

    #[Route('/friend-requests/{id}/cancel', name: 'app_friend_request_cancel', methods: ['POST'])]
    public function cancel(int $id, Request $request): Response
    {
        $friendRequest = $this->requests->find($id);

        if ($friendRequest === null) {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('cancel', (string) $request->request->get('_csrf_token'))) {
            return $this->redirectToRoute('app_friends');
        }

        // Different attribute — senders cancel, recipients decline.
        $this->denyAccessUnlessGranted(FriendRequestVoter::CANCEL, $friendRequest);

        if ($friendRequest->isPending()) {
            $this->requestService->cancel($friendRequest);
            $this->addFlash('success', 'Request cancelled.');
        }

        return $this->redirectToRoute('app_friends');
    }

    #[Route('/search', name: 'app_search', methods: ['GET'])]
    public function search(Request $request): Response
    {
        /** @var User $me */
        $me = $this->getUser();

        $term = trim((string) $request->query->get('q', ''));

        // Reuses the search() method written back in Phase 1 — the first
        // repository query in the project, finally getting used.
        $results = $term === '' ? [] : $this->users->search($term, 20);

        // Drop yourself from the results.
        $results = array_values(array_filter(
            $results,
            static fn (User $u) => $u->getId() !== $me->getId()
        ));

        return $this->render('friends/search.html.twig', [
            'term' => $term,
            'results' => $results,
        ]);
    }
}
