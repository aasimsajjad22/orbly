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
}
