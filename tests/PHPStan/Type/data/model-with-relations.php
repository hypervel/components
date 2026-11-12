<?php

namespace ModelWithRelations;

use App\User;

use function PHPStan\Testing\assertType;

/** @param 'accounts'|'group' $name */
function eagerLoad(User $user, string $dynamic, string $name): void
{
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::with('accounts'));
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::with(['accounts', 'group']));
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::with([]));

    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::with(['accounts' => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', $relation);
        assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', $relation->where('active', true)->orderBy('id'));
    }]));

    $user->with(relations: ['group' => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\BelongsTo<App\Group, App\User>', $relation);
    }]);

    // Hypervel: each key's callback receives that key's own relation, not a union of the keys' relations.
    User::with([
        'accounts' => function ($relation): void {
            assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', $relation);
        },
        'group' => function ($relation): void {
            assertType('Hypervel\Database\Eloquent\Relations\BelongsTo<App\Group, App\User>', $relation);
        },
    ]);

    User::with(['accounts' => fn ($relation) => assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', $relation)]);
    User::with(['posts.comments' => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\MorphMany<App\Comment, App\Post>', $relation);
    }]);

    // Hypervel: a key that may be either name receives either relation, as a plain union.
    User::with([$name => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\BelongsTo<App\Group, App\User>|Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', $relation);
    }]);

    User::with([$dynamic => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\Relation<*, *, *>', $relation);
        $relation->where('active', true);
    }]);

    User::with(['accounts', 'group' => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\BelongsTo<App\Group, App\User>', $relation);
    }]);

    User::with(['accounts' => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\Relation<*, *, *>', $relation);
    }, $dynamic => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\Relation<*, *, *>', $relation);
    }]);
}

class ChildUser extends User
{
    public function insideClass(): void
    {
        static::with(['accounts' => function ($relation): void {
            assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, ModelWithRelations\ChildUser>', $relation);
        }]);
        $this->with(['group' => function ($relation): void {
            assertType('Hypervel\Database\Eloquent\Relations\BelongsTo<App\Group, ModelWithRelations\ChildUser>', $relation);
        }]);
    }
}
