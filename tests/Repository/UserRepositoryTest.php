<?php

namespace App\Tests\Repository;

use App\Factory\UserFactory;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;

class UserRepositoryTest extends KernelTestCase
{
    // Per-test isolation (Laravel's RefreshDatabase equivalent) is already
    // handled globally by DAMADoctrineTestBundle (see phpunit.dist.xml),
    // which wraps each test in a transaction and rolls it back. Don't add
    // Foundry's ResetDatabase trait on top — for PostgreSQL it force-drops
    // and recreates the database (via pg_terminate_backend) at suite start,
    // which kills DAMA's shared static connection and breaks every test
    // that runs afterward in the same process.

    // Factories = lets us call UserFactory inside the test.
    use Factories;

    private UserRepository $repository;

    protected function setUp(): void
    {
        // Boots the Symfony kernel and builds the service container.
        self::bootKernel();

        // Pull the repository out of the container instead of using "new".
        // getContainer() in tests exposes private services too.
        $this->repository = self::getContainer()->get(UserRepository::class);
    }

    public function testItFindsAUserByEmailIgnoringCase(): void
    {
        // Arrange — create one user in the test database.
        UserFactory::createOne(['email' => 'sajjad@orbly.test']);

        // Act — search with different casing and stray spaces.
        $found = $this->repository->findOneByEmail('  SAJJAD@Orbly.TEST ');

        // Assert
        $this->assertNotNull($found);
        $this->assertSame('sajjad@orbly.test', $found->getEmail());
    }

    public function testItReturnsNullWhenEmailIsUnknown(): void
    {
        $this->assertNull($this->repository->findOneByEmail('nobody@orbly.test'));
    }

    public function testEveryUserAlwaysHasRoleUser(): void
    {
        // A user created with no roles should still report ROLE_USER,
        // because User::getRoles() adds it at read time.
        $user = UserFactory::createOne();

        $this->assertContains('ROLE_USER', $user->getRoles());
    }

    public function testSearchMatchesPartialDisplayName(): void
    {
        UserFactory::createOne(['displayName' => 'Aasim Sajjad']);
        UserFactory::createOne(['displayName' => 'Someone Else']);

        $results = $this->repository->search('aasim');

        $this->assertCount(1, $results);
        $this->assertSame('Aasim Sajjad', $results[0]->getDisplayName());
    }
}
