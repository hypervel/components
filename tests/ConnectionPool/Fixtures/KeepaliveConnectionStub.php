<?php

declare(strict_types=1);

namespace Hypervel\Tests\ConnectionPool\Fixtures;

use Closure;
use Hypervel\ConnectionPool\KeepaliveConnection;
use Hypervel\Context\CoroutineContext;
use Hypervel\Coordinator\Timer;
use Throwable;

class KeepaliveConnectionStub extends KeepaliveConnection
{
    public Timer $timer;

    public int $closeCount = 0;

    public ?Throwable $heartbeatFailure = null;

    public ?Closure $createCallback = null;

    public ?Closure $heartbeatCallback = null;

    public ?Closure $closeCallback = null;

    protected mixed $activeConnection = null;

    public function setActiveConnection(mixed $connection): void
    {
        $this->activeConnection = $connection;
    }

    protected function getActiveConnection(): mixed
    {
        if ($this->createCallback !== null) {
            return ($this->createCallback)();
        }

        return $this->activeConnection;
    }

    protected function sendClose(mixed $connection): void
    {
        ++$this->closeCount;
        $this->closeCallback?->__invoke($connection);

        $data = CoroutineContext::get('test.pool.heartbeat_connection', []);
        $data['close'] = 'close protocol';
        CoroutineContext::set('test.pool.heartbeat_connection', $data);
    }

    protected function heartbeat(): void
    {
        $this->heartbeatCallback?->__invoke();

        if ($this->heartbeatFailure !== null) {
            throw $this->heartbeatFailure;
        }

        $data = CoroutineContext::get('test.pool.heartbeat_connection', []);
        $data['heartbeat'] = 'heartbeat protocol';
        CoroutineContext::set('test.pool.heartbeat_connection', $data);
    }
}
