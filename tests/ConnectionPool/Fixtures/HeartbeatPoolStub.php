<?php

declare(strict_types=1);

namespace Hypervel\Tests\ConnectionPool\Fixtures;

use Hypervel\ConnectionPool\ConnectionPool;
use Hypervel\Contracts\ConnectionPool\Connection;

class HeartbeatPoolStub extends ConnectionPool
{
    protected function createConnection(): Connection
    {
        return new KeepaliveConnectionStub($this->container, $this);
    }
}
