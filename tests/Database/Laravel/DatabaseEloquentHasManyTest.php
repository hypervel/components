<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel;

use Exception;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Database\UniqueConstraintViolationException;
use Hypervel\Tests\TestCase;
use Mockery as m;
use stdClass;

class DatabaseEloquentHasManyTest extends TestCase
{
    public function testMakeMethodDoesNotSaveNewModel()
    {
        $relation = $this->getRelation();
        $instance = $this->expectNewModel($relation, ['name' => 'taylor']);
        $instance->shouldReceive('save')->never();

        $this->assertEquals($instance, $relation->make(['name' => 'taylor']));
    }

    public function testMakeManyCreatesARelatedModelForEachRecord()
    {
        $records = [
            'taylor' => ['name' => 'taylor'],
            'colin' => ['name' => 'colin'],
        ];

        // Use concrete stub to properly test distinct instances and save() behavior
        EloquentHasManyRelatedStub::resetState();
        $relation = $this->getRelationWithConcreteRelated();

        $instances = $relation->makeMany($records);

        $this->assertInstanceOf(Collection::class, $instances);
        $this->assertCount(2, $instances);
        // Verify distinct instances were created (not the same object)
        $this->assertNotSame($instances[0], $instances[1]);
        // Verify save() was never called
        $this->assertFalse(EloquentHasManyRelatedStub::$saveCalled);
        // Verify foreign key was set on each instance
        $this->assertEquals(1, $instances[0]->getAttribute('foreign_key'));
        $this->assertEquals(1, $instances[1]->getAttribute('foreign_key'));
    }

    public function testCreateMethodProperlyCreatesNewModel()
    {
        $relation = $this->getRelation();
        $created = $this->expectCreatedModel($relation, ['name' => 'taylor']);

        $this->assertEquals($created, $relation->create(['name' => 'taylor']));
    }

    public function testForceCreateMethodProperlyCreatesNewModel()
    {
        $relation = $this->getRelation();
        $created = $this->expectForceCreatedModel($relation, ['name' => 'taylor']);

        $this->assertEquals($created, $relation->forceCreate(['name' => 'taylor']));
        $this->assertEquals(1, $created->getAttribute('foreign_key'));
    }

    public function testFindOrNewMethodFindsModel()
    {
        $relation = $this->getRelation();
        $relation->getQuery()->shouldReceive('find')->once()->with('foo', ['*'])->andReturn($model = m::mock(Model::class));
        $model->shouldReceive('setAttribute')->never();

        $this->assertInstanceOf(Model::class, $relation->findOrNew('foo'));
    }

    public function testFindOrNewMethodReturnsNewModelWithForeignKeySet()
    {
        $relation = $this->getRelation();
        $relation->getQuery()->shouldReceive('find')->once()->with('foo', ['*'])->andReturn(null);
        $relation->getRelated()->shouldReceive('newInstance')->once()->with()->andReturn($model = m::mock(Model::class));
        $model->shouldReceive('setAttribute')->once()->with('foreign_key', 1);

        $this->assertInstanceOf(Model::class, $relation->findOrNew('foo'));
    }

    public function testFirstOrNewMethodFindsFirstModel()
    {
        $relation = $this->getRelation();
        $relation->getQuery()->shouldReceive('where')->once()->with(['foo'])->andReturn($relation->getQuery());
        $relation->getQuery()->shouldReceive('first')->once()->with()->andReturn($model = m::mock(Model::class));
        $model->shouldReceive('setAttribute')->never();

        $this->assertInstanceOf(Model::class, $relation->firstOrNew(['foo']));
    }

    public function testFirstOrNewMethodWithValuesFindsFirstModel()
    {
        $relation = $this->getRelation();
        $relation->getQuery()->shouldReceive('where')->once()->with(['foo' => 'bar'])->andReturn($relation->getQuery());
        $relation->getQuery()->shouldReceive('first')->once()->with()->andReturn($model = m::mock(Model::class));
        $relation->getRelated()->shouldReceive('newInstance')->never();
        $model->shouldReceive('setAttribute')->never();

        $this->assertInstanceOf(Model::class, $relation->firstOrNew(['foo' => 'bar'], ['baz' => 'qux']));
    }

