<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pool\Stub;

use Hyperf\Contract\ConnectionInterface;
use Hypervel\Pool\Pool;
use Mockery as m;

class FooPool extends Pool
{
    protected function createConnection(): ConnectionInterface
    {
        return m::mock(ConnectionInterface::class);
    }
}
