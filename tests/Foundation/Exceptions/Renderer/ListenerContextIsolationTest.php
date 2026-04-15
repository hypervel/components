<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Exceptions\Renderer;

use Hypervel\Database\Connection;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Foundation\Exceptions\Renderer\Listener;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

class ListenerContextIsolationTest extends TestCase
{
    public function testQueryCapStopsAtMaxQueries()
    {
        $listener = new Listener;

        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getName')->andReturn('testing');
        $connection->shouldReceive('prepareBindings')->andReturn([]);

        for ($i = 0; $i < 110; ++$i) {
            $listener->onQueryExecuted(
                new QueryExecuted("SELECT {$i}", [], 1.0, $connection)
            );
        }

        $this->assertCount(101, $listener->queries());
    }

    public function testQueriesAreIsolatedBetweenCoroutines()
    {
        $channel = new Channel(2);

        Coroutine::create(function () use ($channel) {
            $listener = new Listener;

            $connection = m::mock(Connection::class);
            $connection->shouldReceive('getName')->andReturn('conn-a');
            $connection->shouldReceive('prepareBindings')->andReturn([]);

            $listener->onQueryExecuted(
                new QueryExecuted('SELECT a1', [], 1.0, $connection)
            );
            $listener->onQueryExecuted(
                new QueryExecuted('SELECT a2', [], 2.0, $connection)
            );

            $channel->push([
                'count' => count($listener->queries()),
                'sqls' => array_column($listener->queries(), 'sql'),
            ]);
        });

        Coroutine::create(function () use ($channel) {
            $listener = new Listener;

            $connection = m::mock(Connection::class);
            $connection->shouldReceive('getName')->andReturn('conn-b');
            $connection->shouldReceive('prepareBindings')->andReturn([]);

            $listener->onQueryExecuted(
                new QueryExecuted('SELECT b1', [], 3.0, $connection)
            );

            $channel->push([
                'count' => count($listener->queries()),
                'sqls' => array_column($listener->queries(), 'sql'),
            ]);
        });

        $results = [];
        $results[] = $channel->pop();
        $results[] = $channel->pop();

        // Sort by count so assertions are deterministic
        usort($results, fn ($a, $b) => $a['count'] <=> $b['count']);

        // Coroutine B: 1 query, only its own
        $this->assertSame(1, $results[0]['count']);
        $this->assertSame(['SELECT b1'], $results[0]['sqls']);

        // Coroutine A: 2 queries, only its own
        $this->assertSame(2, $results[1]['count']);
        $this->assertSame(['SELECT a1', 'SELECT a2'], $results[1]['sqls']);
    }
}
