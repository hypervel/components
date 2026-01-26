<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Closure;
use Generator;
use Hypervel\Database\Query\Builder;
use Hypervel\Database\Query\Expression;
use Hypervel\Database\Query\Grammars\Grammar as QueryGrammar;
use Hypervel\Database\Query\Processors\Processor;
use Hypervel\Database\Schema\Builder as SchemaBuilder;
use PDO;
use Throwable;
use UnitEnum;

interface ConnectionInterface
{
    /**
     * Begin a fluent query against a database table.
     */
    public function table(Closure|Builder|UnitEnum|string $table, ?string $as = null): Builder;

    /**
     * Get a new raw query expression.
     */
    public function raw(mixed $value): Expression;

    /**
     * Run a select statement and return a single result.
     */
    public function selectOne(string $query, array $bindings = [], bool $useReadPdo = true): mixed;

    /**
     * Run a select statement and return the first column of the first row.
     *
     * @throws MultipleColumnsSelectedException
     */
    public function scalar(string $query, array $bindings = [], bool $useReadPdo = true): mixed;

    /**
     * Run a select statement against the database.
     */
    public function select(string $query, array $bindings = [], bool $useReadPdo = true): array;

    /**
     * Run a select statement against the database and returns a generator.
     */
    public function cursor(string $query, array $bindings = [], bool $useReadPdo = true): Generator;

    /**
     * Run an insert statement against the database.
     */
    public function insert(string $query, array $bindings = []): bool;

    /**
     * Run an update statement against the database.
     */
    public function update(string $query, array $bindings = []): int;

    /**
     * Run a delete statement against the database.
     */
    public function delete(string $query, array $bindings = []): int;

    /**
     * Execute an SQL statement and return the boolean result.
     */
    public function statement(string $query, array $bindings = []): bool;

    /**
     * Run an SQL statement and get the number of rows affected.
     */
    public function affectingStatement(string $query, array $bindings = []): int;

    /**
     * Run a raw, unprepared query against the PDO connection.
     */
    public function unprepared(string $query): bool;

    /**
     * Prepare the query bindings for execution.
     */
    public function prepareBindings(array $bindings): array;

    /**
     * Execute a Closure within a transaction.
     *
     * @throws Throwable
     */
    public function transaction(Closure $callback, int $attempts = 1): mixed;

    /**
     * Start a new database transaction.
     */
    public function beginTransaction(): void;

    /**
     * Commit the active database transaction.
     */
    public function commit(): void;

    /**
     * Rollback the active database transaction.
     */
    public function rollBack(): void;

    /**
     * Get the number of active transactions.
     */
    public function transactionLevel(): int;

    /**
     * Execute the given callback in "dry run" mode.
     */
    public function pretend(Closure $callback): array;

    /**
     * Get the name of the connected database.
     */
    public function getDatabaseName(): string;

    /**
     * Get the connection name.
     */
    public function getName(): ?string;

    /**
     * Get a schema builder instance for the connection.
     */
    public function getSchemaBuilder(): SchemaBuilder;

    /**
     * Get the query grammar used by the connection.
     */
    public function getQueryGrammar(): QueryGrammar;

    /**
     * Get the query post processor used by the connection.
     */
    public function getPostProcessor(): Processor;

    /**
     * Get the current PDO connection.
     */
    public function getPdo(): PDO;

    /**
     * Get the table prefix for the connection.
     */
    public function getTablePrefix(): string;

    /**
     * Get the database server version.
     */
    public function getServerVersion(): string;

    /**
     * Get an option from the configuration options.
     */
    public function getConfig(?string $option = null): mixed;

    /**
     * Run a select statement against the database using the write connection.
     */
    public function selectFromWriteConnection(string $query, array $bindings = []): array;

    /**
     * Indicate if any records have been modified.
     */
    public function recordsHaveBeenModified(bool $value = true): void;
}
