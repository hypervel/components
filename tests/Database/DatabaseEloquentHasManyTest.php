<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseEloquentHasManyTest;

use Closure;
use Exception;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Database\UniqueConstraintViolationException;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\MockInterface;

class DatabaseEloquentHasManyTest extends TestCase
{
    public function testMakeMethodDoesNotSaveNewModel()
    {
        $relation = $this->getRelation();
        $instance = $this->expectNewModel($relation, ['name' => 'taylor']);
        $instance->shouldReceive('save')->never();

        $this->assertEquals($instance, $relation->make(['name' => 'taylor']));
    }

    public function testMakeManyCreatesARelatedModelForEachRecord(): void
    {
        $records = [
            'taylor' => ['name' => 'taylor'],
            'colin' => ['name' => 'colin'],
        ];

        $relation = $this->getRelation();
        $relation->getRelated()->expects('newCollection')->andReturn(new Collection);

        $taylor = $this->expectNewModel($relation, ['name' => 'taylor']);
        $taylor->shouldReceive('save')->never();
        $colin = $this->expectNewModel($relation, ['name' => 'colin']);
        $colin->shouldReceive('save')->never();

        $instances = $relation->makeMany($records);

        $this->assertInstanceOf(Collection::class, $instances);
        $this->assertCount(2, $instances);
        $this->assertNotSame($instances[0], $instances[1]);
        $this->assertSame($taylor, $instances[0]);
        $this->assertSame($colin, $instances[1]);
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

    public function testFindOrNewMethodFindsModel(): void
    {
        $relation = $this->getRelation();
        $model = m::mock(Model::class);
        $relation->getQuery()->expects('find')->with('foo', ['*'])->andReturn($model);
        $model->shouldReceive('setAttribute')->never();

        $this->assertInstanceOf(Model::class, $relation->findOrNew('foo'));
    }

    public function testFindOrNewMethodReturnsNewModelWithForeignKeySet(): void
    {
        $relation = $this->getRelation();
        $relation->getQuery()->shouldReceive('find')->once()->with('foo', ['*'])->andReturn(null);
        $model = m::mock(Model::class);
        $relation->getRelated()->expects('newInstance')->with()->andReturn($model);
        $model->shouldReceive('setAttribute')->once()->with('foreign_key', 1);

        $this->assertInstanceOf(Model::class, $relation->findOrNew('foo'));
    }

    public function testFirstOrNewMethodFindsFirstModel(): void
    {
        $relation = $this->getRelation();
        $relation->getQuery()->shouldReceive('where')->once()->with(['foo'])->andReturn($relation->getQuery());
        $model = m::mock(Model::class);
        $relation->getQuery()->expects('first')->with()->andReturn($model);
        $model->shouldReceive('setAttribute')->never();

        $this->assertInstanceOf(Model::class, $relation->firstOrNew(['foo']));
    }

    public function testFirstOrNewMethodWithValuesFindsFirstModel(): void
    {
        $relation = $this->getRelation();
        $relation->getQuery()->shouldReceive('where')->once()->with(['foo' => 'bar'])->andReturn($relation->getQuery());
        $model = m::mock(Model::class);
        $relation->getQuery()->expects('first')->with()->andReturn($model);
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

    public function testFirstOrCreateMethodFindsFirstModel(): void
    {
        $relation = $this->getRelation();
        $relation->getQuery()->shouldReceive('where')->once()->with(['foo'])->andReturn($relation->getQuery());
        $model = m::mock(Model::class);
        $relation->getQuery()->expects('first')->with()->andReturn($model);
        $relation->getRelated()->shouldReceive('newInstance')->never();
        $model->shouldReceive('setAttribute')->never();
        $model->shouldReceive('save')->never();

        $this->assertInstanceOf(Model::class, $relation->firstOrCreate(['foo']));
    }

    public function testFirstOrCreateMethodWithValuesFindsFirstModel(): void
    {
        $relation = $this->getRelation();
        $relation->getQuery()->shouldReceive('where')->once()->with(['foo' => 'bar'])->andReturn($relation->getQuery());
        $model = m::mock(Model::class);
        $relation->getQuery()->expects('first')->with()->andReturn($model);
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

    public function testCreateOrFirstMethodWithValuesFindsFirstModel(): void
    {
        $relation = $this->getRelation();

        $relation->getRelated()->shouldReceive('newInstance')->once()->with(['foo' => 'bar', 'baz' => 'qux'])->andReturn(m::mock(Model::class, function (MockInterface $model): void {
            $model->shouldReceive('setAttribute')->once()->with('foreign_key', 1);
            $model->shouldReceive('save')->once()->andThrow(
                new UniqueConstraintViolationException('mysql', 'example mysql', [], new Exception('SQLSTATE[23000]: Integrity constraint violation: 1062')),
            );
        }));

        $relation->getQuery()->shouldReceive('withSavepointIfNeeded')->once()->andReturnUsing(function (Closure $scope): Model {
            return $scope();
        });
        $relation->getQuery()->shouldReceive('useWritePdo')->once()->andReturn($relation->getQuery());
        $relation->getQuery()->shouldReceive('where')->once()->with(['foo' => 'bar'])->andReturn($relation->getQuery());
        $model = m::mock(Model::class);
        $relation->getQuery()->expects('first')->with()->andReturn($model);

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

    public function testUpdateOrCreateMethodFindsFirstModelAndUpdates(): void
    {
        $relation = $this->getRelation();
        $relation->getQuery()->shouldReceive('where')->once()->with(['foo'])->andReturn($relation->getQuery());
        $model = m::mock(Model::class);
        $relation->getQuery()->expects('first')->with()->andReturn($model);
        $relation->getRelated()->shouldReceive('newInstance')->never();

        $model->wasRecentlyCreated = false;
        $model->shouldReceive('fill')->once()->with(['bar'])->andReturn($model);
        $model->shouldReceive('save')->once();

        $this->assertInstanceOf(Model::class, $relation->updateOrCreate(['foo'], ['bar']));
    }

    public function testUpdateOrCreateMethodCreatesNewModelWithForeignKeySet(): void
    {
        $relation = $this->getRelation();
        $relation->getQuery()->shouldReceive('withSavepointIfNeeded')->once()->andReturnUsing(function (Closure $scope): Model {
            return $scope();
        });
        $relation->getQuery()->shouldReceive('where')->once()->with(['foo'])->andReturn($relation->getQuery());
        $relation->getQuery()->shouldReceive('first')->once()->with()->andReturn(null);
        $model = m::mock(Model::class);
        $relation->getRelated()->expects('newInstance')->with(['foo', 'bar'])->andReturn($model);

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

    public function testRelationIsProperlyInitialized(): void
    {
        $relation = $this->getRelation();
        $model = m::mock(Model::class);
        $relation->getRelated()->expects('newCollection')->andReturnUsing(function (array $array = []): Collection {
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
        $model1 = new ModelStub;
        $model1->id = 1;
        $model2 = new ModelStub;
        $model2->id = 2;
        $relation->addEagerConstraints([$model1, $model2]);
    }

    public function testEagerConstraintsAreProperlyAddedWithStringKey()
    {
        $relation = $this->getRelation();
        $relation->getParent()->shouldReceive('getKeyName')->once()->andReturn('id');
        $relation->getParent()->shouldReceive('getKeyType')->once()->andReturn('string');
        $relation->getQuery()->shouldReceive('whereIn')->once()->with('table.foreign_key', [1, 2]);
        $model1 = new ModelStub;
        $model1->id = 1;
        $model2 = new ModelStub;
        $model2->id = 2;
        $relation->addEagerConstraints([$model1, $model2]);
    }

    public function testModelsAreProperlyMatchedToParents(): void
    {
        $relation = $this->getRelation();

        $result1 = new ModelStub;
        $result1->foreign_key = 1;
        $result2 = new ModelStub;
        $result2->foreign_key = 2;
        $result3 = new ModelStub;
        $result3->foreign_key = 2;

        $model1 = new ModelStub;
        $model1->id = 1;
        $model2 = new ModelStub;
        $model2->id = 2;
        $model3 = new ModelStub;
        $model3->id = 3;

        $relation->getRelated()->expects('newCollection')->times(2)->andReturnUsing(function (array $array): Collection {
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

    public function testCreateManyCreatesARelatedModelForEachRecord(): void
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
        $this->assertSame($taylor, $instances[0]);
        $this->assertSame($colin, $instances[1]);
    }

    protected function getRelation()
    {
        $queryBuilder = m::mock(QueryBuilder::class);
        $builder = m::mock(Builder::class, [$queryBuilder]);
        $builder->shouldReceive('ensureCanCreateOrFirst')->passthru();
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

    /**
     * Expect a new related model with its foreign key set.
     */
    protected function expectNewModel(HasMany $relation, ?array $attributes = null): Model
    {
        $model = m::mock(Model::class);
        $relation->getRelated()->expects('newInstance')->with($attributes)->andReturn($model);
        $model->expects('setAttribute')->with('foreign_key', 1)->andReturnSelf();

        return $model;
    }

    /**
     * Expect a related model to be created.
     */
    protected function expectCreatedModel(HasMany $relation, array $attributes): Model
    {
        $model = $this->expectNewModel($relation, $attributes);
        $model->expects('save')->andReturn(true);

        return $model;
    }

    /**
     * Expect a related model to be created without guarding attributes.
     */
    protected function expectForceCreatedModel(HasMany $relation, array $attributes): Model
    {
        $attributes[$relation->getForeignKeyName()] = $relation->getParentKey();

        $model = m::mock(Model::class);
        $model->expects('getAttribute')->with($relation->getForeignKeyName())->andReturn($relation->getParentKey());

        $relation->getRelated()->shouldReceive('forceCreate')->once()->with($attributes)->andReturn($model);

        return $model;
    }
}

class ModelStub extends Model
{
    public string|int $foreign_key = 'foreign.value';
}
