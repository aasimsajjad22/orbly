<?php

namespace App\Factory;

use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;  // was PersistentProxyObjectFactory

/**
 * @extends PersistentObjectFactory<User>   // update the PHPDoc too, or PHPStan will complain
 */
final class UserFactory extends PersistentObjectFactory
{
    public function __construct(
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    public static function class(): string
    {
        return User::class;
    }

    protected function defaults(): array
    {
        return [
            'email' => self::faker()->unique()->safeEmail(),
            'displayName' => self::faker()->name(),
            'bio' => self::faker()->optional(0.6)->sentence(12),
            'password' => 'password',   // plain here; hashed in initialize() below
            // Default to UNVERIFIED, matching a real registration. Tests that
            // need to log in must now say ->verified() explicitly, which
            // makes the requirement visible in the test rather than implied.
            'emailVerified' => false,
        ];
    }

    protected function initialize(): static
    {
        return $this->afterInstantiate(function (User $user): void {
            // Only hash when a password was actually given — Google-only
            // users have null, and hashPassword(null) would throw.
            if ($user->getPassword() !== null) {
                $user->setPassword(
                    $this->hasher->hashPassword($user, $user->getPassword())
                );
            }
        });
    }

    /**
     * State: an account created through Google sign-in.
     *
     * A "state method" returns a modified copy of the factory (factories are
     * immutable), so it chains: UserFactory::new()->google()->create().
     * Laravel's factory states work the same way.
     */
    public function google(?string $googleId = null): static
    {
        return $this->with([
            'password' => null,          // no password at all
            'googleId' => $googleId ?? 'google-'.self::faker()->unique()->randomNumber(8),
            'emailVerified' => true,     // Google already proved the address
        ]);
    }

    /**
     * State: a local account that has confirmed its email.
     * Barely used now; essential in Phase 2c when unverified users
     * cannot log in at all.
     */
    public function verified(): static
    {
        return $this->with(['emailVerified' => true]);
    }

    public function admin(): static
    {
        return $this->with(['roles' => ['ROLE_ADMIN']]);
    }
}
