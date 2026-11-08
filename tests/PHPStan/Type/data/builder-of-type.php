<?php

namespace BuilderOfType;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use function PHPStan\Testing\assertType;

/**
 * @param builder-of<\App\User> $userBuilder
 * @param builder-of<\App\Account> $accountBuilder
 * @param builder-of<\App\Team> $teamBuilder
 * @param builder-of<\App\User|\App\Team> $union
 */
function test($userBuilder, Builder $accountBuilder, Builder $teamBuilder, Builder $union): void
{
    assertType('Hypervel\Database\Eloquent\Builder<App\User>', $userBuilder);
    assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $accountBuilder);
    assertType('App\ChildTeamBuilder', $teamBuilder);
    assertType('App\ChildTeamBuilder|Hypervel\Database\Eloquent\Builder<App\User>', $union);

    assertType('Hypervel\Database\Eloquent\Builder<App\User>', genericMethod(\App\User::class));
    assertType('Hypervel\Database\Eloquent\Builder<App\Account>', genericMethod(\App\Account::class));
    assertType('App\ChildTeamBuilder', genericMethod(\App\Team::class));
}

/**
 * @template T of Model
 *
 * @param class-string<T> $class
 *
 * @return builder-of<T>
 */
function genericMethod(string $class): Builder
{
    return $class::query();
}

class ModelWithStaticBuilder extends Model
{
    /** @return builder-of<static> */
    public function builder(): Builder
    {
        return $this->newQuery();
    }

    /** @return builder-of<$this> */
    public function instanceBuilder(): Builder
    {
        return $this->newEloquentBuilder($this->newBaseQueryBuilder())->setModel($this);
    }

    public function testStaticBuilder(): void
    {
        assertType('Hypervel\Database\Eloquent\Builder<static(BuilderOfType\ModelWithStaticBuilder)>', $this->builder());
        assertType('Hypervel\Database\Eloquent\Builder<$this(BuilderOfType\ModelWithStaticBuilder)>', $this->instanceBuilder());
    }
}

class ChildModelWithStaticBuilder extends ModelWithStaticBuilder {}

function testStaticBuilderOutsideClass(ModelWithStaticBuilder $model, ChildModelWithStaticBuilder $child): void
{
    assertType('Hypervel\Database\Eloquent\Builder<BuilderOfType\ModelWithStaticBuilder>', $model->builder());
    assertType('Hypervel\Database\Eloquent\Builder<BuilderOfType\ChildModelWithStaticBuilder>', $child->builder());
}

/** @template TValue */
class GenericModel extends Model {}

/**
 * @param builder-of<GenericModel<string>> $generic
 * @param builder-of<GenericModel<string>|\App\Team> $union
 * @param builder-of<\App\User&object{extra: string}> $intersection
 * @param builder-of<\App\User>|null $nullable
 */
function testModelTypes($generic, $union, $intersection, $nullable): void
{
    assertType('Hypervel\Database\Eloquent\Builder<BuilderOfType\GenericModel<string>>', $generic);
    assertType('App\ChildTeamBuilder|Hypervel\Database\Eloquent\Builder<BuilderOfType\GenericModel<string>>', $union);
    assertType('Hypervel\Database\Eloquent\Builder<App\User&object{extra: string}>', $intersection);
    assertType('Hypervel\Database\Eloquent\Builder<App\User>|null', $nullable);
}

class PostWithBuilderMethod extends \App\Post
{
    /** @return builder-of<static> */
    public function builder(): Builder
    {
        return $this->newQuery();
    }

    public function testCustomBuilder(): void
    {
        assertType('App\PostBuilder<static(BuilderOfType\PostWithBuilderMethod)>', $this->builder());
    }
}

final class FinalPostWithBuilderMethod extends PostWithBuilderMethod {}

/** @param GenericModel<string> $generic */
function testInheritedAndGenericBuilders(FinalPostWithBuilderMethod $post, GenericModel $generic): void
{
    assertType('App\PostBuilder<BuilderOfType\FinalPostWithBuilderMethod>', $post->builder());
    assertType('Hypervel\Database\Eloquent\Builder<BuilderOfType\GenericModel<string>>', $generic->newQuery());
    assertType('Hypervel\Database\Eloquent\Builder<BuilderOfType\GenericModel<string>>', $generic::query());
}

class ModelWithNeverQuery extends Model
{
    public static function query(): never
    {
        throw new \LogicException();
    }
}

function testNeverQuery(): void
{
    assertType('never', ModelWithNeverQuery::query());
}

