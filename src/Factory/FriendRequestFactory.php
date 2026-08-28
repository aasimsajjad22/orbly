<?php

namespace App\Factory;

use App\Entity\FriendRequest;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * Builds FriendRequest objects for tests and fixtures.
 *
 * @extends PersistentObjectFactory<FriendRequest>
 */
final class FriendRequestFactory extends PersistentObjectFactory
{
    /**
     * Tells Foundry which entity this factory builds. Required — this is
     * how ::createOne() and ::new() know what to instantiate.
     */
    public static function class(): string
    {
        return FriendRequest::class;
    }

    /**
     * Default attribute values, used when a test does not supply them.
     */
    protected function defaults(): array
    {
        return [
            // Nested factories: if a test does not pass a sender or
            // recipient, Foundry creates verified users automatically.
            // ->verified() matters because unverified users cannot log in
            // and the notification handler skips unverified addresses.
            'sender' => UserFactory::new()->verified(),
            'recipient' => UserFactory::new()->verified(),
        ];
    }

    /**
     * FriendRequest has a REQUIRED constructor — new FriendRequest($sender,
     * $recipient) — and no setters for those two fields, by design.
     *
     * By default Foundry instantiates with no arguments and then calls
     * setters, which would fail here. instantiateWith() overrides that:
     * we map the attributes array onto the constructor ourselves.
     */
    protected function initialize(): static
    {
        return $this->instantiateWith(
            static fn (array $attributes): FriendRequest => new FriendRequest(
                $attributes['sender'],
                $attributes['recipient'],
            )
        );
    }

    /**
     * State: an accepted request.
     *
     * afterInstantiate() runs on the built object before it is saved, so
     * we can call the entity's own transition method rather than writing
     * the status field directly. That keeps respondedAt in step, exactly
     * as it would be in real use.
     */
    public function accepted(): static
    {
        return $this->afterInstantiate(
            static fn (FriendRequest $request) => $request->accept()
        );
    }

    public function declined(): static
    {
        return $this->afterInstantiate(
            static fn (FriendRequest $request) => $request->decline()
        );
    }

    public function cancelled(): static
    {
        return $this->afterInstantiate(
            static fn (FriendRequest $request) => $request->cancel()
        );
    }
}
