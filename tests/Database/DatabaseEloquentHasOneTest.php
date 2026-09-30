<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseEloquentHasOneTest;

use Hypervel\Contracts\Database\Query\Expression;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\HasOne;
use Hypervel\Database\Query\Builder as BaseBuilder;
use Hypervel\Tests\TestCase;
use Mockery as m;

class DatabaseEloquentHasOneTest extends TestCase
{
    protected $builder;

    protected $related;

    protected $parent;

    public function testHasOneWithDefault(): void
    {
        $relation = $this->getRelation()->withDefault();

        $this->builder->expects('first')->andReturnNull();

        // Use andReturnSelf() to satisfy static return type of newInstance()
        $this->related->expects('newInstance')->andReturnSelf();
        $this->related->expects('setAttribute')->with('foreign_key', 1)->passthru();

        $result = $relation->getResults();
        $this->assertSame($this->related, $result);
        $this->assertSame(1, $result->getAttribute('foreign_key'));
    }

    public function testHasOneWithDynamicDefault(): void
    {
        $relation = $this->getRelation()->withDefault(function (Model $newModel): void {
            $newModel->username = 'taylor';
        });

        $this->builder->expects('first')->andReturnNull();

        // Use andReturnSelf() to satisfy static return type of newInstance()
        $this->related->expects('newInstance')->andReturnSelf();
        $this->related->expects('setAttribute')->with('foreign_key', 1)->passthru();

        $result = $relation->getResults();
        $this->assertSame($this->related, $result);
        $this->assertSame('taylor', $result->username);
        $this->assertSame(1, $result->getAttribute('foreign_key'));
    }

    public function testHasOneWithDynamicDefaultUseParentModel(): void
    {
        $relation = $this->getRelation()->withDefault(function (Model $newModel, Model $parentModel): void {
            $newModel->username = $parentModel->username;
        });

        $this->builder->expects('first')->andReturnNull();

        // Use andReturnSelf() to satisfy static return type of newInstance()
        $this->related->expects('newInstance')->andReturnSelf();
        $this->related->expects('setAttribute')->with('foreign_key', 1)->passthru();

        $result = $relation->getResults();
        $this->assertSame($this->related, $result);
        $this->assertSame('taylor', $result->username);
        $this->assertSame(1, $result->getAttribute('foreign_key'));
    }

    public function testHasOneWithArrayDefault(): void
    {
        $attributes = ['username' => 'taylor'];

        $relation = $this->getRelation()->withDefault($attributes);

        $this->builder->expects('first')->andReturnNull();

        // Use andReturnSelf() to satisfy static return type of newInstance()
        $this->related->expects('newInstance')->andReturnSelf();
        $this->related->expects('setAttribute')->with('foreign_key', 1)->passthru();

        $result = $relation->getResults();
        $this->assertSame($this->related, $result);
        $this->assertSame('taylor', $result->username);
        $this->assertSame(1, $result->getAttribute('foreign_key'));
    }

    public function testRelationIsProperlyInitialized()
    {
        $relation = $this->getRelation();
        $model = m::mock(Model::class);
        $model->expects('setRelation')->with('foo', null);
        $models = $relation->initRelation([$model], 'foo');

        $this->assertEquals([$model], $models);
    }

    public function testEagerConstraintsAreProperlyAdded()
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

    public function testModelsAreProperlyMatchedToParents()
    {
        $relation = $this->getRelation();

        $result1 = new ModelStub;
        $result1->foreign_key = 1;
        $result2 = new ModelStub;
        $result2->foreign_key = 2;
        $result3 = new ModelStub;
        $result3->foreign_key = new class {
            public function __toString()
            {
                return '4';
            }
        };

        $model1 = new ModelStub;
        $model1->id = 1;
        $model2 = new ModelStub;
        $model2->id = 2;
        $model3 = new ModelStub;
        $model3->id = 3;
        $model4 = new ModelStub;
        $model4->id = 4;

        $models = $relation->match([$model1, $model2, $model3, $model4], new Collection([$result1, $result2, $result3]), 'foo');

        $this->assertEquals(1, $models[0]->foo->foreign_key);
        $this->assertEquals(2, $models[1]->foo->foreign_key);
        $this->assertNull($models[2]->foo);
        $this->assertSame('4', (string) $models[3]->foo->foreign_key);
    }

    public function testRelationCountQueryCanBeBuilt(): void
    {
        $relation = $this->getRelation();
        $builder = m::mock(Builder::class);

        $baseQuery = m::mock(BaseBuilder::class);
        $baseQuery->from = 'one';
        $parentQuery = m::mock(BaseBuilder::class);
        $parentQuery->from = 'two';

        $builder->expects('getQuery')->andReturn($baseQuery);
        $builder->expects('getQuery')->andReturn($parentQuery);

        $builder->expects('select')->with(m::type(Expression::class))->andReturnSelf();
        $relation->getParent()->expects('qualifyColumn')->andReturn('table.id');
        // Return $builder (Eloquent Builder) to satisfy return type
        $builder->expects('whereColumn')->with('table.id', '=', 'table.foreign_key')->andReturnSelf();
        // setBindings is called on the Eloquent Builder, which forwards to base query
        $builder->expects('setBindings')->with([], 'select')->andReturnSelf();

        $relation->getRelationExistenceCountQuery($builder, $builder);
    }

    /**
     * Get the has one relationship.
     */
    protected function getRelation(): HasOne
    {
        $this->builder = m::mock(Builder::class);
        $this->builder->shouldReceive('whereNotNull')->with('table.foreign_key');
        $this->builder->shouldReceive('where')->with('table.foreign_key', '=', 1);
        // Use partial mock so real Model methods work (setAttribute, forceFill, etc.)
        $this->related = m::mock(Model::class)->makePartial();
        $this->builder->shouldReceive('getModel')->andReturn($this->related);
        $this->parent = m::mock(Model::class);
        $this->parent->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $this->parent->shouldReceive('getAttribute')->with('username')->andReturn('taylor');
        $this->parent->shouldReceive('getCreatedAtColumn')->andReturn('created_at');
        $this->parent->shouldReceive('getUpdatedAtColumn')->andReturn('updated_at');
        $this->parent->shouldReceive('newQueryWithoutScopes')->andReturn($this->builder);

        return new HasOne($this->builder, $this->parent, 'table.foreign_key', 'id');
    }
}

class ModelStub extends Model
{
    public mixed $foreign_key = 'foreign.value';
}
