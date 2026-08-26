<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class ResendVerificationRequest
{
    #[Assert\NotBlank(message: 'Email is required.')]
    #[Assert\Email(message: 'This is not a valid email address.')]
    public readonly string $email;

    public function __construct(string $email = '')
    {
        // Normalise at the boundary, same rule as RegisterRequest. Also
        // means the rate limiter keys on a canonical address — otherwise
        // "A@x.com" and "a@x.com" would get separate buckets.
        $this->email = strtolower(trim($email));
    }
}
