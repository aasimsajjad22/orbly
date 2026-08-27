<?php

namespace App\Security\Voter;

use App\Entity\Post;
use App\Entity\User;
use App\Enum\PostVisibility;
use App\Repository\BlockRepository;
use App\Repository\FriendshipRepository;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Post>
 */
final class PostVoter extends Voter
{
    public const VIEW = 'POST_VIEW';
    public const EDIT = 'POST_EDIT';
    public const DELETE = 'POST_DELETE';

    public function __construct(
        private readonly FriendshipRepository $friendships,
        private readonly BlockRepository $blocks,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE], true)
            && $subject instanceof Post;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Post $post */
        $post = $subject;

        // A soft-deleted post is invisible to everyone, including its
        // author. Checked first so no later rule can accidentally grant it.
        if ($post->isDeleted()) {
            return false;
        }

        return match ($attribute) {
            self::VIEW => $this->canView($post, $user),
            // Only the author edits or deletes. No "admins can edit anyone's
            // posts" rule — if you want that, it goes here explicitly.
            self::EDIT, self::DELETE => $this->isAuthor($post, $user),
            default => false,
        };
    }

    private function canView(Post $post, User $user): bool
    {
        // Authors always see their own posts, whatever the visibility.
        if ($this->isAuthor($post, $user)) {
            return true;
        }

        // A block hides everything, in either direction, regardless of how
        // public the post is. Checked BEFORE visibility so a public post
        // from a blocked user stays hidden.
        if ($this->blocks->existsBetween($post->getAuthor(), $user)) {
            return false;
        }

        return match ($post->getVisibility()) {
            PostVisibility::Public => true,
            PostVisibility::Friends => $this->friendships->areFriends($user, $post->getAuthor()),
            // Only the author, and we already returned true for them above.
            PostVisibility::Private => false,
        };
    }

    private function isAuthor(Post $post, User $user): bool
    {
        return $post->getAuthor()->getId() === $user->getId();
    }
}
