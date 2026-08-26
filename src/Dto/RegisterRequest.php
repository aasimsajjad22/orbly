<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class RegisterRequest
{
    public function __construct(
        // "readonly promoted properties" — PHP 8 shorthand that declares the
        // property AND assigns it from the constructor argument in one line.
        // readonly means nothing can change it after construction.

        #[Assert\NotBlank(message: 'Email is required.')]
        #[Assert\Email(message: 'This is not a valid email address.')]
        #[Assert\Length(max: 180)]
        public readonly string $email = '',

        #[Assert\NotBlank(message: 'Password is required.')]
                               // Minimum length is the one password rule worth enforcing. Complexity
            // rules (a symbol, a number) push people toward weaker, guessable
            // passwords in practice.
        #[Assert\Length(min: 8, max: 4096, minMessage: 'Password must be at least {{ limit }} characters.')]
        public readonly string $password = '',

        #[Assert\NotBlank(message: 'Display name is required.')]
        #[Assert\Length(min: 2, max: 50)]
        public readonly string $displayName = '',

        #[Assert\Length(max: 500)]
        public readonly ?string $bio = null,
    ) {
    }
}
