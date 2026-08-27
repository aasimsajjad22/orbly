<?php

namespace App\Enum;

enum PostVisibility: string
{
    case Public = 'public';
    case Friends = 'friends';
    case Private = 'private';

    /**
     * Could someone other than the author ever see this?
     *
     * Used by the feed query as a quick filter before the per-post Voter
     * runs. Behaviour lives on the enum so the rule is in one place.
     */
    public function isVisibleToOthers(): bool
    {
        return $this !== self::Private;
    }
}
