<?php

namespace App\Security\Voter;

use App\Entity\Comment;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<string, Comment>
 */
final class CommentVoter extends Voter
{
    public const DELETE = 'COMMENT_DELETE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::DELETE && $subject instanceof Comment;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var Comment $comment */
        $comment = $subject;

        // TWO different people may delete a comment, for two different
        // reasons:
        //   - the comment's author, retracting what they said
        //   - the POST's author, moderating their own thread
        // That is why this needs a Voter rather than a simple ownership
        // check — the rule is not "is this yours".
        return $comment->getAuthor()->getId() === $user->getId()
            || $comment->getPost()->getAuthor()->getId() === $user->getId();
    }
}
