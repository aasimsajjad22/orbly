<?php

namespace App\Message;

/**
 * "Send a verification email to user X."
 *
 * A message is a plain data object. Three rules, and each has a reason:
 *
 * 1. NO SERVICES. No mailer, no EntityManager, no repository. The message
 *    gets SERIALIZED and put in a queue — services cannot be serialized,
 *    and they would be meaningless in the worker process anyway.
 *
 * 2. NO ENTITIES. Store the user's ID, not the User object. By the time
 *    the worker runs — maybe seconds, maybe minutes later — the row may
 *    have changed or been deleted. A serialized entity would be a stale
 *    snapshot; an ID forces a fresh read.
 *
 * 3. IMMUTABLE. readonly properties, no setters. Nothing should mutate a
 *    message once it is in flight.
 */
final readonly class SendVerificationEmail
{
    public function __construct(
        public int $userId,
    ) {
    }
}
