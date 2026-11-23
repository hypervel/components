<?php

declare(strict_types=1);

namespace Hypervel\PHPStan\Properties;

use Hypervel\SchemaParser\MariaDbParser;
use Hypervel\SchemaParser\MySqlParser;
use Hypervel\SchemaParser\Parser;
use Hypervel\SchemaParser\PostgresParser;
use Hypervel\SchemaParser\SQLiteParser;

use function strrpos;
use function substr;

/**
 * The facts of a configured database connection that schema inference reads.
 *
 * @internal
 */
readonly class ConnectionConfig
{
    /**
     * Create a new connection config instance.
     *
     * @param string $owner the connection its migrations run on, which owns its schema facts
     * @param null|string $defaultSchema the schema of unqualified names: the MySQL database or SQLite's main database
     * @param list<string> $searchPath the schemas an unqualified PostgreSQL name resolves through, in order
     * @param bool $nativeJson whether SQLite's schema grammar creates Blueprint json columns as json rather than text
     * @param bool $nativeJsonb whether SQLite's schema grammar creates Blueprint jsonb columns as jsonb rather than text
     * @param list<array<int, mixed>> $fetchOptions each distinct set of the PDO options that change fetched values
     * @param bool $customized whether a DB::extend() resolver or a custom connector creates the connection, so fetched values are unknown
     */
    public function __construct(
        public string $owner,
        public string $driver,
        public string $prefix,
        public ?string $defaultSchema,
        public array $searchPath,
        public bool $nativeJson,
        public bool $nativeJsonb,
        public array $fetchOptions,
        public bool $customized,
    ) {
    }

    /**
     * Create the schema parser for the connection's driver, or return null when the driver has none.
     */
    public function createParser(): ?Parser
    {
        return match ($this->driver) {
            'mysql' => new MySqlParser,
            'mariadb' => new MariaDbParser,
            'pgsql' => new PostgresParser,
            'sqlite' => new SQLiteParser,
            default => null,
        };
    }

    /**
     * Get the schema an unqualified definition belongs to: the MySQL database or SQLite's main database.
     *
     * PostgreSQL definitions stay unqualified with the connection's search path.
     */
    public function definitionSchema(): ?string
    {
        return $this->driver === 'pgsql' ? null : $this->defaultSchema;
    }

    /**
     * Get the schema and physical name of a logical table name.
     *
     * The prefix applies to the final segment, as Grammar::wrapTable() applies it.
     *
     * @return array{?string, string}
     */
    public function physicalTable(string $table): array
    {
        $separator = strrpos($table, '.');

        return $separator === false
            ? [null, $this->prefix . $table]
            : [substr($table, 0, $separator), $this->prefix . substr($table, $separator + 1)];
    }
}
