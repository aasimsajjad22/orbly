<?php

namespace App\Service;

/**
 * Named constructors instead of `new FriendRequestException('...')` at call
 * sites: the reasons are enumerated in one place, and the controller can
 * map each to a status code without string-matching the message.
 */
final class FriendRequestException extends \RuntimeException
{
    public static function cannotFriendYourself(): self
    {
        return new self('You cannot send a friend request to yourself.');
    }

    public static function alreadyFriends(): self
    {
        return new self('You are already friends with this user.');
    }

    public static function blocked(): self
    {
        // Deliberately vague. Saying "they blocked you" tells the sender
        // something the recipient probably did not want them to know.
        return new self('You cannot send a friend request to this user.');
    }
}
