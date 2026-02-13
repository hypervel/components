<?php

declare(strict_types=1);

namespace Hypervel\Types\Builder;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\HasBuilder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Database\Query\Builder as QueryBuilder;

use function PHPStan\Testing\assertType;

/** @param \Hypervel\Database\Eloquent\Builder<User> $query */
function test(
    Builder $query,
    User $user,
    Post $post,
    ChildPost $childPost,
    Comment $comment,
    QueryBuilder $queryBuilder
): void {
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->where('id', 1));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->orWhere('name', 'John'));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->whereNot('status', 'active'));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->with('relation'));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->with(['relation' => ['foo' => fn ($q) => $q]]));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->with(['relation' => function ($query) {
        // assertType('Hypervel\Database\Eloquent\Relations\Relation<*,*,*>', $query);
    }]));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->without('relation'));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->withOnly(['relation']));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->withOnly(['relation' => ['foo' => fn ($q) => $q]]));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->withOnly(['relation' => function ($query) {
        // assertType('Hypervel\Database\Eloquent\Relations\Relation<*,*,*>', $query);
    }]));
    assertType('array<int, Hypervel\Types\Builder\User>', $query->getModels());
    assertType('array<int, Hypervel\Types\Builder\User>', $query->eagerLoadRelations([]));
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Types\Builder\User>', $query->get());
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Types\Builder\User>', $query->hydrate([]));
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Types\Builder\User>', $query->fromQuery('foo', []));
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Types\Builder\User>', $query->findMany([1, 2, 3]));
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Types\Builder\User>', $query->findOrFail([1]));
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Types\Builder\User>', $query->findOrNew([1]));
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Types\Builder\User>', $query->find([1]));
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Types\Builder\User>', $query->findOr([1], callback: fn () => 42));
    assertType('Hypervel\Types\Builder\User', $query->findOrFail(1));
    assertType('Hypervel\Types\Builder\User|null', $query->find(1));
    assertType('42|Hypervel\Types\Builder\User', $query->findOr(1, fn () => 42));
    assertType('42|Hypervel\Types\Builder\User', $query->findOr(1, callback: fn () => 42));
    assertType('Hypervel\Types\Builder\User|null', $query->first());
    assertType('42|Hypervel\Types\Builder\User', $query->firstOr(fn () => 42));
    assertType('42|Hypervel\Types\Builder\User', $query->firstOr(callback: fn () => 42));
    assertType('Hypervel\Types\Builder\User', $query->firstOrNew(['id' => 1]));
    assertType('Hypervel\Types\Builder\User', $query->findOrNew(1));
    assertType('Hypervel\Types\Builder\User', $query->firstOrCreate(['id' => 1]));
    assertType('Hypervel\Types\Builder\User', $query->create(['name' => 'John']));
    assertType('Hypervel\Types\Builder\User', $query->forceCreate(['name' => 'John']));
    assertType('Hypervel\Types\Builder\User', $query->forceCreateQuietly(['name' => 'John']));
    assertType('Hypervel\Types\Builder\User', $query->getModel());
    assertType('Hypervel\Types\Builder\User', $query->make(['name' => 'John']));
    assertType('Hypervel\Types\Builder\User', $query->forceCreate(['name' => 'John']));
    assertType('Hypervel\Types\Builder\User', $query->updateOrCreate(['id' => 1], ['name' => 'John']));
    assertType('Hypervel\Types\Builder\User', $query->firstOrFail());
    assertType('Hypervel\Types\Builder\User', $query->findSole(1));
    assertType('Hypervel\Types\Builder\User', $query->sole());
    assertType('Hypervel\Support\LazyCollection<int, Hypervel\Types\Builder\User>', $query->cursor());
    assertType('Hypervel\Support\LazyCollection<int, Hypervel\Types\Builder\User>', $query->cursor());
    assertType('Hypervel\Support\LazyCollection<int, Hypervel\Types\Builder\User>', $query->lazy());
    assertType('Hypervel\Support\LazyCollection<int, Hypervel\Types\Builder\User>', $query->lazyById());
    assertType('Hypervel\Support\LazyCollection<int, Hypervel\Types\Builder\User>', $query->lazyByIdDesc());
    assertType('Hypervel\Support\Collection<(int|string), mixed>', $query->pluck('foo'));
    assertType('Hypervel\Database\Eloquent\Relations\Relation<Hypervel\Database\Eloquent\Model, Hypervel\Types\Builder\User, *>', $query->getRelation('foo'));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\Post>', $query->setModel(new Post()));

    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->has('foo', callback: function ($query) {
        assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $query);
    }));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->has($user->posts(), callback: function ($query) {
        assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\Post>', $query);
    }));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->orHas($user->posts()));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->doesntHave($user->posts(), callback: function ($query) {
        assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\Post>', $query);
    }));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->orDoesntHave($user->posts()));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->whereHas($user->posts(), function ($query) {
        assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\Post>', $query);
    }));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->withWhereHas('posts', function ($query) {
        assertType('Hypervel\Database\Eloquent\Builder<*>|Hypervel\Database\Eloquent\Relations\Relation<*, *, *>', $query);
    }));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->orWhereHas($user->posts(), function ($query) {
        assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\Post>', $query);
    }));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->whereDoesntHave($user->posts(), function ($query) {
        assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\Post>', $query);
    }));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->orWhereDoesntHave($user->posts(), function ($query) {
        assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\Post>', $query);
    }));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->hasMorph($post->taggable(), 'taggable', callback: function ($query, $type) {
        assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $query);
        assertType('string', $type);
    }));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->orHasMorph($post->taggable(), 'taggable'));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->doesntHaveMorph($post->taggable(), 'taggable', callback: function ($query, $type) {
        assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $query);
        assertType('string', $type);
    }));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->orDoesntHaveMorph($post->taggable(), 'taggable'));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->whereHasMorph($post->taggable(), 'taggable', function ($query, $type) {
        assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $query);
        assertType('string', $type);
    }));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->orWhereHasMorph($post->taggable(), 'taggable', function ($query, $type) {
        assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $query);
        assertType('string', $type);
    }));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->whereDoesntHaveMorph($post->taggable(), 'taggable', function ($query, $type) {
        assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $query);
        assertType('string', $type);
    }));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->orWhereDoesntHaveMorph($post->taggable(), 'taggable', function ($query, $type) {
        assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $query);
        assertType('string', $type);
    }));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->whereRelation($user->posts(), function ($query) {
        assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\Post>', $query);
    }));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->orWhereRelation($user->posts(), function ($query) {
        assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\Post>', $query);
    }));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->whereDoesntHaveRelation($user->posts(), function ($query) {
        assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\Post>', $query);
    }));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->orWhereDoesntHaveRelation($user->posts(), function ($query) {
        assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\Post>', $query);
    }));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->whereMorphRelation($post->taggable(), 'taggable', function ($query) {
        assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $query);
    }));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->orWhereMorphRelation($post->taggable(), 'taggable', function ($query) {
        assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $query);
    }));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->whereMorphDoesntHaveRelation($post->taggable(), 'taggable', function ($query) {
        assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $query);
    }));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->orWhereMorphDoesntHaveRelation($post->taggable(), 'taggable', function ($query) {
        assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $query);
    }));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->whereMorphedTo($post->taggable(), new Post()));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->whereNotMorphedTo($post->taggable(), new Post()));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->orWhereMorphedTo($post->taggable(), new Post()));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->orWhereNotMorphedTo($post->taggable(), new Post()));

    $query->chunk(1, function ($users, $page) {
        assertType('Hypervel\Support\Collection<int, Hypervel\Types\Builder\User>', $users);
        assertType('int', $page);
    });
    $query->chunkById(1, function ($users, $page) {
        assertType('Hypervel\Support\Collection<int, Hypervel\Types\Builder\User>', $users);
        assertType('int', $page);
    });
    $query->chunkMap(function ($users) {
        assertType('Hypervel\Types\Builder\User', $users);
    });
    $query->chunkByIdDesc(1, function ($users, $page) {
        assertType('Hypervel\Support\Collection<int, Hypervel\Types\Builder\User>', $users);
        assertType('int', $page);
    });
    $query->each(function ($users, $page) {
        assertType('Hypervel\Types\Builder\User', $users);
        assertType('int', $page);
    });
    $query->eachById(function ($users, $page) {
        assertType('Hypervel\Types\Builder\User', $users);
        assertType('int', $page);
    });

    assertType('Hypervel\Types\Builder\CommonBuilder<Hypervel\Types\Builder\Post>', Post::query());
    assertType('Hypervel\Types\Builder\CommonBuilder<Hypervel\Types\Builder\Post>', Post::on());
    assertType('Hypervel\Types\Builder\CommonBuilder<Hypervel\Types\Builder\Post>', Post::onWriteConnection());
    assertType('Hypervel\Types\Builder\CommonBuilder<Hypervel\Types\Builder\Post>', Post::with([]));
    assertType('Hypervel\Types\Builder\CommonBuilder<Hypervel\Types\Builder\Post>', $post->newQuery());
    assertType('Hypervel\Types\Builder\CommonBuilder<Hypervel\Types\Builder\Post>', $post->newEloquentBuilder($queryBuilder));
    assertType('Hypervel\Types\Builder\CommonBuilder<Hypervel\Types\Builder\Post>', $post->newModelQuery());
    assertType('Hypervel\Types\Builder\CommonBuilder<Hypervel\Types\Builder\Post>', $post->newQueryWithoutRelationships());
    assertType('Hypervel\Types\Builder\CommonBuilder<Hypervel\Types\Builder\Post>', $post->newQueryWithoutScopes());
    assertType('Hypervel\Types\Builder\CommonBuilder<Hypervel\Types\Builder\Post>', $post->newQueryWithoutScope('foo'));
    assertType('Hypervel\Types\Builder\CommonBuilder<Hypervel\Types\Builder\Post>', $post->newQueryForRestoration(1));
    assertType('Hypervel\Types\Builder\CommonBuilder<Hypervel\Types\Builder\Post>', $post->newQuery()->where('foo', 'bar'));
    assertType('Hypervel\Types\Builder\CommonBuilder<Hypervel\Types\Builder\Post>', $post->newQuery()->foo());
    assertType('Hypervel\Types\Builder\Post', $post->newQuery()->create(['name' => 'John']));

    assertType('Hypervel\Types\Builder\CommonBuilder<Hypervel\Types\Builder\ChildPost>', ChildPost::query());
    assertType('Hypervel\Types\Builder\CommonBuilder<Hypervel\Types\Builder\ChildPost>', ChildPost::on());
    assertType('Hypervel\Types\Builder\CommonBuilder<Hypervel\Types\Builder\ChildPost>', ChildPost::onWriteConnection());
    assertType('Hypervel\Types\Builder\CommonBuilder<Hypervel\Types\Builder\ChildPost>', ChildPost::with([]));
    assertType('Hypervel\Types\Builder\CommonBuilder<Hypervel\Types\Builder\ChildPost>', $childPost->newQuery());
    assertType('Hypervel\Types\Builder\CommonBuilder<Hypervel\Types\Builder\ChildPost>', $childPost->newEloquentBuilder($queryBuilder));
    assertType('Hypervel\Types\Builder\CommonBuilder<Hypervel\Types\Builder\ChildPost>', $childPost->newModelQuery());
    assertType('Hypervel\Types\Builder\CommonBuilder<Hypervel\Types\Builder\ChildPost>', $childPost->newQueryWithoutRelationships());
    assertType('Hypervel\Types\Builder\CommonBuilder<Hypervel\Types\Builder\ChildPost>', $childPost->newQueryWithoutScopes());
    assertType('Hypervel\Types\Builder\CommonBuilder<Hypervel\Types\Builder\ChildPost>', $childPost->newQueryWithoutScope('foo'));
    assertType('Hypervel\Types\Builder\CommonBuilder<Hypervel\Types\Builder\ChildPost>', $childPost->newQueryForRestoration(1));
    assertType('Hypervel\Types\Builder\CommonBuilder<Hypervel\Types\Builder\ChildPost>', $childPost->newQuery()->where('foo', 'bar'));
    assertType('Hypervel\Types\Builder\CommonBuilder<Hypervel\Types\Builder\ChildPost>', $childPost->newQuery()->foo());
    assertType('Hypervel\Types\Builder\ChildPost', $childPost->newQuery()->create(['name' => 'John']));

    assertType('Hypervel\Types\Builder\CommentBuilder', Comment::query());
    assertType('Hypervel\Types\Builder\CommentBuilder', Comment::on());
    assertType('Hypervel\Types\Builder\CommentBuilder', Comment::onWriteConnection());
    assertType('Hypervel\Types\Builder\CommentBuilder', Comment::with([]));
    assertType('Hypervel\Types\Builder\CommentBuilder', $comment->newQuery());
    assertType('Hypervel\Types\Builder\CommentBuilder', $comment->newEloquentBuilder($queryBuilder));
    assertType('Hypervel\Types\Builder\CommentBuilder', $comment->newModelQuery());
    assertType('Hypervel\Types\Builder\CommentBuilder', $comment->newQueryWithoutRelationships());
    assertType('Hypervel\Types\Builder\CommentBuilder', $comment->newQueryWithoutScopes());
    assertType('Hypervel\Types\Builder\CommentBuilder', $comment->newQueryWithoutScope('foo'));
    assertType('Hypervel\Types\Builder\CommentBuilder', $comment->newQueryForRestoration(1));
    assertType('Hypervel\Types\Builder\CommentBuilder', $comment->newQuery()->where('foo', 'bar'));
    assertType('Hypervel\Types\Builder\CommentBuilder', $comment->newQuery()->foo());
    assertType('Hypervel\Types\Builder\Comment', $comment->newQuery()->create(['name' => 'John']));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->pipe(function () {
    }));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->pipe(fn () => null));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\Builder\User>', $query->pipe(fn ($query) => $query));
    assertType('5', $query->pipe(fn ($query) => 5));
}

class User extends Model
{
    /** @return HasMany<Post, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}

class Post extends Model
{
    /** @use HasBuilder<CommonBuilder<static>> */
    use HasBuilder;

    protected static string $builder = CommonBuilder::class;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<\Hypervel\Database\Eloquent\Model, $this> */
    public function taggable(): MorphTo
    {
        return $this->morphTo();
    }
}

class ChildPost extends Post
{
}

class Comment extends Model
{
    /** @use HasBuilder<CommentBuilder> */
    use HasBuilder;

    protected static string $builder = CommentBuilder::class;
}

/**
 * @template TModel of \Hypervel\Database\Eloquent\Model
 *
 * @extends \Hypervel\Database\Eloquent\Builder<TModel>
 */
class CommonBuilder extends Builder
{
    public function foo(): static
    {
        return $this->where('foo', 'bar');
    }
}

/** @extends CommonBuilder<Comment> */
class CommentBuilder extends CommonBuilder
{
}
