<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel;

use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class DatabaseIntegrationTest extends TestCase
{
    protected array $listeners = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->listeners = [];

        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('listen')->andReturnUsing(function ($event, $callback) {
            $this->listeners[$event] = $callback;
        });
        $dispatcher->shouldReceive('dispatch')->andReturnUsing(function ($event) {
            $eventClass = get_class($event);
            if (isset($this->listeners[$eventClass])) {
                ($this->listeners[$eventClass])($event);
            }
        });

        $db = new DB();
        $db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $db->setAsGlobal();
        $db->setEventDispatcher($dispatcher);
    }

    public function testQueryExecutedToRawSql(): void
    {
        $connection = DB::connection();

        $connection->listen(function (QueryExecuted $query) use (&$queryExecuted): void {
            $queryExecuted = $query;
        });

        $connection->select('select ?', [true]);

        $this->assertInstanceOf(QueryExecuted::class, $queryExecuted);
        $this->assertSame('select ?', $queryExecuted->sql);
        $this->assertSame([true], $queryExecuted->bindings);
        $this->assertSame('select 1', $queryExecuted->toRawSql());
    }
}
