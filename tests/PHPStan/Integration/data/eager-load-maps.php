<?php

declare(strict_types=1);

namespace EagerLoadMapsIntegration;

use App\Account;
use App\Comment;
use App\Group;
use App\PostBuilder;
use App\User;
use Closure;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\MorphTo;

/**
 * @param Collection<int, User> $users
 * @param Collection<int, Comment> $comments
 * @param MorphTo<Model, Comment> $commentable
 * @param array{accounts?: Closure(HasMany<Account, User>): void, group: Closure(BelongsTo<Group, User>): void} $optional
 * @param 'accounts'|'group' $name
 */
function callbacks(User $user, Collection $users, Collection $comments, MorphTo $commentable, array $optional, string $name): void
{
    $user->loadCount(['posts' => fn (PostBuilder $query) => $query, 'accounts' => fn (Builder $query) => $query]);
    $users->loadSum(['accounts as total' => fn (Builder $query) => $query], 'id');
    $comments->loadMorph('commentable', ['post' => ['users' => fn (BelongsToMany $relation) => $relation]]);
    $commentable->constrain(['post' => fn (PostBuilder $query) => $query]);
    $users->load($optional);

    $users->loadSum(['accounts as total' => fn (PostBuilder $query) => $query], 'id');
    $comments->loadMorph('commentable', ['post' => ['users' => fn (HasMany $relation) => $relation]]);
    $commentable->morphWith(['post' => ['users' => fn (HasMany $relation) => $relation]]);
    $commentable->constrain([User::class => fn (PostBuilder $query) => $query]);
    $comments->loadMorph('commentable', [Group::class => ['users' => fn (HasMany $relation) => $relation]]);
    User::query()->with($name, fn (HasMany $relation) => $relation);
}
