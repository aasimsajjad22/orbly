<?php

namespace App\Repository;

use App\Entity\Post;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Post>
 */
class PostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Post::class);
    }

    /**
     * Base query builder with the soft-delete filter already applied.
     *
     * EVERY read of posts should start here rather than with
     * createQueryBuilder() directly. Centralising the clause is the only
     * practical defence against forgetting it — one missed WHERE and
     * deleted posts reappear.
     */
    private function notDeleted(string $alias = 'p'): QueryBuilder
    {
        return $this->createQueryBuilder($alias)
            ->andWhere($alias.'.deletedAt IS NULL');
    }

    /**
     * Find a live post by id. Returns null for deleted ones, so callers
     * cannot accidentally serve deleted content.
     */
    public function findLive(int $id): ?Post
    {
        return $this->notDeleted()
            ->andWhere('p.id = :id')
            ->setParameter('id', $id)
            // Eager-load the author: every post response includes it, and
            // without this each post fires its own SELECT for the user.
            ->leftJoin('p.author', 'a')->addSelect('a')
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return Post[]
     */
    public function findByAuthor(User $author, int $limit = 20): array
    {
        return $this->notDeleted()
            ->andWhere('p.author = :author')
            ->setParameter('author', $author)
            ->leftJoin('p.author', 'a')->addSelect('a')
            // Uses idx_posts_author_created — equality on author_id, then
            // ordered by created_at, which is exactly the index's shape.
            ->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
