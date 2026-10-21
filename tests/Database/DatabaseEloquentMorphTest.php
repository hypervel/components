<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseEloquentMorphTest;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphMany;
use Hypervel\Database\Eloquent\Relations\MorphOne;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Tests\TestCase;
use Mockery as m;

class DatabaseEloquentMorphTest extends TestCase
{
    public function testMorphOneSetsProperConstraints(): void
    {
        $this->getOneRelation();
    }

    public function testMorphOneEagerConstraintsAreProperlyAdded(): void
    {
        $relation = $this->getOneRelation();
        $relation->getParent()->expects('getKeyName')->andReturn('id');
        $relation->getParent()->expects('getKeyType')->andReturn('string');
        $relation->getQuery()->expects('whereIn')->with('table.morph_id', [1, 2]);
        $relation->getQuery()->expects('where')->with('table.morph_type', get_class($relation->getParent()));

        $model1 = new ResetModelStub;
        $model1->id = 1;
        $model2 = new ResetModelStub;
        $model2->id = 2;
        $relation->addEagerConstraints([$model1, $model2]);
    }

    /**
     * Note that the tests are the exact same for morph many because the classes share this code...
     * Will still test to be safe.
     */
    public function testMorphManySetsProperConstraints(): void
    {
        $this->getManyRelation();
    }

    public function testMorphManyEagerConstraintsAreProperlyAdded(): void
    {
        $relation = $this->getManyRelation();
        $relation->getParent()->expects('getKeyName')->andReturn('id');
        $relation->getParent()->expects('getKeyType')->andReturn('int');
        $relation->getQuery()->expects('whereIntegerInRaw')->with('table.morph_id', [1, 2]);
        $relation->getQuery()->expects('where')->with('table.morph_type', get_class($relation->getParent()));

        $model1 = new ResetModelStub;
        $model1->id = 1;
        $model2 = new ResetModelStub;
        $model2->id = 2;
        $relation->addEagerConstraints([$model1, $model2]);
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

    protected function getOneRelation()
    {
        $queryBuilder = m::mock(QueryBuilder::class);
        $builder = m::mock(Builder::class, [$queryBuilder]);
        $builder->expects('whereNotNull')->with('table.morph_id');
        $builder->expects('where')->with('table.morph_id', '=', 1);
        $related = m::mock(Model::class);
        $builder->shouldReceive('getModel')->andReturn($related);
        $parent = m::mock(Model::class);
        $parent->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $parent->shouldReceive('getMorphClass')->andReturn(get_class($parent));
        $builder->expects('where')->with('table.morph_type', get_class($parent));

        return new MorphOne($builder, $parent, 'table.morph_type', 'table.morph_id', 'id');
    }

    protected function getManyRelation()
    {
        $builder = m::mock(Builder::class);
        $builder->expects('whereNotNull')->with('table.morph_id');
        $builder->expects('where')->with('table.morph_id', '=', 1);
        $related = m::mock(Model::class);
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