    public function testFirstOrNewMethodReturnsNewModelWithForeignKeySet()
    {
        $relation = $this->getRelation();
        $relation->getQuery()->shouldReceive('where')->once()->with(['foo'])->andReturn($relation->getQuery());
        $relation->getQuery()->shouldReceive('first')->once()->with()->andReturn(null);
        $model = $this->expectNewModel($relation, ['foo']);

        $this->assertEquals($model, $relation->firstOrNew(['foo']));
    }

    public function testFirstOrNewMethodWithValuesCreatesNewModelWithForeignKeySet()
    {
        $relation = $this->getRelation();
        $relation->getQuery()->shouldReceive('where')->once()->with(['foo' => 'bar'])->andReturn($relation->getQuery());
        $relation->getQuery()->shouldReceive('first')->once()->with()->andReturn(null);
        $model = $this->expectNewModel($relation, ['foo' => 'bar', 'baz' => 'qux']);

        $this->assertEquals($model, $relation->firstOrNew(['foo' => 'bar'], ['baz' => 'qux']));
    }

    public function testFirstOrCreateMethodFindsFirstModel()
    {
        $relation = $this->getRelation();
        $relation->getQuery()->shouldReceive('where')->once()->with(['foo'])->andReturn($relation->getQuery());
        $relation->getQuery()->shouldReceive('first')->once()->with()->andReturn($model = m::mock(Model::class));
        $relation->getRelated()->shouldReceive('newInstance')->never();
        $model->shouldReceive('setAttribute')->never();
        $model->shouldReceive('save')->never();

        $this->assertInstanceOf(Model::class, $relation->firstOrCreate(['foo']));
    }

    public function testFirstOrCreateMethodWithValuesFindsFirstModel()
    {
        $relation = $this->getRelation();
        $relation->getQuery()->shouldReceive('where')->once()->with(['foo' => 'bar'])->andReturn($relation->getQuery());
        $relation->getQuery()->shouldReceive('first')->once()->with()->andReturn($model = m::mock(Model::class));
        $relation->getRelated()->shouldReceive('newInstance')->never();
        $model->shouldReceive('setAttribute')->never();
        $model->shouldReceive('save')->never();

        $this->assertInstanceOf(Model::class, $relation->firstOrCreate(['foo' => 'bar'], ['baz' => 'qux']));
    }

    public function testFirstOrCreateMethodCreatesNewModelWithForeignKeySet()
    {
        $relation = $this->getRelation();
        $relation->getQuery()->shouldReceive('where')->once()->with(['foo'])->andReturn($relation->getQuery());
        $relation->getQuery()->shouldReceive('first')->once()->with()->andReturn(null);
        $relation->getQuery()->shouldReceive('withSavepointIfNeeded')->once()->andReturnUsing(fn ($scope) => $scope());
        $model = $this->expectCreatedModel($relation, ['foo']);

        $this->assertEquals($model, $relation->firstOrCreate(['foo']));
    }

    public function testFirstOrCreateMethodWithValuesCreatesNewModelWithForeignKeySet()
    {
        $relation = $this->getRelation();
        $relation->getQuery()->shouldReceive('where')->once()->with(['foo' => 'bar'])->andReturn($relation->getQuery());
        $relation->getQuery()->shouldReceive('first')->once()->with()->andReturn(null);
        $relation->getQuery()->shouldReceive('withSavepointIfNeeded')->once()->andReturnUsing(fn ($scope) => $scope());
        $model = $this->expectCreatedModel($relation, ['foo' => 'bar', 'baz' => 'qux']);

        $this->assertEquals($model, $relation->firstOrCreate(['foo' => 'bar'], ['baz' => 'qux']));
    }

