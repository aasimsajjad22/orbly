<?php

namespace App\Payment;

use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Test-only gateway. Never calls Stripe.
 *
 * #[When('test')] means this class does not exist in the dev or prod
 * container at all — it cannot be reached by accident.
 *
 * Beyond avoiding the network, this lets tests produce situations Stripe
 * will not create on demand: a forged signature, an unknown status, an
 * event for a customer we have never seen.
 */
#[When('test')]
final class FakeStripeGateway implements StripeGateway
{
    /** Records what was asked of it, so tests can assert on the calls. */
    public array $createdCustomers = [];
    public array $createdCheckoutSessions = [];
    public array $createdPortalSessions = [];

    /**
     * When true, parseWebhook() always throws. Lets a test simulate a
     * forged request without constructing a real HMAC.
     */
    public bool $rejectSignatures = false;

    private int $counter = 0;

    public function createCustomer(string $email, string $displayName, int $userId): string
    {
        $id = 'cus_fake_'.(++$this->counter);

        $this->createdCustomers[] = compact('email', 'displayName', 'userId', 'id');

        return $id;
    }

    public function createCheckoutSession(string $customerId, string $successUrl, string $cancelUrl): string
    {
        $this->createdCheckoutSessions[] = compact('customerId', 'successUrl', 'cancelUrl');

        return 'https://checkout.stripe.test/session_fake';
    }

    public function createPortalSession(string $customerId, string $returnUrl): string
    {
        $this->createdPortalSessions[] = compact('customerId', 'returnUrl');

        return 'https://billing.stripe.test/portal_fake';
    }

    /**
     * The "payload" here is simply the JSON a test wants the app to see.
     * There is no real signature to verify — that behaviour is switched
     * with $rejectSignatures instead.
     */
    public function parseWebhook(string $payload, string $signatureHeader): StripeWebhookEvent
    {
        if ($this->rejectSignatures) {
            throw new InvalidWebhookSignatureException('Fake gateway: rejected by request.');
        }

        $decoded = json_decode($payload, true);

        if (!is_array($decoded) || !isset($decoded['id'], $decoded['type'])) {
            throw new InvalidWebhookSignatureException('Fake gateway: malformed payload.');
        }

        return new StripeWebhookEvent(
            id: $decoded['id'],
            type: $decoded['type'],
            data: $decoded['data']['object'] ?? [],
        );
    }
}
