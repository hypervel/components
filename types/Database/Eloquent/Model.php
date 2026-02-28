<?php

declare(strict_types=1);

namespace Hypervel\Types\Model;

use Hypervel\Database\Eloquent\Attributes\CollectedBy;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\HasCollection;
use Hypervel\Database\Eloquent\Model;
use User;

use function PHPStan\Testing\assertType;

function test(User $user, Post $post, Comment $comment, Article $article): void
{
    assertType('UserFactory', User::factory(function ($attributes, $model) {
        assertType('array<string, mixed>', $attributes);
        assertType('User|null', $model);

        return ['string' => 'string'];
    }));
    assertType('UserFactory', User::factory(42, function ($attributes, $model) {
        assertType('array<string, mixed>', $attributes);
        assertType('User|null', $model);

        return ['string' => 'string'];
    }));

    User::addGlobalScope('ancient', function ($builder) {
        assertType('Hypervel\Database\Eloquent\Builder<User>', $builder);

        $builder->where('created_at', '<', now()->subYears(2000));
    });

    assertType('Hypervel\Database\Eloquent\Builder<User>', User::query());
    assertType('Hypervel\Database\Eloquent\Builder<User>', $user->newQuery());
    assertType('Hypervel\Database\Eloquent\Builder<User>', $user->withTrashed());
    assertType('Hypervel\Database\Eloquent\Builder<User>', $user->onlyTrashed());
    assertType('Hypervel\Database\Eloquent\Builder<User>', $user->withoutTrashed());
    assertType('Hypervel\Database\Eloquent\Builder<User>', $user->prunable());
    assertType('Hypervel\Database\Eloquent\Relations\MorphMany', $user->notifications());
    assertType('Hypervel\Database\Query\Builder', $user->unreadNotifications());

    assertType('Hypervel\Database\Eloquent\Collection<(int|string), User>', $user->newCollection([new User()]));
    assertType('Hypervel\Types\Model\Posts<(int|string), Hypervel\Types\Model\Post>', $post->newCollection(['foo' => new Post()]));
    assertType('Hypervel\Types\Model\Articles<(int|string), Hypervel\Types\Model\Article>', $article->newCollection([new Article()]));
    assertType('Hypervel\Types\Model\Comments', $comment->newCollection([new Comment()]));

    assertType('bool', $user->restore());
    assertType('User', $user->restoreOrCreate());
    assertType('User', $user->createOrRestore());
}

class Post extends Model
{
    /** @use HasCollection<Posts<array-key, static>> */
    use HasCollection;

    protected static string $collectionClass = Posts::class;
}

/**
 * @template TKey of array-key
 * @template TModel of Post
 *
 * @extends Collection<TKey, TModel> */
class Posts extends Collection
{
}

final class Comment extends Model
{
    /** @use HasCollection<Comments> */
    use HasCollection;

    /** @param  array<array-key, Comment>  $models */
    public function newCollection(array $models = []): Comments
    {
        return new Comments($models);
    }
}

/** @extends Collection<array-key, Comment> */
final class Comments extends Collection
{
}

#[CollectedBy(Articles::class)]
class Article extends Model
{
    /** @use HasCollection<Articles<array-key, static>> */
    use HasCollection;
}

/**
 * @template TKey of array-key
 * @template TModel of Article
 *
 * @extends Collection<TKey, TModel> */
class Articles extends Collection
{
}
