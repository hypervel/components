<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseEloquentMorphToTest;

use Hypervel\Database\ClassMorphViolationException;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Tests\Database\Fixtures\TestEnum;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;

class DatabaseEloquentMorphToTest extends TestCase
{
    protected Builder $builder;

    protected Model $related;

    protected function addMockConnection(Model $model): void
    {
        $model->setConnectionResolver($resolver = m::mock(ConnectionResolverInterface::class));
        $resolver->shouldReceive('connection')->andReturn($connection = m::mock(Connection::class));
        $connection->shouldReceive('getQueryGrammar')->andReturn($grammar = m::mock(Grammar::class));
        $grammar->shouldReceive('getBitwiseOperators')->andReturn([]);
        $connection->shouldReceive('getPostProcessor')->andReturn($processor = m::mock(Processor::class));
        $connection->shouldReceive('query')->andReturnUsing(function () use ($connection, $grammar, $processor) {
            return new QueryBuilder($connection, $grammar, $processor);
        });
    }

    public function testLookupDictionaryIsProperlyConstructedForEnums()
    {
        $relation = $this->getRelation();
        $relation->addEagerConstraints([
            $one = (object) ['morph_type' => 'morph_type_2', 'foreign_key' => TestEnum::Test],
        ]);
        $dictionary = $relation->getDictionary();
        $relation->getDictionary();
        $enumKey = TestEnum::Test;
        if (isset($enumKey->value)) {
            $value = $dictionary['morph_type_2'][$enumKey->value][0]->foreign_key;
            $this->assertEquals(TestEnum::Test, $value);
        } else {
            $this->fail('An enum should contain value property');
        }
    }

    public function testLookupDictionaryIsProperlyConstructed()
    {
        $stringish = new class {
            public function __toString()
            {
                return 'foreign_key_2';
            }
        };

        $relation = $this->getRelation();
        $relation->addEagerConstraints([
            $one = (object) ['morph_type' => 'morph_type_1', 'foreign_key' => 'foreign_key_1'],
            $two = (object) ['morph_type' => 'morph_type_1', 'foreign_key' => 'foreign_key_1'],
            $three = (object) ['morph_type' => 'morph_type_2', 'foreign_key' => 'foreign_key_2'],
            $four = (object) ['morph_type' => 'morph_type_2', 'foreign_key' => $stringish],
        ]);

        $dictionary = $relation->getDictionary();

        $this->assertEquals([
            'morph_type_1' => [
                'foreign_key_1' => [
                    $one,
                    $two,
                ],
            ],
            'morph_type_2' => [
                'foreign_key_2' => [
                    $three,
                    $four,
                ],
            ],
        ], $dictionary);
    }

    public function testLookupDictionaryExcludesModelsWithNullForeignKeys(): void
    {
        $relation = $this->getRelation();
        $relation->addEagerConstraints([
            (object) ['morph_type' => 'morph_type_1', 'foreign_key' => null],
        ]);

        $this->assertSame([], $relation->getDictionary());
    }

    public function testLookupDictionaryRetainsZeroMorphTypesAndSkipsEmptyTypes(): void
    {
        $relation = $this->getRelation();
        $relation->addEagerConstraints([
            $integerZero = (object) ['morph_type' => 0, 'foreign_key' => 'integer-zero'],
            $stringZero = (object) ['morph_type' => '0', 'foreign_key' => 'string-zero'],
            (object) ['morph_type' => '', 'foreign_key' => 'empty'],
            (object) ['morph_type' => null],
        ]);

        $this->assertSame([
            0 => [
                'integer-zero' => [$integerZero],
                'string-zero' => [$stringZero],
            ],
        ], $relation->getDictionary());
    }

    public function testMorphToWithDefault()
    {
        $this->addMockConnection(new ModelStub);

        $relation = $this->getRelation()->withDefault();

        $this->builder->shouldReceive('first')->once()->andReturnNull();

        $newModel = new ModelStub;

        $this->assertEquals($newModel, $relation->getResults());
    }