    public function testCreateOrFirstMethodWithValuesFindsFirstModel()
    {
        $relation = $this->getRelation();

        $relation->getRelated()->shouldReceive('newInstance')->once()->with(['foo' => 'bar', 'baz' => 'qux'])->andReturn(m::mock(Model::class, function ($model) {
            $model->shouldReceive('setAttribute')->once()->with('foreign_key', 1);
            $model->shouldReceive('save')->once()->andThrow(
                new UniqueConstraintViolationException('mysql', 'example mysql', [], new Exception('SQLSTATE[23000]: Integrity constraint violation: 1062')),
            );
        }));

        $relation->getQuery()->shouldReceive('withSavepointIfNeeded')->once()->andReturnUsing(function ($scope) {
            return $scope();
        });
        $relation->getQuery()->shouldReceive('useWritePdo')->once()->andReturn($relation->getQuery());
        $relation->getQuery()->shouldReceive('where')->once()->with(['foo' => 'bar'])->andReturn($relation->getQuery());
        $relation->getQuery()->shouldReceive('first')->once()->with()->andReturn($model = m::mock(Model::class));

        $this->assertInstanceOf(Model::class, $found = $relation->createOrFirst(['foo' => 'bar'], ['baz' => 'qux']));
        $this->assertSame($model, $found);
    }

    public function testCreateOrFirstMethodCreatesNewModelWithForeignKeySet()
    {
        $relation = $this->getRelation();

        $relation->getQuery()->shouldReceive('withSavepointIfNeeded')->once()->andReturnUsing(function ($scope) {
            return $scope();
        });
        $relation->getQuery()->shouldReceive('where')->never();
        $relation->getQuery()->shouldReceive('first')->never();
        $model = $this->expectCreatedModel($relation, ['foo']);

        $this->assertEquals($model, $relation->createOrFirst(['foo']));
    }

    public function testCreateOrFirstMethodWithValuesCreatesNewModelWithForeignKeySet()
    {
        $relation = $this->getRelation();
        $relation->getQuery()->shouldReceive('withSavepointIfNeeded')->once()->andReturnUsing(function ($scope) {
            return $scope();
        });
        $relation->getQuery()->shouldReceive('where')->never();
        $relation->getQuery()->shouldReceive('first')->never();
        $model = $this->expectCreatedModel($relation, ['foo' => 'bar', 'baz' => 'qux']);

        $this->assertEquals($model, $relation->createOrFirst(['foo' => 'bar'], ['baz' => 'qux']));
    }

    public function testUpdateOrCreateMethodFindsFirstModelAndUpdates()
    {
        $relation = $this->getRelation();
        $relation->getQuery()->shouldReceive('where')->once()->with(['foo'])->andReturn($relation->getQuery());
        $relation->getQuery()->shouldReceive('first')->once()->with()->andReturn($model = m::mock(Model::class));
        $relation->getRelated()->shouldReceive('newInstance')->never();

        $model->wasRecentlyCreated = false;
        $model->shouldReceive('fill')->once()->with(['bar'])->andReturn($model);
        $model->shouldReceive('save')->once();

        $this->assertInstanceOf(Model::class, $relation->updateOrCreate(['foo'], ['bar']));
    }

    public function testUpdateOrCreateMethodCreatesNewModelWithForeignKeySet()
    {
        $relation = $this->getRelation();
        $relation->getQuery()->shouldReceive('withSavepointIfNeeded')->once()->andReturnUsing(function ($scope) {
            return $scope();
        });
        $relation->getQuery()->shouldReceive('where')->once()->with(['foo'])->andReturn($relation->getQuery());
        $relation->getQuery()->shouldReceive('first')->once()->with()->andReturn(null);
        $relation->getRelated()->shouldReceive('newInstance')->once()->with(['foo', 'bar'])->andReturn($model = m::mock(Model::class));

        $model->wasRecentlyCreated = true;
        $model->shouldReceive('save')->once()->andReturn(true);
        $model->shouldReceive('setAttribute')->once()->with('foreign_key', 1);

        $this->assertInstanceOf(Model::class, $relation->updateOrCreate(['foo'], ['bar']));
    }

