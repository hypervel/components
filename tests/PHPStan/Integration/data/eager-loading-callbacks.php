<?php

namespace EagerLoadingCallbacksIntegration;

use App\User;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\Relation;

/**
 * @param Builder<User>         $builder
 * @param Collection<int, User> $users
 */
function callbacks(Builder $builder, User $user, Collection $users, string $dynamic): void
{
    $builder->with(['accounts' => function (HasMany $relation): void {}]);
    $builder->with(['accounts' => function (Relation $relation): void {}, 'group']);
    $builder->with([$dynamic => function (Relation $relation): void {}]);
    $builder->with('accounts', function (HasMany $relation): void {});
    $builder->with('accounts', 'group');
    $builder->withOnly(['group' => function (BelongsTo $relation): void {}]);
    User::withOnly(['group' => function (BelongsTo $relation): void {}]);
    $user->load(['accounts' => function (HasMany $relation): void {}]);
    $user->load('accounts', 'group');
    $user->loadMissing(['group' => function (BelongsTo $relation): void {}]);
    $users->load(['accounts' => function (HasMany $relation): void {}]);
    $users->loadMissing(['group' => function (BelongsTo $relation): void {}]);
    $user->posts()->with(['comments' => function (Relation $relation): void {}]);

    $builder->with(['accounts' => function (BelongsTo $relation): void {}]);
    $builder->withOnly(['accounts' => function (int $relation): void {}]);
    $user->load(['accounts' => function (BelongsTo $relation): void {}]);
    $user->loadMissing(['group' => function (HasMany $relation): void {}]);
    $users->load(['accounts' => function (BelongsTo $relation): void {}]);
    $users->loadMissing(['group' => function (HasMany $relation): void {}]);
    $builder->with(['missing' => function ($relation): void {}]);
    $builder->with('accounts', function (BelongsTo $relation): void {});
}
