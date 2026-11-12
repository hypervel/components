<?php

namespace RelationOfType;

use App\Account;
use App\User;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\MorphTo;

use function PHPStan\Testing\assertType;

/**
 * @param relation-of<User, 'accounts'> $accounts
 * @param relation-of<User, 'group'> $group
 * @param relation-of<User, 'posts.comments'> $nested
 * @param relation-of<User, 'accounts:id,user_id'> $columns
 * @param relation-of<User, 'accounts'|'group'> $union
 * @param relation-of<User, 'accounts'>|null $nullable
 * @param relation-of<User&object{extra: string}, 'accounts'> $intersection
 */
function relations($accounts, $group, $nested, $columns, $union, $nullable, $intersection): void
{
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', $accounts);
    assertType('Hypervel\Database\Eloquent\Relations\BelongsTo<App\Group, App\User>', $group);
    assertType('Hypervel\Database\Eloquent\Relations\MorphMany<App\Comment, App\Post>', $nested);
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', $columns);
    // Hypervel: several possible relations form a strict union, not upstream's benevolent one.
    assertType('Hypervel\Database\Eloquent\Relations\BelongsTo<App\Group, App\User>|Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', $union);
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>|null', $nullable);
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User&object{extra: string}>', $intersection);
}

/**
 * @param relation-of<User, string> $dynamic
 * @param relation-of<User, non-empty-string> $nonEmpty
 * @param relation-of<User, 'missing'> $missing
 * @param relation-of<User, 'posts.missing'> $missingNested
 * @param relation-of<User, 'getAllCapsName'> $nonRelation
 * @param relation-of<User, 'accounts'|'missing'> $partlyMissing
 * @param relation-of<User|\App\Team, 'accounts'> $modelUnion
 */
function fallbacks($dynamic, $nonEmpty, $missing, $missingNested, $nonRelation, $partlyMissing, $modelUnion): void
{
    assertType('Hypervel\Database\Eloquent\Relations\Relation<*, *, *>', $dynamic);
    assertType('Hypervel\Database\Eloquent\Relations\Relation<*, *, *>', $nonEmpty);
    assertType('Hypervel\Database\Eloquent\Relations\Relation<*, *, *>', $missing);
    assertType('Hypervel\Database\Eloquent\Relations\Relation<*, *, *>', $missingNested);
    assertType('Hypervel\Database\Eloquent\Relations\Relation<*, *, *>', $nonRelation);
    assertType('Hypervel\Database\Eloquent\Relations\Relation<*, *, *>', $partlyMissing);
    // Unlike upstream, Team's missing accounts relation is unknown.
    assertType('Hypervel\Database\Eloquent\Relations\Relation<*, *, *>', $modelUnion);
}

/**
 * @template TModel of Model
 * @template TRelation of string
 * @param TModel $model
 * @param TRelation $relation
 * @return relation-of<TModel, TRelation>
 */
function genericRelation(Model $model, string $relation)
{
    throw new \LogicException();
}

/** @template TValue */
class GenericRelated extends Model
{
}

/** @template TValue */
class GenericParent extends Model
{
    /** @return HasMany<GenericRelated<TValue>, $this> */
    public function children(): HasMany
    {
        throw new \LogicException();
    }

    /** @return MorphTo<User|Account, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}

/** @param GenericParent<string> $parent */
function generics(User $user, GenericParent $parent): void
{
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>', genericRelation($user, 'accounts'));
    assertType('Hypervel\Database\Eloquent\Relations\HasMany<RelationOfType\GenericRelated<string>, RelationOfType\GenericParent<string>>', genericRelation($parent, 'children'));
    assertType('Hypervel\Database\Eloquent\Relations\MorphTo<App\Account|App\User, RelationOfType\GenericParent<string>>', genericRelation($parent, 'subject'));
}
