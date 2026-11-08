<?php

declare(strict_types=1);

namespace EloquentBuilder;

use App\Account;
use App\Comment;
use App\Post;
use App\PostBuilder;
use App\Team;
use App\User;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Builder as EloquentBuilder;
use Hypervel\Database\Eloquent\Model;

use function PHPStan\Testing\assertType;

interface OnlyUsers
{ }

/**
 * @template TModel of \Hypervel\Database\Eloquent\Model
 *
 * @param Builder<User> $userBuilder
 * @param Builder<User|Team> $userOrTeamBuilder
 * @param Builder<TModel> $templateBuilder
 */
function test(
    User $user,
    Post $post,
    Builder $userBuilder,
    OnlyUsers&User $userAndAuth,
    Builder $userOrTeamBuilder,
    Builder $templateBuilder,
): void {
    User::query()->has('accounts', '=', 1, 'and', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $query);
    });

    Post::query()->has('users', '=', 1, 'and', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    });

    User::query()->doesntHave('accounts', 'and', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $query);
    });

    Post::query()->doesntHave('users', 'and', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    });

    User::query()->whereHas('accounts', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $query);
    });

    Post::query()->whereHas('users', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    });

    User::query()->orWhereHas('accounts', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $query);
    });

    Post::query()->orWhereHas('users', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    });

    Comment::query()->hasMorph('commentable', [Account::class], '=', 1, 'and', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $query);
    });

    Comment::query()->hasMorph('commentable', [User::class], '=', 1, 'and', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    });

    Comment::query()->doesntHaveMorph('commentable', [Account::class], 'and', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $query);
    });

    Comment::query()->doesntHaveMorph('commentable', [User::class], 'and', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    });

    Comment::query()->whereHasMorph('commentable', [Account::class], function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $query);
    });

    Comment::query()->whereHasMorph('commentable', [User::class], function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    });

    Comment::query()->orWhereHasMorph('commentable', [Account::class], function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $query);
    });

    Comment::query()->orWhereHasMorph('commentable', [User::class], function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    });

    Comment::query()->whereDoesntHaveMorph('commentable', [Account::class], function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $query);
    });

    Comment::query()->whereDoesntHaveMorph('commentable', [User::class], function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    });

    Comment::query()->orWhereDoesntHaveMorph('commentable', [Account::class], function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $query);
    });

    Comment::query()->orWhereDoesntHaveMorph('commentable', [User::class], function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    });

    User::query()->whereDoesntHave('accounts', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $query);
    });

    Post::query()->whereDoesntHave('users', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    });

    User::query()->orWhereDoesntHave('accounts', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $query);
    });

    Post::query()->orWhereDoesntHave('users', function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    });

    User::query()->firstWhere(function (Builder $query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    });

    Post::query()->firstWhere(function (PostBuilder $query) {
        assertType('App\PostBuilder<App\Post>', $query);
    });

    Post::query()->where(static function (PostBuilder $query) {
        assertType('App\PostBuilder<App\Post>', $query
            ->orWhere('bar', 'LIKE', '%foo%')
            ->orWhereRelation('users', 'name', 'LIKE', '%foo%'));
    });

    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::where([
        ['active', true],
        ['id', '>=', 5],
        ['id', '<=', 10],
    ])->get());

    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::where('id', 1)->get());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', (new User)->where('id', 1)->get());
    // Unlike upstream, which runs without model property checks, these dynamic wheres name a real column.
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::where('id', 1)
        ->whereNotNull('name')
        ->where('email', 'bar')
        ->whereName(['bar'])
        ->get());
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', (new User)->whereNotNull('name')
        ->where('email', 'bar')
        ->whereName(['bar'])
        ->get());
    // Unlike upstream, User's public email() accessor applies, as it does in Eloquent, so email reads as mixed.
    assertType('Hypervel\Support\Collection<string, mixed>', User::whereIn('id', [1, 2, 3])->get()->mapWithKeys(function (User $user): array {
        return [$user->name => $user->email];
    }));

    assertType('mixed', (new User)->where('email', 1)->max('email'));
    assertType('bool', (new User)->where('email', 1)->exists());
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::with('accounts')->whereNull('name'));
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::with('accounts')
        ->where('email', 'bar')
        ->orWhere('name', 'baz'));

    assertType('App\User|null', User::with(['accounts'])->find(1));
    assertType('App\User', User::with(['accounts'])->findOrFail(1));
    assertType('App\User', User::with(['accounts'])->findOrNew(1));
    assertType('Hypervel\Database\Eloquent\Model|null', (new CustomBuilder(User::query()->getQuery()))->with('email')->find(1));

    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::with(['accounts'])->find([1, 2, 3]));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::with(['accounts'])->findOrNew([1, 2, 3]));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::hydrate([]));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', User::fromQuery('SELECT * FROM users'));

    assertType('Hypervel\Database\Eloquent\Builder<App\User>', $userBuilder->whereNotNull('test'));

    assertType('Hypervel\Database\Eloquent\Builder<App\User>', $user->newQuery());
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', $user->newModelQuery());
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', $user->newQueryWithoutRelationships());
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', $user->newQueryWithoutScopes());
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', $user->newQueryWithoutScope('foo'));
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', $user->newQueryForRestoration([1]));

    assertType('App\PostBuilder<App\Post>', $post->newQuery());
    assertType('App\PostBuilder<App\Post>', $post->newModelQuery());
    assertType('App\PostBuilder<App\Post>', $post->newQueryWithoutRelationships());
    assertType('App\PostBuilder<App\Post>', $post->newQueryWithoutScopes());
    assertType('App\PostBuilder<App\Post>', $post->newQueryWithoutScope('foo'));
    assertType('App\PostBuilder<App\Post>', $post->newQueryForRestoration([1]));

    assertType('Hypervel\Database\Eloquent\Builder<App\User&EloquentBuilder\OnlyUsers>', $userAndAuth->newQuery());
    assertType('Hypervel\Database\Eloquent\Builder<App\User&EloquentBuilder\OnlyUsers>', $userAndAuth->newModelQuery());
    assertType('Hypervel\Database\Eloquent\Builder<App\User&EloquentBuilder\OnlyUsers>', $userAndAuth->newQueryWithoutRelationships());
    assertType('Hypervel\Database\Eloquent\Builder<App\User&EloquentBuilder\OnlyUsers>', $userAndAuth->newQueryWithoutScopes());
    assertType('Hypervel\Database\Eloquent\Builder<App\User&EloquentBuilder\OnlyUsers>', $userAndAuth->newQueryWithoutScope('foo'));
    assertType('Hypervel\Database\Eloquent\Builder<App\User&EloquentBuilder\OnlyUsers>', $userAndAuth->newQueryForRestoration([1]));
    assertType('Hypervel\Database\Eloquent\Builder<App\User&EloquentBuilder\OnlyUsers>', $userAndAuth::query());

    assertType('Hypervel\Support\LazyCollection<int, App\User>', User::query()->lazy());
    assertType('Hypervel\Support\LazyCollection<int, App\User>', User::query()->lazyById());
    assertType('Hypervel\Support\LazyCollection<int, App\User>', User::query()->lazyByIdDesc());
    assertType('Hypervel\Support\LazyCollection<int, App\User>', User::query()->cursor());
    assertType('Hypervel\Support\LazyCollection<int, App\Post>', $post->newQuery()->lazy());
    assertType('Hypervel\Support\LazyCollection<int, App\Post>', $post->newQuery()->lazyById());
    assertType('Hypervel\Support\LazyCollection<int, App\Post>', $post->newQuery()->lazyByIdDesc());

    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::query()->groupBy('foo', 'bar'));
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::query()->whereEmail('bar'));
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::query()->whereIdAndEmail(1, 'foo@example.com'));
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::query()->whereEmail(1));
    assertType('Hypervel\Database\Query\Builder', User::query()
        ->whereNull('name')
        ->orderBy('email')
        ->toBase()
    );
    assertType('Hypervel\Database\Query\Builder', User::query()
        ->whereNotBetween('a', [1, 5])
        ->orWhereNotBetween('a', [1, 5])
        ->toBase()
    );
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::query()->withTrashed());
    assertType('Hypervel\Database\Query\Builder', User::query()
        ->whereNull('name')
        ->orderBy(\Hypervel\Support\Facades\DB::raw('name'))
        ->toBase()
    );
    assertType('Hypervel\Database\Query\Builder', User::query()
        ->whereNull('name')
        ->orderBy(User::whereNotNull('name'))
        ->toBase()
    );
    assertType('Hypervel\Database\Query\Builder', User::query()
        ->whereNull('name')
        ->latest(\Hypervel\Support\Facades\DB::raw('created_at'))
        ->toBase()
    );
    assertType('Hypervel\Database\Query\Builder', User::query()
        ->whereNull('name')
        ->oldest(\Hypervel\Support\Facades\DB::raw('created_at'))
        ->toBase()
    );
    assertType('Hypervel\Support\Collection<(int|string), mixed>', User::query()
        ->whereNull('name')
        ->pluck(\Hypervel\Support\Facades\DB::raw('created_at'))
        ->toBase()
    );
    assertType('int', User::query()->increment(\Hypervel\Support\Facades\DB::raw('counter')));
    assertType('int', User::query()->decrement(\Hypervel\Support\Facades\DB::raw('counter')));
    // Unlike upstream, registering a local macro returns null, as Builder::__call() does.
    assertType('null', $userBuilder->macro('customMacro', function () {
    }));
    assertType('string', $userBuilder->globalCustomMacro('foo'));
    assertType('App\User', User::with('accounts')
        ->where('email', 'bar')
        ->orWhere('name', 'baz')
        ->firstOrFail()
    );
    assertType('App\User|null', User::with('accounts')
        ->where('email', 'bar')
        ->orWhere('name', 'baz')
        ->first()
    );
    assertType('App\User|null', User::query()->firstWhere(['email' => 'foo@bar.com']));
    assertType('App\User|null', User::with('accounts')
        ->orWhere(\Hypervel\Support\Facades\DB::raw('name'), 'like', '%john%')
        ->first()
    );
    assertType('App\User|null', User::with('accounts')
        ->where(\Hypervel\Support\Facades\DB::raw('name'), 'like', '%john%')
        ->first()
    );
    assertType('App\User|null', User::with('accounts')
        ->firstWhere(\Hypervel\Support\Facades\DB::raw('name'), 'like', '%john%')
    );
    assertType('mixed', User::with('accounts')
        ->value(\Hypervel\Support\Facades\DB::raw('name'))
    );
    assertType('int', User::query()->restore());
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::query()->joinSub(
        Post::query()->whereIn('id', [1, 2, 3]),
        'users',
        'users.id',
        'posts.id'
    ));

    // Unlike upstream, paginators carry their collection class as a third template.
    assertType('Hypervel\Pagination\LengthAwarePaginator<int, App\User, Hypervel\Database\Eloquent\Collection<int, App\User>>', User::query()->paginate());
    assertType('array<int, App\User>', User::query()->paginate()->items());

    User::chunk(1000, fn ($collection) => assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $collection));
    User::chunkById(1000, fn ($collection) => assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $collection));
    assertType('Hypervel\Support\Collection<int, string>', User::chunkMap(function ($model) {
        assertType('App\User', $model);

        return $model->name;
    }, 1000));
    $userBuilder->chunk(1000, fn ($collection) => assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $collection));
    $userBuilder->chunkById(1000, fn ($collection) => assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $collection));
    assertType('Hypervel\Support\Collection<int, string>', $userBuilder->chunkMap(function ($model) {
        assertType('App\User', $model);

        return $model->name;
    }, 1000));

    assertType('App\Team|App\User', $userOrTeamBuilder->findOrFail(4));
    assertType('Hypervel\Database\Eloquent\Builder<App\Team|App\User>', $userOrTeamBuilder->where('id', 5));

    assertType('Hypervel\Database\Eloquent\Builder<TModel of Hypervel\Database\Eloquent\Model (function EloquentBuilder\test(), argument)>', $templateBuilder->select());
}

