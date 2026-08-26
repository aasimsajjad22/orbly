<?php

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;


/**
 * Runs on every authentication, for every firewall.
 */
final class UserChecker implements UserCheckerInterface
{
    /**
     * Called BEFORE the password is verified.
     *
     * Checks here apply even when the password is wrong, so anything in this
     * method leaks information to someone who does NOT know the password.
     * That is why the email check is NOT here — see checkPostAuth().
     */
    public function checkPreAuth(UserInterface $user): void
    {
        // Nothing yet. A "banned" or "deleted" check would go here: those
        // are worth enforcing even before checking a password.
    }

    /**
     * Called AFTER the password (or JWT signature) has been verified.
     *
     * Reaching this method means the caller has genuinely proven they own
     * the account, so telling them "your email isn't verified" gives nothing
     * away — they already know they own it.
     */
    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        // Guard for other UserInterface implementations. In practice we only
        // have App\Entity\User, but the interface signature is broader.
        if (!$user instanceof User) {
            return;
        }

        // THE HARD GATE. Google users have emailVerified = true set at
        // creation, so this never fires for them — exactly as designed
        // in Phase 2b.
        if (!$user->isEmailVerified()) {
            throw new EmailNotVerifiedException();
        }
    }
}
