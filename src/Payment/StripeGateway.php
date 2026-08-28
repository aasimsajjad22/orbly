<?php

namespace App\Payment;

/**
 * Everything this app asks of Stripe.
 *
 * The controllers depend on THIS, never on \Stripe\StripeClient. That is
 * what lets us swap in a fake for tests — the same trick as
 * GoogleIdTokenVerifier in Phase 2b.
 *
 * Deliberately narrow: four methods, not "all of Stripe". A small
 * interface is easy to fake and makes the app's actual dependency on
 * Stripe explicit.
 */
interface StripeGateway
{
    /**
     * Create (or reuse) a Stripe customer for this user.
     *
     * @return string the customer id, "cus_..."
     */
    public function createCustomer(string $email, string $displayName, int $userId): string;

    /**
     * Start a hosted checkout for the Pro subscription.
     *
     * @return string the URL to redirect the user to
     */
    public function createCheckoutSession(string $customerId, string $successUrl, string $cancelUrl): string;

    /**
     * A link to Stripe's hosted billing portal, where the user can update
     * their card or cancel — without us building any of those screens.
     *
     * @return string the portal URL
     */
    public function createPortalSession(string $customerId, string $returnUrl): string;

    /**
     * Verify a webhook's signature and return the parsed event.
     *
     * @throws InvalidWebhookSignatureException if it does not verify
     */
    public function parseWebhook(string $payload, string $signatureHeader): StripeWebhookEvent;
}
