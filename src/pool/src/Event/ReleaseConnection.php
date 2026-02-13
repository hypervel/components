<?php

declare(strict_types=1);

namespace Hypervel\Pool\Event;

use Hypervel\Contracts\Pool\ConnectionInterface;

/**
 * Event dispatched when a connection is released back to the pool.
 */
class ReleaseConnection
{
    public function __construct(
        public ConnectionInterface $connection
    ) {
    }
}
