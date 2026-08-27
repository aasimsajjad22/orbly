<?php

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final class SendFriendRequestRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'recipientId is required.')]
        #[Assert\Positive(message: 'recipientId must be a positive integer.')]
        public readonly ?int $recipientId = null,
    ) {
    }
}
