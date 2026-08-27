<?php

namespace App\Dto;

use App\Enum\PostVisibility;
use Symfony\Component\Validator\Constraints as Assert;

final class CreatePostRequest
{
    #[Assert\NotBlank(message: 'A post cannot be empty.')]
    #[Assert\Length(min: 1, max: 2000)]
    public readonly string $content;

    #[Assert\NotNull]
    public readonly PostVisibility $visibility;

    public function __construct(
        string $content = '',
        // The serializer converts the incoming string ("public") into the
        // enum automatically, because the parameter is typed as the enum.
        // An unrecognised value becomes a 422 rather than a 500.
        ?PostVisibility $visibility = null,
    ) {
        $this->content = trim($content);
        $this->visibility = $visibility ?? PostVisibility::Public;
    }
}
