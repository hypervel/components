<?php

declare(strict_types=1);

namespace EloquentWhere;

use App\Post;
use App\User;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;

use function PHPStan\Testing\assertType;

function callbacks(User $user, string|null $operator, mixed $unknown): void
{
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::query()->where(function ($query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    }));

    User::query()->where(function ($query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    }, null, 2);

    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::query()->where(function ($query) {
        assertType('Hypervel\Database\Query\Builder', $query);
    }, '=', 2));

    User::where(function ($query) {
        assertType('Hypervel\Database\Query\Builder', $query);
    }, 2);

    $user->where(function ($query) {
        assertType('Hypervel\Database\Query\Builder', $query);
    }, '=', null);

    assertType('App\User|null', User::query()->firstWhere(function ($query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    }));

    assertType('App\User|null', User::firstWhere(function ($query) {
        assertType('Hypervel\Database\Query\Builder', $query);
    }, '=', 2));

    User::query()->whereNot(function ($query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    }, null, null, 'or');

    User::query()->whereNot(function ($query) {
        assertType('Hypervel\Database\Query\Builder', $query);
    }, '=', 2);

    User::query()->orWhereNot(function ($query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    }, null);

    User::query()->orWhereNot(function ($query) {
        assertType('Hypervel\Database\Query\Builder', $query);
    }, '=', 2);

    Post::where(function ($query) {
        assertType('App\PostBuilder<App\Post>', $query);
    });

    Post::query()->where(function ($query) {
        assertType('Hypervel\Database\Query\Builder', $query);
    }, '=', 2);

    $user->posts()->where(function ($query) {
        assertType('App\PostBuilder<App\Post>', $query);
    });

    $user->posts()->where(function ($query) {
        assertType('Hypervel\Database\Query\Builder', $query);
    }, '=', 2);

    User::query()->where(value: 2, column: function ($query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    });

    User::query()->where(operator: '=', value: 2, column: function ($query) {
        assertType('Hypervel\Database\Query\Builder', $query);
    });

    User::query()->where(function ($query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>|Hypervel\Database\Query\Builder', $query);
    }, $operator, 2);

    User::query()->where(function ($query) {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>|Hypervel\Database\Query\Builder', $query);
    }, $unknown, 2);

    User::query()->orWhere(function ($query) {
        assertType('(Hypervel\Database\Eloquent\Builder<App\User>|Hypervel\Database\Query\Builder)', $query);
    });

    User::query()->orWhere(function ($query) {
        assertType('(Hypervel\Database\Eloquent\Builder<App\User>|Hypervel\Database\Query\Builder)', $query);
    }, null);

    User::query()->orWhere(function ($query) {
        assertType('(Hypervel\Database\Eloquent\Builder<App\User>|Hypervel\Database\Query\Builder)', $query);
    }, null, null);

    Post::orWhere(function ($query) {
        assertType('(App\PostBuilder<App\Post>|Hypervel\Database\Query\Builder)', $query);
    }, '=', 2);
}

/**
 * @param Builder<Model> $unknown
 */
function dynamicWheres(Builder $unknown, User $user): void
{
    // Any column may belong to a subclass of an abstract model.
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $unknown->whereNameAndEmail('Taylor', 'taylor@example.com'));

    // Unlike upstream, a missing column does not remove the method: ModelPropertyColumnRule reports it.
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', User::query()->whereMissingColumn(1));
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', $user->accounts()->whereMissingColumn(1));
}
