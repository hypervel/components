<?php

declare(strict_types=1);

namespace Hypervel\Database\Console;

use Hypervel\Console\Command;
use Hypervel\Console\Prohibitable;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Connection;
use Hypervel\Database\ConnectionResolverInterface;
use Hypervel\Database\Events\MigrationsPruned;
use Hypervel\Database\Events\SchemaDumped;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Facades\Config;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'schema:dump')]
class DumpCommand extends Command
{
    use Prohibitable;

    /**
     * The console command name.
     */
    protected ?string $signature = 'schema:dump
                {--database= : The database connection to use}
                {--path= : The path where the schema dump file should be stored}
                {--prune : Delete all existing migration files}';

    /**
     * The console command description.
     */
    protected string $description = 'Dump the given database schema';

    /**
     * Execute the console command.
     */
    public function handle(ConnectionResolverInterface $connections, Dispatcher $dispatcher): int
    {
        if ($this->isProhibited()) {
            return Command::FAILURE;
        }

        /** @var Connection $connection */
        $connection = $connections->connection($database = $this->input->getOption('database'));

        $this->schemaState($connection)->dump(
            $connection,
            $path = $this->path($connection)
        );

        $dispatcher->dispatch(new SchemaDumped($connection, $path));

        $info = 'Database schema dumped';

        if ($this->option('prune')) {
            (new Filesystem())->deleteDirectory(
                $path = database_path('migrations'),
                preserve: false
            );

            $info .= ' and pruned';

            $dispatcher->dispatch(new MigrationsPruned($connection, $path));
        }

        $this->components->info($info . ' successfully.');

        return 0;
    }

    /**
     * Create a schema state instance for the given connection.
     */
    protected function schemaState(Connection $connection): mixed
    {
        $migrations = Config::get('database.migrations', 'migrations');

        $migrationTable = is_array($migrations) ? ($migrations['table'] ?? 'migrations') : $migrations;

        return $connection->getSchemaState()
            ->withMigrationTable($migrationTable)
            ->handleOutputUsing(function ($type, $buffer) {
                $this->output->write($buffer);
            });
    }

    /**
     * Get the path that the dump should be written to.
     */
    protected function path(Connection $connection): string
    {
        return tap($this->option('path') ?: database_path('schema/' . $connection->getName() . '-schema.sql'), function ($path) {
            (new Filesystem())->ensureDirectoryExists(dirname($path));
        });
    }
}
