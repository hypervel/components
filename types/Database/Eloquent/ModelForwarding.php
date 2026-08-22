<?php

declare(strict_types=1);

namespace Hypervel\Types\ModelForwarding;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\HasBuilder;
use Hypervel\Database\Eloquent\Model;

use function PHPStan\Testing\assertType;

function test(User $user, Post $post): void
{
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\ModelForwarding\User>', User::where('active', true));
    assertType('Hypervel\Types\ModelForwarding\User|null', User::first());
    assertType('int<0, max>', User::count());
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\ModelForwarding\User>', $user->where('active', true));

    assertType('Hypervel\Types\ModelForwarding\PostBuilder<Hypervel\Types\ModelForwarding\Post>', Post::where('active', true));
    assertType('Hypervel\Types\ModelForwarding\PostBuilder<Hypervel\Types\ModelForwarding\Post>', Post::published());
    assertType('Hypervel\Types\ModelForwarding\Post|null', Post::first());
    assertType('Hypervel\Types\ModelForwarding\PostBuilder<Hypervel\Types\ModelForwarding\Post>', $post->published());

    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\ModelForwarding\Admin>', Admin::where('active', true));
    assertType('Hypervel\Types\ModelForwarding\Admin|null', Admin::first());
    assertType('Hypervel\Types\ModelForwarding\PostBuilder<Hypervel\Types\ModelForwarding\EditorPost>', EditorPost::where('active', true));
}

class User extends Model
{
}

class Admin extends User
{
}

class Post extends Model
{
    /** @use HasBuilder<PostBuilder<static>> */
    use HasBuilder;

    protected static string $builder = PostBuilder::class;
}

class EditorPost extends Post
{
}

/**
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class PostBuilder extends Builder
{
    public function published(): static
    {
        return $this->whereNotNull('published_at');
    }
}
