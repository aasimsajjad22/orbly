<?php

namespace App\Repository;

use App\Entity\FriendRequest;
use App\Entity\User;
use App\Enum\FriendRequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FriendRequest>
 */
class FriendRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FriendRequest::class);
    }

    /**
     * Is there an open request from $sender to $recipient?
     *
     * Direction matters here — this only looks one way.
     */
    public function findPendingBetween(User $sender, User $recipient): ?FriendRequest
    {
        return $this->findOneBy([
            'sender' => $sender,
            'recipient' => $recipient,
            'status' => FriendRequestStatus::Pending,
        ]);
    }

    /**
     * @return FriendRequest[]
     */
    public function findForUser(User $user, string $direction, string $status): array
    {
        $qb = $this->createQueryBuilder('fr')
            // THE N+1 FIX, applied up front.
            //
            // Without these two lines, each result's getSender() would fire
            // its own SELECT when the controller touches it — 20 requests
            // means 21 queries. addSelect() tells Doctrine to fetch the
            // joined users in the SAME query and hydrate them immediately.
            //
            // leftJoin alone is NOT enough: it lets you filter on the
            // relation, but Doctrine still lazy-loads the objects. The
            // addSelect is what actually eager-loads them.
            ->leftJoin('fr.sender', 's')->addSelect('s')
            ->leftJoin('fr.recipient', 'r')->addSelect('r')
            ->orderBy('fr.createdAt', 'DESC')
            ->setMaxResults(50);

        // Which side of the relation is "me"?
        if ($direction === 'outgoing') {
            $qb->andWhere('fr.sender = :me');
        } else {
            $qb->andWhere('fr.recipient = :me');
        }

        $qb->setParameter('me', $user);

        // tryFrom returns null for an unrecognised string instead of
        // throwing — so a junk ?status= value simply means "no filter"
        // rather than a 500.
        $statusEnum = FriendRequestStatus::tryFrom($status);

        if ($statusEnum !== null) {
            $qb->andWhere('fr.status = :status')
                ->setParameter('status', $statusEnum);
        }

        return $qb->getQuery()->getResult();
    }
}
