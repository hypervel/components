<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Grammars\MySqlGrammar;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;

class DatabaseMySqlQueryGrammarTest extends TestCase
{
    public function testToRawSql()
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('escape')->with('foo', false)->andReturn("'foo'");
        $grammar = new MySqlGrammar($connection);

        $query = $grammar->substituteBindingsIntoRawSql(
            'select * from "users" where \'Hello\\\'World?\' IS NOT NULL AND "email" = ?',
            ['foo'],
        );

        $this->assertSame('select * from "users" where \'Hello\\\'World?\' IS NOT NULL AND "email" = \'foo\'', $query);
    }

    public function testTimeout(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')->where('email', 'like', '%test%')->timeout(60);

        $this->assertSame(
            'select /*+ MAX_EXECUTION_TIME(60000) */ * from `users` where `email` like ?',
            $builder->toSql()
        );
    }

    public function testTimeoutWithDistinctAndAggregateQueries(): void
    {
        $builder = $this->getBuilder();
        $builder->distinct()->select('*')->from('users')->timeout(30);
        $this->assertSame(
            'select /*+ MAX_EXECUTION_TIME(30000) */ distinct * from `users`',
            $builder->toSql()
        );

        $builder = $this->getBuilder();
        $builder->from('users')->timeout(10);
        $builder->aggregate = ['function' => 'count', 'columns' => ['*']];
        $this->assertSame(
            'select /*+ MAX_EXECUTION_TIME(10000) */ count(*) as `aggregate` from `users`',
            $builder->toSql()
        );
    }

    public function testTimeoutCanBeCleared(): void
    {
        $builder = $this->getBuilder();
        $builder->select('*')->from('users')->timeout(60)->timeout(null);

        $this->assertSame('select * from `users`', $builder->toSql());
    }

    public function testTimeoutRejectsNonPositiveValues(): void
    {
        foreach ([0, -1] as $timeout) {
            try {
                $this->getBuilder()->timeout($timeout);
                $this->fail('Expected an invalid timeout exception.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Timeout must be greater than zero.', $exception->getMessage());
            }
        }
    }

    protected function getBuilder(): Builder
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getDatabaseName')->andReturn('database');
        $connection->shouldReceive('getTablePrefix')->andReturn('');

        return new Builder(
            $connection,
            new MySqlGrammar($connection),
            m::mock(Processor::class)
        );
    }
}
