<?php

namespace App\Entity;

use App\Repository\ProcessedWebhookEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A record that we have already handled a given Stripe event.
 *
 * THE idempotency guarantee for this phase.
 *
 * Stripe retries on any non-2xx and can deliver duplicates even after a
 * success. Processing "invoice.paid" twice would grant two billing
 * periods for one payment — so every event id is recorded, and a repeat
 * is dropped.
 *
 * Same pattern as the unique friend-request index: the application checks
 * first, and the database guarantees it when two requests race.
 */
#[ORM\Entity(repositoryClass: ProcessedWebhookEventRepository::class)]
#[ORM\Table(name: 'processed_webhook_events')]
#[ORM\UniqueConstraint(name: 'uniq_stripe_event_id', columns: ['stripe_event_id'])]
class ProcessedWebhookEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Stripe's event id, "evt_...". */
    #[ORM\Column(length: 255)]
    private ?string $stripeEventId = null;

    /** e.g. "customer.subscription.updated" — useful when debugging. */
    #[ORM\Column(length: 100)]
    private ?string $eventType = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $processedAt;

    public function __construct(string $stripeEventId, string $eventType)
    {
        $this->stripeEventId = $stripeEventId;
        $this->eventType = $eventType;
        $this->processedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStripeEventId(): string
    {
        return $this->stripeEventId;
    }
}