class Foo extends Model
{
    /** @use FooTrait<Foo> */
    use FooTrait;
}

/** @template TModel of Model */
trait FooTrait
{
    /** @return Builder<TModel> */
    public function doFoo(): Builder
    {
        return $this->newQuery();
    }
}

/** @property string $email */
class TestModel extends Model
{
    public function test(): void
    {
        // Unlike upstream, forwarding through a model keeps its late-static type, as static::query() does.
        assertType('Hypervel\Database\Eloquent\Collection<int, static(EloquentBuilder\TestModel)>', $this->where('email', 1)->get());
        assertType('Hypervel\Database\Eloquent\Builder<static(EloquentBuilder\TestModel)>', static::query()->where('email', 'bar'));
        assertType('Hypervel\Database\Eloquent\Builder<static(EloquentBuilder\TestModel)>', $this->where('email', 'bar'));
    }
}

/** @extends Builder<Model> */
class CustomBuilder extends Builder
{
}

/** @template TModel of User|Team */
abstract class UnionClass
{
    /** @return TModel */
    public function test(int $id): Model
    {
        assertType('TModel of App\Team|App\User (class EloquentBuilder\UnionClass, argument)', $this->getQuery()->findOrFail($id));

        return $this->getQuery()->findOrFail($id);
    }

    /** @return Builder<TModel> */
    abstract public function getQuery(): Builder;
}

/** @extends UnionClass<Team> */
class TeamClass extends UnionClass
{
    public function foo(): void
    {
        assertType('App\Team', $this->test(5));
    }

    /** @inheritDoc */
    public function getQuery(): Builder
    {
        return Team::query();
    }
}
