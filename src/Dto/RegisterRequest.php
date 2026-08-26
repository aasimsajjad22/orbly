<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class RegisterRequest
{
    // Properties are declared normally here, NOT promoted, because we need
    // to clean the values in the constructor body before assigning them.
    // Constraints are read from these declarations, and the validator runs
    // AFTER construction — so it sees the cleaned values.

    #[Assert\NotBlank(message: 'Email is required.')]
    #[Assert\Email(message: 'This is not a valid email address.')]
    #[Assert\Length(max: 180)]
    public readonly string $email;

    #[Assert\NotBlank(message: 'Password is required.')]
    #[Assert\Length(min: 8, max: 4096, minMessage: 'Password must be at least {{ limit }} characters.')]
    public readonly string $password;

    #[Assert\NotBlank(message: 'Display name is required.')]
    #[Assert\Length(min: 2, max: 50)]
    public readonly string $displayName;

    #[Assert\Length(max: 500)]
    public readonly ?string $bio;

    public function __construct(
        string $email = '',
        string $password = '',
        string $displayName = '',
        ?string $bio = null,
    ) {
        // Clean at the boundary. The Serializer calls this constructor when
        // it builds the object from JSON, so every value is normalised before
        // a single constraint runs.
        $this->email = strtolower(trim($email));
        $this->displayName = trim($displayName);

        // Password is deliberately NOT trimmed — spaces are valid password
        // characters, and stripping them would lock out anyone who typed one.
        $this->password = $password;

        // Trim the bio, but keep an empty string as null rather than "".
        $this->bio = null === $bio ? null : (trim($bio) ?: null);
    }
}
