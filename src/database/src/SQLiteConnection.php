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

class SQLiteConnection extends Connection
{
    /**
     * Get a human-readable name for the given connection driver.
     */
    public function getDriverTitle(): string
    {
        return 'SQLite';
    }

    /**
     * Run the statement to start a new transaction.
     */
    protected function executeBeginTransactionStatement(): void
    {
        if (version_compare(PHP_VERSION, '8.4.0', '>=')) {
            $mode = $this->getConfig('transaction_mode') ?? 'DEFERRED';

            $this->getPdo()->exec("BEGIN {$mode} TRANSACTION");

            return;
        }

        $this->getPdo()->beginTransaction();
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
        return new SQLiteProcessor();
    }
}
