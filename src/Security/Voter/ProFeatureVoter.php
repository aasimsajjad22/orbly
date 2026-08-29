<?php

namespace App\Security\Voter;

use App\Entity\User;
use App\Repository\SubscriptionRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Answers "may this user use Pro features?"
 *
 * Unlike the other Voters, the subject is null — this is not about a
 * specific object, it is a capability check on the current user. Voters
 * handle both shapes.
 *
 * @extends Voter<string, null>
 */
final class ProFeatureVoter extends Voter
{
    public const ACCESS = 'PRO_FEATURE';

    public function __construct(
        private readonly SubscriptionRepository $subscriptions,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        // No subject check — this attribute is always about the user.
        return $attribute === self::ACCESS;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        // Admins get Pro features without paying. A single line here
        // rather than an "or is admin" repeated at every call site.
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        $subscription = $this->subscriptions->findOneByUser($user);

        // No subscription row at all — they never started checkout.
        if ($subscription === null) {
            return false;
        }

        // isPro() delegates to SubscriptionStatus::grantsProAccess(), so
        // the "does past_due still count" decision lives in exactly one
        // place: the enum.
        return $subscription->isPro();
    }
}
