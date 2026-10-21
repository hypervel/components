<?php

declare(strict_types=1);

namespace Hypervel\Tests\NestedSet;

use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Eloquent\Attributes\UseEloquentBuilder;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\HasBuilder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Query\Builder as BaseQueryBuilder;
use Hypervel\Database\Query\Grammars\Grammar as QueryGrammar;
use Hypervel\NestedSet\Eloquent\QueryBuilder;
use Hypervel\NestedSet\HasNode;
use Hypervel\NestedSet\NestedSet;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use LogicException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Stringable;

class NestedSetTest extends TestCase
{
    public function testStructuralIdentityUsesTheNormalizedConnectionNameAndTableWithoutResolving(): void
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('getDefaultConnection')->andReturn('primary::write');
        $resolver->shouldNotReceive('connection');

        Model::setConnectionResolver($resolver);

        $default = new NestedSetTestIdentityModel;
        $read = (new NestedSetTestIdentityAliasModel)->setConnection('primary::read');
        $write = (new NestedSetTestIdentityAliasModel)->setConnection('primary::write');
        $otherTable = new NestedSetTestOtherTableModel;
        $otherConnection = (new NestedSetTestIdentityModel)->setConnection('secondary');

        $this->assertSame(
            NestedSet::structuralIdentity($default),
            NestedSet::structuralIdentity($read),
        );
        $this->assertSame(
            NestedSet::structuralIdentity($default),
            NestedSet::structuralIdentity($write),
        );
        $this->assertNotSame(
            NestedSet::structuralIdentity($default),
            NestedSet::structuralIdentity($otherTable),
        );
        $this->assertNotSame(
            NestedSet::structuralIdentity($default),
            NestedSet::structuralIdentity($otherConnection),
        );
        $this->assertSame(
            'connection [primary] and table [nodes]',
            NestedSet::structuralDescription($read),
        );
        $this->assertSame(
            'connection [primary] and table [other_nodes]',
            NestedSet::structuralDescription($otherTable),
        );
    }

    public function testStructuralIdentityFallsBackToTheResolversDefaultConnection(): void
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('getDefaultConnection')->andReturn('primary');

        Model::setConnectionResolver($resolver);

        $this->assertSame(
            NestedSet::structuralIdentity(new NestedSetTestIdentityModel),
            NestedSet::structuralIdentity(
                (new NestedSetTestIdentityModel)->setConnection('primary'),
            ),
        );
    }

    public function testStructuralIdentityUsesAStableMarkerWithoutAnyConnectionName(): void
    {
        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('getDefaultConnection')->andReturn(null);

        Model::setConnectionResolver($resolver);

        $this->assertSame(
            '7:default:nodes',
            NestedSet::structuralIdentity(new NestedSetTestIdentityModel),
        );
    }

    public function testIsNodeReturnsTrueForModelUsingHasNode(): void
    {
        $this->assertTrue(NestedSet::isNode(new NestedSetTestNodeModel));
    }

    public function testIsNodeReturnsTrueForModelUsingHasNodeThroughAnotherTrait(): void
    {
        $this->assertTrue(NestedSet::isNode(new NestedSetTestNestedTraitNodeModel));
    }

    public function testNodeUsesNestedSetBuilderByDefault(): void
    {
        $builder = (new NestedSetTestNodeModel)
            ->newEloquentBuilder(m::mock(BaseQueryBuilder::class));

        $this->assertInstanceOf(QueryBuilder::class, $builder);
    }

    /**
     * @param class-string<Model> $model
     * @param class-string<QueryBuilder> $builder
     */
    #[DataProvider('compatibleBuilderModels')]
    public function testNodeUsesCompatibleCustomBuilder(string $model, string $builder): void
    {
        $instance = (new $model)->newEloquentBuilder(m::mock(BaseQueryBuilder::class));

        $this->assertSame($builder, $instance::class);
    }

    /**
     * Provide node models with compatible custom builders.
     *
     * @return array<string, array{class-string<Model>, class-string<QueryBuilder>}>
     */
    public static function compatibleBuilderModels(): array
    {
        return [
            'attribute' => [NestedSetTestCustomBuilderNodeModel::class, NestedSetTestCustomBuilder::class],
            'attribute naming the nested set builder' => [NestedSetTestNestedSetBuilderNodeModel::class, QueryBuilder::class],
            'builder property' => [NestedSetTestBuilderPropertyNodeModel::class, NestedSetTestCustomBuilder::class],
        ];
    }

    /**
     * @param class-string<Model> $model
     */
    #[DataProvider('incompatibleBuilderModels')]
    public function testNodeRejectsIncompatibleCustomBuilder(string $model): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIs(sprintf(
            'Nested set model [%s] must use a builder that extends [%s].',
            $model,
            QueryBuilder::class,
        ));

        (new $model)->newEloquentBuilder(m::mock(BaseQueryBuilder::class));
    }

    /**
     * Provide node models with builders that do not extend the nested set builder.
     *
     * @return array<string, array{class-string<Model>}>
     */
    public static function incompatibleBuilderModels(): array
    {
        return [
            'attribute' => [NestedSetTestIncompatibleBuilderNodeModel::class],
            'builder property' => [NestedSetTestIncompatibleBuilderPropertyNodeModel::class],
        ];
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

    public function testScopeValuesAreNormalizedForSqlAndBucketIdentity(): void
    {
        // Dates use the connection grammar's own format, which a custom grammar may change.
        $grammar = m::mock(QueryGrammar::class);
        $grammar->shouldReceive('getDateFormat')->andReturn('d/m/Y H:i:s');
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getQueryGrammar')->andReturn($grammar);
        $resolver = m::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->andReturn($connection);
        Model::setConnectionResolver($resolver);

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
            'third' => '02/01/2026 03:04:05',
            'fourth' => 'value',
            'fifth' => null,
        ], $model->getNestedSetScope());
    }

    public function testScopeDateValuesHonorTheModelDateFormatWithoutAConnection(): void
    {
        $date = CarbonImmutable::parse('2026-01-02 03:04:05');
        $model = new NestedSetTestScopeNodeModel;
        $model->setDateFormat('U');
        $model->setRawAttributes(['third' => $date]);

        $this->assertSame($date->format('U'), $model->getNestedSetScope()['third']);
    }

    public function testScopeKeysDistinguishCompositeValuesWithoutSeparatorsColliding(): void
    {
        $defaults = ['third' => null, 'fourth' => null, 'fifth' => null];

        $first = new NestedSetTestScopeNodeModel;
        $first->setRawAttributes(['first' => '1', 'second' => '23', ...$defaults]);

        $second = new NestedSetTestScopeNodeModel;
        $second->setRawAttributes(['first' => '12', 'second' => '3', ...$defaults]);

        $integer = new NestedSetTestScopeNodeModel;
        $integer->setRawAttributes(['first' => 1, 'second' => null, ...$defaults]);

        $string = new NestedSetTestScopeNodeModel;
        $string->setRawAttributes(['first' => '1', 'second' => null, ...$defaults]);

        $empty = new NestedSetTestScopeNodeModel;
        $empty->setRawAttributes(['first' => '', 'second' => null, ...$defaults]);

        $null = new NestedSetTestScopeNodeModel;
        $null->setRawAttributes(['first' => null, 'second' => null, ...$defaults]);

        $this->assertNotSame($first->getNestedSetScopeKey(), $second->getNestedSetScopeKey());
        $this->assertSame($integer->getNestedSetScopeKey(), $string->getNestedSetScopeKey());
        $this->assertNotSame($empty->getNestedSetScopeKey(), $null->getNestedSetScopeKey());
    }

    public function testIncompleteScopeKeysCannotMatchEachOther(): void
    {
        $first = new NestedSetTestScopeNodeModel;
        $first->setRawAttributes(['first' => 1]);

        $second = new NestedSetTestScopeNodeModel;
        $second->setRawAttributes(['first' => 1]);

        $this->assertNotSame($first->getNestedSetScopeKey(), $second->getNestedSetScopeKey());
    }

    #[DataProvider('unsupportedScopeValues')]
    public function testUnsupportedScopeValuesFailDescriptively(mixed $value, string $type): void
    {
        $model = new NestedSetTestScopeNodeModel;
        $model->setRawAttributes(['first' => $value]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageIsOrContains("unsupported scope value [{$type}] for attribute [first]");

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

class NestedSetTestIdentityModel extends Model
{
    protected ?string $table = 'nodes';
}

class NestedSetTestIdentityAliasModel extends Model
{
    protected ?string $table = 'nodes';
}

class NestedSetTestOtherTableModel extends Model
{
    protected ?string $table = 'other_nodes';
}

class NestedSetTestNestedTraitNodeModel extends Model
{
    use NestedSetTestNestedNode;

    protected ?string $table = 'nested_set_test_nested_trait_nodes';
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

    /** @use HasBuilder<NestedSetTestCustomBuilder<static>> */
    use HasBuilder {
        HasNode::newEloquentBuilder insteadof HasBuilder;
    }

    protected static string $builder = QueryBuilder::class;

    protected ?string $table = 'nested_set_test_custom_builder_nodes';
}

#[UseEloquentBuilder(QueryBuilder::class)]
class NestedSetTestNestedSetBuilderNodeModel extends Model
{
    use HasNode;

    protected ?string $table = 'nested_set_test_nested_set_builder_nodes';
}

class NestedSetTestBuilderPropertyNodeModel extends Model
{
    use HasNode;

    protected static string $builder = NestedSetTestCustomBuilder::class;

    protected ?string $table = 'nested_set_test_builder_property_nodes';
}

#[UseEloquentBuilder(Builder::class)]
class NestedSetTestIncompatibleBuilderNodeModel extends Model
{
    use HasNode;

    protected ?string $table = 'nested_set_test_incompatible_builder_nodes';
}

/**
 * @template TModel of Model
 * @extends Builder<TModel>
 */
class NestedSetTestPlainBuilder extends Builder
{
}

class NestedSetTestIncompatibleBuilderPropertyNodeModel extends Model
{
    use HasNode;

    protected static string $builder = NestedSetTestPlainBuilder::class;

    protected ?string $table = 'nested_set_test_incompatible_builder_property_nodes';
}
