<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseEloquentBelongsToTest;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\Query\Builder as BaseBuilder;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Tests\Database\Fixtures\Enums\Bar;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PDO;

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
        $relation = $this->getRelationWithRealQuery();
        $models = [$this->newModelWithKey(1), $this->newModelWithKey(2), $this->newModelWithKey(1)];
        $relation->addEagerConstraints($models);

        $this->assertSame('select * from "relation" where "relation"."id" = ? and "relation"."id" in (1, 2)', $relation->toSql());
    }

    public function testIdsInEagerConstraintsCanBeZero(): void
    {
        $relation = $this->getRelationWithRealQuery();
        $relation->addEagerConstraints([$this->newModelWithKey(1), new ModelStubWithZeroId]);

        $this->assertSame('select * from "relation" where "relation"."id" = ? and "relation"."id" in (0, 1)', $relation->toSql());
    }

    public function testIdsInEagerConstraintsCanBeBackedEnum(): void
    {
        $relation = $this->getRelationWithRealQuery();
        $relation->addEagerConstraints([$this->newModelWithKey(1), new ModelStubWithBackedEnumCast]);

        $this->assertSame('select * from "relation" where "relation"."id" = ? and "relation"."id" in (1, 5)', $relation->toSql());
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
        $relation = $this->getRelationWithRealQuery();
        $associate = new EloquentBelongsToRelatedStub;
        $associate->id = 1;

        $child = $relation->associate($associate);

        $this->assertSame(1, $child->getAttribute('foreign_key'));
        $this->assertSame($associate, $child->getRelation('relation'));
    }

    public function testDissociateMethodUnsetsForeignKeyOnModel(): void
    {
        $relation = $this->getRelationWithRealQuery();
        $relation->getChild()->setAttribute('foreign_key', 5);

        $child = $relation->dissociate();

        $this->assertNull($child->getAttribute('foreign_key'));
        // Always set relation when we received Model
        $this->assertTrue($child->relationLoaded('relation'));
        $this->assertNull($child->getRelation('relation'));
    }

    public function testAssociateMethodSetsForeignKeyOnModelById(): void
    {
        $relation = $this->getRelationWithRealQuery();
        $relation->getChild()->setRelation('relation', new EloquentBelongsToRelatedStub);

        $child = $relation->associate(1);

        $this->assertSame(1, $child->getAttribute('foreign_key'));
        // Always unset relation when we received id, regardless of dirtiness
        $this->assertFalse($child->relationLoaded('relation'));
    }

    public function testDefaultEagerConstraintsWhenIncrementing(): void
    {
        $relation = $this->getRelationWithRealQuery();
        $relation->addEagerConstraints([new MissingModelStub, new MissingModelStub]);

        $this->assertSame('select * from "relation" where "relation"."id" = ? and 0 = 1', $relation->toSql());
    }

    public function testDefaultEagerConstraintsWhenIncrementingAndNonIntKeyType(): void
    {
        $relation = $this->getRelationWithRealQuery('string');
        $relation->addEagerConstraints([$this->newModelWithKey('abc'), $this->newModelWithKey('1abc')]);

        $this->assertSame('select * from "relation" where "relation"."id" = ? and "relation"."id" in (?, ?)', $relation->toSql());
        $this->assertSame(['foreign.value', '1abc', 'abc'], $relation->getBindings());
    }

    /**
     * Create a child model with the given foreign key.
     */
    protected function newModelWithKey(int|string $key): ModelStub
    {
        $model = new ModelStub;
        $model->foreign_key = $key;

        return $model;
    }

    /**
     * Get a belongs to relationship backed by a real query builder.
     */
    protected function getRelationWithRealQuery(string $keyType = 'int'): BelongsTo
    {
        $related = new EloquentBelongsToRelatedStub;
        $related->setKeyType($keyType);
        $connection = new PdoConnection(new PDO('sqlite::memory:'));
        $builder = (new Builder(new BaseBuilder($connection, new Grammar($connection), new Processor)))->setModel($related);

        return new BelongsTo($builder, new ModelStub, 'foreign_key', 'id', 'relation');
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

class EloquentBelongsToRelatedStub extends Model
{
    protected ?string $table = 'relation';
}

class ModelStub extends Model
{
    public mixed $foreign_key = 'foreign.value';
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
