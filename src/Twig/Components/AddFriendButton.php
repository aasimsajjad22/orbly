<?php

namespace App\Twig\Components;

use App\Entity\User;
use App\Repository\FriendRequestRepository;
use App\Repository\FriendshipRepository;
use App\Service\FriendRequestException;
use App\Service\FriendRequestService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class AddFriendButton
{
    use DefaultActionTrait;

    /** The person we might befriend. Not writable. */
    #[LiveProp]
    public User $target;

    public ?string $error = null;

    public function __construct(
        private readonly FriendRequestService $requestService,
        private readonly FriendRequestRepository $requests,
        private readonly FriendshipRepository $friendships,
        private readonly Security $security,
    ) {
    }

    #[LiveAction]
    public function send(): void
    {
        /** @var User $me */
        $me = $this->security->getUser();

        try {
            // Same service as the API — the self-request rule, the
            // duplicate rule, the block rule, and the auto-accept
            // behaviour all come along for free.
            $this->requestService->send($me, $this->target);
        } catch (FriendRequestException $e) {
            $this->error = $e->getMessage();
        }
    }

    /**
     * Computed on each render rather than stored, so the button always
     * reflects the real state — including when the other person accepted
     * while this page was open.
     */
    public function getState(): string
    {
        /** @var User $me */
        $me = $this->security->getUser();

        if ($this->friendships->areFriends($me, $this->target)) {
            return 'friends';
        }

        if ($this->requests->findPendingBetween($me, $this->target) !== null) {
            return 'sent';
        }

        if ($this->requests->findPendingBetween($this->target, $me) !== null) {
            return 'incoming';
        }

        return 'none';
    }
}
