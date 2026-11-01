<?php

declare(strict_types=1);

namespace Hypervel\Types\Model;

use Hypervel\Contracts\Database\Query\Expression;
use Hypervel\Database\Eloquent\Attributes\CollectedBy;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\HasCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Notifications\DatabaseNotification;
use User;

use function PHPStan\Testing\assertType;

function test(User $user, Post $post, Comment $comment, Article $article, DatabaseNotification $notification, Expression $expression): void
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

    User::handleLazyLoadingViolationUsing(fn (Model $model, string $key) => 'handled');
    User::handleDiscardedAttributeViolationUsing(fn (Model $model, array $keys) => 'handled');
    User::handleMissingAttributeViolationUsing(fn (Model $model, string $key) => 'default');

    assertType('Hypervel\Database\Eloquent\Builder<User>', User::query());
    assertType('Hypervel\Database\Eloquent\Builder<User>', $user->newQuery());
    assertType('Hypervel\Database\Eloquent\Builder<User>', User::with(['posts' => function ($relation) {
        assertType('Hypervel\Database\Eloquent\Relations\Relation<*, *, *>', $relation);
    }]));
    assertType('User', $user->load(['posts' => function ($relation) {
        assertType('Hypervel\Database\Eloquent\Relations\Relation<*, *, *>', $relation);
    }]));
    assertType('User', $user->loadMissing(['posts' => function ($relation) {
        assertType('Hypervel\Database\Eloquent\Relations\Relation<*, *, *>', $relation);
    }]));
    assertType('User|null', $user->fresh(['posts' => function ($relation) {
        assertType('Hypervel\Database\Eloquent\Relations\Relation<*, *, *>', $relation);
    }]));
    assertType('User', $user->load(['posts' => ['comments' => fn ($relation) => $relation]]));
    assertType('User', $user->loadMorph('parentable', [Post::class => ['comments' => function ($relation) {
        assertType('Hypervel\Database\Eloquent\Relations\Relation<*, *, *>', $relation);
    }]]));
    assertType('User', $user->loadMorph('parentable', [Post::class => 'comments']));

    $constraint = new class {
        /**
         * Constrain eager loading through an invokable object.
         *
         * @param Relation<*, *, *> $relation
         */
        public function __invoke(Relation $relation): void
        {
            $relation->where('active', true);
        }
    };
    assertType('Hypervel\Database\Eloquent\Builder<User>', User::with(['posts' => $constraint]));
    assertType('Hypervel\Database\Eloquent\Builder<User>', $user->newQuery()->withOnly(['posts' => $constraint]));
    assertType('User', $user->load(['posts' => $constraint]));
    assertType('User', $user->loadMissing(['posts' => $constraint]));
    assertType('Hypervel\Database\Eloquent\Builder<User>', $user->withTrashed());
    assertType('Hypervel\Database\Eloquent\Builder<User>', $user->onlyTrashed());
    assertType('Hypervel\Database\Eloquent\Builder<User>', $user->withoutTrashed());
    assertType('Hypervel\Database\Eloquent\Builder<User>', $user->prunable());
    assertType('Hypervel\Database\Eloquent\Relations\MorphMany<Hypervel\Notifications\DatabaseNotification, User>', $user->notifications());
    assertType('Hypervel\Database\Eloquent\Relations\MorphMany<Hypervel\Notifications\DatabaseNotification, User>', $user->readNotifications());
    assertType('Hypervel\Database\Eloquent\Relations\MorphMany<Hypervel\Notifications\DatabaseNotification, User>', $user->unreadNotifications());
    assertType('Hypervel\Database\Eloquent\Relations\MorphTo<Hypervel\Database\Eloquent\Model, Hypervel\Notifications\DatabaseNotification>', $notification->notifiable());

    assertType('Hypervel\Database\Eloquent\Collection<(int|string), User>', $user->newCollection([new User]));
    assertType('Hypervel\Types\Model\Posts<(int|string), Hypervel\Types\Model\Post>', $post->newCollection(['foo' => new Post]));
    assertType('Hypervel\Types\Model\Articles<(int|string), Hypervel\Types\Model\Article>', $article->newCollection([new Article]));
    assertType('Hypervel\Types\Model\Comments', $comment->newCollection([new Comment]));

    assertType('User', $user->load(['posts' => fn ($query) => $query]));
    assertType('User', $user->loadMorph('parentable', ['post' => ['comments' => fn ($query) => $query]]));
    assertType('User', $user->loadCount(['posts' => fn (Builder $query) => $query->where('published', true)]));
    assertType('User', $user->loadAggregate('posts', $expression, 'sum'));
    assertType('User', $user->loadMax('posts', $expression));
    assertType('User', $user->loadMin('posts', $expression));
    assertType('User', $user->loadSum('posts', $expression));
    assertType('User', $user->loadAvg('posts', $expression));
    assertType('User', $user->loadMorphAggregate('parentable', [Post::class => ['comments']], $expression, 'sum'));
    assertType('User', $user->loadMorphMax('parentable', [Post::class => ['comments']], $expression));
    assertType('User', $user->loadMorphMin('parentable', [Post::class => ['comments']], $expression));
    assertType('User', $user->loadMorphSum('parentable', [Post::class => ['comments']], $expression));
    assertType('User', $user->loadMorphAvg('parentable', [Post::class => ['comments']], $expression));
    assertType('User', $user->loadCount(['posts' => function ($query) {
        assertType('Hypervel\Database\Eloquent\Builder<*>', $query);
    }]));
    assertType('User', $user->loadMorphCount('parentable', [Post::class => ['comments' => function ($query) {
        assertType('Hypervel\Database\Eloquent\Builder<*>', $query);
    }]]));
    $aggregate = static fn (Builder $query): Builder => $query->where('active', true);
    assertType('User', $user->loadAggregate(['posts' => $aggregate], $expression, 'sum'));
    assertType('User', $user->loadMax(['posts' => $aggregate], $expression));
    assertType('User', $user->loadMin(['posts' => $aggregate], $expression));
    assertType('User', $user->loadSum(['posts' => $aggregate], $expression));
    assertType('User', $user->loadAvg(['posts' => $aggregate], $expression));
    assertType('User', $user->loadExists(['posts' => $aggregate]));
    assertType('User', $user->loadMorphAggregate('parentable', [Post::class => ['comments' => $aggregate]], $expression, 'sum'));
    assertType('User', $user->loadMorphMax('parentable', [Post::class => ['comments' => $aggregate]], $expression));
    assertType('User', $user->loadMorphMin('parentable', [Post::class => ['comments' => $aggregate]], $expression));
    assertType('User', $user->loadMorphSum('parentable', [Post::class => ['comments' => $aggregate]], $expression));
    assertType('User', $user->loadMorphAvg('parentable', [Post::class => ['comments' => $aggregate]], $expression));
    assertType('User', $user->loadMorphCount('parentable', [Post::class => 'comments']));

    foreach (['post', 2] as $alias) {
        assertType('User', $user->loadMorph('parentable', [$alias => ['comments']]));
        assertType('User', $user->loadMorphAggregate('parentable', [$alias => ['comments' => []]], $expression, 'sum'));
        assertType('User', $user->loadMorphCount('parentable', [$alias => ['comments' => []]]));
        assertType('User', $user->loadMorphMax('parentable', [$alias => ['comments' => []]], $expression));
        assertType('User', $user->loadMorphMin('parentable', [$alias => ['comments' => []]], $expression));
        assertType('User', $user->loadMorphSum('parentable', [$alias => ['comments' => []]], $expression));
        assertType('User', $user->loadMorphAvg('parentable', [$alias => ['comments' => []]], $expression));
    }

    assertType('bool', $user->restore());
    assertType('User', $user->restoreOrCreate());
    assertType('User', $user->createOrRestore());

    assertType("'foo'", User::withoutEvents(fn () => 'foo'));
    assertType("'foo'", User::withoutBroadcasting(fn () => 'foo'));
    assertType("'foo'", User::withoutTimestampsOn([], fn () => 'foo'));
    assertType("'foo'", User::withoutTimestamps(fn () => 'foo'));
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
