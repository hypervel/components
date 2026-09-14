<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseEloquentBelongsToTest;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Tests\Database\Fixtures\Enums\Bar;
use Hypervel\Tests\TestCase;
use Mockery as m;

class DatabaseEloquentBelongsToTest extends TestCase
{
    protected Builder $builder;

    protected Model $related;

    public function testBelongsToWithDefault(): void
    {
        // Use partial mock so newInstance() can return self (satisfies static return type)
        // while still having real Model behavior for attribute handling
        $relation = $this->getRelationWithPartialMock()->withDefault();

        $this->builder->expects('first')->andReturnNull();
        $this->related->expects('newInstance')->andReturnSelf();

        $result = $relation->getResults();

        $this->assertSame($this->related, $result);
    }

    public function testBelongsToWithDynamicDefault(): void
    {
        $relation = $this->getRelationWithPartialMock()->withDefault(function (Model $newModel): void {
            $newModel->username = 'taylor';
        });

        $this->builder->expects('first')->andReturnNull();
        $this->related->expects('newInstance')->andReturnSelf();

        $result = $relation->getResults();

        $this->assertSame($this->related, $result);
        // Partial mock has real Model attribute behavior, so this actually tests the callback worked
        $this->assertSame('taylor', $result->username);
    }

    public function testBelongsToWithArrayDefault(): void
    {
        $relation = $this->getRelationWithPartialMock()->withDefault(['username' => 'taylor']);

        $this->builder->expects('first')->andReturnNull();
        $this->related->expects('newInstance')->andReturnSelf();

        $result = $relation->getResults();

        $this->assertSame($this->related, $result);
        // Partial mock has real Model attribute behavior, so this actually tests forceFill worked
        $this->assertSame('taylor', $result->username);
    }

    public function testEagerConstraintsAreProperlyAdded(): void
    {
        $relation = $this->getRelation();
        $relation->getQuery()->expects('whereIntegerInRaw')->with('relation.id', ['foreign.value', 'foreign.value.two']);
        $models = [new ModelStub, new ModelStub, new AnotherModelStub];
        $relation->addEagerConstraints($models);
    }

    public function testIdsInEagerConstraintsCanBeZero(): void
    {
        $relation = $this->getRelation();
        $relation->getQuery()->expects('whereIntegerInRaw')->with('relation.id', [0, 'foreign.value']);
        $models = [new ModelStub, new ModelStubWithZeroId];
        $relation->addEagerConstraints($models);
    }

    public function testIdsInEagerConstraintsCanBeBackedEnum(): void
    {
        $relation = $this->getRelation();
        $relation->getQuery()->expects('whereIntegerInRaw')->with('relation.id', [5, 'foreign.value']);
        $models = [new ModelStub, new ModelStubWithBackedEnumCast];
        $relation->addEagerConstraints($models);
    }

    public function testRelationIsProperlyInitialized(): void
    {
        $relation = $this->getRelation();
        $model = m::mock(Model::class);
        $model->expects('setRelation')->with('foo', null);
        $models = $relation->initRelation([$model], 'foo');

        $this->assertEquals([$model], $models);
    }

    public function testModelsAreProperlyMatchedToParents(): void
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

            /**
             * Get the string representation of the model.
             */
            public function __toString(): string
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

        $model1 = new ModelStub;
        $model1->foreign_key = 1;
        $model2 = new ModelStub;
        $model2->foreign_key = 2;
        $model3 = new ModelStub;
        $model3->foreign_key = new class {
            /**
             * Get the string representation of the key.
             */
            public function __toString(): string
            {
                return '3';
            }
        };
        $model4 = new ModelStub;
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

    public function testModelsWithNullRelatedKeysAreNotMatchedToEmptyStringForeignKeys(): void
    {
        $relation = $this->getRelation();

        $result = new class extends Model {
            protected array $attributes = ['id' => null];
        };

        $model = new ModelStub;
        $model->foreign_key = '';

        $relation->match([$model], new Collection([$result]), 'foo');

        $this->assertFalse($model->relationLoaded('foo'));
    }

