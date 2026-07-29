<?php

declare(strict_types=1);

namespace Hypervel\Tests\NestedSet;

use Hypervel\Database\Eloquent\Attributes\UseEloquentBuilder;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Query\Builder as BaseQueryBuilder;
use Hypervel\NestedSet\Eloquent\QueryBuilder;
use Hypervel\NestedSet\HasNode;
use Hypervel\NestedSet\NestedSet;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use LogicException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use stdClass;
use Stringable;

class NestedSetTest extends TestCase
{
    public function testIsNodeReturnsTrueForModelUsingHasNode(): void
    {
        $this->assertTrue(NestedSet::isNode(new NestedSetTestNodeModel));
    }

    public function testIsNodeReturnsTrueForModelUsingHasNodeThroughAnotherTrait(): void
    {
        $this->assertTrue(NestedSet::isNode(new NestedSetTestNestedTraitNodeModel));
    }

    public function testNodeBootDetectsSoftDeletesWithoutNestedModelConstruction(): void
    {
        $node = new NestedSetTestNodeModel;
        $softDeletingNode = new NestedSetTestSoftDeletingNodeModel;

        $this->assertFalse($node::usesSoftDelete());
        $this->assertTrue($softDeletingNode::usesSoftDelete());
    }

    public function testNodeUsesNestedSetBuilderByDefault(): void
    {
        $builder = (new NestedSetTestNodeModel)
            ->newEloquentBuilder(m::mock(BaseQueryBuilder::class));

        $this->assertInstanceOf(QueryBuilder::class, $builder);
    }

    public function testNodeUsesCompatibleAttributedBuilder(): void
    {
        $builder = (new NestedSetTestCustomBuilderNodeModel)
            ->newEloquentBuilder(m::mock(BaseQueryBuilder::class));

        $this->assertInstanceOf(NestedSetTestCustomBuilder::class, $builder);
    }

    public function testNodeRejectsIncompatibleAttributedBuilder(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('must use a builder that extends');

        (new NestedSetTestIncompatibleBuilderNodeModel)
            ->newEloquentBuilder(m::mock(BaseQueryBuilder::class));
    }

    public function testIsNodeReturnsFalseForPlainEloquentModel(): void
    {
        $this->assertFalse(NestedSet::isNode(new NestedSetTestPlainModel));
    }

    public function testIsNodeReturnsFalseForNonObject(): void
    {
        $this->assertFalse(NestedSet::isNode('not an object'));
        $this->assertFalse(NestedSet::isNode(42));
        $this->assertFalse(NestedSet::isNode(null));
        $this->assertFalse(NestedSet::isNode([]));
    }

    public function testIsNodeReturnsFalseForArbitraryObject(): void
    {
        $this->assertFalse(NestedSet::isNode(new stdClass));
    }

    public function testIsNodeCachesEachConcreteClassAndFlushesItsState(): void
    {
        NestedSet::flushState();

        $node = new NestedSetTestNodeModel;
        $plain = new NestedSetTestPlainModel;

        $this->assertTrue(NestedSet::isNode($node));
        $this->assertFalse(NestedSet::isNode($plain));

        $property = new ReflectionProperty(NestedSet::class, 'nodeClasses');

        $this->assertSame([
            NestedSetTestNodeModel::class => true,
            NestedSetTestPlainModel::class => false,
        ], $property->getValue());

        NestedSet::flushState();

        $this->assertSame([], $property->getValue());
    }

    public function testScopeValuesAreNormalizedForSqlAndBucketIdentity(): void
    {
        $model = new NestedSetTestScopeNodeModel;
        $model->setRawAttributes([
            'first' => NestedSetTestScope::One,
            'second' => true,
            'third' => CarbonImmutable::parse('2026-01-02 03:04:05'),
            'fourth' => new NestedSetTestStringable('value'),
            'fifth' => null,
        ]);

        $this->assertSame([
            'first' => 1,
            'second' => 1,
            'third' => '2026-01-02 03:04:05',
            'fourth' => 'value',
            'fifth' => null,
        ], $model->getNestedSetScope());
    }

    public function testScopeKeysDistinguishCompositeValuesWithoutSeparatorsColliding(): void
    {
        $first = new NestedSetTestScopeNodeModel;
        $first->setRawAttributes(['first' => '1', 'second' => '23']);

        $second = new NestedSetTestScopeNodeModel;
        $second->setRawAttributes(['first' => '12', 'second' => '3']);

        $integer = new NestedSetTestScopeNodeModel;
        $integer->setRawAttributes(['first' => 1, 'second' => null]);

        $string = new NestedSetTestScopeNodeModel;
        $string->setRawAttributes(['first' => '1', 'second' => null]);

        $empty = new NestedSetTestScopeNodeModel;
        $empty->setRawAttributes(['first' => '']);

        $null = new NestedSetTestScopeNodeModel;
        $null->setRawAttributes(['first' => null]);

        $this->assertNotSame($first->getNestedSetScopeKey(), $second->getNestedSetScopeKey());
        $this->assertSame($integer->getNestedSetScopeKey(), $string->getNestedSetScopeKey());
        $this->assertNotSame($empty->getNestedSetScopeKey(), $null->getNestedSetScopeKey());
    }

    #[DataProvider('unsupportedScopeValues')]
    public function testUnsupportedScopeValuesFailDescriptively(mixed $value, string $type): void
    {
        $model = new NestedSetTestScopeNodeModel;
        $model->setRawAttributes(['first' => $value]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("unsupported scope value [{$type}] for attribute [first]");

        $model->getNestedSetScope();
    }

    public static function unsupportedScopeValues(): array
    {
        return [
            'float' => [1.5, 'float'],
            'non-stringable object' => [new stdClass, stdClass::class],
        ];
    }
}

enum NestedSetTestScope: int
{
    case One = 1;
}

class NestedSetTestStringable implements Stringable
{
    public function __construct(protected string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

trait NestedSetTestNestedNode
{
    use HasNode;
}

class NestedSetTestNodeModel extends Model
{
    use HasNode;

    protected ?string $table = 'nested_set_test_nodes';
}

class NestedSetTestPlainModel extends Model
{
    protected ?string $table = 'nested_set_test_plain';
}

class NestedSetTestNestedTraitNodeModel extends Model
{
    use NestedSetTestNestedNode;

    protected ?string $table = 'nested_set_test_nested_trait_nodes';
}

class NestedSetTestSoftDeletingNodeModel extends Model
{
    use SoftDeletes;
    use HasNode;

    protected ?string $table = 'nested_set_test_soft_deleting_nodes';
}

class NestedSetTestScopeNodeModel extends Model
{
    use HasNode;

    protected ?string $table = 'nested_set_test_scope_nodes';

    protected function getScopeAttributes(): array
    {
        return ['first', 'second', 'third', 'fourth', 'fifth'];
    }
}

/**
 * @template TModel of Model
 * @extends QueryBuilder<TModel>
 */
class NestedSetTestCustomBuilder extends QueryBuilder
{
}

#[UseEloquentBuilder(NestedSetTestCustomBuilder::class)]
class NestedSetTestCustomBuilderNodeModel extends Model
{
    use HasNode;

    protected ?string $table = 'nested_set_test_custom_builder_nodes';
}

#[UseEloquentBuilder(Builder::class)]
class NestedSetTestIncompatibleBuilderNodeModel extends Model
{
    use HasNode;

    protected ?string $table = 'nested_set_test_incompatible_builder_nodes';
}
