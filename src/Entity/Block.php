<?php

namespace App\Entity;

use App\Repository\BlockRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One user blocking another.
 *
 * ONE row per block, not two — unlike Friendship. The asymmetry is
 * deliberate: the block belongs to the person who created it, and only
 * they can undo it. Storing it twice would make "who unblocks?" ambiguous.
 *
 * The EFFECT is symmetric (neither party can contact the other), but that
 * is enforced in queries, not in the data.
 */
#[ORM\Entity(repositoryClass: BlockRepository::class)]
#[ORM\Table(name: 'blocks')]
#[ORM\UniqueConstraint(name: 'uniq_block_pair', columns: ['blocker_id', 'blocked_id'])]
class Block
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** The person who pressed block. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $blocker = null;

    /** The person on the receiving end. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $blocked = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $blocker, User $blocked)
    {
        $this->blocker = $blocker;
        $this->blocked = $blocked;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBlocker(): User
    {
        return $this->blocker;
    }

    public function getBlocked(): User
    {
        return $this->blocked;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
