<?php

namespace App\Repository;

use App\Entity\Friendship;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Friendship>
 */
class FriendshipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Friendship::class);
    }

    /**
     * List a user's friends.
     *
     * This is the query the two-row design exists for: one column, one
     * index, no OR.
     *
     * @return Friendship[]
     */
    public function findFriendsOf(User $user, int $limit = 50, int $offset = 0): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.user = :me')
            ->setParameter('me', $user)
            // Eager-load the friend so the controller can read displayName
            // without firing one query per row. Without addSelect, 50
            // friends = 51 queries.
            ->leftJoin('f.friend', 'friend')->addSelect('friend')
            ->orderBy('friend.displayName', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    public function countFriendsOf(User $user): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->andWhere('f.user = :me')
            ->setParameter('me', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Are these two friends? Checks one direction only — which is safe
     * BECAUSE we always write both rows together.
     */
    public function areFriends(User $a, User $b): bool
    {
        return $this->findOneBy(['user' => $a, 'friend' => $b]) !== null;
    }
}
