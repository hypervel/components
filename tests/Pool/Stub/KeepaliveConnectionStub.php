<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pool\Stub;

use Hypervel\Context\Context;
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
        $data = Context::get('test.pool.heartbeat_connection', []);
        $data['close'] = 'close protocol';
        Context::set('test.pool.heartbeat_connection', $data);
    }

    protected function heartbeat(): void
    {
        $data = Context::get('test.pool.heartbeat_connection', []);
        $data['heartbeat'] = 'heartbeat protocol';
        Context::set('test.pool.heartbeat_connection', $data);
    }
}
