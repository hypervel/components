<?php

declare(strict_types=1);

namespace RelationExistenceHypervel;

use App\ChildUser;
use App\Comment;
use App\Post;
use App\Team;
use App\User;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @template TScope
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
class ScopedBuilder extends Builder
{
}

/**
 * @template TRelated of Model
 */
class GenericModel extends Model
{
    /**
     * @return HasMany<TRelated, $this>
     */
    public function children(): HasMany
    {
        throw new LogicException;
    }
}

/**
 * @param class-string<User> $modelClass
 * @param ScopedBuilder<string, User> $scopedBuilder
 */
function receivers(string $modelClass, ScopedBuilder $scopedBuilder): void
{
    $modelClass::with('missing');
    $scopedBuilder->has('missing');
    Team::query()->whereHas('missing');
}

function dynamicRelations(): void
{
    User::with('dynamicAccounts');
    ChildUser::with('dynamicAccounts');
    User::with('dynamicAccounts.anything');
}

function columnSelections(): void
{
    User::withWhereHas('accounts:id', static function (): void {});
    User::whereHas('accounts:id');
    User::withWhereRelation('accounts:id', 'active', true);
}

function morphPaths(): void
{
    Comment::hasMorph('commentable', Post::class);
    Comment::hasMorph('commentable.user', Post::class);
}

/**
 * @param GenericModel<Post> $model
 */
function genericPaths(GenericModel $model): void
{
    $model->load('children.users');
    $model->load('children.missing');
}