/**
 * @param builder-of<\App\User, 'accounts'> $accounts
 * @param builder-of<\App\User, 'posts'> $posts
 * @param builder-of<\App\User, 'posts.comments'> $comments
 * @param builder-of<\App\User, 'posts:id'> $postColumns
 * @param builder-of<\App\User, 'posts.comments:id,post_id'> $commentColumns
 */
function testRelationshipBuilders($accounts, $posts, $comments, $postColumns, $commentColumns): void
{
    assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $accounts);
    assertType('App\PostBuilder<App\Post>', $posts);
    assertType('Hypervel\Database\Eloquent\Builder<App\Comment>', $comments);
    assertType('App\PostBuilder<App\Post>', $postColumns);
    assertType('Hypervel\Database\Eloquent\Builder<App\Comment>', $commentColumns);
}

/**
 * @param builder-of<\App\BareRelations\Owner> $owner
 * @param builder-of<\App\BareRelations\Owner, 'items'> $items
 * @param builder-of<\App\BareRelations\Owner, 'items.category'> $category
 * @param builder-of<\App\BareRelations\Owner, 'items.category.labels'> $labels
 * @param builder-of<\App\BareRelations\Owner, 'missing'> $missing
 * @param builder-of<\App\BareRelations\Owner, string> $unknown
 */
function testBareRelationshipBuilders($owner, $items, $category, $labels, $missing, $unknown): void
{
    assertType('Hypervel\Database\Eloquent\Builder<App\BareRelations\Owner>', $owner);
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $items);
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $category);
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $labels);
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $missing);
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $unknown);
}

abstract class AbstractSubject extends Model
{
    /** @return HasMany<\App\Account, $this> */
    public function accounts(): HasMany
    {
        throw new \LogicException();
    }
}

class ModelWithAbstractRelation extends Model
{
    /** @return HasMany<AbstractSubject, $this> */
    public function subjects(): HasMany
    {
        throw new \LogicException();
    }

    /** @return MorphTo<\App\Account|AbstractSubject, $this> */
    public function subject(): MorphTo
    {
        throw new \LogicException();
    }
}

/**
 * @param builder-of<ModelWithAbstractRelation, 'subjects'> $subjects
 * @param builder-of<ModelWithAbstractRelation, 'subjects.accounts'> $throughAbstract
 * @param builder-of<ModelWithAbstractRelation, 'subjects.missing'> $missingOnAbstract
 * @param builder-of<ModelWithAbstractRelation, 'missing'> $missingOnConcrete
 * @param builder-of<ModelWithAbstractRelation, 'subject.transactions'> $partOfUnion
 * @param builder-of<ModelWithAbstractRelation, 'subject.missing'> $missingOnUnion
 */
function testAbstractRelatedModels($subjects, $throughAbstract, $missingOnAbstract, $missingOnConcrete, $partOfUnion, $missingOnUnion): void
{
    assertType('Hypervel\Database\Eloquent\Builder<BuilderOfType\AbstractSubject>', $subjects);
    assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $throughAbstract);
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $missingOnAbstract);
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $missingOnConcrete);
    // Unlike upstream, a model alternative without the relation, here the abstract subject, is unknown.
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $partOfUnion);
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $missingOnUnion);
}

/**
 * @param builder-of<\App\User, 'posts'|'accounts'> $relations
 * @param builder-of<\App\User, 'posts'|'missing'> $partlyMissing
 * @param builder-of<\App\User, 'posts'|'address.addressable.missing'> $partlyUnknown
 * @param builder-of<\App\User, 'posts.comments'|'posts.missing'> $nested
 * @param builder-of<\App\User|\App\Team, 'posts'> $models
 * @param builder-of<\App\User|\App\Team, 'missing'> $missingModels
 * @param builder-of<\App\User&object{extra: string}, 'accounts'> $intersection
 * @param builder-of<\App\User, 'accounts'>|null $nullable
 */
function testRelationshipUnions($relations, $partlyMissing, $partlyUnknown, $nested, $models, $missingModels, $intersection, $nullable): void
{
    assertType('App\PostBuilder<App\Post>|Hypervel\Database\Eloquent\Builder<App\Account>', $relations);
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $partlyMissing);
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $partlyUnknown);
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $nested);
    // Unlike upstream, Team's missing posts relation is unknown.
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $models);
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $missingModels);
    assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $intersection);
    assertType('Hypervel\Database\Eloquent\Builder<App\Account>|null', $nullable);
}

