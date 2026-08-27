<?php

namespace App\Service;

use App\Entity\Block;
use App\Entity\User;
use App\Enum\FriendRequestStatus;
use App\Repository\BlockRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class BlockService
{
    public function __construct(
        private EntityManagerInterface $em,
        private BlockRepository $blocks,
        private FriendshipService $friendships,
    ) {
    }

    /**
     * Block someone, and tear down any existing relationship.
     *
     * All of it in one transaction: a half-applied block (row created but
     * friendship still standing) is worse than no block at all, because the
     * user believes they are protected.
     */
    public function block(User $blocker, User $blocked): void
    {
        if ($blocker->getId() === $blocked->getId()) {
            throw new \InvalidArgumentException('You cannot block yourself.');
        }

        // Idempotent — blocking twice is a no-op, not an error.
        if ($this->blocks->findBlock($blocker, $blocked) !== null) {
            return;
        }

        $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($blocker, $blocked): void {
            $em->persist(new Block($blocker, $blocked));

            // 1. Remove the friendship if there is one. This deletes BOTH
            //    mirror rows — see FriendshipService::remove().
            $this->friendships->remove($blocker, $blocked);

            // 2. Cancel any pending request in EITHER direction. A DQL
            //    UPDATE runs as one SQL statement rather than loading the
            //    entities, which is what we want for a bulk state change.
            $em->createQuery(
                'UPDATE App\Entity\FriendRequest fr
                 SET fr.status = :cancelled, fr.respondedAt = :now
                 WHERE fr.status = :pending
                   AND ((fr.sender = :a AND fr.recipient = :b)
                     OR (fr.sender = :b AND fr.recipient = :a))'
            )
                ->setParameter('cancelled', FriendRequestStatus::Cancelled)
                ->setParameter('pending', FriendRequestStatus::Pending)
                ->setParameter('now', new \DateTimeImmutable())
                ->setParameter('a', $blocker)
                ->setParameter('b', $blocked)
                ->execute();

            $em->flush();
        });
    }

    /**
     * Unblock. Note this does NOT restore the old friendship — that is
     * gone. They can send a fresh friend request if they want.
     */
    public function unblock(User $blocker, User $blocked): bool
    {
        $block = $this->blocks->findBlock($blocker, $blocked);

        if ($block === null) {
            return false;
        }

        $this->em->remove($block);
        $this->em->flush();

        return true;
    }
}
