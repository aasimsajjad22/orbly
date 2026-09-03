<?php

namespace App\Message;

/**
 * The user's subscription became active — first payment or renewal.
 *
 * Dispatched by ProcessStripeEventHandler AFTER the local state is
 * saved, so the email handler reads a subscription that is already
 * correct.
 */
final readonly class SubscriptionActivated
{
    public function __construct(
        public int $userId,
        // True for the first activation, false for a renewal. Lets one
        // handler send two different emails without two message types.
        public bool $isFirstPayment,
    ) {
    }
}