    public function testModelsWithNullForeignKeysAreNotMatchedToEmptyStringRelatedKeys(): void
    {
        $relation = $this->getRelation();

        $result = new class extends Model {
            protected string $keyType = 'string';

            protected array $attributes = ['id' => ''];
        };

        $model = new ModelStub;
        $model->foreign_key = null;

        $relation->match([$model], new Collection([$result]), 'foo');

        $this->assertFalse($model->relationLoaded('foo'));
    }

    public function testModelsWithFloatKeysAreProperlyMatchedToParents(): void
    {
        $relation = $this->getRelation();

        $result1 = new class extends Model {
            protected string $keyType = 'string';

            protected array $attributes = ['id' => 1.5];
        };

        $result2 = new class extends Model {
            protected string $keyType = 'string';

            protected array $attributes = ['id' => 1.9];
        };

        $model1 = new ModelStub;
        $model1->foreign_key = 1.5;
        $model2 = new ModelStub;
        $model2->foreign_key = 1.9;

        $models = $relation->match([$model1, $model2], new Collection([$result1, $result2]), 'foo');

        $this->assertSame($result1, $models[0]->foo);
        $this->assertSame($result2, $models[1]->foo);
    }

    public function testAssociateMethodSetsForeignKeyOnModel(): void
    {
        $parent = m::mock(Model::class);
        $parent->expects('getAttribute')->with('foreign_key')->andReturn('foreign.value');
        $relation = $this->getRelation($parent);
        $associate = m::mock(Model::class);
        $associate->expects('getAttribute')->with('id')->andReturn(1);
        $parent->expects('setAttribute')->with('foreign_key', 1);
        $parent->expects('setRelation')->with('relation', $associate);

        $relation->associate($associate);
    }

    public function testDissociateMethodUnsetsForeignKeyOnModel(): void
    {
        $parent = m::mock(Model::class);
        $parent->expects('getAttribute')->with('foreign_key')->andReturn('foreign.value');
        $relation = $this->getRelation($parent);
        $parent->expects('setAttribute')->with('foreign_key', null);

        // Always set relation when we received Model
        $parent->expects('setRelation')->with('relation', null);

        $relation->dissociate();
    }

    public function testAssociateMethodSetsForeignKeyOnModelById(): void
    {
        $parent = m::mock(Model::class);
        $parent->expects('getAttribute')->with('foreign_key')->andReturn('foreign.value');
        $relation = $this->getRelation($parent);
        $parent->expects('setAttribute')->with('foreign_key', 1);

        // Always unset relation when we received id, regardless of dirtiness
        $parent->shouldReceive('isDirty')->never();
        $parent->expects('unsetRelation')->with($relation->getRelationName());

        $relation->associate(1);
    }

    public function testDefaultEagerConstraintsWhenIncrementing(): void
    {
        $relation = $this->getRelation();
        $relation->getQuery()->expects('whereIntegerInRaw')->with('relation.id', m::mustBe([]));
        $models = [new MissingModelStub, new MissingModelStub];
        $relation->addEagerConstraints($models);
    }

    public function testDefaultEagerConstraintsWhenIncrementingAndNonIntKeyType(): void
    {
        $relation = $this->getRelation(null, 'string');
        $relation->getQuery()->expects('whereIn')->with('relation.id', m::mustBe([]));
        $models = [new MissingModelStub, new MissingModelStub];
        $relation->addEagerConstraints($models);
    }

    public function testDefaultEagerConstraintsWhenNotIncrementing(): void
    {
        $relation = $this->getRelation();
        $relation->getQuery()->expects('whereIntegerInRaw')->with('relation.id', m::mustBe([]));
        $models = [new MissingModelStub, new MissingModelStub];
        $relation->addEagerConstraints($models);
    }

