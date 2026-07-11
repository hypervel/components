<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pool;

use Hypervel\Container\Container;
use Hypervel\Contracts\Pool\ConnectionInterface;
use Hypervel\Pool\Pool;
use Hypervel\Tests\TestCase;
use RuntimeException;

class PoolNonCoroutineTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testExhaustedPoolFailsWithoutTryingToBlock(): void
    {
        $container = new Container;
        Container::setInstance($container);
        $pool = new NonCoroutinePool($container, 'test', ['max_connections' => 1]);
        $pool->get();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(
            'Connection pool exhausted. Cannot establish new connection before wait_timeout.'
        );

        $pool->get();
    }
}

class NonCoroutinePool extends Pool
{
    protected function createConnection(): ConnectionInterface
    {
        return new NonCoroutinePoolConnection;
    }
}

class NonCoroutinePoolConnection implements ConnectionInterface
{
    public function getConnection(): mixed
    {
        return $this;
    }

    public function reconnect(): bool
    {
        return true;
    }

    public function check(): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function release(): void
    {
    }
}
