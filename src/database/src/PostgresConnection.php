<?php

declare(strict_types=1);

namespace Hypervel\Database;

use DateTimeInterface;
use Exception;
use Hypervel\Database\Query\Grammars\PostgresGrammar;
use Hypervel\Database\Query\Processors\PostgresProcessor;
use Hypervel\Database\Schema\Grammars\PostgresGrammar as PostgresSchemaGrammar;
use Hypervel\Database\Schema\PostgresBuilder;
use Hypervel\Database\Schema\PostgresSchemaState;
use Hypervel\Filesystem\Filesystem;
use Override;
use PDO;

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

        return "'\\x{$hex}'::bytea";
    }

    /**
     * Escape a bool value for safe SQL embedding.
     */
    protected function escapeBool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    /**
     * Prepare the query bindings for execution.
     *
     * The base connection converts booleans to integers (1/0). With native
     * prepares that's fine — the driver handles type coercion. But with
     * emulated prepares (PDO::ATTR_EMULATE_PREPARES), PDO inlines the
     * integer literal into the SQL string, and PostgreSQL rejects integers
     * for boolean columns. This override converts booleans to 'true'/'false'
     * string literals instead, which PostgreSQL accepts.
     *
     * @param array<mixed> $bindings
     * @return array<mixed>
     */
    public function prepareBindings(array $bindings): array
    {
        if (! $this->isUsingEmulatedPrepares()) {
            return parent::prepareBindings($bindings);
        }

        $grammar = $this->getQueryGrammar();

        foreach ($bindings as $key => $value) {
            if ($value instanceof DateTimeInterface) {
                $bindings[$key] = $value->format($grammar->getDateFormat());
            } elseif (is_bool($value)) {
                // Use PostgreSQL boolean literals instead of integers.
                $bindings[$key] = $value ? 'true' : 'false';
            }
        }

        return $bindings;
    }

    /**
     * Determine if emulated prepares are enabled for the connection.
     */
    protected function isUsingEmulatedPrepares(): bool
    {
        return ($this->config['options'][PDO::ATTR_EMULATE_PREPARES] ?? false) === true;
    }

    /**
     * Determine if the given database exception was caused by a unique constraint violation.
     */
    protected function isUniqueConstraintError(Exception $exception): bool
    {
        return $exception->getCode() === '23505';
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
    #[Override]
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
