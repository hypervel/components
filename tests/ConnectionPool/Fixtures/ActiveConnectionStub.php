<?php

declare(strict_types=1);

namespace Hypervel\Tests\ConnectionPool\Fixtures;

use Hypervel\ConnectionPool\Connection;

class ActiveConnectionStub extends Connection
{
    public function getActiveConnection(): mixed
    {
        return $this;
    }

    public function reconnect(): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }
}
