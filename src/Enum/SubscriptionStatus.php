<?php

namespace App\Enum;

/**
 * Mirrors Stripe's subscription statuses.
 *
 * The string values MUST match Stripe's exactly, so a webhook payload can
 * be converted with SubscriptionStatus::from($stripeStatus) and any value
 * we do not know about throws loudly instead of being silently ignored.
 */
enum SubscriptionStatus: string
{
    /** Created, payment not completed yet. */
    case Incomplete = 'incomplete';

    /** Payment failed and the window to fix it expired. Terminal. */
    case IncompleteExpired = 'incomplete_expired';

    /** Free trial. */
    case Trialing = 'trialing';

    /** Paid and current. */
    case Active = 'active';

    /** Payment failed; Stripe is retrying. Access is a policy choice. */
    case PastDue = 'past_due';

    /** Ended, by the user or after failed retries. */
    case Canceled = 'canceled';

    /** Payment failed and the subscription is paused. */
    case Unpaid = 'unpaid';

    /**
     * Should this status unlock Pro features?
     *
     * PastDue is the interesting one — a real product decision, not a
     * technical fact. We keep access during the retry window, because
     * cutting someone off over a temporarily declined card is hostile
     * and most failures resolve within days.
     *
     * Putting it here means the rule lives in ONE place rather than
     * being re-decided at every call site.
     */
    public function grantsProAccess(): bool
    {
        return match ($this) {
            self::Active, self::Trialing, self::PastDue => true,
            self::Incomplete, self::IncompleteExpired, self::Canceled, self::Unpaid => false,
        };
    }
}
