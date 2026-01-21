<?php

declare(strict_types=1);

namespace Hypervel\Database\Schema;

use Hypervel\Database\Connection;
use Hypervel\Support\Collection;

class SqliteSchemaState extends SchemaState
{
    /**
     * Dump the database's schema into a file.
     */
    #[\Override]
    public function dump(Connection $connection, string $path): void
    {
        $process = $this->makeProcess($this->baseCommand().' ".schema --indent"')
            ->setTimeout(null)
            ->mustRun(null, array_merge($this->baseVariables($this->connection->getConfig()), [
                //
            ]));

        $migrations = preg_replace('/CREATE TABLE sqlite_.+?\);[\r\n]+/is', '', $process->getOutput());

        $this->files->put($path, $migrations.PHP_EOL);

        if ($this->hasMigrationTable()) {
            $this->appendMigrationData($path);
        }
    }

    /**
     * Append the migration data to the schema dump.
     */
    protected function appendMigrationData(string $path): void
    {
        $process = $this->makeProcess(
            $this->baseCommand().' ".dump \''.$this->getMigrationTable().'\'"'
        )->mustRun(null, array_merge($this->baseVariables($this->connection->getConfig()), [
            //
        ]));

        $migrations = (new Collection(preg_split("/\r\n|\n|\r/", $process->getOutput())))
            ->filter(fn ($line) => preg_match('/^\s*(--|INSERT\s)/iu', $line) === 1 && strlen($line) > 0)
            ->all();

        $this->files->append($path, implode(PHP_EOL, $migrations).PHP_EOL);
    }

    /**
     * Load the given schema file into the database.
     */
    #[\Override]
    public function load(string $path): void
    {
        $database = $this->connection->getDatabaseName();

        if ($database === ':memory:' ||
            str_contains($database, '?mode=memory') ||
            str_contains($database, '&mode=memory')
        ) {
            $this->connection->getPdo()->exec($this->files->get($path));

            return;
        }

        $process = $this->makeProcess($this->baseCommand().' < "${:LARAVEL_LOAD_PATH}"');

        $process->mustRun(null, array_merge($this->baseVariables($this->connection->getConfig()), [
            'LARAVEL_LOAD_PATH' => $path,
        ]));
    }

    /**
     * Get the base sqlite command arguments as a string.
     */
    protected function baseCommand(): string
    {
        return 'sqlite3 "${:LARAVEL_LOAD_DATABASE}"';
    }

    /**
     * Get the base variables for a dump / load command.
     */
    #[\Override]
    protected function baseVariables(array $config): array
    {
        return [
            'LARAVEL_LOAD_DATABASE' => $config['database'],
        ];
    }
}
