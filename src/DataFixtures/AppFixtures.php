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
        UserFactory::new()->admin()->create([
            'email' => 'super@orbly.test',
            'displayName' => 'Super Admin',
        ]);

        UserFactory::createMany(20);
    }
}
