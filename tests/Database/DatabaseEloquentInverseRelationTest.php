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
use Hypervel\Database\SQLiteConnection;
use Hypervel\Support\Stringable;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionFunction;

class DatabaseEloquentInverseRelationTest extends TestCase
{
    use VerifiesDoubles;

    public function testBuilderCallbackIsNotAppliedWhenInverseRelationIsNotSet(): void
    {
        $builder = Double::for(Builder::class);
        $builder->expects('getModel')->returns(new HasInverseRelationRelatedStub);
        $builder->expects('afterQuery')->never();

        new HasInverseRelationStub($builder, new HasInverseRelationParentStub);
    }

    public function testBuilderCallbackIsNotSetIfInverseRelationIsEmptyString(): void
    {
        $builder = Double::for(Builder::class);
        $builder->expects('getModel')->times(2)->returns(new HasInverseRelationRelatedStub);
        $builder->expects('afterQuery')->never();

        $this->expectException(RelationNotFoundException::class);

        (new HasInverseRelationStub($builder, new HasInverseRelationParentStub))->inverse('');
    }

    public function testBuilderCallbackIsNotSetIfInverseRelationshipDoesNotExist(): void
    {
        $builder = Double::for(Builder::class);
        $builder->expects('getModel')->times(3)->returns(new HasInverseRelationRelatedStub);
        $builder->expects('afterQuery')->never();

        $this->expectException(RelationNotFoundException::class);

        (new HasInverseRelationStub($builder, new HasInverseRelationParentStub))->inverse('foo');
    }

    public function testWithoutInverseMethodRemovesInverseRelation(): void
    {
        $builder = Double::for(Builder::class);
        $builder->expects('getModel')->times(2)->returns(new HasInverseRelationRelatedStub);
        $builder->expects('afterQuery')->returns($builder);

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

        $builder = Double::for(Builder::class);
        $builder->expects('getModel')->times(2)->returns(new HasInverseRelationRelatedStub);
        $builder->expects('afterQuery')->with(Argument::satisfies(function (Closure $callback) use ($parent): bool {
            $relation = (new ReflectionFunction($callback))->getClosureThis();

            return $relation instanceof HasInverseRelationStub && $relation->getParent() === $parent;
        }))->returns($builder);

        (new HasInverseRelationStub($builder, $parent))->inverse('test');
    }

