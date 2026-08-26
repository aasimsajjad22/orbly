<?php

namespace App\Security\Google;

/**
 * One exception for every verification failure.
 *
 * Deliberately does NOT expose which check failed to the API client.
 * Telling an attacker "wrong audience" vs "bad signature" helps them
 * probe your setup. We log the detail; the client gets a flat 401.
 */
final class InvalidGoogleTokenException extends \RuntimeException
{
}
