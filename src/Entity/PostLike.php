<?php

namespace App\Entity;

use App\Repository\PostLikeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Named PostLike, not Like — "like" is a reserved word in SQL and fighting
 * the quoting is not worth the shorter name.
 */
#[ORM\Entity(repositoryClass: PostLikeRepository::class)]
#[ORM\Table(name: 'post_likes')]
// One like per user per post, enforced by the database. The application
// checks too, but two simultaneous requests can both pass that check —
// this index is what actually guarantees it.
#[ORM\UniqueConstraint(name: 'uniq_post_like', columns: ['post_id', 'user_id'])]
class PostLike
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Post $post = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Post $post, User $user)
    {
        $this->post = $post;
        $this->user = $user;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPost(): Post
    {
        return $this->post;
    }

    public function getUser(): User
    {
        return $this->user;
    }
}
