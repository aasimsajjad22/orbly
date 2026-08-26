<?php

namespace App\Security\Google;

/**
 * Turns a raw Google ID token string into trusted claims.
 *
 * The controller depends on THIS, never on a concrete class. That is what
 * lets us swap in a fake for tests without touching a line of app code.
 */
interface GoogleIdTokenVerifier
{
    /**
     * @throws InvalidGoogleTokenException if the token is invalid for ANY
     *         reason: bad signature, expired, wrong audience, wrong issuer.
     *
     * Note it either returns valid claims or throws. There is no "return
     * null and let the caller decide" — that invites callers to forget to
     * check, which in security code is how holes appear.
     */
    public function verify(string $idToken): GoogleUserPayload;
}
