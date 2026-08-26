<?php

namespace App\Enum;

/**
 * A "backed" enum — each case has a scalar value behind it. That value is
 * what lands in the database column; your PHP code always works with the
 * enum object itself.
 *
 * Strings, not integers: `select * from friend_requests where status='pending'`
 * is readable in psql, and an added case can't shift existing meanings.
 */
enum FriendRequestStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Cancelled = 'cancelled';

    /**
     * Enums can have methods. Putting this here means the rule lives with
     * the type instead of being repeated as `=== Pending` all over the app.
     */
    public function isFinal(): bool
    {
        return $this !== self::Pending;
    }
}
