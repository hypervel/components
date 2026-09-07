<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Grammars\MySqlGrammar;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use JsonException;
use Mockery as m;

class DatabaseMySqlQueryGrammarTest extends TestCase
{
    public function testUpdateBindingsRejectUnencodableArrays(): void
    {
        $this->expectException(JsonException::class);

        (new MySqlGrammar(m::mock(Connection::class)))
            ->prepareBindingsForUpdate([], ['payload' => [NAN]]);
    }

    public function testToRawSql(): void
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

    public function testTimeoutDecoratesOrdinaryAndAggregateUnionStatementsOnce(): void
    {
        $builder = $this->getBuilder()
            ->select('*')
            ->from('posts')
            ->where('published', true)
            ->unionAll(
                $this->getBuilder()
                    ->select('*')
                    ->from('videos')
                    ->where('published', false)
            )
            ->timeout(5);

        $this->assertSame(
            '(select /*+ MAX_EXECUTION_TIME(5000) */ * from `posts` where `published` = ?) union all (select * from `videos` where `published` = ?)',
            $builder->toSql()
        );
        $this->assertSame([true, false], $builder->getBindings());

        $builder->aggregate = ['function' => 'count', 'columns' => ['*']];

        $sql = $builder->toSql();

        $this->assertSame(
            'select /*+ MAX_EXECUTION_TIME(5000) */ count(*) as `aggregate` from ((select * from `posts` where `published` = ?) union all (select * from `videos` where `published` = ?)) as `temp_table`',
            $sql
        );
        $this->assertSame(1, substr_count($sql, 'MAX_EXECUTION_TIME'));
    }

    public function testTimeoutDecoratesExistsAndLockingStatementsAtTheRoot(): void
    {
        $exists = $this->getBuilder()->from('users')->where('active', true)->timeout(4);

        $this->assertSame(
            'select /*+ MAX_EXECUTION_TIME(4000) */ exists(select * from `users` where `active` = ?) as `exists`',
            $exists->getGrammar()->compileExists($exists)
        );

        $locking = $this->getBuilder()->from('users')->where('id', 1)->lockForUpdate()->timeout(3);

        $this->assertSame(
            'select /*+ MAX_EXECUTION_TIME(3000) */ * from `users` where `id` = ? for update',
            $locking->toSql()
        );
    }

    public function testOuterTimeoutLeavesAnUntimedSubqueryUndecorated(): void
    {
        $subquery = $this->getBuilder()->select('user_id')->from('memberships')->where('active', true);
        $builder = $this->getBuilder()
            ->select('*')
            ->from('users')
            ->whereIn('id', $subquery)
            ->where('status', 'enabled')
            ->timeout(6);

        $sql = $builder->toSql();

        $this->assertSame(
            'select /*+ MAX_EXECUTION_TIME(6000) */ * from `users` where `id` in (select `user_id` from `memberships` where `active` = ?) and `status` = ?',
            $sql
        );
        $this->assertSame([true, 'enabled'], $builder->getBindings());
        $this->assertSame(1, substr_count($sql, 'MAX_EXECUTION_TIME'));
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
