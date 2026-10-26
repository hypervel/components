<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseEloquentMorphTest;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphMany;
use Hypervel\Database\Eloquent\Relations\MorphOne;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PDO;

class DatabaseEloquentMorphTest extends TestCase
{
    public function testMorphOneSetsProperConstraints(): void
    {
        $relation = $this->getRelationWithRealQuery(MorphOne::class);

        $this->assertSame('select * from "reset_model_stubs" where "table"."morph_type" = ? and "table"."morph_id" = ? and "table"."morph_id" is not null', $relation->toSql());
        $this->assertSame([ResetModelStub::class, 1], $relation->getBindings());
    }

    public function testMorphOneEagerConstraintsAreProperlyAdded(): void
    {
        $parent = new ResetModelStub;
        $parent->setKeyType('string');
        $relation = $this->getRelationWithRealQuery(MorphOne::class, $parent);

        $model1 = new ResetModelStub;
        $model1->id = 1;
        $model2 = new ResetModelStub;
        $model2->id = 2;
        $relation->addEagerConstraints([$model1, $model2]);

        $this->assertSame('select * from "reset_model_stubs" where "table"."morph_type" = ? and "table"."morph_id" = ? and "table"."morph_id" is not null and "table"."morph_id" in (?, ?) and "table"."morph_type" = ?', $relation->toSql());
        $this->assertSame([ResetModelStub::class, '1', 1, 2, ResetModelStub::class], $relation->getBindings());
    }

    /**
     * Note that the tests are the exact same for morph many because the classes share this code...
     * Will still test to be safe.
     */
    public function testMorphManySetsProperConstraints(): void
    {
        $relation = $this->getRelationWithRealQuery(MorphMany::class);

        $this->assertSame('select * from "reset_model_stubs" where "table"."morph_type" = ? and "table"."morph_id" = ? and "table"."morph_id" is not null', $relation->toSql());
        $this->assertSame([ResetModelStub::class, 1], $relation->getBindings());
    }

    public function testMorphManyEagerConstraintsAreProperlyAdded(): void
    {
        $relation = $this->getRelationWithRealQuery(MorphMany::class);

        $model1 = new ResetModelStub;
        $model1->id = 1;
        $model2 = new ResetModelStub;
        $model2->id = 2;
        $relation->addEagerConstraints([$model1, $model2]);

        $this->assertSame('select * from "reset_model_stubs" where "table"."morph_type" = ? and "table"."morph_id" = ? and "table"."morph_id" is not null and "table"."morph_id" in (1, 2) and "table"."morph_type" = ?', $relation->toSql());
        $this->assertSame([ResetModelStub::class, 1, ResetModelStub::class], $relation->getBindings());
    }

    public function testMorphRelationUpsertFillsForeignKey(): void
    {
        $relation = $this->getManyRelation();

        $relation->getQuery()->expects('upsert')->with(
            [
                ['email' => 'foo3', 'name' => 'bar', $relation->getForeignKeyName() => $relation->getParentKey(), $relation->getMorphType() => $relation->getMorphClass()],
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
                ['email' => 'foo3', 'name' => 'bar', $relation->getForeignKeyName() => $relation->getParentKey(), $relation->getMorphType() => $relation->getMorphClass()],
                ['name' => 'bar2', 'email' => 'foo2', $relation->getForeignKeyName() => $relation->getParentKey(), $relation->getMorphType() => $relation->getMorphClass()],
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

    /**
     * Get a morph one or many relationship backed by a real query builder.
     *
     * @param class-string<MorphMany|MorphOne> $relation
     */
    protected function getRelationWithRealQuery(string $relation, ?Model $parent = null): MorphMany|MorphOne
    {
        $connection = new PdoConnection(new PDO('sqlite::memory:'));
        $builder = (new Builder(new QueryBuilder($connection, new Grammar($connection), new Processor)))->setModel(new ResetModelStub);

        $parent ??= new ResetModelStub;
        $parent->id = 1;

        return new $relation($builder, $parent, 'table.morph_type', 'table.morph_id', 'id');
    }

    protected function getManyRelation()
    {
        $builder = m::mock(Builder::class);
        $builder->expects('whereNotNull')->with('table.morph_id');
        $builder->expects('where')->with('table.morph_id', '=', 1);
        $related = new ResetModelStub;
        $builder->shouldReceive('getModel')->andReturn($related);
        $parent = m::mock(Model::class);
        $parent->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $parent->shouldReceive('getMorphClass')->andReturn(get_class($parent));
        $builder->expects('where')->with('table.morph_type', get_class($parent));

        return new MorphMany($builder, $parent, 'table.morph_type', 'table.morph_id', 'id');
    }
}

class ResetModelStub extends Model
{
}
