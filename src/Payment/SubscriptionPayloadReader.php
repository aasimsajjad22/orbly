<?php

namespace App\Payment;

use App\Enum\SubscriptionStatus;

/**
 * Reads the fields we care about out of a Stripe subscription payload.
 *
 * Exists as its own class because the payload shape is a moving target
 * and this is where that mess is contained. Everything downstream gets
 * clean typed values.
 */
final class SubscriptionPayloadReader
{
    /**
     * Pull the current period end out of a subscription object.
     *
     * THE Basil gotcha. Before the 2025-03-31 API version this lived at
     * the top level as $data['current_period_end']. Stripe moved it onto
     * the subscription ITEMS, so it is now
     * $data['items']['data'][0]['current_period_end'].
     *
     * A subscription can have several items with different billing
     * periods; we take the earliest, which is when access should lapse.
     *
     * We check BOTH locations so the code survives a version change in
     * either direction — the failure mode otherwise is silent: the field
     * is simply absent, no error, and the date quietly becomes null.
     */
    public function readCurrentPeriodEnd(array $data): ?\DateTimeImmutable
    {
        $timestamps = [];

        // New location (Basil and later): on each subscription item.
        foreach ($data['items']['data'] ?? [] as $item) {
            if (isset($item['current_period_end'])) {
                $timestamps[] = (int) $item['current_period_end'];
            }
        }

        // Old location (pre-Basil), kept as a fallback.
        if ($timestamps === [] && isset($data['current_period_end'])) {
            $timestamps[] = (int) $data['current_period_end'];
        }

        if ($timestamps === []) {
            return null;
        }

        // Earliest period end across all items — the conservative choice.
        // Stripe timestamps are Unix seconds; @ tells DateTimeImmutable
        // to read it as one.
        return new \DateTimeImmutable('@'.min($timestamps));
    }

    /**
     * Convert Stripe's status string into our enum.
     *
     * from() THROWS on an unknown value rather than returning null. That
     * is what we want: a status Stripe invented that we have never seen
     * should fail loudly and land in the failure transport, not silently
     * become "not Pro" and cut off a paying customer.
     */
    public function readStatus(array $data): SubscriptionStatus
    {
        return SubscriptionStatus::from($data['status']);
    }

    /**
     * Have they cancelled, with time still left on the period?
     *
     * Note Stripe deprecated cancel_at_period_end in favour of cancel_at
     * enums, but the field still works and its behaviour is unchanged, so
     * we read it — and fall back to cancel_at being set.
     */
    public function readCancelAtPeriodEnd(array $data): bool
    {
        if (isset($data['cancel_at_period_end'])) {
            return (bool) $data['cancel_at_period_end'];
        }

        return isset($data['cancel_at']) && $data['cancel_at'] !== null;
    }
}
