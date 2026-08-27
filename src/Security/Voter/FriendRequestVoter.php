<?php

namespace App\Security\Voter;

use App\Entity\FriendRequest;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class FriendRequestVoter extends Voter
{
    public const RESPOND = 'FRIEND_REQUEST_RESPOND';
    public const CANCEL = 'FRIEND_REQUEST_CANCEL';
    public const VIEW = 'FRIEND_REQUEST_VIEW';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::RESPOND, self::CANCEL, self::VIEW], true)
            && $subject instanceof FriendRequest;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        // Anonymous requests can never act on a friend request. In practice
        // access_control already blocks these, but a voter must not assume
        // it is only reachable through one path.
        if (!$user instanceof User) {
            return false;
        }

        /** @var FriendRequest $subject */
        return match ($attribute) {
            // Only the RECIPIENT may accept or decline. The sender asking to
            // accept their own request would be self-approval.
            self::RESPOND => $this->isRecipient($subject, $user),

            // Only the SENDER may cancel. Recipients decline; senders cancel.
            // Two different actions, two different people, two different
            // resulting statuses.
            self::CANCEL => $this->isSender($subject, $user),

            // Either party may view it.
            self::VIEW => $this->isRecipient($subject, $user) || $this->isSender($subject, $user),

            // Unreachable — supports() already filtered the attribute — but
            // match must be exhaustive.
            default => false,
        };
    }

    private function isRecipient(FriendRequest $request, User $user): bool
    {
        // Compare IDs, not object identity. Two Doctrine instances of the
        // same row are usually identical, but that is not guaranteed across
        // different EntityManager states, and === on objects would then
        // silently deny a legitimate action.
        return $request->getRecipient()->getId() === $user->getId();
    }

    private function isSender(FriendRequest $request, User $user): bool
    {
        return $request->getSender()->getId() === $user->getId();
    }
}