    public function testMorphToWithDynamicDefault()
    {
        $this->addMockConnection(new ModelStub);

        $relation = $this->getRelation()->withDefault(function ($newModel) {
            $newModel->username = 'taylor';
        });

        $this->builder->shouldReceive('first')->once()->andReturnNull();

        $newModel = new ModelStub;
        $newModel->username = 'taylor';

        $result = $relation->getResults();

        $this->assertEquals($newModel, $result);

        $this->assertSame('taylor', $result->username);
    }

    public function testMorphToWithArrayDefault()
    {
        $this->addMockConnection(new ModelStub);

        $relation = $this->getRelation()->withDefault(['username' => 'taylor']);

        $this->builder->shouldReceive('first')->once()->andReturnNull();

        $newModel = new ModelStub;
        $newModel->username = 'taylor';

        $result = $relation->getResults();

        $this->assertEquals($newModel, $result);

        $this->assertSame('taylor', $result->username);
    }

    public function testMorphToWithZeroMorphType()
    {
        $parent = $this->getMockBuilder(ModelStub::class)->onlyMethods(['getAttributeFromArray', 'morphEagerTo', 'morphInstanceTo'])->getMock();
        $parent->expects($this->once())->method('getAttributeFromArray')->with('relation_type')->willReturn(0);
        $parent->expects($this->once())->method('morphInstanceTo');
        $parent->expects($this->never())->method('morphEagerTo');

        $parent->relation();
    }

    public function testMorphToWithEmptyStringMorphType()
    {
        $parent = $this->getMockBuilder(ModelStub::class)->onlyMethods(['getAttributeFromArray', 'morphEagerTo', 'morphInstanceTo'])->getMock();
        $parent->expects($this->once())->method('getAttributeFromArray')->with('relation_type')->willReturn('');
        $parent->expects($this->once())->method('morphEagerTo');
        $parent->expects($this->never())->method('morphInstanceTo');

        $parent->relation();
    }

    public function testMorphToWithSpecifiedClassDefault()
    {
        $this->addMockConnection(new RelatedStub);

        $parent = new ModelStub;
        $parent->relation_type = RelatedStub::class;

        $relation = $parent->relation()->withDefault();

        $newModel = new RelatedStub;

        $result = $relation->getResults();

        $this->assertEquals($newModel, $result);
    }

    public function testAssociateMethodSetsForeignKeyAndTypeOnModel()
    {
        $parent = m::mock(Model::class);
        $parent->shouldReceive('getAttribute')->with('foreign_key')->andReturn('foreign.value');

        $relation = $this->getRelationAssociate($parent);

        $associate = m::mock(Model::class);
        $associate->shouldReceive('getAttribute')->andReturn(1);
        $associate->shouldReceive('getMorphClass')->andReturn('Model');

        $parent->shouldReceive('setAttribute')->once()->with('foreign_key', 1);
        $parent->shouldReceive('setAttribute')->once()->with('morph_type', 'Model');
        $parent->shouldReceive('setRelation')->once()->with('relation', $associate);

        $relation->associate($associate);
    }

    public function testAssociateMethodIgnoresNullValue()
    {
        $parent = m::mock(Model::class);
        $parent->shouldReceive('getAttribute')->once()->with('foreign_key')->andReturn('foreign.value');

        $relation = $this->getRelationAssociate($parent);

        $parent->shouldReceive('setAttribute')->once()->with('foreign_key', null);
        $parent->shouldReceive('setAttribute')->once()->with('morph_type', null);
        $parent->shouldReceive('setRelation')->once()->with('relation', null);

        $relation->associate(null);
    }

