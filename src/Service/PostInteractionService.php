<?php

namespace App\Service;

use App\Entity\Comment;
use App\Entity\Post;
use App\Entity\PostLike;
use App\Entity\User;
use App\Repository\PostLikeRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PostInteractionService
{
    public function __construct(
        private EntityManagerInterface $em,
        private PostLikeRepository $likes,
    ) {
    }

    /**
     * Like a post. Idempotent — liking twice is a no-op, not an error.
     *
     * @return bool true if a new like was created
     */
    public function like(Post $post, User $user): bool
    {
        // Application-level check: fast, and handles the normal case.
        if ($this->likes->findOneByPostAndUser($post, $user) !== null) {
            return false;
        }

        try {
            $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($post, $user): void {
                $em->persist(new PostLike($post, $user));

                // ATOMIC increment. Note it is NOT $post->incrementLikeCount()
                // followed by flush() — that would read the value into PHP,
                // add one, and write it back. Two simultaneous likes would
                // both read 5 and both write 6, losing one.
                //
                // Letting the database do the arithmetic means the increment
                // happens under the row lock the UPDATE already takes.
                $em->createQuery(
                    'UPDATE App\Entity\Post p
                     SET p.likeCount = p.likeCount + 1
                     WHERE p.id = :id'
                )
                    ->setParameter('id', $post->getId())
                    ->execute();

                $em->flush();
            });
        } catch (UniqueConstraintViolationException) {
            // Two requests passed the check above at the same instant and
            // both tried to insert. The unique index rejected the second.
            // That is the index doing its job — treat it as "already
            // liked" rather than an error.
            return false;
        }

        return true;
    }

    /**
     * @return bool true if a like was actually removed
     */
    public function unlike(Post $post, User $user): bool
    {
        $like = $this->likes->findOneByPostAndUser($post, $user);

        if ($like === null) {
            return false;
        }

        $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($like, $post): void {
            $em->remove($like);

            // GREATEST(x, 0) floors the counter. If it ever hits zero and
            // something tries to decrement again, we do not want -1 — that
            // would be a visible symptom of a bug elsewhere, and the repair
            // command is the right place to catch it.
            $em->createQuery(
                'UPDATE App\Entity\Post p
                 SET p.likeCount = p.likeCount - 1
                 WHERE p.id = :id AND p.likeCount > 0'
            )
                ->setParameter('id', $post->getId())
                ->execute();

            $em->flush();
        });

        return true;
    }

    public function comment(Post $post, User $author, string $content): Comment
    {
        $comment = new Comment($post, $author, $content);

        $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($comment, $post): void {
            $em->persist($comment);

            $em->createQuery(
                'UPDATE App\Entity\Post p
                 SET p.commentCount = p.commentCount + 1
                 WHERE p.id = :id'
            )
                ->setParameter('id', $post->getId())
                ->execute();

            $em->flush();
        });

        return $comment;
    }

    public function deleteComment(Comment $comment): void
    {
        $postId = $comment->getPost()->getId();

        $this->em->wrapInTransaction(function (EntityManagerInterface $em) use ($comment, $postId): void {
            $em->remove($comment);

            $em->createQuery(
                'UPDATE App\Entity\Post p
                 SET p.commentCount = p.commentCount - 1
                 WHERE p.id = :id AND p.commentCount > 0'
            )
                ->setParameter('id', $postId)
                ->execute();

            $em->flush();
        });
    }
}
