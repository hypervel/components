<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema;

use Hypervel\Database\Connection;
use Hypervel\Database\PdoConnection;
use Hypervel\Database\SQLiteDatabase;
use Hypervel\Support\Collection;
use LogicException;
use Override;

class SqliteSchemaState extends SchemaState
{
    /**
     * Dump the database's schema into a file.
     */
    #[Override]
    public function dump(Connection $connection, string $path): void
    {
        // Virtual tables create their shadow tables again when they are loaded.
        $shadowTables = (new Collection($this->connection->selectFromWriteConnection('pragma main.table_list')))
            ->where('type', 'shadow')
            ->pluck('name')
            ->flip();

        // Each stored statement is read whole, in the order .schema prints, so no filtering can reach into its literals.
        $schema = (new Collection($this->connection->selectFromWriteConnection(<<<'SQL'
            select name, sql from main.sqlite_master
            where sql is not null and name not like 'sqlite\_%' escape '\'
            order by rowid
            SQL)))
            ->reject(static fn (object $statement): bool => $shadowTables->has($statement->name))
            // Like .schema, quoted table names get IF NOT EXISTS: SQLite before 3.37 cannot list shadow tables,
            // so they stay in the dump and must not fail after their virtual table has created them.
            ->map(static fn (object $statement): string => preg_replace('/^CREATE TABLE (?=[\'"])/', 'CREATE TABLE IF NOT EXISTS ', $statement->sql) . ";\n")
            ->implode('');

        $this->files->put($path, $schema . PHP_EOL);

        if ($this->hasMigrationTable()) {
            $this->appendMigrationData($path);
        }
    }

    /**
     * Append the migration data to the schema dump.
     */
    protected function appendMigrationData(string $path): void
    {
        // Escape the LIKE pattern first, then SQLite's double-quoted dot-command argument.
        $pattern = strtr($this->getMigrationTable(), ['\\' => '\\\\', '%' => '\%', '_' => '\_']);
        $command = '.dump "' . strtr($pattern, ['\\' => '\\\\', '"' => '\"']) . '"';

        $process = $this->makeProcess(
            $this->baseCommand() . ' "${:HYPERVEL_LOAD_MIGRATION_COMMAND}"'
        )->mustRun(null, array_merge($this->baseVariables($this->connection->getConfig()), [
            'HYPERVEL_LOAD_MIGRATION_COMMAND' => $command,
        ]));

        $migrations = (new Collection(preg_split("/\r\n|\n|\r/", $process->getOutput())))
            ->filter(fn ($line) => preg_match('/^\s*(--|INSERT\s)/iu', $line) === 1)
            ->all();

        $this->files->append($path, implode(PHP_EOL, $migrations) . PHP_EOL);
    }

    /**
     * Load the given schema file into the database.
     */
    #[Override]
    public function load(string $path): void
    {
        $database = $this->connection->getDatabaseName();

        if (SQLiteDatabase::isInMemory($database)) {
            if (! $this->connection instanceof PdoConnection) {
                throw new LogicException('In-memory SQLite schema loading requires a PDO-backed connection.');
            }

            $this->connection->getPdo()->exec($this->files->get($path));

            return;
        }

        $process = $this->makeProcess($this->baseCommand() . ' < "${:HYPERVEL_LOAD_PATH}"');

        $process->mustRun(null, array_merge($this->baseVariables($this->connection->getConfig()), [
            'HYPERVEL_LOAD_PATH' => $path,
        ]));
    }

    /**
     * Get the base sqlite command arguments as a string.
     */
    protected function baseCommand(): string
    {
        return 'sqlite3 "${:HYPERVEL_LOAD_DATABASE}"';
    }

    /**
     * Get the base variables for a dump / load command.
     */
    #[Override]
    protected function baseVariables(array $config): array
    {
        return array_map(strval(...), [
            'HYPERVEL_LOAD_DATABASE' => $config['database'],
        ]);
    }
}
