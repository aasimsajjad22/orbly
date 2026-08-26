<?php

namespace App\Security\Google;

/**
 * The trusted claims we extract from a verified Google ID token.
 *
 * Constructing one of these means verification ALREADY PASSED. Nothing
 * downstream re-checks anything — that's the point of a value object: it
 * makes an invalid state unrepresentable.
 */
final readonly class GoogleUserPayload
{
    public function __construct(
        // Google's "sub" claim: permanent, unique, never reassigned.
        // This is what we link accounts on.
        public string $googleId,

        public string $email,

        // Google's own "have we proven this address" flag. We refuse to
        // trust the email at all when this is false.
        public bool $emailVerified,

        // The "name" claim. Optional — not every Google account has one,
        // so we fall back to the email local-part when creating a user.
        public ?string $name = null,
    ) {
    }
}
