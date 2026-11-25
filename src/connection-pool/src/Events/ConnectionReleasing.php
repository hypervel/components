<?php

declare(strict_types=1);

namespace Hypervel\ConnectionPool\Events;

use Hypervel\Contracts\ConnectionPool\Connection;
use Hypervel\Contracts\Events\ShouldNotBeDeferred;

class ConnectionReleasing implements ShouldNotBeDeferred
{
    /**
     * Create an event before a connection is returned to its pool.
     */
    public function __construct(
        public Connection $connection
    ) {
    }
}
