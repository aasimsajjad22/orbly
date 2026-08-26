<?php

namespace App\Security\Google;

/**
 * Google gave us a real token, but for an address it has not confirmed.
 * Separate from InvalidGoogleTokenException because the cause is different
 * and the user-facing message should be too — this one is actionable.
 */
final class UnverifiedGoogleEmailException extends \RuntimeException
{
}
