<?php

namespace App\DataFixtures;

use App\Factory\UserFactory;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class AppFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        // createOne() now returns a real User object, not a proxy.
        UserFactory::new()->verified()->create([
            'email' => 'x@orbly.test',
            'password' => 'secret1234',
        ]);

        // new() builds an unsaved factory instance so we can chain states,
        // then create() persists it. admin() is our state method.
        UserFactory::new()->verified()->admin()->create([
            'email' => 'super@orbly.test',
            'displayName' => 'Super Admin',
        ]);

        // A second known account, so you can test two-sided flows like friend
        // requests without hunting for a random seeded user's email.
        UserFactory::new()->verified()->create([
            'email' => 'sajjad@orbly.test',
            'displayName' => 'Sajjad',
        ]);

        // 20 random users, all verified so they're usable in manual testing.
        UserFactory::new()->verified()->many(20)->create();
    }
}
