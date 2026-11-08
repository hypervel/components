<?php

declare(strict_types=1);

namespace EagerLoadMaps;

use App\Comment;
use App\Group;
use App\Post;
use App\User;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Pagination\LengthAwarePaginator;

use function PHPStan\Testing\assertType;

/**
 * Nested arrays and fresh(), beside upstream's eager-loading-callbacks.php.
 *
 * @param Collection<int, User> $users
 */
function eagerLoads(Collection $users): void
{
    // A nested array continues the path from its key's name, without the column list.
    $users->load(['posts:id,title' => ['comments' => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\MorphMany<App\Comment, App\Post>', $relation);
    }]]);

    $users->fresh(['accounts' => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', $relation);
    }]);
}

/**
 * Aggregate callbacks receive the related model's builder.
 *
 * @param Collection<int, User> $users
 */
function relationAggregates(User $user, Collection $users): void
{
    $user->loadCount(['posts' => function ($query): void {
        assertType('App\PostBuilder<App\Post>', $query);
    }]);

    // withAggregate() splits an exact "{relation} as {alias}" name, ignoring the case of "as".
    $users->loadSum(['accounts AS total' => function ($query): void {
        assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $query);
    }], 'id');

    // A relation that does not resolve keeps Laravel's Builder<*> callback.
    $users->loadExists(['accounts as' => function ($query): void {
        assertType('Hypervel\Database\Eloquent\Builder<*>', $query);
    }]);
}

/**
 * loadMorph() and its siblings read a model's class key before its morph alias.
 *
 * @param Collection<int, Comment> $comments
 * @param LengthAwarePaginator<int, Comment> $paginator
 */
function morphEagerLoads(Comment $comment, Collection $comments, LengthAwarePaginator $paginator): void
{
    $comment->loadMorph('commentable', ['post' => ['users' => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\BelongsToMany<App\User, App\Post, Hypervel\Database\Eloquent\Relations\Pivot, \'pivot\'>', $relation);
    }]]);

    // Group's class name is also Role's alias, so the entry's callback receives either model's relation.
    $comments->loadMorph('commentable', [Group::class => ['users' => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\BelongsToMany<App\User, App\Role, Hypervel\Database\Eloquent\Relations\Pivot, \'pivot\'>|Hypervel\Database\Eloquent\Relations\HasMany<App\User, App\Group>', $relation);
    }]]);

    // Post's own class key wins, so its alias entry is never read.
    $comments->loadMorph('commentable', [
        Post::class => ['users'],
        'post' => ['users' => function ($relation): void {
            assertType('Hypervel\Database\Eloquent\Relations\Relation<*, *, *>', $relation);
        }],
    ]);

    $paginator->loadMorphCount('commentable', ['post' => ['users as people' => function ($query): void {
        assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query);
    }]]);
}

/**
 * MorphTo resolves a key through the morph map before using it as a class name.
 *
 * @param MorphTo<Model, Comment> $commentable
 */
function morphTypeMaps(MorphTo $commentable): void
{
    $commentable->constrain([
        Group::class => function ($query): void {
            assertType('Hypervel\Database\Eloquent\Builder<App\Role>', $query);
        },
        'post' => function ($query): void {
            assertType('App\PostBuilder<App\Post>', $query);
        },
        'unknown' => function ($query): void {
            assertType('mixed', $query);
        },
    ]);

    $commentable->morphWith(['post' => ['users' => function ($relation): void {
        assertType('Hypervel\Database\Eloquent\Relations\BelongsToMany<App\User, App\Post, Hypervel\Database\Eloquent\Relations\Pivot, \'pivot\'>', $relation);
    }]]);

    $commentable->morphWithCount([User::class => ['accounts as total' => function ($query): void {
        assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $query);
    }]]);
}
