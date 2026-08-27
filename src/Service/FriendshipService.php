<?php

namespace App\Service;

use App\Entity\Friendship;
use App\Entity\User;
use App\Repository\FriendshipRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class FriendshipService
{
    public function __construct(
        private EntityManagerInterface $em,
        private FriendshipRepository $friendships,
    ) {
    }

    /**
     * Create a friendship — BOTH rows, atomically.
     */
    public function create(User $a, User $b): void
    {
        if ($this->friendships->areFriends($a, $b)) {
            return;   // idempotent: calling twice is harmless
        }

        // wrapInTransaction() runs the closure inside a database
        // transaction. If the closure throws, EVERYTHING rolls back —
        // including the flush() inside it.
        //
        // This is what guarantees the invariant: you can never end up with
        // A->B stored but B->A missing. Without it, a failure between the
        // two persists would leave the data permanently inconsistent, and
        // areFriends() would then give different answers depending on which
        // way round you asked.
        $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($a, $b): void {
            $em->persist(new Friendship($a, $b));
            $em->persist(new Friendship($b, $a));
            $em->flush();
        });
    }

    /**
     * Remove a friendship — both rows, atomically.
     *
     * Unfriending is one-sided in the UI (you don't ask permission) but
     * two-sided in the data: removing only your row would leave you on
     * their friend list.
     */
    public function remove(User $a, User $b): void
    {
        $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($a, $b): void {
            // A DQL DELETE runs directly as SQL — it does not load the
            // entities into memory first. Much faster, but note it also
            // bypasses lifecycle callbacks, so don't use it where you need
            // preRemove hooks to fire.
            $em->createQuery(
                'DELETE FROM App\Entity\Friendship f
                 WHERE (f.user = :a AND f.friend = :b)
                    OR (f.user = :b AND f.friend = :a)'
            )
                ->setParameter('a', $a)
                ->setParameter('b', $b)
                ->execute();
        });
    }
}
