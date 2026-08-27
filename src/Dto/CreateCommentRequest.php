<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class CreateCommentRequest
{
    #[Assert\NotBlank(message: 'A comment cannot be empty.')]
    #[Assert\Length(min: 1, max: 1000)]
    public readonly string $content;

    public function __construct(string $content = '')
    {
        // Normalise at the boundary, same rule as everywhere else.
        $this->content = trim($content);
    }
}
