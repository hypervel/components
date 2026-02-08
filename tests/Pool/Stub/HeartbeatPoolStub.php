<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pool\Stub;

use Hyperf\Contract\ConnectionInterface;
use Hypervel\Pool\Pool;

class HeartbeatPoolStub extends Pool
{
    protected function createConnection(): ConnectionInterface
    {
        return new KeepaliveConnectionStub($this->container, $this);
    }
}
