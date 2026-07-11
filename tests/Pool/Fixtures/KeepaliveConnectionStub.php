<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pool\Fixtures;

use Hypervel\Context\CoroutineContext;
use Hypervel\Coordinator\Timer;
use Hypervel\Pool\KeepaliveConnection;
use RuntimeException;

class KeepaliveConnectionStub extends KeepaliveConnection
{
    public Timer $timer;

    public int $closeCount = 0;

    public ?RuntimeException $heartbeatFailure = null;

    protected mixed $activeConnection = null;

    public function setActiveConnection(mixed $connection): void
    {
        $this->activeConnection = $connection;
    }

    protected function getActiveConnection(): mixed
    {
        return $this->activeConnection;
    }

    protected function sendClose(mixed $connection): void
    {
        ++$this->closeCount;

        $data = CoroutineContext::get('test.pool.heartbeat_connection', []);
        $data['close'] = 'close protocol';
        CoroutineContext::set('test.pool.heartbeat_connection', $data);
    }

    protected function heartbeat(): void
    {
        if ($this->heartbeatFailure !== null) {
            throw $this->heartbeatFailure;
        }

        $data = CoroutineContext::get('test.pool.heartbeat_connection', []);
        $data['heartbeat'] = 'heartbeat protocol';
        CoroutineContext::set('test.pool.heartbeat_connection', $data);
    }
}
