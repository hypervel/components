<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Hyperf\Stringable\Str;
use Hypervel\Support\Facades\DB;
use PDOException;
use Throwable;

class QueryException extends PDOException
{
    /**
     * The database connection name.
     */
    public string $connectionName;

    /**
     * The SQL for the query.
     */
    protected string $sql;

    /**
     * The bindings for the query.
     */
    protected array $bindings;

    /**
     * The PDO read / write type for the executed query.
     *
     * @var null|'read'|'write'
     */
    public ?string $readWriteType;

    /**
     * The connection details for the query (host, port, database, etc.).
     */
    protected array $connectionDetails = [];

    /**
     * Create a new query exception instance.
     *
     * @param null|'read'|'write' $readWriteType
     */
    public function __construct(
        string $connectionName,
        string $sql,
        array $bindings,
        Throwable $previous,
        array $connectionDetails = [],
        ?string $readWriteType = null
    ) {
        parent::__construct('', 0, $previous);

        $this->connectionName = $connectionName;
        $this->sql = $sql;
        $this->bindings = $bindings;
        $this->connectionDetails = $connectionDetails;
        $this->readWriteType = $readWriteType;
        $this->code = $previous->getCode();
        $this->message = $this->formatMessage($connectionName, $sql, $bindings, $previous);

        if ($previous instanceof PDOException) {
            $this->errorInfo = $previous->errorInfo;
        }
    }

    /**
     * Format the SQL error message.
     */
    protected function formatMessage(string $connectionName, string $sql, array $bindings, Throwable $previous): string
    {
        $details = $this->formatConnectionDetails();

        return $previous->getMessage() . ' (Connection: ' . $connectionName . $details . ', SQL: ' . Str::replaceArray('?', $bindings, $sql) . ')';
    }

    /**
     * Format the connection details for the error message.
     */
    protected function formatConnectionDetails(): string
    {
        if (empty($this->connectionDetails)) {
            return '';
        }

        $driver = $this->connectionDetails['driver'] ?? '';

        $segments = [];

        if ($driver !== 'sqlite') {
            if (! empty($this->connectionDetails['unix_socket'])) {
                $segments[] = 'Socket: ' . $this->connectionDetails['unix_socket'];
            } else {
                $host = $this->connectionDetails['host'] ?? '';

                $segments[] = 'Host: ' . (is_array($host) ? implode(', ', $host) : $host);
                $segments[] = 'Port: ' . ($this->connectionDetails['port'] ?? '');
            }
        }

        $segments[] = 'Database: ' . ($this->connectionDetails['database'] ?? '');

        return ', ' . implode(', ', $segments);
    }

    /**
     * Get the connection name for the query.
     */
    public function getConnectionName(): string
    {
        return $this->connectionName;
    }

    /**
     * Get the SQL for the query.
     */
    public function getSql(): string
    {
        return $this->sql;
    }

    /**
     * Get the raw SQL representation of the query with embedded bindings.
     */
    public function getRawSql(): string
    {
        return DB::connection($this->getConnectionName())
            ->getQueryGrammar()
            ->substituteBindingsIntoRawSql($this->getSql(), $this->getBindings());
    }

    /**
     * Get the bindings for the query.
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Get information about the connection such as host, port, database, etc.
     */
    public function getConnectionDetails(): array
    {
        return $this->connectionDetails;
    }
}
