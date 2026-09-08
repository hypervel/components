<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Exceptions\Renderer;

use Hypervel\Database\Connection;
use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Foundation\Exceptions\Renderer\Exception;
use Hypervel\Foundation\Exceptions\Renderer\Listener;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Symfony\Component\ErrorHandler\Exception\FlattenException;

class ExceptionTest extends TestCase
{
    public function testApplicationQueriesPreserveBindingText(): void
    {
        $connection = m::mock(Connection::class);
        $connection->shouldReceive('getName')->once()->andReturn(null);
        $connection->shouldReceive('prepareBindings')->once()->andReturnUsing(fn (array $bindings): array => $bindings);

        $listener = new Listener;
        $listener->onQueryExecuted(new QueryExecuted(
            'select * from t where a = ? and b = ? and c = ? and d = ? and e = ?',
            ['$1 off?', 'next \1', 7, 1.5, null],
            null,
            $connection,
        ));

        $exception = new Exception(
            FlattenException::createFromThrowable(new RuntimeException('Example exception.')),
            Request::create('/'),
            $listener,
            __DIR__,
        );

        $this->assertSame([[
            'connectionName' => null,
            'time' => null,
            'sql' => "select * from t where a = '$1 off?' and b = 'next \\1' and c = 7 and d = 1.5 and e = NULL",
        ]], $exception->applicationQueries());
    }
}
