<?php

declare(strict_types=1);

namespace Hypervel\Database\Events;

use Hypervel\Database\Connection;
use Throwable;

class QueryFailed
{
    /**
     * The database connection name.
     */
    public ?string $connectionName;

    /**
     * Create a new event instance.
     *
     * @param null|'read'|'write' $readWriteType
     */
    public function __construct(
        public string $sql,
        public array $bindings,
        public float $time,
        public Connection $connection,
        public Throwable $exception,
        public ?string $readWriteType = null,
    ) {
        $this->connectionName = $connection->getName();
    }
}
