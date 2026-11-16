<?php

declare(strict_types=1);

namespace Hypervel\Tests\ConnectionPool\Fixtures;

use Hypervel\ConnectionPool\ConnectionPool;
use Hypervel\Contracts\ConnectionPool\Connection;
use JMac\Testing\Double;

class FooPool extends ConnectionPool
{
    protected function createConnection(): Connection
    {
        return Double::for(Connection::class);
    }
}
