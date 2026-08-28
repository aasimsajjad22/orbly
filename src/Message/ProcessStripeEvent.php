<?php

namespace App\Message;

/**
 * A verified Stripe event, waiting to be applied to our local mirror.
 *
 * Note this one DOES carry the payload, breaking the "ids only" rule from
 * Phase 5 — deliberately. The alternative is re-fetching the event from
 * Stripe's API in the worker, which costs a network round trip and can
 * fail. The payload is already verified and immutable, so carrying it is
 * safe: Stripe events never change after they are created.
 */
final readonly class ProcessStripeEvent
{
    public function __construct(
        public string $eventId,
        public string $eventType,
        public array $data,
    ) {
    }
}