    public function testIsNotNull(): void
    {
        $relation = $this->getRelation();

        $this->related->shouldReceive('getConnectionName')->never();

        $this->assertFalse($relation->is(null));
    }

    public function testIsModel(): void
    {
        $relation = $this->getRelation();

        $this->related->expects('getConnectionName')->andReturn('relation');

        $model = m::mock(Model::class);
        $model->expects('getAttribute')->with('id')->andReturn('foreign.value');
        $model->expects('getTable')->andReturn('relation');
        $model->expects('getConnectionName')->andReturn('relation');

        $this->assertTrue($relation->is($model));
    }

    public function testIsModelWithIntegerParentKey(): void
    {
        $parent = m::mock(Model::class);

        // when addConstraints is called we need to return the foreign value
        $parent->expects('getAttribute')->with('foreign_key')->andReturn('foreign.value');
        // when getParentKey is called we want to return an integer
        $parent->expects('getAttribute')->with('foreign_key')->andReturn(1);

        $relation = $this->getRelation($parent);

        $this->related->expects('getConnectionName')->andReturn('relation');

        $model = m::mock(Model::class);
        $model->expects('getAttribute')->with('id')->andReturn('1');
        $model->expects('getTable')->andReturn('relation');
        $model->expects('getConnectionName')->andReturn('relation');

        $this->assertTrue($relation->is($model));
    }

    public function testIsModelWithIntegerRelatedKey(): void
    {
        $parent = m::mock(Model::class);

        // when addConstraints is called we need to return the foreign value
        $parent->expects('getAttribute')->with('foreign_key')->andReturn('foreign.value');
        // when getParentKey is called we want to return a string
        $parent->expects('getAttribute')->with('foreign_key')->andReturn('1');

        $relation = $this->getRelation($parent);

        $this->related->expects('getConnectionName')->andReturn('relation');

        $model = m::mock(Model::class);
        $model->expects('getAttribute')->with('id')->andReturn(1);
        $model->expects('getTable')->andReturn('relation');
        $model->expects('getConnectionName')->andReturn('relation');

        $this->assertTrue($relation->is($model));
    }

    public function testIsModelWithIntegerKeys(): void
    {
        $parent = m::mock(Model::class);

        // when addConstraints is called we need to return the foreign value
        $parent->expects('getAttribute')->with('foreign_key')->andReturn('foreign.value');
        // when getParentKey is called we want to return an integer
        $parent->expects('getAttribute')->with('foreign_key')->andReturn(1);

        $relation = $this->getRelation($parent);

        $this->related->expects('getConnectionName')->andReturn('relation');

        $model = m::mock(Model::class);
        $model->expects('getAttribute')->with('id')->andReturn(1);
        $model->expects('getTable')->andReturn('relation');
        $model->expects('getConnectionName')->andReturn('relation');

        $this->assertTrue($relation->is($model));
    }

    public function testIsNotModelWithNullParentKey(): void
    {
        $parent = m::mock(Model::class);

        // when addConstraints is called we need to return the foreign value
        $parent->expects('getAttribute')->with('foreign_key')->andReturn('foreign.value');
        // when getParentKey is called we want to return null
        $parent->expects('getAttribute')->with('foreign_key')->andReturn(null);

        $relation = $this->getRelation($parent);

        $this->related->shouldReceive('getConnectionName')->never();

        $model = m::mock(Model::class);
        $model->expects('getAttribute')->with('id')->andReturn('foreign.value');
        $model->shouldReceive('getTable')->never();
        $model->shouldReceive('getConnectionName')->never();

        $this->assertFalse($relation->is($model));
    }

    public function testIsNotModelWithNullRelatedKey(): void
    {
        $relation = $this->getRelation();

        $this->related->shouldReceive('getConnectionName')->never();

        $model = m::mock(Model::class);
        $model->expects('getAttribute')->with('id')->andReturn(null);
        $model->shouldReceive('getTable')->never();
        $model->shouldReceive('getConnectionName')->never();

        $this->assertFalse($relation->is($model));
    }

