<?php

declare(strict_types=1);

namespace Hypervel\Types\NamedScopes;

use Hypervel\Contracts\Database\Eloquent\Builder as BuilderContract;
use Hypervel\Database\Eloquent\Attributes\Scope;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\HasBuilder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Notifications\DatabaseNotification;
use LogicException;

use function PHPStan\Testing\assertType;

function test(User $user, Post $post, DatabaseNotification $notification): void
{
    assertType('Hypervel\Types\NamedScopes\PostBuilder<Hypervel\Types\NamedScopes\Post>', Post::published());
    assertType('Hypervel\Types\NamedScopes\PostBuilder<Hypervel\Types\NamedScopes\Post>', $post->published(false));
    assertType('Hypervel\Types\NamedScopes\PostBuilder<Hypervel\Types\NamedScopes\Post>', Post::query()->published());
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<Hypervel\Types\NamedScopes\Post, Hypervel\Types\NamedScopes\User>', $user->posts()->published());

    assertType('Hypervel\Types\NamedScopes\PostBuilder<Hypervel\Types\NamedScopes\Post>', Post::ofType());
    assertType('Hypervel\Types\NamedScopes\PostBuilder<Hypervel\Types\NamedScopes\Post>', Post::query()->ofType('article', 'tutorial'));
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<Hypervel\Types\NamedScopes\Post, Hypervel\Types\NamedScopes\User>', $user->posts()->ofType());
    assertType('Hypervel\Types\NamedScopes\PostBuilder<Hypervel\Types\NamedScopes\Post>', Post::inherited());
    assertType('Hypervel\Types\NamedScopes\PostBuilder<Hypervel\Types\NamedScopes\Post>', Post::recent());
    assertType('Hypervel\Types\NamedScopes\PostBuilder<Hypervel\Types\NamedScopes\Post>', Post::query()->annotatedBuilder());
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<Hypervel\Types\NamedScopes\Post, Hypervel\Types\NamedScopes\User>', $user->posts()->annotatedBuilder());
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<Hypervel\Types\NamedScopes\Post, Hypervel\Types\NamedScopes\User>', $user->posts()->customBuilder());
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<Hypervel\Types\NamedScopes\Post, Hypervel\Types\NamedScopes\User>', $user->posts()->nullableBuilder());
    assertType('Hypervel\Types\NamedScopes\PostBuilder<Hypervel\Types\NamedScopes\Post>', Post::archived());
    assertType('Hypervel\Types\NamedScopes\PostBuilder<Hypervel\Types\NamedScopes\Post>', Post::query()->archived());
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<Hypervel\Types\NamedScopes\Post, Hypervel\Types\NamedScopes\User>', $user->posts()->archived());

    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\NamedScopes\Article>', Article::archived());
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\NamedScopes\Article>', Article::query()->archived());
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<Hypervel\Types\NamedScopes\Article, Hypervel\Types\NamedScopes\User>', $user->articles()->archived());
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Types\NamedScopes\Article>', Article::archived()->get());
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\NamedScopes\Article>', Article::annotatedBuilder());
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Types\NamedScopes\Article>', Article::query()->annotatedBuilder());
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<Hypervel\Types\NamedScopes\Article, Hypervel\Types\NamedScopes\User>', $user->articles()->annotatedBuilder());
    assertType('Hypervel\Types\NamedScopes\PostBuilder<Hypervel\Types\NamedScopes\Post>', Post::inheritedBuilder());
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<Hypervel\Types\NamedScopes\Post, Hypervel\Types\NamedScopes\User>', $user->posts()->inheritedBuilder());
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Types\NamedScopes\Post>', Post::inheritedCollection());
    assertType('Hypervel\Types\NamedScopes\Post', Post::inheritedModel(new Post));

    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Types\NamedScopes\Post>', Post::query()->asCollection());
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Types\NamedScopes\Post>', $user->posts()->asCollection());
    assertType('Hypervel\Types\NamedScopes\Post', Post::query()->asModel());
    assertType('Hypervel\Types\NamedScopes\Post', $user->posts()->asModel());
    assertType('Hypervel\Types\NamedScopes\PostBuilder<Hypervel\Types\NamedScopes\Post>', Post::query()->withSameModel(new Post));
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<Hypervel\Types\NamedScopes\Post, Hypervel\Types\NamedScopes\User>', $user->posts()->withSameModel(new Post));
    assertType('Hypervel\Database\Query\Builder', Post::query()->baseQuery());
    assertType('Hypervel\Database\Query\Builder', $user->posts()->baseQuery());

    assertType('int', Post::ranking());
    assertType('int', Post::query()->ranking());
    assertType('int', $user->posts()->ranking());
    assertType('Hypervel\Types\NamedScopes\PostBuilder<Hypervel\Types\NamedScopes\Post>|int', Post::optionalRanking());
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<Hypervel\Types\NamedScopes\Post, Hypervel\Types\NamedScopes\User>|int', $user->posts()->optionalRanking());

    assertType('Hypervel\Types\NamedScopes\PostBuilder<Hypervel\Types\NamedScopes\Post>', Post::untypedReturn());
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<Hypervel\Types\NamedScopes\Post, Hypervel\Types\NamedScopes\User>', $user->posts()->untypedReturn());
    assertType('mixed', Post::mixedScope());
    assertType('mixed', Post::docblockMixed());
    assertType('object', Post::objectScope());
    assertType('Hypervel\Types\NamedScopes\PostBuilder<Hypervel\Types\NamedScopes\Post>|int', Post::builderOrInt());
    assertType('Hypervel\Types\NamedScopes\PostBuilder<Hypervel\Types\NamedScopes\Post>|int', Post::query()->builderOrInt());
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<Hypervel\Types\NamedScopes\Post, Hypervel\Types\NamedScopes\User>|int', $user->posts()->builderOrInt());
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Types\NamedScopes\Post>|Hypervel\Types\NamedScopes\PostBuilder<Hypervel\Types\NamedScopes\Post>', Post::builderOrCollection());

    assertType('Hypervel\Types\NamedScopes\PostBuilder<Hypervel\Types\NamedScopes\Post>', Post::where('active', true));
    assertType('Hypervel\Types\NamedScopes\PostBuilder<Hypervel\Types\NamedScopes\Post>', Post::query()->where('active', true));
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<Hypervel\Types\NamedScopes\Post, Hypervel\Types\NamedScopes\User>', $user->posts()->where('active', true));

    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Notifications\DatabaseNotification>', DatabaseNotification::query()->read());
    assertType('Hypervel\Database\Eloquent\Collection<int, Hypervel\Notifications\DatabaseNotification>', DatabaseNotification::query()->unread()->get());
    assertType('bool', $notification->read());

    assertType('never', $user->posts()->unavailable());
}

