<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Eloquent;

use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Database\Query\Grammars\Grammar;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphPivot;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Testbench\TestCase;
use Mockery as m;

/**
 * Tests that Model, Pivot, and MorphPivot delegate to the connection's
 * query() method when creating base query builders. This allows custom
 * connections to provide custom query builders with additional methods.
 *
 * @internal
 * @coversNothing
 */
class NewBaseQueryBuilderTest extends TestCase
{
    public function testModelUsesConnectionQueryMethod(): void
    {
        $customBuilder = new CustomQueryBuilder(
            m::mock(ConnectionInterface::class),
            new Grammar(),
            new Processor()
        );

        $connection = m::mock(ConnectionInterface::class);
        $connection->shouldReceive('query')->once()->andReturn($customBuilder);

        $model = new NewBaseQueryBuilderTestModel();
        $model->setTestConnection($connection);

        $builder = $model->testNewBaseQueryBuilder();

        $this->assertInstanceOf(CustomQueryBuilder::class, $builder);
        $this->assertSame($customBuilder, $builder);
    }

    public function testPivotUsesConnectionQueryMethod(): void
    {
        $customBuilder = new CustomQueryBuilder(
            m::mock(ConnectionInterface::class),
            new Grammar(),
            new Processor()
        );

        $connection = m::mock(ConnectionInterface::class);
        $connection->shouldReceive('query')->once()->andReturn($customBuilder);

        $pivot = new NewBaseQueryBuilderTestPivot();
        $pivot->setTestConnection($connection);

        $builder = $pivot->testNewBaseQueryBuilder();

        $this->assertInstanceOf(CustomQueryBuilder::class, $builder);
        $this->assertSame($customBuilder, $builder);
    }

    public function testMorphPivotUsesConnectionQueryMethod(): void
    {
        $customBuilder = new CustomQueryBuilder(
            m::mock(ConnectionInterface::class),
            new Grammar(),
            new Processor()
        );

        $connection = m::mock(ConnectionInterface::class);
        $connection->shouldReceive('query')->once()->andReturn($customBuilder);

        $morphPivot = new NewBaseQueryBuilderTestMorphPivot();
        $morphPivot->setTestConnection($connection);

        $builder = $morphPivot->testNewBaseQueryBuilder();

        $this->assertInstanceOf(CustomQueryBuilder::class, $builder);
        $this->assertSame($customBuilder, $builder);
    }
}

// Test fixtures

class NewBaseQueryBuilderTestModel extends Model
{
    protected ?string $table = 'test_models';

    protected ?ConnectionInterface $testConnection = null;

    public function setTestConnection(ConnectionInterface $connection): void
    {
        $this->testConnection = $connection;
    }

    public function getConnection(): ConnectionInterface
    {
        return $this->testConnection ?? parent::getConnection();
    }

    public function testNewBaseQueryBuilder(): QueryBuilder
    {
        return $this->newBaseQueryBuilder();
    }
}

class NewBaseQueryBuilderTestPivot extends Pivot
{
    protected ?string $table = 'test_pivots';

    protected ?ConnectionInterface $testConnection = null;

    public function setTestConnection(ConnectionInterface $connection): void
    {
        $this->testConnection = $connection;
    }

    public function getConnection(): ConnectionInterface
    {
        return $this->testConnection ?? parent::getConnection();
    }

    public function testNewBaseQueryBuilder(): QueryBuilder
    {
        return $this->newBaseQueryBuilder();
    }
}

class NewBaseQueryBuilderTestMorphPivot extends MorphPivot
{
    protected ?string $table = 'test_morph_pivots';

    protected ?ConnectionInterface $testConnection = null;

    public function setTestConnection(ConnectionInterface $connection): void
    {
        $this->testConnection = $connection;
    }

    public function getConnection(): ConnectionInterface
    {
        return $this->testConnection ?? parent::getConnection();
    }

    public function testNewBaseQueryBuilder(): QueryBuilder
    {
        return $this->newBaseQueryBuilder();
    }
}

/**
 * A custom query builder to verify the connection's builder is used.
 */
class CustomQueryBuilder extends QueryBuilder
{
}
