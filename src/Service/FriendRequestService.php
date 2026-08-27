<?php

namespace App\Service;

use App\Entity\FriendRequest;
use App\Entity\User;
use App\Repository\FriendRequestRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * All the business rules for sending a friend request.
 *
 * Kept out of the controller so the rules are testable without HTTP, and
 * so the controller stays a thin HTTP adapter.
 */
final readonly class FriendRequestService
{
    public function __construct(
        private FriendRequestRepository $requests,
        private EntityManagerInterface $em,
        private FriendshipService $friendships,
    ) {
    }

    /**
     * @return array{0: FriendRequest, 1: bool} the request, and whether it
     *         was auto-accepted because the other person had already asked
     *
     * @throws FriendRequestException
     */
    public function send(User $sender, User $recipient): array
    {
        // RULE 1: no self-requests. Compare IDs, not objects — two Doctrine
        // instances of the same row are usually identical, but not
        // guaranteed across different EntityManager states.
        if ($sender->getId() === $recipient->getId()) {
            throw FriendRequestException::cannotFriendYourself();
        }

        // RULE 2: already have one open in this direction? Return it rather
        // than erroring. Sending twice is a normal double-click, not an
        // attack, and 200-with-the-existing-request is kinder than a 409.
        $existing = $this->requests->findPendingBetween($sender, $recipient);

        if ($existing !== null) {
            return [$existing, false];
        }

        // RULE 3: the interesting one. If THEY already sent US a request and
        // we send one back, that is mutual consent — accept theirs instead
        // of creating a second, opposite request. Without this you end up
        // with two pending requests between the same pair and no clear way
        // to resolve them.
        $reverse = $this->requests->findPendingBetween($recipient, $sender);

        if ($reverse !== null) {
            $reverse->accept();
            $this->friendships->create($recipient, $sender);   // ← new
            $this->em->flush();

            // The bool says "this became a friendship" — the controller
            // uses it to pick a different status code and message.
            return [$reverse, true];
        }

        $request = new FriendRequest($sender, $recipient);

        $this->em->persist($request);
        $this->em->flush();

        return [$request, false];
    }

    /**
     * Accept a pending request.
     *
     * Authorization is NOT checked here — that is the Voter's job, called
     * from the controller. Keeping them separate means this method stays
     * usable from a console command or a message handler, where there is
     * no logged-in user to check against.
     */
    public function accept(FriendRequest $request): void
    {
        // The entity guards its own state transitions, so a double-accept
        // throws rather than creating two friendships.
        $request->accept();
        $this->friendships->create($request->getSender(), $request->getRecipient());

        $this->em->flush();

        // Chunk 4 adds the Friendship rows here, inside a transaction.
    }

    public function decline(FriendRequest $request): void
    {
        $request->decline();
        $this->em->flush();
    }

    public function cancel(FriendRequest $request): void
    {
        $request->cancel();
        $this->em->flush();
    }
}
