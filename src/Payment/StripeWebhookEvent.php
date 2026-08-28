<?php

namespace App\Payment;

/**
 * A verified Stripe event.
 *
 * Constructing one means the signature ALREADY passed — nothing
 * downstream re-checks it. Same "make invalid states unrepresentable"
 * idea as GoogleUserPayload.
 */
final readonly class StripeWebhookEvent
{
    public function __construct(
        /** "evt_..." — the idempotency key. */
        public string $id,
        /** e.g. "customer.subscription.updated" */
        public string $type,
        /** The event's data.object, as a plain array. */
        public array $data,
    ) {
    }
}