class User extends Model
{
    /** @return HasMany<Post, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /** @return HasMany<Article, $this> */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }
}

class BaseArticle extends Model
{
    protected function scopeArchived(BuilderContract $query): void
    {
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    #[Scope]
    protected function annotatedBuilder(Builder $query): Builder
    {
        return $query;
    }
}

class Article extends BaseArticle
{
}

class BasePost extends Model
{
    #[Scope]
    protected function inherited(BuilderContract $query): void
    {
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    #[Scope]
    protected function inheritedBuilder(Builder $query): Builder
    {
        return $query;
    }

    /** @return Collection<int, static> */
    #[Scope]
    protected function inheritedCollection(BuilderContract $query): Collection
    {
        return new Collection;
    }

    /**
     * @param static $other
     * @return static
     */
    #[Scope]
    protected function inheritedModel(BuilderContract $query, Model $other): Model
    {
        return $other;
    }
}

class Post extends BasePost
{
    use HasArchivedScope;

    /** @use HasBuilder<PostBuilder<static>> */
    use HasBuilder;

    protected static string $builder = PostBuilder::class;

    #[Scope]
    protected function published(BuilderContract $query, bool $active = true): void
    {
    }

    protected function scopeOfType(BuilderContract $query, string $type = 'news', string ...$additionalTypes): void
    {
    }

    #[Scope]
    protected static function recent(BuilderContract $query): null
    {
        return null;
    }

    #[Scope]
    protected function ranking(BuilderContract $query): int
    {
        return 1;
    }

    #[Scope]
    protected function optionalRanking(BuilderContract $query): ?int
    {
        return null;
    }

    /** @phpstan-ignore missingType.return */
    #[Scope]
    protected function untypedReturn(BuilderContract $query)
    {
    }

    #[Scope]
    protected function mixedScope(BuilderContract $query): mixed
    {
        return null;
    }

    /** @return mixed */
    #[Scope]
    protected function docblockMixed(BuilderContract $query)
    {
        return null;
    }

    #[Scope]
    protected function objectScope(BuilderContract $query): object
    {
        return $this;
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>|int
     */
    #[Scope]
    protected function builderOrInt(Builder $query): Builder|int
    {
        return 1;
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>|Collection<int, static>
     */
    #[Scope]
    protected function builderOrCollection(Builder $query): Builder|Collection
    {
        return $query;
    }

    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    #[Scope]
    protected function annotatedBuilder(Builder $query): Builder
    {
        return $query;
    }

    /**
     * @param PostBuilder<static> $query
     * @return PostBuilder<static>
     */
    #[Scope]
    protected function customBuilder(PostBuilder $query): PostBuilder
    {
        return $query;
    }

    /**
     * @param Builder<static> $query
     * @return null|Builder<static>
     */
    #[Scope]
    protected function nullableBuilder(Builder $query): ?Builder
    {
        return null;
    }

    /**
     * @param Builder<static> $query
     * @return Collection<int, static>
     */
    #[Scope]
    protected function asCollection(Builder $query): Collection
    {
        return $query->getModel()->newCollection();
    }

    /** @return $this */
    #[Scope]
    protected function asModel(BuilderContract $query): Model
    {
        return $this;
    }

    /** @param static $other */
    #[Scope]
    protected function withSameModel(BuilderContract $query, Model $other): void
    {
    }

    /** @param Builder<static> $query */
    #[Scope]
    protected function baseQuery(Builder $query): QueryBuilder
    {
        return $query->toBase();
    }

    #[Scope]
    protected function unavailable(BuilderContract $query): never
    {
        throw new LogicException;
    }

    protected function scopeWhere(BuilderContract $query): void
    {
    }

    public function verifyNativeScopeSignature(BuilderContract $query): void
    {
        $this->published($query);
    }
}

/**
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class PostBuilder extends Builder
{
}

trait HasArchivedScope
{
    /**
     * @param Builder<static> $query
     * @return Builder<static>
     */
    protected function scopeArchived(Builder $query): Builder
    {
        return $query;
    }
}
