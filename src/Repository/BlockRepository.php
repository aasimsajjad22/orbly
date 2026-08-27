<?php

namespace App\Repository;

use App\Entity\Block;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Block>
 */
class BlockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Block::class);
    }

    /**
     * Is there a block between these two, in EITHER direction?
     *
     * This is THE method the rest of the app uses. Almost nothing cares who
     * blocked whom — only whether contact is allowed. Asking one direction
     * would let a blocked user still send requests to the person who
     * blocked them.
     *
     * Note this one genuinely needs the OR, unlike friendships. That is
     * fine: it runs on a pair of specific IDs, so the index does the work
     * either way, and it never scans.
     */
    public function existsBetween(User $a, User $b): bool
    {
        $count = $this->createQueryBuilder('b')
            ->select('COUNT(b.id)')
            ->andWhere('(b.blocker = :a AND b.blocked = :b) OR (b.blocker = :b AND b.blocked = :a)')
            ->setParameter('a', $a)
            ->setParameter('b', $b)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * Did $blocker specifically block $blocked? Direction matters here,
     * because only the blocker may unblock.
     */
    public function findBlock(User $blocker, User $blocked): ?Block
    {
        return $this->findOneBy(['blocker' => $blocker, 'blocked' => $blocked]);
    }

    /**
     * @return Block[]
     */
    public function findBlocksBy(User $blocker): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.blocker = :me')
            ->setParameter('me', $blocker)
            ->leftJoin('b.blocked', 'u')->addSelect('u')   // eager-load, avoid N+1
            ->orderBy('b.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
