<?php

namespace EagerLoadingCallbacks;

use App\Account;
use App\User;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection;

use function PHPStan\Testing\assertType;

/**
 * @param Builder<User>                $builder
 * @param 'accounts'|'group'           $name
 */
function builderWith(Builder $builder, string $dynamic, string $name): void
{
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::query()->with(['accounts' => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', $relation);
        assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', $relation->where('active', true)->orderBy('id'));
    }]));

    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::query()->with('accounts'));
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::query()->with(['accounts', 'group']));
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::query()->with('accounts', 'group'));

    User::where('id', 1)->with(relations: ['group' => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\BelongsTo<App\Group, App\User>', $relation);
    }]);

    $builder->with(['accounts' => fn ($relation) => assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', $relation)]);

    $builder->with(['posts.comments' => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\MorphMany<App\Comment, App\Post>', $relation);
    }]);

    // Hypervel: each key's callback receives that key's own relation, not a union of the keys' relations.
    $builder->with([
        'accounts' => function ($relation): void {
            assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', $relation);
        },
        'group' => function ($relation): void {
            assertType('Hypervel\Database\Eloquent\Relations\BelongsTo<App\Group, App\User>', $relation);
        },
    ]);

    // Hypervel: a key that may be either name receives either relation, as a plain union.
    $builder->with([$name => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\BelongsTo<App\Group, App\User>|Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', $relation);
    }]);

    $builder->with([$dynamic => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\Relation<*, *, *>', $relation);
    }]);

    $builder->with(['accounts', 'group' => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\BelongsTo<App\Group, App\User>', $relation);
    }]);

    $builder->with('accounts', function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', $relation);
    });
}

/** @param Builder<User> $builder */
function builderWithOnly(Builder $builder): void
{
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', $builder->withOnly(['accounts' => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', $relation);
    }]));

    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::withOnly(['group' => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\BelongsTo<App\Group, App\User>', $relation);
    }]));

    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::withOnly('accounts'));
}

function modelLoad(User $user, string $dynamic): void
{
    assertType('App\User', $user->load(['accounts' => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', $relation);
    }]));

    assertType('App\User', $user->load('accounts'));
    assertType('App\User', $user->load('accounts', 'group'));
    assertType('App\User', $user->load(['accounts', 'group']));

    $user->load(['posts.comments' => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\MorphMany<App\Comment, App\Post>', $relation);
    }]);

    $user->load([$dynamic => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\Relation<*, *, *>', $relation);
    }]);

    assertType('App\User', $user->loadMissing(['group' => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\BelongsTo<App\Group, App\User>', $relation);
    }]));

    assertType('App\User', $user->loadMissing('accounts'));
}

/**
 * @param Collection<int, User>         $users
 * @param Collection<int, User|Account> $mixed
 */
function collectionLoad(Collection $users, Collection $mixed, string $dynamic): void
{
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $users->load(['accounts' => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', $relation);
    }]));

    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $users->load('accounts'));
    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $users->load(['accounts', 'group']));

    $users->load([$dynamic => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\Relation<*, *, *>', $relation);
    }]);

    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $users->loadMissing(['posts.comments' => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\MorphMany<App\Comment, App\Post>', $relation);
    }]));

    assertType('Hypervel\Database\Eloquent\Collection<int, App\User>', $users->loadMissing('accounts'));

    // Hypervel: several possible relations form a strict union, not upstream's benevolent one.
    $mixed->load(['group' => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\BelongsTo<App\Group, App\Account>|Hypervel\Database\Eloquent\Relations\BelongsTo<App\Group, App\User>', $relation);
    }]);

    // Hypervel: Account has no accounts relation, which a subclass or resolveRelationUsing() could supply, so the
    // relation is unknown.
    $mixed->load(['accounts' => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\Relation<*, *, *>', $relation);
    }]);
}

function relationWith(User $user): void
{
    assertType('Hypervel\Database\Eloquent\Relations\BelongsToMany<App\Post, App\User, Hypervel\Database\Eloquent\Relations\Pivot, \'pivot\'>', $user->posts()->with(['comments' => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\MorphMany<App\Comment, App\Post>', $relation);
    }]));
}

class ChildUser extends User
{
    public function insideClass(): void
    {
        static::query()->with(['accounts' => function ($relation): void {
            assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, EagerLoadingCallbacks\ChildUser>', $relation);
        }]);
        $this->load(['group' => function ($relation): void {
            assertType('Hypervel\Database\Eloquent\Relations\BelongsTo<App\Group, EagerLoadingCallbacks\ChildUser>', $relation);
        }]);
    }
}
