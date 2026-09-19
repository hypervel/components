<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Closure;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\RelationNotFoundException;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\Concerns\SupportsInverseRelations;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Support\Stringable;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionFunction;

class DatabaseEloquentInverseRelationTest extends TestCase
{
    public function testBuilderCallbackIsNotAppliedWhenInverseRelationIsNotSet(): void
    {
        $builder = m::mock(Builder::class);
        $builder->expects('getModel')->andReturn(new HasInverseRelationRelatedStub);
        $builder->shouldReceive('afterQuery')->never();

        new HasInverseRelationStub($builder, new HasInverseRelationParentStub);
    }

    public function testBuilderCallbackIsNotSetIfInverseRelationIsEmptyString(): void
    {
        $builder = m::mock(Builder::class);
        $builder->expects('getModel')->times(2)->andReturn(new HasInverseRelationRelatedStub);
        $builder->shouldReceive('afterQuery')->never();

        $this->expectException(RelationNotFoundException::class);

        (new HasInverseRelationStub($builder, new HasInverseRelationParentStub))->inverse('');
    }

    public function testBuilderCallbackIsNotSetIfInverseRelationshipDoesNotExist(): void
    {
        $builder = m::mock(Builder::class);
        $builder->expects('getModel')->times(3)->andReturn(new HasInverseRelationRelatedStub);
        $builder->shouldReceive('afterQuery')->never();

        $this->expectException(RelationNotFoundException::class);

        (new HasInverseRelationStub($builder, new HasInverseRelationParentStub))->inverse('foo');
    }

    public function testWithoutInverseMethodRemovesInverseRelation(): void
    {
        $builder = m::mock(Builder::class);
        $builder->expects('getModel')->times(2)->andReturn(new HasInverseRelationRelatedStub);
        $builder->expects('afterQuery')->andReturnSelf();

        $relation = (new HasInverseRelationStub($builder, new HasInverseRelationParentStub));
        $this->assertNull($relation->getInverseRelationship());

        $relation->inverse('test');
        $this->assertSame('test', $relation->getInverseRelationship());

        $relation->withoutInverse();
        $this->assertNull($relation->getInverseRelationship());
    }

    public function testBuilderCallbackIsAppliedWhenInverseRelationIsSet(): void
    {
        $parent = new HasInverseRelationParentStub;

        $builder = m::mock(Builder::class);
        $builder->expects('getModel')->times(2)->andReturn(new HasInverseRelationRelatedStub);
        $builder->expects('afterQuery')->withArgs(function (Closure $callback) use ($parent): bool {
            $relation = (new ReflectionFunction($callback))->getClosureThis();

            return $relation instanceof HasInverseRelationStub && $relation->getParent() === $parent;
        })->andReturnSelf();

        (new HasInverseRelationStub($builder, $parent))->inverse('test');
    }

    public function testBuilderCallbackAppliesInverseRelationToAllModelsInResult(): void
    {
        $builder = m::mock(Builder::class);
        $builder->expects('getModel')->times(2)->andReturn(new HasInverseRelationRelatedStub);

        // Capture the callback so that we can manually call it.
        $afterQuery = null;
        $builder->expects('afterQuery')->withArgs(function (Closure $callback) use (&$afterQuery): bool {
            return (bool) $afterQuery = $callback;
        })->andReturnSelf();

        $parent = new HasInverseRelationParentStub;
        (new HasInverseRelationStub($builder, $parent))->inverse('test');

        $results = Collection::times(5, fn (): HasInverseRelationRelatedStub => new HasInverseRelationRelatedStub);

        foreach ($results as $model) {
            $this->assertEmpty($model->getRelations());
            $this->assertFalse($model->relationLoaded('test'));
        }

        $results = $afterQuery($results);

        foreach ($results as $model) {
            $this->assertNotEmpty($model->getRelations());
            $this->assertTrue($model->relationLoaded('test'));
            $this->assertSame($parent, $model->test);
        }
    }

