<?php

namespace App\Message;

final readonly class SubscriptionCancelled
{
    public function __construct(
        public int $userId,
        // Cancellations are usually "at period end", so tell them when
        // access actually stops rather than implying it already has.
        public ?string $accessUntil = null,
    ) {
    }
}
