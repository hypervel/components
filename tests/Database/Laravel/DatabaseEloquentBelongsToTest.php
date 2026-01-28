<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Tests\Database\Laravel\Fixtures\Enums\Bar;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class DatabaseEloquentBelongsToTest extends TestCase
{
    protected $builder;

    protected $related;

    public function testBelongsToWithDefault()
    {
        // Use partial mock so newInstance() can return self (satisfies static return type)
        // while still having real Model behavior for attribute handling
        $relation = $this->getRelationWithPartialMock()->withDefault();

        $this->builder->shouldReceive('first')->once()->andReturnNull();
        $this->related->shouldReceive('newInstance')->once()->andReturnSelf();

        $result = $relation->getResults();

        $this->assertSame($this->related, $result);
    }

    public function testBelongsToWithDynamicDefault()
    {
        $relation = $this->getRelationWithPartialMock()->withDefault(function ($newModel) {
            $newModel->username = 'taylor';
        });

        $this->builder->shouldReceive('first')->once()->andReturnNull();
        $this->related->shouldReceive('newInstance')->once()->andReturnSelf();

        $result = $relation->getResults();

        $this->assertSame($this->related, $result);
        // Partial mock has real Model attribute behavior, so this actually tests the callback worked
        $this->assertSame('taylor', $result->username);
    }

    public function testBelongsToWithArrayDefault()
    {
        $relation = $this->getRelationWithPartialMock()->withDefault(['username' => 'taylor']);

        $this->builder->shouldReceive('first')->once()->andReturnNull();
        $this->related->shouldReceive('newInstance')->once()->andReturnSelf();

        $result = $relation->getResults();

        $this->assertSame($this->related, $result);
        // Partial mock has real Model attribute behavior, so this actually tests forceFill worked
        $this->assertSame('taylor', $result->username);
    }

    public function testEagerConstraintsAreProperlyAdded()
    {
        $relation = $this->getRelation();
        $relation->getRelated()->shouldReceive('getKeyName')->andReturn('id');
        $relation->getRelated()->shouldReceive('getKeyType')->andReturn('int');
        $relation->getQuery()->shouldReceive('whereIntegerInRaw')->once()->with('relation.id', ['foreign.value', 'foreign.value.two']);
        $models = [new EloquentBelongsToModelStub(), new EloquentBelongsToModelStub(), new AnotherEloquentBelongsToModelStub()];
        $relation->addEagerConstraints($models);
    }

    public function testIdsInEagerConstraintsCanBeZero()
    {
        $relation = $this->getRelation();
        $relation->getRelated()->shouldReceive('getKeyName')->andReturn('id');
        $relation->getRelated()->shouldReceive('getKeyType')->andReturn('int');
        $relation->getQuery()->shouldReceive('whereIntegerInRaw')->once()->with('relation.id', [0, 'foreign.value']);
        $models = [new EloquentBelongsToModelStub(), new EloquentBelongsToModelStubWithZeroId()];
        $relation->addEagerConstraints($models);
    }

    public function testIdsInEagerConstraintsCanBeBackedEnum()
    {
        $relation = $this->getRelation();
        $relation->getRelated()->shouldReceive('getKeyName')->andReturn('id');
        $relation->getRelated()->shouldReceive('getKeyType')->andReturn('int');
        $relation->getQuery()->shouldReceive('whereIntegerInRaw')->once()->with('relation.id', [5, 'foreign.value']);
        $models = [new EloquentBelongsToModelStub(), new EloquentBelongsToModelStubWithBackedEnumCast()];
        $relation->addEagerConstraints($models);
    }

    public function testRelationIsProperlyInitialized()
    {
        $relation = $this->getRelation();
        $model = m::mock(Model::class);
        $model->shouldReceive('setRelation')->once()->with('foo', null);
        $models = $relation->initRelation([$model], 'foo');

        $this->assertEquals([$model], $models);
    }

    public function testModelsAreProperlyMatchedToParents()
    {
        $relation = $this->getRelation();

        $result1 = new class extends Model {
            protected array $attributes = ['id' => 1];
        };

        $result2 = new class extends Model {
            protected array $attributes = ['id' => 2];
        };

        $result3 = new class extends Model {
            protected array $attributes = ['id' => 3];

            public function __toString()
            {
                return '3';
            }
        };

        $result4 = new class extends Model {
            protected array $casts = [
                'id' => Bar::class,
            ];

            protected array $attributes = ['id' => 5];
        };

        $model1 = new EloquentBelongsToModelStub();
        $model1->foreign_key = 1;
        $model2 = new EloquentBelongsToModelStub();
        $model2->foreign_key = 2;
        $model3 = new EloquentBelongsToModelStub();
        $model3->foreign_key = new class {
            public function __toString()
            {
                return '3';
            }
        };
        $model4 = new EloquentBelongsToModelStub();
        $model4->foreign_key = 5;
        $models = $relation->match(
            [$model1, $model2, $model3, $model4],
            new Collection([$result1, $result2, $result3, $result4]),
            'foo'
        );

        $this->assertEquals(1, $models[0]->foo->getAttribute('id'));
        $this->assertEquals(2, $models[1]->foo->getAttribute('id'));
        $this->assertSame('3', (string) $models[2]->foo->getAttribute('id'));
        $this->assertEquals(5, $models[3]->foo->getAttribute('id')->value);
    }

    public function testAssociateMethodSetsForeignKeyOnModel()
    {
        $parent = m::mock(Model::class);
        $parent->shouldReceive('getAttribute')->once()->with('foreign_key')->andReturn('foreign.value');
        $relation = $this->getRelation($parent);
        $associate = m::mock(Model::class);
        $associate->shouldReceive('getAttribute')->once()->with('id')->andReturn(1);
        $parent->shouldReceive('setAttribute')->once()->with('foreign_key', 1);
        $parent->shouldReceive('setRelation')->once()->with('relation', $associate);

        $relation->associate($associate);
    }

    public function testDissociateMethodUnsetsForeignKeyOnModel()
    {
        $parent = m::mock(Model::class);
        $parent->shouldReceive('getAttribute')->once()->with('foreign_key')->andReturn('foreign.value');
        $relation = $this->getRelation($parent);
        $parent->shouldReceive('setAttribute')->once()->with('foreign_key', null);

        // Always set relation when we received Model
        $parent->shouldReceive('setRelation')->once()->with('relation', null);

        $relation->dissociate();
    }

    public function testAssociateMethodSetsForeignKeyOnModelById()
    {
        $parent = m::mock(Model::class);
        $parent->shouldReceive('getAttribute')->once()->with('foreign_key')->andReturn('foreign.value');
        $relation = $this->getRelation($parent);
        $parent->shouldReceive('setAttribute')->once()->with('foreign_key', 1);

        // Always unset relation when we received id, regardless of dirtiness
        $parent->shouldReceive('isDirty')->never();
        $parent->shouldReceive('unsetRelation')->once()->with($relation->getRelationName());

        $relation->associate(1);
    }

    public function testDefaultEagerConstraintsWhenIncrementing()
    {
        $relation = $this->getRelation();
        $relation->getRelated()->shouldReceive('getKeyName')->andReturn('id');
        $relation->getRelated()->shouldReceive('getKeyType')->andReturn('int');
        $relation->getQuery()->shouldReceive('whereIntegerInRaw')->once()->with('relation.id', m::mustBe([]));
        $models = [new MissingEloquentBelongsToModelStub(), new MissingEloquentBelongsToModelStub()];
        $relation->addEagerConstraints($models);
    }

    public function testDefaultEagerConstraintsWhenIncrementingAndNonIntKeyType()
    {
        $relation = $this->getRelation(null, 'string');
        $relation->getQuery()->shouldReceive('whereIn')->once()->with('relation.id', m::mustBe([]));
        $models = [new MissingEloquentBelongsToModelStub(), new MissingEloquentBelongsToModelStub()];
        $relation->addEagerConstraints($models);
    }

    public function testDefaultEagerConstraintsWhenNotIncrementing()
    {
        $relation = $this->getRelation();
        $relation->getRelated()->shouldReceive('getKeyName')->andReturn('id');
        $relation->getRelated()->shouldReceive('getKeyType')->andReturn('int');
        $relation->getQuery()->shouldReceive('whereIntegerInRaw')->once()->with('relation.id', m::mustBe([]));
        $models = [new MissingEloquentBelongsToModelStub(), new MissingEloquentBelongsToModelStub()];
        $relation->addEagerConstraints($models);
    }

    public function testIsNotNull()
    {
        $relation = $this->getRelation();

        $this->related->shouldReceive('getConnectionName')->never();

        $this->assertFalse($relation->is(null));
    }

    public function testIsModel()
    {
        $relation = $this->getRelation();

        $this->related->shouldReceive('getConnectionName')->once()->andReturn('relation');

        $model = m::mock(Model::class);
        $model->shouldReceive('getAttribute')->once()->with('id')->andReturn('foreign.value');
        $model->shouldReceive('getTable')->once()->andReturn('relation');
        $model->shouldReceive('getConnectionName')->once()->andReturn('relation');

        $this->assertTrue($relation->is($model));
    }

    public function testIsModelWithIntegerParentKey()
    {
        $parent = m::mock(Model::class);

        // when addConstraints is called we need to return the foreign value
        $parent->shouldReceive('getAttribute')->once()->with('foreign_key')->andReturn('foreign.value');
        // when getParentKey is called we want to return an integer
        $parent->shouldReceive('getAttribute')->once()->with('foreign_key')->andReturn(1);

        $relation = $this->getRelation($parent);

        $this->related->shouldReceive('getConnectionName')->once()->andReturn('relation');

        $model = m::mock(Model::class);
        $model->shouldReceive('getAttribute')->once()->with('id')->andReturn('1');
        $model->shouldReceive('getTable')->once()->andReturn('relation');
        $model->shouldReceive('getConnectionName')->once()->andReturn('relation');

        $this->assertTrue($relation->is($model));
    }

    public function testIsModelWithIntegerRelatedKey()
    {
        $parent = m::mock(Model::class);

        // when addConstraints is called we need to return the foreign value
        $parent->shouldReceive('getAttribute')->once()->with('foreign_key')->andReturn('foreign.value');
        // when getParentKey is called we want to return a string
        $parent->shouldReceive('getAttribute')->once()->with('foreign_key')->andReturn('1');

        $relation = $this->getRelation($parent);

        $this->related->shouldReceive('getConnectionName')->once()->andReturn('relation');

        $model = m::mock(Model::class);
        $model->shouldReceive('getAttribute')->once()->with('id')->andReturn(1);
        $model->shouldReceive('getTable')->once()->andReturn('relation');
        $model->shouldReceive('getConnectionName')->once()->andReturn('relation');

        $this->assertTrue($relation->is($model));
    }

    public function testIsModelWithIntegerKeys()
    {
        $parent = m::mock(Model::class);

        // when addConstraints is called we need to return the foreign value
        $parent->shouldReceive('getAttribute')->once()->with('foreign_key')->andReturn('foreign.value');
        // when getParentKey is called we want to return an integer
        $parent->shouldReceive('getAttribute')->once()->with('foreign_key')->andReturn(1);

        $relation = $this->getRelation($parent);

        $this->related->shouldReceive('getConnectionName')->once()->andReturn('relation');

        $model = m::mock(Model::class);
        $model->shouldReceive('getAttribute')->once()->with('id')->andReturn(1);
        $model->shouldReceive('getTable')->once()->andReturn('relation');
        $model->shouldReceive('getConnectionName')->once()->andReturn('relation');

        $this->assertTrue($relation->is($model));
    }

    public function testIsNotModelWithNullParentKey()
    {
        $parent = m::mock(Model::class);

        // when addConstraints is called we need to return the foreign value
        $parent->shouldReceive('getAttribute')->once()->with('foreign_key')->andReturn('foreign.value');
        // when getParentKey is called we want to return null
        $parent->shouldReceive('getAttribute')->once()->with('foreign_key')->andReturn(null);

        $relation = $this->getRelation($parent);

        $this->related->shouldReceive('getConnectionName')->never();

        $model = m::mock(Model::class);
        $model->shouldReceive('getAttribute')->once()->with('id')->andReturn('foreign.value');
        $model->shouldReceive('getTable')->never();
        $model->shouldReceive('getConnectionName')->never();

        $this->assertFalse($relation->is($model));
    }

    public function testIsNotModelWithNullRelatedKey()
    {
        $relation = $this->getRelation();

        $this->related->shouldReceive('getConnectionName')->never();

        $model = m::mock(Model::class);
        $model->shouldReceive('getAttribute')->once()->with('id')->andReturn(null);
        $model->shouldReceive('getTable')->never();
        $model->shouldReceive('getConnectionName')->never();

        $this->assertFalse($relation->is($model));
    }

    public function testIsNotModelWithAnotherKey()
    {
        $relation = $this->getRelation();

        $this->related->shouldReceive('getConnectionName')->never();

        $model = m::mock(Model::class);
        $model->shouldReceive('getAttribute')->once()->with('id')->andReturn('foreign.value.two');
        $model->shouldReceive('getTable')->never();
        $model->shouldReceive('getConnectionName')->never();

        $this->assertFalse($relation->is($model));
    }

    public function testIsNotModelWithAnotherTable()
    {
        $relation = $this->getRelation();

        $this->related->shouldReceive('getConnectionName')->never();

        $model = m::mock(Model::class);
        $model->shouldReceive('getAttribute')->once()->with('id')->andReturn('foreign.value');
        $model->shouldReceive('getTable')->once()->andReturn('table.two');
        $model->shouldReceive('getConnectionName')->never();

        $this->assertFalse($relation->is($model));
    }

    public function testIsNotModelWithAnotherConnection()
    {
        $relation = $this->getRelation();

        $this->related->shouldReceive('getConnectionName')->once()->andReturn('relation');

        $model = m::mock(Model::class);
        $model->shouldReceive('getAttribute')->once()->with('id')->andReturn('foreign.value');
        $model->shouldReceive('getTable')->once()->andReturn('relation');
        $model->shouldReceive('getConnectionName')->once()->andReturn('relation.two');

        $this->assertFalse($relation->is($model));
    }

    protected function getRelation($parent = null, $keyType = 'int')
    {
        $this->builder = m::mock(Builder::class);
        $this->builder->shouldReceive('where')->with('relation.id', '=', 'foreign.value');
        $this->related = m::mock(Model::class);
        $this->related->shouldReceive('getKeyType')->andReturn($keyType);
        $this->related->shouldReceive('getKeyName')->andReturn('id');
        $this->related->shouldReceive('getTable')->andReturn('relation');
        $this->related->shouldReceive('qualifyColumn')->andReturnUsing(fn (string $column) => "relation.{$column}");
        $this->builder->shouldReceive('getModel')->andReturn($this->related);
        $parent = $parent ?: new EloquentBelongsToModelStub();

        return new BelongsTo($this->builder, $parent, 'foreign_key', 'id', 'relation');
    }

    /**
     * Get relation with a partial mock for the related model.
     *
     * Used for withDefault tests that need real Model attribute behavior.
     * The partial mock satisfies strict `static` return types on newInstance()
     * while retaining real __set/__get behavior for attribute assertions.
     * @param null|mixed $parent
     */
    protected function getRelationWithPartialMock($parent = null)
    {
        $this->builder = m::mock(Builder::class);
        $this->builder->shouldReceive('where')->with('relation.id', '=', 'foreign.value');
        $this->related = m::mock(EloquentBelongsToModelStub::class)->makePartial();
        $this->related->shouldReceive('getKeyType')->andReturn('int');
        $this->related->shouldReceive('getKeyName')->andReturn('id');
        $this->related->shouldReceive('getTable')->andReturn('relation');
        $this->related->shouldReceive('qualifyColumn')->andReturnUsing(fn (string $column) => "relation.{$column}");
        $this->builder->shouldReceive('getModel')->andReturn($this->related);
        $parent = $parent ?: new EloquentBelongsToModelStub();

        return new BelongsTo($this->builder, $parent, 'foreign_key', 'id', 'relation');
    }
}

class EloquentBelongsToModelStub extends Model
{
    public $foreign_key = 'foreign.value';
}

class AnotherEloquentBelongsToModelStub extends Model
{
    public $foreign_key = 'foreign.value.two';
}

class EloquentBelongsToModelStubWithZeroId extends Model
{
    public $foreign_key = 0;
}

class MissingEloquentBelongsToModelStub extends Model
{
    public $foreign_key;
}

class EloquentBelongsToModelStubWithBackedEnumCast extends Model
{
    protected array $casts = [
        'foreign_key' => Bar::class,
    ];

    protected array $attributes = [
        'foreign_key' => 5,
    ];
}
