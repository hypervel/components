<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Exception;
use Hypervel\Database\Query\Grammars\SQLiteGrammar as QueryGrammar;
use Hypervel\Database\Query\Processors\SQLiteProcessor;
use Hypervel\Database\Schema\Grammars\SQLiteGrammar as SchemaGrammar;
use Hypervel\Database\Schema\SQLiteBuilder;
use Hypervel\Database\Schema\SqliteSchemaState;
use Hypervel\Filesystem\Filesystem;

class SQLiteConnection extends Connection
{
    /**
     * {@inheritdoc}
     */
    public function getDriverTitle()
    {
        return 'SQLite';
    }

    /**
     * Run the statement to start a new transaction.
     *
     * @return void
     */
    protected function executeBeginTransactionStatement()
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
     *
     * @param  string  $value
     * @return string
     */
    protected function escapeBinary($value)
    {
        $hex = bin2hex($value);

        return "x'{$hex}'";
    }

    /**
     * Determine if the given database exception was caused by a unique constraint violation.
     *
     * @param  \Exception  $exception
     * @return bool
     */
    protected function isUniqueConstraintError(Exception $exception)
    {
        return (bool) preg_match('#(column(s)? .* (is|are) not unique|UNIQUE constraint failed: .*)#i', $exception->getMessage());
    }

    /**
     * Get the default query grammar instance.
     *
     * @return \Hypervel\Database\Query\Grammars\SQLiteGrammar
     */
    protected function getDefaultQueryGrammar()
    {
        return new QueryGrammar($this);
    }

    /**
     * Get a schema builder instance for the connection.
     *
     * @return \Hypervel\Database\Schema\SQLiteBuilder
     */
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new SQLiteBuilder($this);
    }

    /**
     * Get the default schema grammar instance.
     *
     * @return \Hypervel\Database\Schema\Grammars\SQLiteGrammar
     */
    protected function getDefaultSchemaGrammar()
    {
        return new SchemaGrammar($this);
    }

    /**
     * Get the schema state for the connection.
     *
     * @param  \Hypervel\Filesystem\Filesystem|null  $files
     * @param  callable|null  $processFactory
     *
     * @throws \RuntimeException
     */
    public function getSchemaState(?Filesystem $files = null, ?callable $processFactory = null)
    {
        return new SqliteSchemaState($this, $files, $processFactory);
    }

    /**
     * Get the default post processor instance.
     *
     * @return \Hypervel\Database\Query\Processors\SQLiteProcessor
     */
    protected function getDefaultPostProcessor()
    {
        return new SQLiteProcessor;
    }
}
