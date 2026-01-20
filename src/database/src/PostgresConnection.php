<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Exception;
use Hypervel\Database\Query\Grammars\PostgresGrammar as QueryGrammar;
use Hypervel\Database\Query\Processors\PostgresProcessor;
use Hypervel\Database\Schema\Grammars\PostgresGrammar as SchemaGrammar;
use Hypervel\Database\Schema\PostgresBuilder;
use Hypervel\Database\Schema\PostgresSchemaState;
use Hypervel\Filesystem\Filesystem;

class PostgresConnection extends Connection
{
    /**
     * {@inheritdoc}
     */
    public function getDriverTitle()
    {
        return 'PostgreSQL';
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

        return "'\x{$hex}'::bytea";
    }

    /**
     * Escape a bool value for safe SQL embedding.
     *
     * @param  bool  $value
     * @return string
     */
    protected function escapeBool($value)
    {
        return $value ? 'true' : 'false';
    }

    /**
     * Determine if the given database exception was caused by a unique constraint violation.
     *
     * @param  \Exception  $exception
     * @return bool
     */
    protected function isUniqueConstraintError(Exception $exception)
    {
        return '23505' === $exception->getCode();
    }

    /**
     * Get the default query grammar instance.
     *
     * @return \Hypervel\Database\Query\Grammars\PostgresGrammar
     */
    protected function getDefaultQueryGrammar()
    {
        return new QueryGrammar($this);
    }

    /**
     * Get a schema builder instance for the connection.
     *
     * @return \Hypervel\Database\Schema\PostgresBuilder
     */
    public function getSchemaBuilder()
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new PostgresBuilder($this);
    }

    /**
     * Get the default schema grammar instance.
     *
     * @return \Hypervel\Database\Schema\Grammars\PostgresGrammar
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
     * @return \Hypervel\Database\Schema\PostgresSchemaState
     */
    public function getSchemaState(?Filesystem $files = null, ?callable $processFactory = null)
    {
        return new PostgresSchemaState($this, $files, $processFactory);
    }

    /**
     * Get the default post processor instance.
     *
     * @return \Hypervel\Database\Query\Processors\PostgresProcessor
     */
    protected function getDefaultPostProcessor()
    {
        return new PostgresProcessor;
    }
}
