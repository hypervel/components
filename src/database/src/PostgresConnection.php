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
use RuntimeException;

class PostgresConnection extends PdoConnection
{
    /**
     * Get a human-readable name for the given connection driver.
     */
    public function getDriverTitle(): string
    {
        return 'PostgreSQL';
    }

    /**
     * Get the default database driver name.
     */
    protected function getDefaultDriverName(): string
    {
        return 'pgsql';
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
        $config = $this->latestReadWriteTypeUsed() === 'read'
            && $this->readConnectionConfig !== []
                ? $this->readConnectionConfig
                : $this->config;

        return (bool) ($config['options'][PDO::ATTR_EMULATE_PREPARES] ?? false);
    }

    /**
     * Determine if the given database exception was caused by a unique constraint violation.
     */
    protected function isUniqueConstraintError(Exception $exception): bool
    {
        return $exception->getCode() === '23505';
    }

    /**
     * Determine if the given database exception was caused by an invalid or out of range value.
     */
    protected function isDataTypeError(Exception $exception): bool
    {
        return in_array($exception->getCode(), [
            '22001', // string_data_right_truncation
            '22003', // numeric_value_out_of_range
            '22007', // invalid_datetime_format
            '22008', // datetime_field_overflow
            '22P02', // invalid_text_representation
        ], true);
    }

    /**
     * Extract the index and columns that caused a unique constraint violation.
     *
     * @return array{index: null|string, columns: list<string>}
     */
    protected function parseUniqueConstraintViolation(Exception $exception): array
    {
        [$index, $columns] = [null, []];

        if (preg_match('#unique constraint "([^"]+)"#i', $message = $exception->getMessage(), $matches)) {
            $index = $matches[1];
        }

        if (preg_match('#Key \(([^)]+)\)=#i', $message, $matches)) {
            $columns = array_map(trim(...), explode(',', $matches[1]));
        }

        return ['columns' => $columns, 'index' => $index];
    }

    /**
     * Resolve whether the server supports skipping locked rows.
     */
    protected function resolveSupportsSkipLocked(): bool
    {
        $version = (string) ($this->getConfig('version') ?? $this->getServerVersion());

        return version_compare($version, '9.5', '>=');
    }

    /**
     * Resolve the maximum number of bindings supported by one statement.
     */
    protected function resolveMaxBindings(): int
    {
        return 65_535;
    }

    /**
     * Get the ID of the last committed transaction.
     */
    public function getLastCommitId(): string
    {
        // pg_current_wal_lsn() can precede an acknowledged asynchronous commit. The insert position covers it;
        // at a page boundary it points past the next page header, which a replica passes with the next WAL record.
        return $this->usingWritePdo(fn () => $this->scalar('select pg_current_wal_insert_lsn()'));
    }

    /**
     * Calculate the commit ID for a set of commit IDs that includes all of them.
     *
     * @param string[] $values
     *
     * @throws RuntimeException
     */
    public function mergeCommitIds(array $values): string
    {
        if ($values === []) {
            throw new RuntimeException("The list of commit IDs can't be empty.");
        }

        // LSNs are linear, so the newest one includes all older ones...
        return $this->scalar('select value::pg_lsn from json_array_elements_text(?) order by 1 desc limit 1', [json_encode(array_values($values))]);
    }

    /**
     * Determine if the given commit ID has been applied.
     *
     * @throws RuntimeException
     */
    public function hasCommitId(string $value, int $wait = 0): bool
    {
        if ($wait > 0) {
            // Check the server that runs the wait; getServerVersion() would also contact the primary.
            if (version_compare($this->getPdoForSelect()->getAttribute(PDO::ATTR_SERVER_VERSION), '19', '<')) {
                throw new RuntimeException('The PostgreSQL version does not support waiting for a commit ID. PostgreSQL 19 or newer is required.');
            }

            $status = $this->scalar("wait for lsn {$this->escapeString($value)} with (timeout {$this->escapeString("{$wait}s")}, no_throw)");

            // A primary can't wait for its own LSNs, so fall back to the immediate check...
            if ($status !== 'not in recovery') {
                return $status === 'success';
            }
        }

        return (bool) $this->scalar('select case when pg_is_in_recovery() then pg_last_wal_replay_lsn() else pg_current_wal_insert_lsn() end >= ?::pg_lsn', [$value]);
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
