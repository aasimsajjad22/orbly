<?php

namespace App\Entity;

use App\Enum\FriendRequestStatus;
use App\Repository\FriendRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FriendRequestRepository::class)]
#[ORM\Table(name: 'friend_requests')]
#[ORM\Index(columns: ['recipient_id', 'status'], name: 'idx_fr_recipient_status')]
#[ORM\Index(columns: ['sender_id', 'status'], name: 'idx_fr_sender_status')]
// Partial unique index: at most ONE pending request per (sender, recipient)
// pair, while still allowing a fresh request after a decline or cancel.
// The 'where' option is Postgres-specific — it becomes the WHERE clause on
// CREATE UNIQUE INDEX. Declaring it here (rather than only in the migration)
// means Doctrine's schema comparator knows about it, so schema:validate
// stops reporting a false difference.
#[ORM\UniqueConstraint(
    name: 'uniq_pending_friend_request',
    columns: ['sender_id', 'recipient_id'],
    options: ['where' => "((status)::text = 'pending'::text)"],
)]
class FriendRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Who sent it.
     *
     * ManyToOne: many requests point at one user. This is the OWNING side —
     * the side that actually holds the foreign key column (sender_id).
     *
     * inversedBy names the property on User that mirrors this one. It must
     * match exactly, and it is NOT the same as the recipient's mirror.
     *
     * onDelete: 'CASCADE' is a DATABASE-level rule: delete the user row and
     * Postgres removes their requests too, without Doctrine loading them.
     */
    #[ORM\ManyToOne(inversedBy: 'sentFriendRequests')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $sender = null;

    /**
     * Who received it. Same shape, different mirror property.
     */
    #[ORM\ManyToOne(inversedBy: 'receivedFriendRequests')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $recipient = null;

    /**
     * enumType tells Doctrine to convert between the string column and the
     * enum object. Reads give you a FriendRequestStatus; writes accept one.
     * An invalid string in the database throws on hydration rather than
     * silently becoming garbage.
     */
    #[ORM\Column(length: 20, enumType: FriendRequestStatus::class)]
    private FriendRequestStatus $status = FriendRequestStatus::Pending;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * When it was accepted or declined. Null while pending.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $respondedAt = null;


    /**
     * Requiring both users in the constructor means a FriendRequest can
     * never exist in a half-built state. Compare with User, where we used
     * setters because Doctrine's maker generated it that way — this is the
     * better pattern where you control the class.
     */
    public function __construct(User $sender, User $recipient)
    {
        $this->sender = $sender;
        $this->recipient = $recipient;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSender(): ?User
    {
        return $this->sender;
    }

    public function getRecipient(): User
    {
        return $this->recipient;
    }

    public function getStatus(): FriendRequestStatus
    {
        return $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRespondedAt(): ?\DateTimeImmutable
    {
        return $this->respondedAt;
    }

    public function isPending(): bool
    {
        return $this->status === FriendRequestStatus::Pending;
    }

    /**
     * State transitions as named methods rather than a public setStatus().
     *
     * This is deliberate: with a setter, any caller could move a request
     * from accepted back to pending, or set respondedAt without changing
     * status. These methods make the valid transitions the only ones
     * available, and keep the two fields in step.
     */
    public function accept(): void
    {
        $this->transitionTo(FriendRequestStatus::Accepted);
    }

    public function decline(): void
    {
        $this->transitionTo(FriendRequestStatus::Declined);
    }

    public function cancel(): void
    {
        $this->transitionTo(FriendRequestStatus::Cancelled);
    }

    private function transitionTo(FriendRequestStatus $status): void
    {
        // Guard against double-processing: a request that has already been
        // answered cannot be answered again. Without this, two rapid accept
        // clicks would create two friendships.
        if (!$this->isPending()) {
            throw new \LogicException(
                sprintf('Cannot change a request that is already %s.', $this->status->value)
            );
        }

        $this->status = $status;
        $this->respondedAt = new \DateTimeImmutable();
    }
}
