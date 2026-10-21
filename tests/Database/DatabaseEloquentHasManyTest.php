<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseEloquentHasManyTest;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Tests\TestCase;
use Mockery as m;

class DatabaseEloquentHasManyTest extends TestCase
{
    public function testRelationUpsertFillsForeignKey(): void
    {
        $relation = $this->getRelation();

        $relation->getQuery()->expects('upsert')->with(
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

        $relation->getQuery()->expects('upsert')->with(
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
        $model->expects('setRelation')->with('foo', m::type(Collection::class));
        $models = $relation->initRelation([$model], 'foo');

        $this->assertEquals([$model], $models);
    }

    public function testEagerConstraintsAreProperlyAdded(): void
    {
        $relation = $this->getRelation();
        $relation->getParent()->expects('getKeyName')->andReturn('id');
        $relation->getParent()->expects('getKeyType')->andReturn('int');
        $relation->getQuery()->expects('whereIntegerInRaw')->with('table.foreign_key', [1, 2]);
        $model1 = new ModelStub;
        $model1->id = 1;
        $model2 = new ModelStub;
        $model2->id = 2;
        $relation->addEagerConstraints([$model1, $model2]);
    }

    public function testEagerConstraintsAreProperlyAddedWithStringKey(): void
    {
        $relation = $this->getRelation();
        $relation->getParent()->expects('getKeyName')->andReturn('id');
        $relation->getParent()->expects('getKeyType')->andReturn('string');
        $relation->getQuery()->expects('whereIn')->with('table.foreign_key', [1, 2]);
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
}

class ModelStub extends Model
{
    public string|int $foreign_key = 'foreign.value';
}