    public function testBuilderCallbackAppliesInverseRelationToAllModelsInResult(): void
    {
        $builder = Double::for(Builder::class);
        $builder->expects('getModel')->times(2)->returns(new HasInverseRelationRelatedStub);

        // Capture the callback so that we can manually call it.
        $afterQuery = null;
        $builder->expects('afterQuery')->with(Argument::satisfies(function (Closure $callback) use (&$afterQuery): bool {
            return (bool) $afterQuery = $callback;
        }))->returns($builder);

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
        $builder = Double::for(Builder::class);
        $builder->expects('getModel')->times(2)->returns(new HasInverseRelationRelatedStub);

        // Capture the callback so that we can manually call it.
        $afterQuery = null;
        $builder->expects('afterQuery')->with(Argument::satisfies(function (Closure $callback) use (&$afterQuery): bool {
            return (bool) $afterQuery = $callback;
        }))->returns($builder);

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

    public function testProvidesPossibleInverseRelationBasedOnParent(): void
    {
        $builder = Double::for(Builder::class);
        $builder->allows('getModel')->returns(new HasInverseRelationRelatedStub);

        $relation = (new HasInverseRelationStub($builder, new HasInverseRelationParentStub));

        $possibleRelations = ['hasInverseRelationParentStub', 'parentStub', 'owner'];
        $this->assertSame($possibleRelations, array_values($relation->exposeGetPossibleInverseRelations()));
    }

    public function testProvidesPossibleInverseRelationBasedOnForeignKey(): void
    {
        $builder = Double::for(Builder::class);
        $builder->expects('getModel')->times(2)->returns(new HasInverseRelationParentStub);

        $relation = (new HasInverseRelationStub($builder, new HasInverseRelationParentStub, 'test_id'));

        $this->assertContains('test', $relation->exposeGetPossibleInverseRelations());
    }

    public function testProvidesPossibleRecursiveRelationsIfRelatedIsTheSameClassAsParent(): void
    {
        $builder = Double::for(Builder::class);
        $builder->expects('getModel')->times(2)->returns(new HasInverseRelationParentStub);

        $relation = (new HasInverseRelationStub($builder, new HasInverseRelationParentStub));

        $this->assertContains('parent', $relation->exposeGetPossibleInverseRelations());
    }

    #[DataProvider('guessedParentRelationsDataProvider')]
    public function testGuessesInverseRelationBasedOnParent($guessedRelation): void
    {
        $related = Double::for(Model::class);
        $related->allows('isRelation')->resolves(fn ($relation) => $relation === $guessedRelation);

        $builder = Double::for(Builder::class);
        $builder->allows('getModel')->returns($related);

        $relation = (new HasInverseRelationStub($builder, new HasInverseRelationParentStub));

        $this->assertSame($guessedRelation, $relation->exposeGuessInverseRelation());
    }

    public function testGuessesPossibleInverseRelationBasedOnForeignKey(): void
    {
        $related = Double::for(Model::class);
        $related->expects('isRelation')->resolves(fn (string $relation): bool => $relation === 'test');

        $builder = Double::for(Builder::class);
        $builder->expects('getModel')->times(3)->returns($related);

        $relation = (new HasInverseRelationStub($builder, new HasInverseRelationParentStub, 'test_id'));

        $this->assertSame('test', $relation->exposeGuessInverseRelation());
    }

    public function testGuessesRecursiveInverseRelationsIfRelatedIsSameClassAsParent(): void
    {
        $related = Double::for(Model::class);
        $related->expects('isRelation')->times(4)->resolves(fn (string $relation): bool => $relation === 'parent');

        $parent = Double::for(Model::class);
        $parent->expects('getForeignKey')->returns('recursive_parent_id');
        $parent->expects('getKeyName')->times(2)->returns('id');

        $builder = Double::for(Builder::class);
        $builder->expects('getModel')->times(6)->returns($related);

        $relation = (new HasInverseRelationStub($builder, $parent));

        $this->assertSame('parent', $relation->exposeGuessInverseRelation());
    }

    #[DataProvider('guessedParentRelationsDataProvider')]
    public function testSetsGuessedInverseRelationBasedOnParent($guessedRelation): void
    {
        $related = Double::for(Model::class);
        $related->allows('isRelation')->resolves(fn ($relation) => $relation === $guessedRelation);

        $builder = Double::for(Builder::class);
        $builder->allows('getModel')->returns($related);
        $builder->expects('afterQuery')->returns($builder);

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
        $related = Double::for(Model::class);
        $related->expects('isRelation')->times(5)->resolves(fn (string $relation): bool => $relation === 'parent');

        $parent = Double::for(Model::class);
        $parent->expects('getForeignKey')->returns('recursive_parent_id');
        $parent->expects('getKeyName')->times(2)->returns('id');

        $builder = Double::for(Builder::class);
        $builder->expects('getModel')->times(7)->returns($related);
        $builder->expects('afterQuery')->returns($builder);

        $relation = (new HasInverseRelationStub($builder, $parent))->inverse();

        $this->assertSame('parent', $relation->getInverseRelationship());
    }

    public function testSetsGuessedInverseRelationBasedOnForeignKey(): void
    {
        $related = Double::for(Model::class);
        $related->expects('isRelation')->times(2)->resolves(fn (string $relation): bool => $relation === 'test');

        $builder = Double::for(Builder::class);
        $builder->expects('getModel')->times(4)->returns($related);
        $builder->expects('afterQuery')->returns($builder);

        $relation = (new HasInverseRelationStub($builder, new HasInverseRelationParentStub, 'test_id'))->inverse();

        $this->assertSame('test', $relation->getInverseRelationship());
    }

    public function testOnlyHydratesInverseRelationOnModels(): void
    {
        $builder = (new Builder((new SQLiteConnection(new PDO('sqlite::memory:')))->query()))->setModel(new HasInverseRelationRelatedStub);
        $parent = new HasInverseRelationParentStub;
        $relation = (new HasInverseRelationStub($builder, $parent))->inverse('test');

        $models = array_map(fn (): HasInverseRelationRelatedStub => new HasInverseRelationRelatedStub, range(1, 6));
        $relation->exposeApplyInverseRelationToCollection([
            $models[0],
            12345,
            $models[1],
            $models[2],
            Model::class,
            $models[3],
            true,
            [],
            $models[4],
            'foo',
            new class {
            },
            $models[5],
        ]);

        foreach ($models as $model) {
            $this->assertTrue($model->relationLoaded('test'));
            $this->assertSame($parent, $model->test);
        }
    }
}

class HasInverseRelationParentStub extends Model
{
    protected static bool $unguarded = true;

    protected ?string $primaryKey = 'id';

    public function getForeignKey(): string
    {
        return 'parent_stub_id';
    }
}

class HasInverseRelationRelatedStub extends Model
{
    protected static bool $unguarded = true;

    protected ?string $primaryKey = 'id';

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
