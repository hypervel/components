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
    public function testQueriesReturnsExpectedShapeAfterQueryExecuted()
    {
        $connection = m::mock(Connection::class);

        $connection->shouldReceive('getName')->andReturn('testing');
        $connection->shouldReceive('prepareBindings')->with(['foo'])->andReturn(['foo']);

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

        $this->assertEquals('testing', $query['connectionName']);
        $this->assertEquals(5.2, $query['time']);
        $this->assertEquals('select * from users where id = ?', $query['sql']);
        $this->assertEquals(['foo'], $query['bindings']);
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
