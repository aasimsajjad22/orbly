<?php

namespace App\Payment;

use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;

final class StripeApiGateway implements StripeGateway
{
    private StripeClient $stripe;

    public function __construct(
        string $stripeSecretKey,
        private readonly string $stripeWebhookSecret,
        private readonly string $stripeProPriceId,
        string $stripeApiVersion,
    ) {
        $this->stripe = new StripeClient([
            'api_key' => $stripeSecretKey,
            // Pinning the version means Stripe keeps serving us the shape
            // we coded against, even after they ship a new default. This
            // single line is what prevents the current_period_end class
            // of breakage.
            'stripe_version' => $stripeApiVersion,
        ]);
    }

    public function createCustomer(string $email, string $displayName, int $userId): string
    {
        $customer = $this->stripe->customers->create([
            'email' => $email,
            'name' => $displayName,
            // metadata is arbitrary key/value storage on the Stripe object.
            // Putting our user id here means anyone looking at the Stripe
            // dashboard can trace a customer back to our database — worth
            // doing for every object you create.
            'metadata' => ['orbly_user_id' => (string) $userId],
        ]);

        return $customer->id;
    }

    public function createCheckoutSession(string $customerId, string $successUrl, string $cancelUrl): string
    {
        $session = $this->stripe->checkout->sessions->create([
            'customer' => $customerId,
            // 'subscription' for recurring, 'payment' for one-off.
            'mode' => 'subscription',
            'line_items' => [[
                'price' => $this->stripeProPriceId,
                'quantity' => 1,
            ]],
            // Where Stripe sends the browser afterwards. {CHECKOUT_SESSION_ID}
            // is a literal placeholder Stripe substitutes.
            'success_url' => $successUrl.'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
        ]);

        return $session->url;
    }

    public function createPortalSession(string $customerId, string $returnUrl): string
    {
        $session = $this->stripe->billingPortal->sessions->create([
            'customer' => $customerId,
            'return_url' => $returnUrl,
        ]);

        return $session->url;
    }

    public function parseWebhook(string $payload, string $signatureHeader): StripeWebhookEvent
    {
        try {
            // THE security boundary of this whole phase.
            //
            // constructEvent recomputes an HMAC of the raw body using the
            // webhook secret and compares it to the header. It also checks
            // the timestamp in the signature, which blocks replaying an
            // old captured request.
            //
            // The payload must be the RAW body — parsing and re-encoding
            // the JSON changes the bytes and the signature will not match.
            $event = Webhook::constructEvent(
                $payload,
                $signatureHeader,
                $this->stripeWebhookSecret,
            );
        } catch (SignatureVerificationException | \UnexpectedValueException $e) {
            throw new InvalidWebhookSignatureException('Invalid webhook signature.', 0, $e);
        }

        return new StripeWebhookEvent(
            id: $event->id,
            type: $event->type,
            // toArray() gives us plain arrays rather than Stripe's objects,
            // so nothing downstream depends on the SDK's classes.
            data: $event->data->object->toArray(),
        );
    }
}
