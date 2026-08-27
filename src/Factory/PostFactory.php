<?php

namespace App\Factory;

use App\Entity\Post;
use App\Enum\PostVisibility;
use Zenstruck\Foundry\Persistence\PersistentObjectFactory;

/**
 * @extends PersistentObjectFactory<Post>
 */
final class PostFactory extends PersistentObjectFactory
{
    public static function class(): string
    {
        return Post::class;
    }

    protected function defaults(): array
    {
        return [
            'author' => UserFactory::new()->verified(),
            'content' => self::faker()->paragraph(),
            'visibility' => PostVisibility::Public,
        ];
    }

    protected function initialize(): static
    {
        // Post has a required constructor, so map the attributes onto it
        // rather than letting Foundry try setters.
        return $this->instantiateWith(
            static fn (array $attributes): Post => new Post(
                $attributes['author'],
                $attributes['content'],
                $attributes['visibility'],
            )
        );
    }

    public function friendsOnly(): static
    {
        return $this->with(['visibility' => PostVisibility::Friends]);
    }

    public function private(): static
    {
        return $this->with(['visibility' => PostVisibility::Private]);
    }

    public function deleted(): static
    {
        return $this->afterInstantiate(
            static fn (Post $p) => $p->softDelete()
        );
    }
}