    public function testIsNotModelWithAnotherKey(): void
    {
        $relation = $this->getRelation();

        $this->related->shouldReceive('getConnectionName')->never();

        $model = m::mock(Model::class);
        $model->expects('getAttribute')->with('id')->andReturn('foreign.value.two');
        $model->shouldReceive('getTable')->never();
        $model->shouldReceive('getConnectionName')->never();

        $this->assertFalse($relation->is($model));
    }

    public function testIsNotModelWithAnotherTable(): void
    {
        $relation = $this->getRelation();

        $this->related->shouldReceive('getConnectionName')->never();

        $model = m::mock(Model::class);
        $model->expects('getAttribute')->with('id')->andReturn('foreign.value');
        $model->expects('getTable')->andReturn('table.two');
        $model->shouldReceive('getConnectionName')->never();

        $this->assertFalse($relation->is($model));
    }

    public function testIsNotModelWithAnotherConnection(): void
    {
        $relation = $this->getRelation();

        $this->related->expects('getConnectionName')->andReturn('relation');

        $model = m::mock(Model::class);
        $model->expects('getAttribute')->with('id')->andReturn('foreign.value');
        $model->expects('getTable')->andReturn('relation');
        $model->expects('getConnectionName')->andReturn('relation.two');

        $this->assertFalse($relation->is($model));
    }

    /**
     * Get the belongs to relationship.
     */
    protected function getRelation(?Model $parent = null, string $keyType = 'int'): BelongsTo
    {
        $this->builder = m::mock(Builder::class);
        $this->builder->expects('where')->with('relation.id', '=', 'foreign.value');
        $this->related = m::mock(Model::class);
        $this->related->shouldReceive('getKeyType')->andReturn($keyType);
        $this->related->shouldReceive('getKeyName')->andReturn('id');
        $this->related->shouldReceive('getTable')->andReturn('relation');
        $this->related->shouldReceive('qualifyColumn')->andReturnUsing(fn (string $column): string => "relation.{$column}");
        $this->builder->expects('getModel')->andReturn($this->related);
        $parent = $parent ?: new ModelStub;

        return new BelongsTo($this->builder, $parent, 'foreign_key', 'id', 'relation');
    }

    /**
     * Get relation with a partial mock for the related model.
     *
     * Used for withDefault tests that need real Model attribute behavior.
     * The partial mock satisfies strict `static` return types on newInstance()
     * while retaining real __set/__get behavior for attribute assertions.
     */
    protected function getRelationWithPartialMock(?Model $parent = null): BelongsTo
    {
        $this->builder = m::mock(Builder::class);
        $this->builder->expects('where')->with('relation.id', '=', 'foreign.value');
        $this->related = m::mock(ModelStub::class)->makePartial();
        $this->related->shouldReceive('getKeyType')->andReturn('int');
        $this->related->shouldReceive('getKeyName')->andReturn('id');
        $this->related->shouldReceive('getTable')->andReturn('relation');
        $this->related->shouldReceive('qualifyColumn')->andReturnUsing(fn (string $column): string => "relation.{$column}");
        $this->builder->expects('getModel')->andReturn($this->related);
        $parent = $parent ?: new ModelStub;

        return new BelongsTo($this->builder, $parent, 'foreign_key', 'id', 'relation');
    }
}

class ModelStub extends Model
{
    public mixed $foreign_key = 'foreign.value';
}

class AnotherModelStub extends Model
{
    public string $foreign_key = 'foreign.value.two';
}

class ModelStubWithZeroId extends Model
{
    public int $foreign_key = 0;
}

class MissingModelStub extends Model
{
    public mixed $foreign_key = null;
}

class ModelStubWithBackedEnumCast extends Model
{
    protected array $casts = [
        'foreign_key' => Bar::class,
    ];

    protected array $attributes = [
        'foreign_key' => 5,
    ];
}