    public function testInverseRelationIsNotSetIfInverseRelationIsUnset(): void
    {
        $builder = m::mock(Builder::class);
        $builder->expects('getModel')->times(2)->andReturn(new HasInverseRelationRelatedStub);

        // Capture the callback so that we can manually call it.
        $afterQuery = null;
        $builder->expects('afterQuery')->withArgs(function (Closure $callback) use (&$afterQuery): bool {
            return (bool) $afterQuery = $callback;
        })->andReturnSelf();

        $parent = new HasInverseRelationParentStub;
        $relation = (new HasInverseRelationStub($builder, $parent));
        $relation->inverse('test');

        $results = Collection::times(5, fn (): HasInverseRelationRelatedStub => new HasInverseRelationRelatedStub);
        foreach ($results as $model) {
            $this->assertEmpty($model->getRelations());
        }
        $results = $afterQuery($results);
        foreach ($results as $model) {
            $this->assertNotEmpty($model->getRelations());
            $this->assertSame($parent, $model->getRelation('test'));
        }

        // Reset the inverse relation
        $relation->withoutInverse();

        $results = Collection::times(5, fn (): HasInverseRelationRelatedStub => new HasInverseRelationRelatedStub);
        foreach ($results as $model) {
            $this->assertEmpty($model->getRelations());
        }
        $results = $afterQuery($results);
        foreach ($results as $model) {
            $this->assertEmpty($model->getRelations());
        }
    }

    public function testProvidesPossibleInverseRelationBasedOnParent()
    {
        $builder = m::mock(Builder::class);
        $builder->shouldReceive('getModel')->andReturn(new InverseRelationChildModel);

        $relation = (new HasInverseRelationStub($builder, new HasInverseRelationParentStub));

        $possibleRelations = ['hasInverseRelationParentStub', 'parentStub', 'owner'];
        $this->assertSame($possibleRelations, array_values($relation->exposeGetPossibleInverseRelations()));
    }

    public function testProvidesPossibleInverseRelationBasedOnForeignKey(): void
    {
        $builder = m::mock(Builder::class);
        $builder->expects('getModel')->times(2)->andReturn(new HasInverseRelationParentStub);

        $relation = (new HasInverseRelationStub($builder, new HasInverseRelationParentStub, 'test_id'));

        $this->assertContains('test', $relation->exposeGetPossibleInverseRelations());
    }

    public function testProvidesPossibleRecursiveRelationsIfRelatedIsTheSameClassAsParent(): void
    {
        $builder = m::mock(Builder::class);
        $builder->expects('getModel')->times(2)->andReturn(new HasInverseRelationParentStub);

        $relation = (new HasInverseRelationStub($builder, new HasInverseRelationParentStub));

        $this->assertContains('parent', $relation->exposeGetPossibleInverseRelations());
    }

    #[DataProvider('guessedParentRelationsDataProvider')]
    public function testGuessesInverseRelationBasedOnParent($guessedRelation)
    {
        $related = m::mock(Model::class);
        $related->shouldReceive('isRelation')->andReturnUsing(fn ($relation) => $relation === $guessedRelation);

        $builder = m::mock(Builder::class);
        $builder->shouldReceive('getModel')->andReturn($related);

        $relation = (new HasInverseRelationStub($builder, new HasInverseRelationParentStub));

        $this->assertSame($guessedRelation, $relation->exposeGuessInverseRelation());
    }

    public function testGuessesPossibleInverseRelationBasedOnForeignKey(): void
    {
        $related = m::mock(Model::class);
        $related->expects('isRelation')->andReturnUsing(fn (string $relation): bool => $relation === 'test');

        $builder = m::mock(Builder::class);
        $builder->expects('getModel')->times(3)->andReturn($related);

        $relation = (new HasInverseRelationStub($builder, new HasInverseRelationParentStub, 'test_id'));

        $this->assertSame('test', $relation->exposeGuessInverseRelation());
    }

    public function testGuessesRecursiveInverseRelationsIfRelatedIsSameClassAsParent(): void
    {
        $related = m::mock(Model::class);
        $related->expects('isRelation')->times(4)->andReturnUsing(fn (string $relation): bool => $relation === 'parent');

        $parent = m::mock(Model::class);
        $parent->expects('getForeignKey')->andReturn('recursive_parent_id');
        $parent->expects('getKeyName')->twice()->andReturn('id');

        $builder = m::mock(Builder::class);
        $builder->expects('getModel')->times(6)->andReturn($related);

        $relation = (new HasInverseRelationStub($builder, $parent));

        $this->assertSame('parent', $relation->exposeGuessInverseRelation());
    }

    #[DataProvider('guessedParentRelationsDataProvider')]
    public function testSetsGuessedInverseRelationBasedOnParent($guessedRelation)
    {
        $related = m::mock(Model::class);
        $related->shouldReceive('isRelation')->andReturnUsing(fn ($relation) => $relation === $guessedRelation);

        $builder = m::mock(Builder::class);
        $builder->shouldReceive('getModel')->andReturn($related);
        $builder->expects('afterQuery')->andReturnSelf();

        $relation = (new HasInverseRelationStub($builder, new HasInverseRelationParentStub))->inverse();

        $this->assertSame($guessedRelation, $relation->getInverseRelationship());
    }

