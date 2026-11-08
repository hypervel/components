<?php

declare(strict_types=1);

namespace ModelPropertyColumns;

use App\SearchableUser;
use App\User;
use Hypervel\Contracts\Database\Query\Expression;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\DB;

/**
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class AliasBuilder extends Builder
{
    /**
     * Pluck a column by an alias the builder maps.
     *
     * @return Collection<array-key, mixed>
     */
    public function pluck(Expression|string $column, ?string $key = null): Collection
    {
        return parent::pluck($column, $key);
    }
}

abstract class AbstractSubject extends Model
{
}

/**
 * @param AliasBuilder<User> $aliases
 * @param Builder<Model> $unknown
 * @param Builder<AbstractSubject|User> $mixed
 */
function test(User $user, AliasBuilder $aliases, Builder $unknown, Builder $mixed, string $column): void
{
    User::query()->pluck('name');
    User::query()->pluck('nonexistent');
    User::query()->pluck(DB::raw('count(*)'));
    User::query()->pluck($column);
    User::query()->paginate(15, ['id', 'nonexistent']);
    User::query()->paginate(15, ['*', 'users.id']);
    User::query()->simplePaginate(columns: ['nonexistent']);
    User::query()->cursorPaginate(15, 'nonexistent');

    User::pluck('nonexistent');
    User::paginate(15, ['nonexistent']);

    $user->accounts()->pluck('nonexistent');
    $user->roles()->paginate(15, ['nonexistent']);
    $user->transactions()->simplePaginate(15, ['nonexistent']);

    $aliases->pluck('alias');
    $unknown->pluck('anything');

    SearchableUser::query()->pluck('name');
    SearchableUser::query()->pluck('nonexistent');

    $mixed->pluck('name');
    $mixed->pluck('nonexistent');

    $class = User::class;
    $class::pluck('nonexistent');
}

/**
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class WhereBuilder extends Builder
{
    /**
     * Constrain a column of whichever model the builder queries.
     */
    public function active(): static
    {
        return $this->whereActive(true);
    }

    /**
     * Declare a where method that the query does not.
     */
    public function whereAlias(string $alias): static
    {
        return $this->where('name', $alias);
    }
}

/**
 * @param WhereBuilder<User> $custom
 * @param Builder<Model> $unknown
 * @param null|Builder<User> $optional
 */
function dynamicWheres(User $user, WhereBuilder $custom, Builder $unknown, ?Builder $optional): void
{
    User::query()->whereNameAndEmail('Taylor', 'taylor@example.com');
    User::query()->whereNameOrNonexistent('Taylor', 1);
    User::whereNonexistent(1);
    $user->whereNonexistent(1);
    $user->accounts()->whereNonexistent(1);
    $custom->whereNonexistent(1);
    $optional?->pluck('nonexistent');

    $custom->whereAlias('Taylor');
    User::query()->whereActive();
    User::query()->whereGlobalMacro();
    User::query()->whereQueryMacro();
    $user->accounts()->whereIn('id', [1]);
    $unknown->whereAnything(1);
}
