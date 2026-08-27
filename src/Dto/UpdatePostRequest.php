<?php

namespace App\Dto;

use App\Enum\PostVisibility;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * PATCH semantics: every field optional, null means "leave unchanged".
 *
 * Distinct from CreatePostRequest, where content is required. Sharing one
 * DTO between POST and PATCH means one of the two gets the wrong rules.
 */
final class UpdatePostRequest
{
    #[Assert\Length(min: 1, max: 2000)]
    public readonly ?string $content;

    public readonly ?PostVisibility $visibility;

    public function __construct(
        ?string $content = null,
        ?PostVisibility $visibility = null,
    ) {
        $this->content = $content === null ? null : trim($content);
        $this->visibility = $visibility;
    }
}
