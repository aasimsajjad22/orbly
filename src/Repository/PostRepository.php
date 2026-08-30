<?php

namespace App\Repository;

use App\Entity\Post;
use App\Entity\User;
use App\Enum\PostVisibility;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use App\Pagination\Cursor;

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

    /**
     * Posts by the user's friends, newest first, cursor-paginated.
     *
     * @return Post[]
     */
    public function findFeedFor(User $user, ?Cursor $cursor = null, int $limit = 20): array
    {
        $qb = $this->notDeleted()
            // THE join the two-row friendship design exists for.
            //
            // Every friendship is stored twice, so "posts by my friends"
            // is a single equality on f.user, and the author match is a
            // single equality on f.friend. With one-row-per-friendship
            // this join condition would need an OR, on the hottest query
            // in the app.
            ->leftJoin(
                'App\Entity\Friendship',
                'f',
                'WITH',
                'f.friend = p.author AND f.user = :me'
            )
            ->setParameter('me', $user)
            // Either a friendship matched, or you wrote it.
            ->andWhere('f.id IS NOT NULL OR p.author = :me')

            // Friends see public and friends-only. You additionally see
            // your own private posts — nobody else ever does.
            ->andWhere('p.visibility IN (:visible) OR p.author = :me')
            ->setParameter('visible', [PostVisibility::Public, PostVisibility::Friends])

            // Exclude anyone involved in a block, either direction. A
            // subquery rather than a join, because a join would duplicate
            // rows when multiple blocks match.
            ->andWhere(
                'NOT EXISTS (
                    SELECT 1 FROM App\Entity\Block b
                    WHERE (b.blocker = :me AND b.blocked = p.author)
                       OR (b.blocker = p.author AND b.blocked = :me)
                )'
            )

            // Eager-load the author: every feed item shows it, and without
            // this each post fires its own SELECT.
            ->leftJoin('p.author', 'a')->addSelect('a')

            // Sort by the same pair the cursor uses, or the tiebreaker
            // means nothing.
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')

            // Ask for one extra row. If we get limit+1 back, there is at
            // least one more page — cheaper than a separate COUNT query,
            // which on a feed would be expensive and instantly stale.
            ->setMaxResults($limit + 1);

        if ($cursor !== null) {
            // The composite comparison. Plain `createdAt < :ts` would skip
            // or repeat rows that share a timestamp; adding the id makes
            // the ordering total.
            $qb->andWhere('(p.createdAt < :cursorDate OR (p.createdAt = :cursorDate AND p.id < :cursorId))')
                ->setParameter('cursorDate', $cursor->createdAt)
                ->setParameter('cursorId', $cursor->id);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Re-read a post from the database, discarding the cached version.
     *
     * Needed after any bulk DQL UPDATE: those change rows directly and do
     * not update objects already in Doctrine's identity map, so
     * $post->getLikeCount() would return the pre-update value.
     */
    public function refresh(Post $post): void
    {
        $this->getEntityManager()->refresh($post);
    }
}
