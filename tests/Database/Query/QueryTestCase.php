<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Query;

use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\Query\Expression;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Grammars\MySqlGrammar;
use Hypervel\Database\Query\Grammars\PostgresGrammar;
use Hypervel\Database\Query\Grammars\SQLiteGrammar;
use Hypervel\Testbench\TestCase;
use Mockery as m;

/**
 * Base test case for Query Builder and Grammar tests.
 *
 * Provides helpers to create builders with mock connections and real grammars.
 *
 * @internal
 */
abstract class QueryTestCase extends TestCase
{
    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }

    protected function getBuilder(): Builder
    {
        return $this->getMySqlBuilder();
    }

    protected function getMySqlBuilder(): Builder
    {
        return new Builder(
            $this->getMockConnection(),
            new MySqlGrammar(),
            m::mock(Processor::class)
        );
    }

    protected function getPostgresBuilder(): Builder
    {
        return new Builder(
            $this->getMockConnection(),
            new PostgresGrammar(),
            m::mock(Processor::class)
        );
    }

    protected function getSQLiteBuilder(): Builder
    {
        return new Builder(
            $this->getMockConnection(),
            new SQLiteGrammar(),
            m::mock(Processor::class)
        );
    }

    protected function getMockConnection(): ConnectionInterface
    {
        $connection = m::mock(ConnectionInterface::class);
        $connection->shouldReceive('getDatabaseName')->andReturn('database');
        $connection->shouldReceive('getTablePrefix')->andReturn('');
        $connection->shouldReceive('raw')->andReturnUsing(
            fn ($value) => new Expression($value)
        );

        return $connection;
    }
}
