<?php

namespace App\Security\Google;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Verifies a Google ID token for real, against Google's published keys.
 */
final class GoogleApiIdTokenVerifier implements GoogleIdTokenVerifier
{
    // Where Google publishes its current public signing keys.
    private const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    // Google issues tokens under one of these two "iss" values. Both are
    // legitimate; historically the bare hostname came first.
    private const VALID_ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
        // Your app's OAuth client ID, injected from GOOGLE_CLIENT_ID.
        // This is the single most important value in this class.
        private readonly string $googleClientId,
    ) {
    }

    public function verify(string $idToken): GoogleUserPayload
    {
        try {
            // ---------- CHECK 1: signature (and expiry) ----------
            // JWT::decode does three things for us:
            //   - reads the "kid" from the token header
            //   - finds the matching public key in the key set
            //   - verifies the RS256 signature
            //   - checks "exp" and "nbf", throwing if expired/not-yet-valid
            // Any failure throws, so reaching the next line means the token
            // genuinely came from Google and is still in date.
            $claims = (array) JWT::decode($idToken, $this->getGooglePublicKeys());
        } catch (\Throwable $e) {
            // We log the real reason but never return it to the client.
            $this->logger->info('Google ID token rejected.', ['reason' => $e->getMessage()]);

            throw new InvalidGoogleTokenException('Invalid Google token.', 0, $e);
        }

        // ---------- CHECK 2: audience ----------
        // "aud" says WHICH app this token was minted for. Google hands out
        // valid, correctly-signed tokens to thousands of apps. Without this
        // check, a token issued to ANY other app would pass check 1 and let
        // its holder log in as that user here. This is THE classic bug in
        // hand-rolled Google sign-in.
        if (($claims['aud'] ?? null) !== $this->googleClientId) {
            $this->logger->warning('Google token with wrong audience.', [
                'expected' => $this->googleClientId,
                'got' => $claims['aud'] ?? null,
            ]);

            throw new InvalidGoogleTokenException('Invalid Google token.');
        }

        // ---------- CHECK 3: issuer ----------
        // Confirms Google itself issued this, not something else that
        // happens to hold a key we trust.
        if (!in_array($claims['iss'] ?? null, self::VALID_ISSUERS, true)) {
            throw new InvalidGoogleTokenException('Invalid Google token.');
        }

        // ---------- CHECK 4: required claims present ----------
        if (!isset($claims['sub'], $claims['email'])) {
            throw new InvalidGoogleTokenException('Invalid Google token.');
        }

        // Note we do NOT reject on email_verified here — we pass it through
        // and let the controller decide. Verification asks "is this token
        // real?"; whether an unverified email is acceptable is a policy
        // question, and policy belongs in the controller, not here.
        return new GoogleUserPayload(
            googleId: (string) $claims['sub'],
            email: strtolower((string) $claims['email']),
            emailVerified: (bool) ($claims['email_verified'] ?? false),
            name: isset($claims['name']) ? (string) $claims['name'] : null,
        );
    }

    /**
     * Fetch Google's public keys, cached.
     *
     * @return array<string, \Firebase\JWT\Key> keyed by "kid"
     */
    private function getGooglePublicKeys(): array
    {
        // get() is Symfony's cache-with-callback: if the key is present and
        // fresh it returns it; otherwise it runs the closure and stores the
        // result. No manual "if (!$cached)" dance.
        $jwks = $this->cache->get('google_oauth_jwks', function (ItemInterface $item): array {
            // One hour. Google rotates keys roughly daily and always
            // publishes the new key before retiring the old one, so an hour
            // is comfortably safe. Fetching on every login would add a
            // network round trip to every sign-in.
            $item->expiresAfter(3600);

            return $this->httpClient
                ->request('GET', self::JWKS_URL)
                ->toArray();   // decodes the JSON response into an array
        });

        // Converts Google's raw JWKS JSON into Key objects that JWT::decode
        // understands, indexed by "kid".
        return JWK::parseKeySet($jwks);
    }
}