/**
 * @param builder-of<\App\User, 'missing'> $missing
 * @param builder-of<\App\User, 'posts.missing'> $nested
 * @param builder-of<\App\User, 'missing'|'posts.missing'> $union
 * @param builder-of<\App\User, 'getAllCapsName'> $nonRelation
 * @param builder-of<\App\User, string> $unknown
 * @param builder-of<\App\User, non-empty-string> $nonEmpty
 * @param builder-of<\App\User, ''|'posts.'|'.posts'|'posts..comments'> $malformed
 * @param builder-of<\App\Team, 'missing'> $custom
 */
function testRelationshipFallbacks($missing, $nested, $union, $nonRelation, $unknown, $nonEmpty, $malformed, $custom): void
{
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $missing);
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $nested);
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $union);
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $nonRelation);
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $unknown);
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $nonEmpty);
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $malformed);
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $custom);
}

/**
 * @template TValue
 * @method HasMany<\App\Team, $this> teams()
 */
class ModelWithRelationships extends \App\User
{
    /** @return HasMany<GenericModel<TValue>, $this> */
    public function genericModels(): HasMany
    {
        throw new \LogicException();
    }

    /** @return MorphTo<\App\Post|\App\Team, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<\App\Account, $this>|null */
    public function nullableRelation(): ?HasMany
    {
        return null;
    }

    /** @return builder-of<static, 'accounts'> */
    public function accountBuilder(): Builder
    {
        return $this->accounts()->getQuery();
    }

    public function testInsideClass(): void
    {
        assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $this->accountBuilder());
    }
}

/**
 * @param builder-of<ModelWithRelationships<string>, 'genericModels'> $generic
 * @param builder-of<ModelWithRelationships<string>, 'subject'> $polymorphic
 * @param builder-of<ModelWithRelationships<string>, 'subject.comments'> $nestedUnion
 * @param builder-of<ModelWithRelationships<string>, 'teams'> $annotation
 * @param builder-of<ModelWithRelationships<string>, 'nullableRelation'> $nullableRelation
 * @param builder-of<\App\Comment, 'commentable'> $broad
 * @param builder-of<\App\Comment, 'commentable.comments'> $broadNested
 * @param builder-of<\App\User, 'syncableRelation'> $customRelation
 * @param ModelWithRelationships<string> $model
 */
function testRelatedModelTypes($generic, $polymorphic, $nestedUnion, $annotation, $nullableRelation, $broad, $broadNested, $customRelation, $model): void
{
    assertType('Hypervel\Database\Eloquent\Builder<BuilderOfType\GenericModel<string>>', $generic);
    assertType('App\ChildTeamBuilder|App\PostBuilder<App\Post>', $polymorphic);
    // Unlike upstream, a subject alternative without a comments relation is unknown.
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $nestedUnion);
    assertType('App\ChildTeamBuilder', $annotation);
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $nullableRelation);
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $broad);
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $broadNested);
    assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $customRelation);
    assertType('Hypervel\Database\Eloquent\Builder<App\Account>', $model->accountBuilder());
}

/**
 * @template TModel of Model
 * @template TRelation of string
 * @param class-string<TModel> $class
 * @param TRelation $relation
 * @return builder-of<TModel, TRelation>
 */
function genericRelationshipBuilder(string $class, string $relation): Builder
{
    throw new \LogicException();
}

/**
 * @template TRelation of string
 * @param builder-of<\App\User, TRelation> $builder
 */
function testUnresolvedRelationshipTemplate($builder): void
{
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', $builder);
}

/** @param 'posts'|'missing' $relation */
function testRelationshipTemplates(string $relation, string $unknown): void
{
    assertType('App\PostBuilder<App\Post>', genericRelationshipBuilder(\App\User::class, 'posts'));
    assertType('Hypervel\Database\Eloquent\Builder<App\Comment>', genericRelationshipBuilder(\App\User::class, 'posts.comments'));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', genericRelationshipBuilder(\App\User::class, $relation));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', genericRelationshipBuilder(\App\User::class, 'missing'));
    assertType('Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>', genericRelationshipBuilder(\App\User::class, $unknown));
}

/**
 * @param builder-of<mixed> $unknown
 * @param \Hypervel\Database\Eloquent\Relations\Relation<*, *, *> $relation
 * @param builder-of<\App\MissingModel> $missing
 */
function unknownModel($unknown, \Hypervel\Database\Eloquent\Relations\Relation $relation, Builder $query, $missing): void
{
    // Unlike upstream, which resolves to never, an unknown model's builder is unknown too.
    assertType('Hypervel\Database\Eloquent\Builder<*>', $unknown);
    assertType('Hypervel\Database\Eloquent\Builder<*>', $relation->getRelationExistenceCountQuery($query, $query));

    // Unlike upstream, which crashes analysis, a missing class is an unknown model.
    assertType('Hypervel\Database\Eloquent\Builder<*>', $missing);
}
