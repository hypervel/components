<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Exceptions\Renderer;

use Hypervel\Database\Connection;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Foundation\Exceptions\Renderer\Listener;
use Hypervel\Tests\TestCase;
use Mockery as m;

class ListenerTest extends TestCase
{
    public function testQueriesReturnsExpectedShapeAfterQueryExecuted(): void
    {
        $connection = m::mock(Connection::class);

        $connection->shouldReceive('getName')->once()->andReturn('testing');
        $connection->shouldReceive('prepareBindings')->once()->with(['foo'])->andReturn(['foo']);

        $event = new QueryExecuted('select * from users where id = ?', ['foo'], 5.2, $connection);

        $listener = new Listener;

        $listener->onQueryExecuted($event);

        $queries = $listener->queries();

        $this->assertIsArray($queries);
        $this->assertCount(1, $queries);

        $query = $queries[0];

        $this->assertArrayHasKey('connectionName', $query);
        $this->assertArrayHasKey('time', $query);
        $this->assertArrayHasKey('sql', $query);
        $this->assertArrayHasKey('bindings', $query);

        $this->assertSame('testing', $query['connectionName']);
        $this->assertSame(5.2, $query['time']);
        $this->assertSame('select * from users where id = ?', $query['sql']);
        $this->assertEquals(['foo'], $query['bindings']);
    }

    public function testListenerCapsAt100Queries(): void
    {
        $listener = new Listener;

        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getName')->times(150)->andReturn('testing');
        $connection->shouldReceive('prepareBindings')->times(100)->andReturnUsing(fn (array $bindings): array => $bindings);

        for ($index = 0; $index < 150; ++$index) {
            $listener->onQueryExecuted(
                new QueryExecuted("select {$index}", [], 1.0, $connection)
            );
        }

        $this->assertCount(100, $listener->queries());
        $this->assertSame('select 0', $listener->queries()[0]['sql']);
        $this->assertSame('select 99', $listener->queries()[99]['sql']);
    }

    public function testLargeSqlIsTruncated(): void
    {
        $listener = new Listener;

        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getName')->once()->andReturn('testing');
        $connection->shouldReceive('prepareBindings')->once()->andReturnUsing(fn (array $bindings): array => $bindings);

        $largeSql = str_repeat('x', 5000);
        $listener->onQueryExecuted(
            new QueryExecuted($largeSql, [], 1.0, $connection)
        );

        $this->assertLessThanOrEqual(2000, strlen($listener->queries()[0]['sql']));
    }

    public function testBindingsMatchPlaceholderCountInTruncatedSql(): void
    {
        $listener = new Listener;

        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getName')->once()->andReturn('testing');
        $connection->shouldReceive('prepareBindings')->once()->andReturnUsing(fn (array $bindings): array => $bindings);

        // Build SQL with 1000 placeholders so truncation to 2000 bytes removes
        // some placeholders and their corresponding bindings.
        $placeholders = implode(', ', array_fill(0, 1000, '?'));
        $sql = "INSERT INTO t (a) VALUES ({$placeholders})";
        $bindings = array_fill(0, 1000, 'value');

        $listener->onQueryExecuted(
            new QueryExecuted($sql, $bindings, 1.0, $connection)
        );

        $storedQuery = $listener->queries()[0];
        $storedPlaceholders = substr_count($storedQuery['sql'], '?');

        $this->assertSame(2000, strlen($storedQuery['sql']));
        $this->assertCount($storedPlaceholders, $storedQuery['bindings']);
    }

    public function testExcessBindingsAreTrimmedToMatchPlaceholders(): void
    {
        $listener = new Listener;

        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getName')->once()->andReturn('testing');
        $connection->shouldReceive('prepareBindings')->once()->andReturnUsing(fn (array $bindings): array => $bindings);

        // 1 placeholder but 1000 bindings — only 1 binding should be kept
        $listener->onQueryExecuted(
            new QueryExecuted('select ?', array_fill(0, 1000, 'v'), 1.0, $connection)
        );

        $this->assertCount(1, $listener->queries()[0]['bindings']);
    }

    public function testShortSqlAndBindingsAreNotModified(): void
    {
        $listener = new Listener;

        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getName')->once()->andReturn('testing');
        $connection->shouldReceive('prepareBindings')->once()->andReturnUsing(fn (array $bindings): array => $bindings);

        $sql = 'select * from users where name = ?';
        $listener->onQueryExecuted(
            new QueryExecuted($sql, ['John'], 1.0, $connection)
        );

        $this->assertEquals($sql, $listener->queries()[0]['sql']);
        $this->assertEquals(['John'], $listener->queries()[0]['bindings']);
    }

    public function testQueryWithNoBindingsIsUnchanged(): void
    {
        $listener = new Listener;

        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getName')->once()->andReturn('testing');
        $connection->shouldReceive('prepareBindings')->once()->andReturnUsing(fn (array $bindings): array => $bindings);

        $listener->onQueryExecuted(
            new QueryExecuted('select count(*) from users', [], 1.0, $connection)
        );

        $this->assertSame('select count(*) from users', $listener->queries()[0]['sql']);
        $this->assertEmpty($listener->queries()[0]['bindings']);
    }

    public function testNormalQuerySkipsTruncation(): void
    {
        $listener = new Listener;

        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getName')->once()->andReturn('testing');
        $connection->shouldReceive('prepareBindings')->once()->andReturnUsing(fn (array $bindings): array => $bindings);

        $sql = 'select * from users where id = ? and name = ? and email = ?';
        $bindings = [1, 'John', 'john@example.com'];

        $listener->onQueryExecuted(
            new QueryExecuted($sql, $bindings, 1.0, $connection)
        );

        $storedQuery = $listener->queries()[0];

        // Nothing should be modified — SQL is short and bindings match placeholders
        $this->assertEquals($sql, $storedQuery['sql']);
        $this->assertEquals($bindings, $storedQuery['bindings']);
    }

    public function testLongQueriesAndBindingsAreBounded(): void
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getName')->once()->andReturn('testing');
        $connection->shouldReceive('prepareBindings')
            ->once()
            ->with(['first', 'second', 'third'])
            ->andReturn(['first', 'second', 'third']);

        $listener = new Listener;
        $listener->onQueryExecuted(new QueryExecuted(
            str_repeat('é', 999) . '?' . str_repeat('é', 20) . '??',
            ['first', 'second', 'third'],
            5.2,
            $connection,
        ));

        $query = $listener->queries()[0];

        $this->assertLessThanOrEqual(2000, strlen($query['sql']));
        $this->assertTrue(mb_check_encoding($query['sql'], 'UTF-8'));
        $this->assertSame(1, substr_count($query['sql'], '?'));
        $this->assertSame(['first'], $query['bindings']);
    }
}
