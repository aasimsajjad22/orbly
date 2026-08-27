<?php

namespace App\DataFixtures;

use App\Entity\User;
use App\Factory\PostFactory;
use App\Factory\UserFactory;
use App\Service\FriendshipService;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture
{
    // Fixtures are services, so we can inject the friendship service and
    // build friendships through the same code path the app uses — rather
    // than hand-writing both mirror rows and risking them drifting apart.
    public function __construct(
        private readonly FriendshipService $friendships,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        // --- Known accounts, for manual testing ---
        // ->verified() is required now: the UserChecker blocks unverified
        // users at login, so a seeded account without it cannot sign in.
        $sajjad = UserFactory::new()->verified()->create([
            'email' => 'sajjad@orbly.test',
            'displayName' => 'Sajjad',
            'bio' => 'Laravel developer learning Symfony.',
        ]);

        $alice = UserFactory::new()->verified()->create([
            'email' => 'alice@orbly.test',
            'displayName' => 'Alice',
        ]);

        $bob = UserFactory::new()->verified()->create([
            'email' => 'bob@orbly.test',
            'displayName' => 'Bob',
        ]);

        // Not a friend of Sajjad — used to check that strangers' posts
        // stay out of the feed.
        $stranger = UserFactory::new()->verified()->create([
            'email' => 'stranger@orbly.test',
            'displayName' => 'Stranger',
        ]);

        UserFactory::new()->verified()->admin()->create([
            'email' => 'admin@orbly.test',
            'displayName' => 'Admin',
        ]);

        // --- Friendships ---
        // Sajjad is friends with Alice and Bob, but NOT the stranger.
        $this->friendships->create($sajjad, $alice);
        $this->friendships->create($sajjad, $bob);

        // --- Posts ---
        // Enough from friends to page through with limit=5.
        PostFactory::createMany(12, ['author' => $alice]);
        PostFactory::createMany(12, ['author' => $bob]);

        // Friends-only: visible to Sajjad because they are friends.
        PostFactory::new()->friendsOnly()->many(3)->create(['author' => $alice]);

        // Private: must NOT appear in Sajjad's feed, even though they
        // are friends.
        PostFactory::new()->private()->many(2)->create(['author' => $alice]);

        // The stranger's posts are public but must NOT appear — Sajjad
        // is not their friend.
        PostFactory::createMany(5, ['author' => $stranger]);

        // Sajjad's own posts. Note the feed shows FRIENDS' posts only,
        // so these should not appear in his own feed either.
        PostFactory::createMany(4, ['author' => $sajjad]);

        // Soft-deleted: must never appear anywhere.
        PostFactory::new()->deleted()->many(2)->create(['author' => $alice]);

        // --- 20 random users, for search and general testing ---
        UserFactory::new()->verified()->many(20)->create();
    }
}