    public function testRelationUpsertFillsForeignKey()
    {
        $relation = $this->getRelation();

        $relation->getQuery()->shouldReceive('upsert')->once()->with(
            [
                ['email' => 'foo3', 'name' => 'bar', $relation->getForeignKeyName() => $relation->getParentKey()],
            ],
            ['email'],
            ['name']
        )->andReturn(1);

        $relation->upsert(
            ['email' => 'foo3', 'name' => 'bar'],
            ['email'],
            ['name']
        );

        $relation->getQuery()->shouldReceive('upsert')->once()->with(
            [
                ['email' => 'foo3', 'name' => 'bar', $relation->getForeignKeyName() => $relation->getParentKey()],
                ['name' => 'bar2', 'email' => 'foo2', $relation->getForeignKeyName() => $relation->getParentKey()],
            ],
            ['email'],
            ['name']
        )->andReturn(2);

        $relation->upsert(
            [
                ['email' => 'foo3', 'name' => 'bar'],
                ['name' => 'bar2', 'email' => 'foo2'],
            ],
            ['email'],
            ['name']
        );
    }

    public function testRelationIsProperlyInitialized()
    {
        $relation = $this->getRelation();
        $model = m::mock(Model::class);
        $relation->getRelated()->shouldReceive('newCollection')->andReturnUsing(function ($array = []) {
            return new Collection($array);
        });
        $model->shouldReceive('setRelation')->once()->with('foo', m::type(Collection::class));
        $models = $relation->initRelation([$model], 'foo');

        $this->assertEquals([$model], $models);
    }

    public function testEagerConstraintsAreProperlyAdded()
    {
        $relation = $this->getRelation();
        $relation->getParent()->shouldReceive('getKeyName')->once()->andReturn('id');
        $relation->getParent()->shouldReceive('getKeyType')->once()->andReturn('int');
        $relation->getQuery()->shouldReceive('whereIntegerInRaw')->once()->with('table.foreign_key', [1, 2]);
        $model1 = new EloquentHasManyModelStub;
        $model1->id = 1;
        $model2 = new EloquentHasManyModelStub;
        $model2->id = 2;
        $relation->addEagerConstraints([$model1, $model2]);
    }

    public function testEagerConstraintsAreProperlyAddedWithStringKey()
    {
        $relation = $this->getRelation();
        $relation->getParent()->shouldReceive('getKeyName')->once()->andReturn('id');
        $relation->getParent()->shouldReceive('getKeyType')->once()->andReturn('string');
        $relation->getQuery()->shouldReceive('whereIn')->once()->with('table.foreign_key', [1, 2]);
        $model1 = new EloquentHasManyModelStub;
        $model1->id = 1;
        $model2 = new EloquentHasManyModelStub;
        $model2->id = 2;
        $relation->addEagerConstraints([$model1, $model2]);
    }

    public function testModelsAreProperlyMatchedToParents()
    {
        $relation = $this->getRelation();

        $result1 = new EloquentHasManyModelStub;
        $result1->foreign_key = 1;
        $result2 = new EloquentHasManyModelStub;
        $result2->foreign_key = 2;
        $result3 = new EloquentHasManyModelStub;
        $result3->foreign_key = 2;

        $model1 = new EloquentHasManyModelStub;
        $model1->id = 1;
        $model2 = new EloquentHasManyModelStub;
        $model2->id = 2;
        $model3 = new EloquentHasManyModelStub;
        $model3->id = 3;

        $relation->getRelated()->shouldReceive('newCollection')->andReturnUsing(function ($array) {
            return new Collection($array);
        });
        $models = $relation->match([$model1, $model2, $model3], new Collection([$result1, $result2, $result3]), 'foo');

        $this->assertEquals(1, $models[0]->foo[0]->foreign_key);
        $this->assertCount(1, $models[0]->foo);
        $this->assertEquals(2, $models[1]->foo[0]->foreign_key);
        $this->assertEquals(2, $models[1]->foo[1]->foreign_key);
        $this->assertCount(2, $models[1]->foo);
        $this->assertNull($models[2]->foo);
    }

