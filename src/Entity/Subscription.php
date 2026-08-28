<?php

namespace App\Entity;

use App\Enum\SubscriptionStatus;
use App\Repository\SubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * A local mirror of a Stripe subscription.
 *
 * Never the source of truth — Stripe is. This row is updated by webhooks
 * and read for fast access checks, so we do not call Stripe's API on
 * every request.
 */
#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
#[ORM\Table(name: 'subscriptions')]
// Looking up by Stripe's ids happens on every webhook, so both are indexed.
#[ORM\UniqueConstraint(name: 'uniq_sub_stripe_customer', columns: ['stripe_customer_id'])]
#[ORM\UniqueConstraint(name: 'uniq_sub_stripe_subscription', columns: ['stripe_subscription_id'])]
class Subscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['subscription:read'])]
    private ?int $id = null;

    /**
     * OneToOne: one subscription per user.
     *
     * A real product might allow several (different plans, team seats).
     * One keeps this phase about payments rather than plan modelling.
     */
    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $user = null;

    /**
     * Stripe's customer id, "cus_...".
     *
     * Created BEFORE any payment — a Stripe customer can exist with no
     * subscription at all. That is why this is non-null while
     * stripeSubscriptionId is nullable.
     */
    #[ORM\Column(length: 255)]
    private ?string $stripeCustomerId = null;

    /**
     * Stripe's subscription id, "sub_...".
     *
     * Null between creating the customer and completing checkout.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeSubscriptionId = null;

    #[ORM\Column(length: 30, enumType: SubscriptionStatus::class)]
    #[Groups(['subscription:read'])]
    private SubscriptionStatus $status = SubscriptionStatus::Incomplete;

    /**
     * When the paid period ends.
     *
     * Displayed to the user as "renews on" or "access until". NOT used to
     * compute access — status decides that, because Stripe may keep a
     * subscription active past this date while retrying a payment.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['subscription:read'])]
    private ?\DateTimeImmutable $currentPeriodEnd = null;

    /**
     * They cancelled, but the period they already paid for is still
     * running. Status stays 'active' until it ends — so this flag is the
     * only way to know they are leaving.
     */
    #[ORM\Column(options: ['default' => false])]
    #[Groups(['subscription:read'])]
    private bool $cancelAtPeriodEnd = false;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(User $user, string $stripeCustomerId)
    {
        $this->user = $user;
        $this->stripeCustomerId = $stripeCustomerId;
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

    public function getStripeCustomerId(): string
    {
        return $this->stripeCustomerId;
    }

    public function getStripeSubscriptionId(): ?string
    {
        return $this->stripeSubscriptionId;
    }

    public function getStatus(): SubscriptionStatus
    {
        return $this->status;
    }

    public function getCurrentPeriodEnd(): ?\DateTimeImmutable
    {
        return $this->currentPeriodEnd;
    }

    public function isCancelAtPeriodEnd(): bool
    {
        return $this->cancelAtPeriodEnd;
    }

    /**
     * THE access check. One method, used everywhere.
     *
     * Delegates to the enum so the rule lives in one place — and so
     * changing the PastDue policy is a one-line change.
     */
    #[Groups(['subscription:read'])]
    public function isPro(): bool
    {
        return $this->status->grantsProAccess();
    }

    /**
     * Apply the state Stripe just told us about.
     *
     * ONE method rather than four setters, deliberately: these fields
     * always change together, and they always change because a webhook
     * said so. A setStatus() on its own would invite updating the status
     * without the period end, leaving the mirror internally inconsistent.
     */
    public function syncFromStripe(
        string $stripeSubscriptionId,
        SubscriptionStatus $status,
        ?\DateTimeImmutable $currentPeriodEnd,
        bool $cancelAtPeriodEnd,
    ): void {
        $this->stripeSubscriptionId = $stripeSubscriptionId;
        $this->status = $status;
        $this->currentPeriodEnd = $currentPeriodEnd;
        $this->cancelAtPeriodEnd = $cancelAtPeriodEnd;
        $this->updatedAt = new \DateTimeImmutable();
    }
}
