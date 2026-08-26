<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class GoogleSignInRequest
{
    public function __construct(
        // Only shape validation here — "is it present, is it plausible".
        // Whether the token is REAL is the verifier's job, not the
        // validator's. Keep those two concerns apart.
        #[Assert\NotBlank(message: 'idToken is required.')]
        public readonly string $idToken = '',
    ) {
    }
}
