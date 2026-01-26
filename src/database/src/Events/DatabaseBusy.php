<?php

declare(strict_types=1);

namespace Hypervel\Database\Events;

class DatabaseBusy
{
    /**
     * Create a new event instance.
     *
     * @param string $connectionName the database connection name
     * @param int $connections the number of open connections
     */
    public function __construct(
        public string $connectionName,
        public int $connections,
    ) {
    }
}
