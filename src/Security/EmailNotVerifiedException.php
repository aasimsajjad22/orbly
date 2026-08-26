<?php

namespace App\Security;

use Symfony\Component\Security\Core\Exception\AccountStatusException;

/**
 * Thrown when a real, correctly-authenticated user has not confirmed
 * their email address.
 *
 * Extends AccountStatusException — Symfony's category for "credentials are
 * fine, the ACCOUNT is the problem" (disabled, locked, expired). That makes
 * the firewall treat it as an authentication failure and return 401/403
 * rather than a 500.
 */
final class EmailNotVerifiedException extends AccountStatusException
{
    /**
     * The message Symfony shows the client. Deliberately distinct from
     * "Invalid credentials" so a frontend can tell the two apart and offer
     * a "resend verification email" button.
     */
    public function getMessageKey(): string
    {
        return 'Please verify your email address before signing in.';
    }
}
