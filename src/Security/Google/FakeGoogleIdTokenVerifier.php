<?php

namespace App\Security\Google;

use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Test-only verifier. Never calls Google.
 *
 * The "token" is just a JSON string the test writes by hand, so a test can
 * produce any claim combination it likes — including ones the real Google
 * would never emit, like emailVerified: false.
 *
 * #[When('test')] means this class is only registered in the test
 * environment. In dev and prod it does not exist in the container at all,
 * so it can never be reached by accident.
 */
#[When('test')]
final class FakeGoogleIdTokenVerifier implements GoogleIdTokenVerifier
{
    public function verify(string $idToken): GoogleUserPayload
    {
        // A test can pass this literal string to simulate a rejected token.
        if ($idToken === 'invalid-token') {
            throw new InvalidGoogleTokenException('Fake verifier: rejected by request.');
        }

        // Otherwise the token IS the claims, as JSON.
        $claims = json_decode($idToken, true);

        if (!is_array($claims) || !isset($claims['sub'], $claims['email'])) {
            throw new InvalidGoogleTokenException('Fake verifier: malformed token.');
        }

        return new GoogleUserPayload(
            googleId: $claims['sub'],
            email: $claims['email'],
            // Default TRUE, matching the normal case. A test that wants the
            // unverified path must set it false explicitly.
            emailVerified: $claims['email_verified'] ?? true,
            name: $claims['name'] ?? null,
        );
    }
}