    public function testCreateManyCreatesARelatedModelForEachRecord()
    {
        $records = [
            'taylor' => ['name' => 'taylor'],
            'colin' => ['name' => 'colin'],
        ];

        $relation = $this->getRelation();
        $relation->getRelated()->shouldReceive('newCollection')->once()->andReturn(new Collection);

        $taylor = $this->expectCreatedModel($relation, ['name' => 'taylor']);
        $colin = $this->expectCreatedModel($relation, ['name' => 'colin']);

        $instances = $relation->createMany($records);
        $this->assertInstanceOf(Collection::class, $instances);
        $this->assertEquals($taylor, $instances[0]);
        $this->assertEquals($colin, $instances[1]);
    }

    protected function getRelation()
    {
        $queryBuilder = m::mock(QueryBuilder::class);
        $builder = m::mock(Builder::class, [$queryBuilder]);
        $builder->shouldReceive('whereNotNull')->with('table.foreign_key');
        $builder->shouldReceive('where')->with('table.foreign_key', '=', 1);
        $related = m::mock(Model::class);
        $builder->shouldReceive('getModel')->andReturn($related);
        $parent = m::mock(Model::class);
        $parent->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $parent->shouldReceive('getCreatedAtColumn')->andReturn('created_at');
        $parent->shouldReceive('getUpdatedAtColumn')->andReturn('updated_at');

        return new HasMany($builder, $parent, 'table.foreign_key', 'id');
    }

    protected function getRelationWithConcreteRelated(): HasMany
    {
        $queryBuilder = m::mock(QueryBuilder::class);
        $builder = m::mock(Builder::class, [$queryBuilder]);
        $builder->shouldReceive('whereNotNull')->with('table.foreign_key');
        $builder->shouldReceive('where')->with('table.foreign_key', '=', 1);

        // Use concrete stub instead of mock
        $related = new EloquentHasManyRelatedStub;
        $builder->shouldReceive('getModel')->andReturn($related);

        $parent = m::mock(Model::class);
        $parent->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $parent->shouldReceive('getCreatedAtColumn')->andReturn('created_at');
        $parent->shouldReceive('getUpdatedAtColumn')->andReturn('updated_at');

        return new HasMany($builder, $parent, 'table.foreign_key', 'id');
    }

    protected function expectNewModel($relation, $attributes = null)
    {
        // Use andReturnSelf() to satisfy static return type of newInstance()
        $related = $relation->getRelated();
        $related->shouldReceive('newInstance')->once()->with($attributes)->andReturnSelf();
        $related->shouldReceive('setAttribute')->once()->with('foreign_key', 1)->andReturnSelf();

        return $related;
    }

    protected function expectCreatedModel($relation, $attributes)
    {
        // Use andReturnSelf() to satisfy static return type of newInstance()
        $related = $relation->getRelated();
        $related->shouldReceive('newInstance')->once()->with($attributes)->andReturnSelf();
        $related->shouldReceive('setAttribute')->once()->with('foreign_key', 1)->andReturnSelf();
        $related->shouldReceive('save')->once()->andReturn(true);

        return $related;
    }

    protected function expectForceCreatedModel($relation, $attributes)
    {
        $attributes[$relation->getForeignKeyName()] = $relation->getParentKey();

        $model = m::mock(Model::class);
        $model->shouldReceive('getAttribute')->with($relation->getForeignKeyName())->andReturn($relation->getParentKey());

        $relation->getRelated()->shouldReceive('forceCreate')->once()->with($attributes)->andReturn($model);

        return $model;
    }
}

class EloquentHasManyModelStub extends Model
{
    public string|int $foreign_key = 'foreign.value';
}

/**
 * Concrete test stub that tracks save() calls and returns distinct instances from newInstance().
 * Used to test makeMany() behavior where we need to verify distinct instances are created.
 */
class EloquentHasManyRelatedStub extends Model
{
    public static bool $saveCalled = false;

    public static function resetState(): void
    {
        static::$saveCalled = false;
    }

    public function newInstance(mixed $attributes = [], mixed $exists = false): static
    {
        $instance = new static;
        $instance->exists = $exists;
        $instance->setRawAttributes((array) $attributes, true);

        return $instance;
    }

    public function save(array $options = []): bool
    {
        static::$saveCalled = true;

        return true;
    }

    public function newCollection(array $models = []): Collection
    {
        return new Collection($models);
    }
}