    public function testAssociateMethodUsesConfiguredOwnerKeyWithZeroValue(): void
    {
        $builder = m::mock(Builder::class);
        $builder->shouldReceive('getModel')->andReturn(new MorphToAssociateRelatedStub);

        $parent = new MorphToAssociateParentStub;
        $associate = (new MorphToAssociateRelatedStub)->forceFill([
            'model_id' => 7,
            'custom_key' => 0,
        ]);

        $relation = Relation::noConstraints(fn () => new MorphTo(
            $builder,
            $parent,
            'foreign_key',
            'custom_key',
            'morph_type',
            'relation'
        ));

        $this->assertSame($parent, $relation->associate($associate));
        $this->assertSame(0, $parent->getAttribute('foreign_key'));
        $this->assertSame(MorphToAssociateRelatedStub::class, $parent->getAttribute('morph_type'));
        $this->assertSame($associate, $parent->getRelation('relation'));
    }

    public function testAssociateMethodUsesConfiguredOwnerKeyWithNullValue(): void
    {
        $builder = m::mock(Builder::class);
        $builder->shouldReceive('getModel')->andReturn(new MorphToAssociateRelatedStub);

        $parent = new MorphToAssociateParentStub;
        $associate = (new MorphToAssociateRelatedStub)->forceFill([
            'model_id' => 7,
            'custom_key' => null,
        ]);

        $relation = Relation::noConstraints(fn () => new MorphTo(
            $builder,
            $parent,
            'foreign_key',
            'custom_key',
            'morph_type',
            'relation'
        ));

        $this->assertSame($parent, $relation->associate($associate));
        $this->assertNull($parent->getAttribute('foreign_key'));
        $this->assertSame(MorphToAssociateRelatedStub::class, $parent->getAttribute('morph_type'));
        $this->assertSame($associate, $parent->getRelation('relation'));
    }

