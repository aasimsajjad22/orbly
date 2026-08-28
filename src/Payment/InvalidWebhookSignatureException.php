<?php

namespace App\Payment;

/**
 * The request did not come from Stripe — or was tampered with.
 *
 * Always answer these with a flat 400. Never leak why.
 */
final class InvalidWebhookSignatureException extends \RuntimeException
{
}
