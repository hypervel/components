<?php

declare(strict_types=1);

namespace Hypervel\Database\Console;

use Hypervel\Console\Command;
use Hypervel\Console\ConfirmableTrait;
use Hypervel\Console\Prohibitable;
use Hypervel\Database\Migrations\Migrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'db:wipe')]
class WipeCommand extends Command
{
    use ConfirmableTrait;
    use Prohibitable;

    /**
     * The console command name.
     */
    protected ?string $name = 'db:wipe';

    /**
     * The console command description.
     */
    protected string $description = 'Drop all tables, views, and types';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->isProhibited()
            || ! $this->confirmToProceed()) {
            return Command::FAILURE;
        }

        $database = Migrator::resolveMigrationConnectionName(
            $this->input->getOption('database')
        );

        if ($this->option('drop-views')) {
            $this->dropAllViews($database);

            $this->components->info('Dropped all views successfully.');
        }

        $this->dropAllTables($database);

        $this->components->info('Dropped all tables successfully.');

        if ($this->option('drop-types')) {
            $this->dropAllTypes($database);

            $this->components->info('Dropped all types successfully.');
        }

        $this->flushDatabaseConnection($database);

        return 0;
    }

    /**
     * Drop all of the database tables.
     */
    protected function dropAllTables(?string $database): void
    {
        $this->hypervel['db']->connection($database)
            ->getSchemaBuilder()
            ->dropAllTables();
    }

    /**
     * Drop all of the database views.
     */
    protected function dropAllViews(?string $database): void
    {
        $this->hypervel['db']->connection($database)
            ->getSchemaBuilder()
            ->dropAllViews();
    }

    /**
     * Drop all of the database types.
     */
    protected function dropAllTypes(?string $database): void
    {
        $this->hypervel['db']->connection($database)
            ->getSchemaBuilder()
            ->dropAllTypes();
    }

    /**
     * Flush the given database connection.
     *
     * Uses purge() instead of disconnect() because Hypervel's pooled connection
     * architecture caches connection wrappers. disconnect() only nulls the PDO
     * on the cached wrapper, leaving it in place — the next query reuses the
     * disconnected wrapper and triggers a reconnect. purge() fully resets the
     * connection including pool and resolver caches.
     */
    protected function flushDatabaseConnection(?string $database): void
    {
        $this->hypervel['db']->purge($database);
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['database', null, InputOption::VALUE_OPTIONAL, 'The database connection to use'],
            ['drop-views', null, InputOption::VALUE_NONE, 'Drop all tables and views'],
            ['drop-types', null, InputOption::VALUE_NONE, 'Drop all tables and types (Postgres only)'],
            ['force', null, InputOption::VALUE_NONE, 'Force the operation to run when in production'],
        ];
    }
}
