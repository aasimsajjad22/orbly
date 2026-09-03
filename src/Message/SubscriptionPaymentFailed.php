<?php

namespace App\Message;

final readonly class SubscriptionPaymentFailed
{
    public function __construct(
        public int $userId,
    ) {
    }
}