    public static function guessedParentRelationsDataProvider()
    {
        yield ['hasInverseRelationParentStub'];
        yield ['parentStub'];
        yield ['owner'];
    }

    public function testSetsRecursiveInverseRelationsIfRelatedIsSameClassAsParent(): void
    {
        $related = m::mock(Model::class);
        $related->expects('isRelation')->times(5)->andReturnUsing(fn (string $relation): bool => $relation === 'parent');

        $parent = m::mock(Model::class);
        $parent->expects('getForeignKey')->andReturn('recursive_parent_id');
        $parent->expects('getKeyName')->twice()->andReturn('id');

        $builder = m::mock(Builder::class);
        $builder->expects('getModel')->times(7)->andReturn($related);
        $builder->expects('afterQuery')->andReturnSelf();

        $relation = (new HasInverseRelationStub($builder, $parent))->inverse();

        $this->assertSame('parent', $relation->getInverseRelationship());
    }

    public function testSetsGuessedInverseRelationBasedOnForeignKey(): void
    {
        $related = m::mock(Model::class);
        $related->expects('isRelation')->times(2)->andReturnUsing(fn (string $relation): bool => $relation === 'test');

        $builder = m::mock(Builder::class);
        $builder->expects('getModel')->times(4)->andReturn($related);
        $builder->expects('afterQuery')->andReturnSelf();

        $relation = (new HasInverseRelationStub($builder, new HasInverseRelationParentStub, 'test_id'))->inverse();

        $this->assertSame('test', $relation->getInverseRelationship());
    }

    public function testOnlyHydratesInverseRelationOnModels(): void
    {
        $relation = m::mock(HasInverseRelationStub::class)->shouldAllowMockingProtectedMethods()->makePartial();
        $relation->expects('getParent')->andReturn(new HasInverseRelationParentStub);
        $relation->expects('applyInverseRelationToModel')->times(6);
        $relation->exposeApplyInverseRelationToCollection([
            new HasInverseRelationRelatedStub,
            12345,
            new HasInverseRelationRelatedStub,
            new HasInverseRelationRelatedStub,
            Model::class,
            new HasInverseRelationRelatedStub,
            true,
            [],
            new HasInverseRelationRelatedStub,
            'foo',
            new class {
            },
            new HasInverseRelationRelatedStub,
        ]);
    }
}

class HasInverseRelationParentStub extends Model
{
    protected static bool $unguarded = true;

    protected string $primaryKey = 'id';

    public function getForeignKey(): string
    {
        return 'parent_stub_id';
    }
}

class HasInverseRelationRelatedStub extends Model
{
    protected static bool $unguarded = true;

    protected string $primaryKey = 'id';

    public function getForeignKey(): string
    {
        return 'child_stub_id';
    }

    public function test(): BelongsTo
    {
        return $this->belongsTo(HasInverseRelationParentStub::class);
    }
}

class HasInverseRelationStub extends Relation
{
    use SupportsInverseRelations;

    public function __construct(
        Builder $query,
        Model $parent,
        protected ?string $foreignKey = null,
    ) {
        parent::__construct($query, $parent);
        $this->foreignKey ??= (new Stringable(class_basename($parent)))->snake()->finish('_id')->toString();
    }

    public function getForeignKeyName(): ?string
    {
        return $this->foreignKey;
    }

    // None of these methods will actually be called - they're just needed to fill out `Relation`
    public function match(array $models, Collection $results, $relation): array
    {
        return $models;
    }

    public function initRelation(array $models, $relation): array
    {
        return $models;
    }

    public function getResults(): mixed
    {
        return $this->query->get();
    }

    public function addConstraints(): void
    {
    }

    public function addEagerConstraints(array $models): void
    {
    }

    // Expose access to protected methods for testing
    public function exposeGetPossibleInverseRelations(): array
    {
        return $this->getPossibleInverseRelations();
    }

    public function exposeGuessInverseRelation(): ?string
    {
        return $this->guessInverseRelation();
    }

    public function exposeApplyInverseRelationToCollection($models, ?Model $parent = null)
    {
        return $this->applyInverseRelationToCollection($models, $parent);
    }
}

/**
 * Local stub for InverseRelationChildModel (originally from DatabaseEloquentInverseRelationHasOneTest).
 */
class InverseRelationChildModel extends Model
{
    protected ?string $table = 'test_child';
}
