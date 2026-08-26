<?php

namespace App\Security\Google;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Turns verified Google claims into a logged-in Orbly user.
 *
 * Three possible outcomes:
 *   1. we already know this googleId  -> return that user
 *   2. we know the email but not the googleId -> link them (auto-link)
 *   3. we know neither -> create a brand new user
 */
final readonly class GoogleSignInHandler
{
    public function __construct(
        private UserRepository $users,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{0: User, 1: bool} the user, and whether they are brand new
     *
     * @throws UnverifiedGoogleEmailException
     */
    public function handle(GoogleUserPayload $payload): array
    {
        // POLICY GATE. Google sometimes issues tokens for addresses it has
        // not confirmed. If we trusted an unconfirmed address, someone could
        // register a Google account claiming an email they don't own and
        // take over the matching Orbly account in step 2 below.
        // This single check is what makes auto-linking safe.
        if (!$payload->emailVerified) {
            throw new UnverifiedGoogleEmailException();
        }

        // ---- Case 1: returning Google user ----
        // Look up by googleId, NOT email. "sub" is permanent; an email
        // address can be changed or reassigned by its domain owner.
        $user = $this->users->findOneByGoogleId($payload->googleId);

        if ($user !== null) {
            return [$user, false];
        }

        // ---- Case 2: existing local account, same email -> link ----
        $user = $this->users->findOneByEmail($payload->email);

        if ($user !== null) {
            // Attach the Google account to the existing user. Their password
            // still works — they now have two ways to sign in.
            $user->setGoogleId($payload->googleId);

            // Google proved the address, so mark it verified. This matters
            // in Phase 2c: an unverified local user who signs in with Google
            // becomes verified without needing the confirmation email.
            $user->setEmailVerified(true);

            // No persist() needed — this object was loaded by Doctrine, so
            // it is already managed. flush() spots the changes and UPDATEs.
            $this->em->flush();

            return [$user, false];
        }

        // ---- Case 3: brand new user ----
        $user = new User();
        $user->setEmail($payload->email);
        $user->setGoogleId($payload->googleId);
        $user->setEmailVerified(true);

        // No password at all. This account can ONLY sign in via Google
        // until the user sets one. That is why the column is nullable.
        $user->setPassword(null);

        // Google's "name" claim is optional, so fall back to the part of
        // the email before the @, capped at the column's 50 chars.
        $user->setDisplayName(
            $payload->name ?? substr(explode('@', $payload->email)[0], 0, 50)
        );

        // persist() = start tracking; flush() = run the INSERT.
        $this->em->persist($user);
        $this->em->flush();

        return [$user, true];
    }
}
