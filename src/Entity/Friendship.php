<?php

namespace App\Entity;

use App\Repository\FriendshipRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One DIRECTION of a friendship.
 *
 * Every friendship is stored as TWO rows: (A, B) and (B, A). They are always
 * created and deleted together, inside a transaction. See FriendshipService.
 *
 * The denormalization is deliberate: it makes "list my friends" a single
 * indexed lookup on one column instead of an OR across two.
 */
#[ORM\Entity(repositoryClass: FriendshipRepository::class)]
#[ORM\Table(name: 'friendships')]
// Stops the same direction being stored twice. Combined with the paired
// writes, this is what guarantees the two rows stay consistent.
#[ORM\UniqueConstraint(name: 'uniq_friendship_pair', columns: ['user_id', 'friend_id'])]
class Friendship
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * The owner of this row — "whose friend list is this in".
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /**
     * The other person.
     *
     * No inversedBy on either side: we never navigate this relation from
     * User. Adding a $friendships collection to User would invite
     * $user->getFriendships() in a loop, which is the N+1 trap. Repository
     * methods with explicit joins only.
     */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $friend = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, User $friend)
    {
        $this->user = $user;
        $this->friend = $friend;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getFriend(): User
    {
        return $this->friend;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
