<?php

namespace App\Entity;

use App\Enum\PostVisibility;
use App\Repository\PostRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Entity(repositoryClass: PostRepository::class)]
#[ORM\Table(name: 'posts')]
// THE feed index. The feed query filters by author (via the friendships
// join) and orders by created_at DESC, so a composite index on exactly
// that pair lets Postgres walk the index instead of sorting.
//
// Column order matters: equality column first, range/sort column second.
#[ORM\Index(columns: ['author_id', 'created_at'], name: 'idx_posts_author_created')]
// Supports the "exclude deleted" clause that every query carries.
#[ORM\Index(columns: ['deleted_at'], name: 'idx_posts_deleted')]
#[ORM\HasLifecycleCallbacks]
class Post
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['post:list', 'post:read'])]
    private ?int $id = null;

    /**
     * onDelete CASCADE: if a user row is deleted, their posts go with it.
     * That is a HARD delete at the database level, separate from our soft
     * delete — the two solve different problems.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['post:list', 'post:read'])]
    private ?User $author = null;

    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank(message: 'A post cannot be empty.')]
    #[Assert\Length(
        min: 1,
        max: 2000,
        maxMessage: 'A post cannot be longer than {{ limit }} characters.',
    )]
    #[Groups(['post:list', 'post:read'])]
    private ?string $content = null;

    #[ORM\Column(length: 20, enumType: PostVisibility::class)]
    #[Groups(['post:list', 'post:read'])]
    private PostVisibility $visibility = PostVisibility::Public;

    /**
     * Denormalized counters.
     *
     * A feed of 20 posts would otherwise need 40 COUNT(*) queries. The cost
     * is that these can drift if a transaction half-fails — Phase 8 adds a
     * CLI command to recompute them from the real rows.
     *
     * options: ['default' => 0] is a DATABASE default, needed because the
     * migration adds these columns to a table that may already have rows.
     */
    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['post:list', 'post:read'])]
    private int $likeCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    #[Groups(['post:list', 'post:read'])]
    private int $commentCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['post:list', 'post:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['post:list', 'post:read'])]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * Soft delete marker. NULL means live.
     *
     * WARNING: nothing enforces this. Every query that reads posts must
     * add `AND p.deletedAt IS NULL` by hand. One forgotten clause and
     * deleted content is visible again.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $deletedAt = null;

    public function __construct(User $author, string $content, PostVisibility $visibility = PostVisibility::Public)
    {
        $this->author = $author;
        $this->content = $content;
        $this->visibility = $visibility;
        $this->createdAt = new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAuthor(): User
    {
        return $this->author;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function getVisibility(): PostVisibility
    {
        return $this->visibility;
    }

    public function setVisibility(PostVisibility $visibility): static
    {
        $this->visibility = $visibility;

        return $this;
    }

    public function getLikeCount(): int
    {
        return $this->likeCount;
    }

    public function getCommentCount(): int
    {
        return $this->commentCount;
    }

    /**
     * Counter adjustments as named methods, not a public setLikeCount().
     *
     * Same reasoning as the FriendRequest state transitions: a setter would
     * let any caller write an arbitrary number, and the counter would drift
     * from reality with no way to tell where it went wrong.
     *
     * max(0, ...) is a floor, not a fix — if it ever triggers, something
     * upstream has already gone wrong and the repair command should catch it.
     */
    public function incrementLikeCount(): void
    {
        $this->likeCount++;
    }

    public function decrementLikeCount(): void
    {
        $this->likeCount = max(0, $this->likeCount - 1);
    }

    public function incrementCommentCount(): void
    {
        $this->commentCount++;
    }

    public function decrementCommentCount(): void
    {
        $this->commentCount = max(0, $this->commentCount - 1);
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }

    /**
     * Soft delete. The row stays; queries must exclude it.
     */
    public function softDelete(): void
    {
        $this->deletedAt = new \DateTimeImmutable();
    }

    #[Groups(['post:list', 'post:read'])]
    public function isEdited(): bool
    {
        return $this->updatedAt !== null;
    }
}
