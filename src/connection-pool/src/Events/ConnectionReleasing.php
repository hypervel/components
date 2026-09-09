<?php

declare(strict_types=1);

namespace Hypervel\ConnectionPool\Events;

use Hypervel\Contracts\ConnectionPool\Connection;

class ConnectionReleasing
{
    /**
     * Create an event before a connection is returned to its pool.
     */
    public function __construct(
        public Connection $connection
    ) {
    }
}
