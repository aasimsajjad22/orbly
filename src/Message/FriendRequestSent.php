<?php

namespace App\Message;

/**
 * EVENT: a friend request was created.
 *
 * Named in the past tense, deliberately. Compare SendVerificationEmail,
 * which is an imperative — "do this". This one states a fact and leaves
 * the reaction open. Any number of handlers can subscribe.
 *
 * IDs only, no entities: by the time a handler runs, the request may have
 * been accepted, declined, or cancelled. Handlers load fresh state and
 * decide what is still appropriate.
 */
final readonly class FriendRequestSent
{
    public function __construct(
        public int $friendRequestId,
    ) {
    }
}
