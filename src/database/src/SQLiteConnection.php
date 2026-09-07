<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Exception;
use Hypervel\Database\Query\Grammars\SQLiteGrammar;
use Hypervel\Database\Query\Processors\SQLiteProcessor;
use Hypervel\Database\Schema\Grammars\SQLiteGrammar as SQLiteSchemaGrammar;
use Hypervel\Database\Schema\SQLiteBuilder;
use Hypervel\Database\Schema\SqliteSchemaState;
use Hypervel\Filesystem\Filesystem;
use Override;

class SQLiteConnection extends PdoConnection
{
    /**
     * Get a human-readable name for the given connection driver.
     */
    public function getDriverTitle(): string
    {
        return 'SQLite';
    }

    /**
     * Get the default database driver name.
     */
    protected function getDefaultDriverName(): string
    {
        return 'sqlite';
    }

    /**
     * Run the statement to start a new transaction.
     */
    protected function executeBeginTransactionStatement(): void
    {
        $mode = $this->getConfig('transaction_mode') ?? 'DEFERRED';

        $this->getPdo()->exec("BEGIN {$mode} TRANSACTION");
    }

    /**
     * Escape a binary value for safe SQL embedding.
     */
    protected function escapeBinary(string $value): string
    {
        $hex = bin2hex($value);

        return "x'{$hex}'";
    }

    /**
     * Determine if the given database exception was caused by a unique constraint violation.
     */
    protected function isUniqueConstraintError(Exception $exception): bool
    {
        return (bool) preg_match('#(column(s)? .* (is|are) not unique|UNIQUE constraint failed: .*)#i', $exception->getMessage());
    }

    /**
     * Extract the columns that caused a unique constraint violation.
     *
     * @return array{index: null, columns: list<string>}
     */
    protected function parseUniqueConstraintViolation(Exception $exception): array
    {
        preg_match('#UNIQUE constraint failed: (.+)#i', $exception->getMessage(), $matches);

        $columns = [];

        if (isset($matches[1])) {
            $columns = array_map(
                static fn (string $column): string => last(explode('.', trim($column))),
                explode(',', $matches[1])
            );
        }

        return ['columns' => $columns, 'index' => null];
    }

    /**
     * Resolve the maximum number of bindings supported by one statement.
     */
    protected function resolveMaxBindings(): int
    {
        $version = (string) ($this->getConfig('version') ?? $this->getServerVersion());

        return version_compare($version, '3.32.0', '>=') ? 32_766 : self::DEFAULT_MAX_BINDINGS;
    }

    /**
     * Get the default query grammar instance.
     */
    protected function getDefaultQueryGrammar(): SQLiteGrammar
    {
        return new SQLiteGrammar($this);
    }

    /**
     * Get a schema builder instance for the connection.
     */
    public function getSchemaBuilder(): SQLiteBuilder
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new SQLiteBuilder($this);
    }

    /**
     * Get the default schema grammar instance.
     */
    protected function getDefaultSchemaGrammar(): SQLiteSchemaGrammar
    {
        return new SQLiteSchemaGrammar($this);
    }

    /**
     * Get the schema state for the connection.
     */
    #[Override]
    public function getSchemaState(?Filesystem $files = null, ?callable $processFactory = null): SqliteSchemaState
    {
        return new SqliteSchemaState($this, $files, $processFactory);
    }

    /**
     * Get the default post processor instance.
     */
    protected function getDefaultPostProcessor(): SQLiteProcessor
    {
        return new SQLiteProcessor;
    }
}
