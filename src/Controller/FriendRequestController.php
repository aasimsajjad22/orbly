<?php

namespace App\Controller;

use App\Dto\SendFriendRequestRequest;
use App\Entity\FriendRequest;
use App\Entity\User;
use App\Repository\FriendRequestRepository;
use App\Repository\UserRepository;
use App\Security\Voter\FriendRequestVoter;
use App\Service\FriendRequestException;
use App\Service\FriendRequestService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

class FriendRequestController extends AbstractController
{
    public function __construct(
        private readonly FriendRequestService $service,
        private readonly FriendRequestRepository $requests,
        private readonly UserRepository $users,
    ) {
    }

    #[Route('/api/friend-requests', name: 'api_friend_request_send', methods: ['POST'])]
    public function send(#[MapRequestPayload] SendFriendRequestRequest $payload): JsonResponse
    {
        /** @var User $me */
        // No null check needed: access_control already rejected anonymous
        // requests to /api with a 401 before we got here.
        $me = $this->getUser();

        $recipient = $this->users->find($payload->recipientId);

        if ($recipient === null) {
            return new JsonResponse(
                ['message' => 'User not found.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        try {
            [$request, $autoAccepted] = $this->service->send($me, $recipient);
        } catch (FriendRequestException $e) {
            // 422: the request was well-formed but breaks a business rule.
            return new JsonResponse(
                ['message' => $e->getMessage()],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return new JsonResponse(
            [
                'id' => $request->getId(),
                'status' => $request->getStatus()->value,   // ->value = the string
                'autoAccepted' => $autoAccepted,
                'message' => $autoAccepted
                    ? 'You are now friends.'
                    : 'Friend request sent.',
            ],
            Response::HTTP_CREATED,
        );
    }

    /**
     * Lists requests involving the current user.
     *
     * ?direction=incoming|outgoing  (default: incoming)
     * ?status=pending|accepted|...  (default: pending)
     */
    #[Route('/api/friend-requests', name: 'api_friend_request_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        /** @var User $me */
        $me = $this->getUser();

        $direction = $request->query->get('direction', 'incoming');
        $status = $request->query->get('status', 'pending');

        $requests = $this->requests->findForUser($me, $direction, $status);

        // array_map over the results to build the JSON shape by hand. In
        // Phase 4 we replace this with the Serializer and #[Groups], which
        // scales better once several endpoints return the same entity.
        return new JsonResponse([
            'direction' => $direction,
            'items' => array_map(
                function ($fr) use ($direction) {
                    // Show the OTHER person — the current user already knows
                    // who they are.
                    $other = $direction === 'incoming'
                        ? $fr->getSender()
                        : $fr->getRecipient();

                    return [
                        'id' => $fr->getId(),
                        'status' => $fr->getStatus()->value,
                        'createdAt' => $fr->getCreatedAt()->format(\DATE_ATOM),
                        'user' => [
                            'id' => $other->getId(),
                            'displayName' => $other->getDisplayName(),
                        ],
                    ];
                },
                $requests
            ),
        ]);
    }

    /**
     * Accept a request. Recipient only.
     *
     * The FriendRequest type-hint on {id} makes Symfony load the entity for
     * us — it matches {id} to the primary key, queries Doctrine, and throws
     * a 404 if there is no such row. Laravel's route-model binding.
     */
    #[Route('/api/friend-requests/{id}/accept', name: 'api_friend_request_accept', methods: ['POST'])]
    public function accept(FriendRequest $request): JsonResponse
    {
        // THE VOTER CALL. Throws AccessDeniedException — which the firewall
        // turns into a 403 — if the voter says no.
        //
        // denyAccessUnlessGranted() is a shortcut on AbstractController for
        //: if (!$this->isGranted(...)) { throw ... }
        $this->denyAccessUnlessGranted(FriendRequestVoter::RESPOND, $request);

        // Guard the state separately from the permission. A request that was
        // already answered gives 409 Conflict, not 403 — the caller has the
        // right to act, the object is just in the wrong state. Two different
        // problems deserve two different codes.
        if (!$request->isPending()) {
            return new JsonResponse(
                ['message' => 'This request has already been answered.'],
                Response::HTTP_CONFLICT,
            );
        }

        $this->service->accept($request);

        return new JsonResponse([
            'id' => $request->getId(),
            'status' => $request->getStatus()->value,
            'message' => 'You are now friends.',
        ]);
    }

    #[Route('/api/friend-requests/{id}/decline', name: 'api_friend_request_decline', methods: ['POST'])]
    public function decline(FriendRequest $request): JsonResponse
    {
        // Same permission as accept: both are the recipient's response.
        $this->denyAccessUnlessGranted(FriendRequestVoter::RESPOND, $request);

        if (!$request->isPending()) {
            return new JsonResponse(
                ['message' => 'This request has already been answered.'],
                Response::HTTP_CONFLICT,
            );
        }

        $this->service->decline($request);

        return new JsonResponse([
            'id' => $request->getId(),
            'status' => $request->getStatus()->value,
            'message' => 'Friend request declined.',
        ]);
    }

    /**
     * Cancel a request you sent. Sender only.
     *
     * DELETE because from the sender's point of view they are withdrawing
     * the thing they created. The row is not actually deleted — we keep it
     * with status 'cancelled' for history.
     */
    #[Route('/api/friend-requests/{id}', name: 'api_friend_request_cancel', methods: ['DELETE'])]
    public function cancel(FriendRequest $request): JsonResponse
    {
        // Different attribute: CANCEL, not RESPOND. This is what stops a
        // recipient from cancelling a request that was sent to them.
        $this->denyAccessUnlessGranted(FriendRequestVoter::CANCEL, $request);

        if (!$request->isPending()) {
            return new JsonResponse(
                ['message' => 'This request has already been answered.'],
                Response::HTTP_CONFLICT,
            );
        }

        $this->service->cancel($request);

        return new JsonResponse([
            'id' => $request->getId(),
            'status' => $request->getStatus()->value,
            'message' => 'Friend request cancelled.',
        ]);
    }
}
