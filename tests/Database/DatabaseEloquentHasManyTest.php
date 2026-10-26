<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseEloquentHasManyTest;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PDO;

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
        $model = new ModelStub;
        $relation->getRelated()->expects('newCollection')->andReturnUsing(function (array $array = []): Collection {
            return new Collection($array);
        });
        $models = $relation->initRelation([$model], 'foo');

        $this->assertEquals([$model], $models);
        $this->assertInstanceOf(Collection::class, $model->getRelation('foo'));
        $this->assertCount(0, $model->getRelation('foo'));
    }

    public function testEagerConstraintsAreProperlyAdded(): void
    {
        $relation = $this->getRelationWithRealQuery();
        $model1 = new ModelStub;
        $model1->id = 1;
        $model2 = new ModelStub;
        $model2->id = 2;
        $relation->addEagerConstraints([$model1, $model2]);

        $this->assertSame('select * from "model_stubs" where "table"."foreign_key" = ? and "table"."foreign_key" is not null and "table"."foreign_key" in (1, 2)', $relation->toSql());
        $this->assertSame([1], $relation->getBindings());
    }

    public function testEagerConstraintsAreProperlyAddedWithStringKey(): void
    {
        $parent = new ModelStub;
        $parent->setKeyType('string');
        $relation = $this->getRelationWithRealQuery($parent);
        $model1 = new ModelStub;
        $model1->id = 1;
        $model2 = new ModelStub;
        $model2->id = 2;
        $relation->addEagerConstraints([$model1, $model2]);

        $this->assertSame('select * from "model_stubs" where "table"."foreign_key" = ? and "table"."foreign_key" is not null and "table"."foreign_key" in (?, ?)', $relation->toSql());
        $this->assertSame(['1', 1, 2], $relation->getBindings());
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

    /**
     * Get a has many relationship backed by a real query builder.
     */
    protected function getRelationWithRealQuery(?Model $parent = null): HasMany
    {
        $connection = new PdoConnection(new PDO('sqlite::memory:'));
        $query = new QueryBuilder($connection, new Grammar($connection), new Processor);
        $builder = (new Builder($query))->setModel(new ModelStub);

        $parent ??= new ModelStub;
        $parent->id = 1;

        return new HasMany($builder, $parent, 'table.foreign_key', 'id');
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
