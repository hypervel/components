<?php

declare(strict_types=1);

namespace Hypervel\Database\Events;

use Hypervel\Database\Connection;

class QueryExecuted
{
    /**
     * The SQL query that was executed.
     */
    public string $sql;

    /**
     * The array of query bindings.
     */
    public array $bindings;

    /**
     * The number of milliseconds it took to execute the query.
     */
    public ?float $time;

    /**
     * The database connection instance.
     */
    public Connection $connection;

    /**
     * The database connection name.
     */
    public string $connectionName;

    /**
     * The PDO read / write type for the executed query.
     *
     * @var null|'read'|'write'
     */
    public ?string $readWriteType;

    /**
     * Create a new event instance.
     *
     * @param null|'read'|'write' $readWriteType
     */
    public function __construct(
        string $sql,
        array $bindings,
        ?float $time,
        Connection $connection,
        ?string $readWriteType = null
    ) {
        $this->sql = $sql;
        $this->time = $time;
        $this->bindings = $bindings;
        $this->connection = $connection;
        $this->connectionName = $connection->getName();
        $this->readWriteType = $readWriteType;
    }

    /**
     * Get the raw SQL representation of the query with embedded bindings.
     */
    public function toRawSql(): string
    {
        return $this->connection
            ->query()
            ->getGrammar()
            ->substituteBindingsIntoRawSql($this->sql, $this->connection->prepareBindings($this->bindings));
    }
}
