<?php

declare(strict_types=1);

namespace Hypervel\Database;

use Exception;
use Hypervel\Database\Query\Grammars\PostgresGrammar;
use Hypervel\Database\Query\Processors\PostgresProcessor;
use Hypervel\Database\Schema\Grammars\PostgresGrammar as PostgresSchemaGrammar;
use Hypervel\Database\Schema\PostgresBuilder;
use Hypervel\Database\Schema\PostgresSchemaState;
use Hypervel\Filesystem\Filesystem;

class PostgresConnection extends Connection
{
    /**
     * Get a human-readable name for the given connection driver.
     */
    public function getDriverTitle(): string
    {
        return 'PostgreSQL';
    }

    /**
     * Escape a binary value for safe SQL embedding.
     */
    protected function escapeBinary(string $value): string
    {
        $hex = bin2hex($value);

        return "'\x{$hex}'::bytea";
    }

    /**
     * Escape a bool value for safe SQL embedding.
     */
    protected function escapeBool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    /**
     * Determine if the given database exception was caused by a unique constraint violation.
     */
    protected function isUniqueConstraintError(Exception $exception): bool
    {
        return '23505' === $exception->getCode();
    }

    /**
     * Get the default query grammar instance.
     */
    protected function getDefaultQueryGrammar(): PostgresGrammar
    {
        return new PostgresGrammar($this);
    }

    /**
     * Get a schema builder instance for the connection.
     */
    public function getSchemaBuilder(): PostgresBuilder
    {
        if (is_null($this->schemaGrammar)) {
            $this->useDefaultSchemaGrammar();
        }

        return new PostgresBuilder($this);
    }

    /**
     * Get the default schema grammar instance.
     */
    protected function getDefaultSchemaGrammar(): PostgresSchemaGrammar
    {
        return new PostgresSchemaGrammar($this);
    }

    /**
     * Get the schema state for the connection.
     */
    public function getSchemaState(?Filesystem $files = null, ?callable $processFactory = null): PostgresSchemaState
    {
        return new PostgresSchemaState($this, $files, $processFactory);
    }

    /**
     * Get the default post processor instance.
     */
    protected function getDefaultPostProcessor(): PostgresProcessor
    {
        return new PostgresProcessor;
    }
}
