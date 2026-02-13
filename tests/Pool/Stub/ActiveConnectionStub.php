<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pool\Stub;

use Exception;
use Hypervel\Pool\Connection;

class ActiveConnectionStub extends Connection
{
    public int $count = 0;

    public function getActiveConnection(): mixed
    {
        if ($this->count === 0) {
            ++$this->count;
            throw new Exception();
        }

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
