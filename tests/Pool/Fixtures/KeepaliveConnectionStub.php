<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pool\Fixtures;

use Hypervel\Context\CoroutineContext;
use Hypervel\Coordinator\Timer;
use Hypervel\Pool\KeepaliveConnection;

class KeepaliveConnectionStub extends KeepaliveConnection
{
    public Timer $timer;

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
        $data = CoroutineContext::get('test.pool.heartbeat_connection', []);
        $data['close'] = 'close protocol';
        CoroutineContext::set('test.pool.heartbeat_connection', $data);
    }

    protected function heartbeat(): void
    {
        $data = CoroutineContext::get('test.pool.heartbeat_connection', []);
        $data['heartbeat'] = 'heartbeat protocol';
        CoroutineContext::set('test.pool.heartbeat_connection', $data);
    }
}