    public function testAssociateMethodRejectsScalarValuesBeforeChangingTheParent(): void
    {
        $builder = m::mock(Builder::class);
        $builder->shouldReceive('getModel')->andReturn(new MorphToAssociateRelatedStub);

        $parent = (new MorphToAssociateParentStub)->forceFill([
            'foreign_key' => 'original-id',
            'morph_type' => 'original-type',
        ]);

        $relation = Relation::noConstraints(fn () => new MorphTo(
            $builder,
            $parent,
            'foreign_key',
            'custom_key',
            'morph_type',
            'relation'
        ));

        try {
            $relation->associate(5);
            $this->fail('Expected an invalid argument exception.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('MorphTo relationships may only be associated with a model instance or null.', $exception->getMessage());
        }

        $this->assertSame('original-id', $parent->getAttribute('foreign_key'));
        $this->assertSame('original-type', $parent->getAttribute('morph_type'));
        $this->assertFalse($parent->relationLoaded('relation'));
    }

    public function testAssociateMethodRejectsUnmappedModelsBeforeChangingTheParent(): void
    {
        $builder = m::mock(Builder::class);
        $builder->shouldReceive('getModel')->andReturn(new MorphToAssociateRelatedStub);

        $parent = (new MorphToAssociateParentStub)->forceFill([
            'foreign_key' => 'original-id',
            'morph_type' => 'original-type',
        ]);
        $originalRelation = new MorphToAssociateRelatedStub;
        $parent->setRelation('relation', $originalRelation);
        $associate = (new MorphToAssociateRelatedStub)->forceFill([
            'custom_key' => 7,
        ]);

        $relation = Relation::noConstraints(fn () => new MorphTo(
            $builder,
            $parent,
            'foreign_key',
            'custom_key',
            'morph_type',
            'relation'
        ));

        Relation::requireMorphMap();

        try {
            $relation->associate($associate);
            $this->fail('Expected a class morph violation exception.');
        } catch (ClassMorphViolationException $exception) {
            $this->assertSame(MorphToAssociateRelatedStub::class, $exception->model);
        }

        $this->assertSame('original-id', $parent->getAttribute('foreign_key'));
        $this->assertSame('original-type', $parent->getAttribute('morph_type'));
        $this->assertSame($originalRelation, $parent->getRelation('relation'));
    }

    public function testDissociateMethodDeletesUnsetsKeyAndTypeOnModel()
    {
        $parent = m::mock(Model::class);
        $parent->shouldReceive('getAttribute')->once()->with('foreign_key')->andReturn('foreign.value');

        $relation = $this->getRelation($parent);

        $parent->shouldReceive('setAttribute')->once()->with('foreign_key', null);
        $parent->shouldReceive('setAttribute')->once()->with('morph_type', null);
        $parent->shouldReceive('setRelation')->once()->with('relation', null);

        $relation->dissociate();
    }

    public function testIsNotNull()
    {
        $relation = $this->getRelation();

        $relation->getRelated()->shouldReceive('getTable')->never();
        $relation->getRelated()->shouldReceive('getConnectionName')->never();

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

    public function testMatchToMorphParentsNormalizesKeyWhenOwnerKeyIsNullAndResultKeyIsObject(): void
    {
        $uuidObject = new class {
            public function __toString(): string
            {
                return 'uuid-value';
            }
        };

        $builder = m::mock(Builder::class);
        $related = m::mock(Model::class);
        $builder->shouldReceive('getModel')->andReturn($related);

        $parent = new ModelStub;
        $parent->morph_type = 'type_1';
        $parent->foreign_key = 'uuid-value';

        $relation = Relation::noConstraints(function () use ($builder, $parent) {
            return new AccessibleMorphTo($builder, $parent, 'foreign_key', null, 'morph_type', 'relation');
        });

        $relation->addEagerConstraints([$parent]);

        $result = m::mock(Model::class);
        $result->shouldReceive('getKey')->once()->andReturn($uuidObject);

        $relation->callMatchToMorphParents('type_1', new EloquentCollection([$result]));

        $this->assertSame($result, $parent->getRelation('relation'));
    }

    protected function getRelationAssociate($parent)
    {
        $builder = m::mock(Builder::class);
        $builder->shouldReceive('where')->with('relation.id', '=', 'foreign.value');
        $related = m::mock(Model::class);
        $related->shouldReceive('getKey')->andReturn(1);
        $related->shouldReceive('getTable')->andReturn('relation');
        $related->shouldReceive('qualifyColumn')->andReturnUsing(fn (string $column) => "relation.{$column}");
        $builder->shouldReceive('getModel')->andReturn($related);

        return new MorphTo($builder, $parent, 'foreign_key', 'id', 'morph_type', 'relation');
    }

    public function getRelation($parent = null, $builder = null)
    {
        $this->builder = $builder ?: m::mock(Builder::class);
        $this->builder->shouldReceive('where')->with('relation.id', '=', 'foreign.value');
        $this->related = m::mock(Model::class);
        $this->related->shouldReceive('getKeyName')->andReturn('id');
        $this->related->shouldReceive('getTable')->andReturn('relation');
        $this->related->shouldReceive('qualifyColumn')->andReturnUsing(fn (string $column) => "relation.{$column}");
        $this->builder->shouldReceive('getModel')->andReturn($this->related);
        $parent = $parent ?: new ModelStub;

        return m::mock(MorphTo::class . '[createModelByType]', [$this->builder, $parent, 'foreign_key', 'id', 'morph_type', 'relation']);
    }
}

class ModelStub extends Model
{
    public string $foreign_key = 'foreign.value';

    protected ?string $table = 'model_stubs';

    public function relation()
    {
        return $this->morphTo();
    }
}

class RelatedStub extends Model
{
    protected ?string $table = 'related_stubs';
}

class MorphToAssociateParentStub extends Model
{
}

class MorphToAssociateRelatedStub extends Model
{
    protected string $primaryKey = 'model_id';
}

class AccessibleMorphTo extends MorphTo
{
    public function callMatchToMorphParents(int|string $type, EloquentCollection $results): void
    {
        $this->matchToMorphParents($type, $results);
    }
}
