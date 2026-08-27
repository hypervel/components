<?php

declare(strict_types=1);

namespace Hypervel\Database\Console\Migrations;

use Hypervel\Console\Command;
use Hypervel\Console\ConfirmableTrait;
use Hypervel\Console\Prohibitable;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Events\DatabaseRefreshed;
use Hypervel\Database\Migrations\Migrator;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(name: 'migrate:fresh')]
class FreshCommand extends BaseCommand
{
    use ConfirmableTrait;
    use Prohibitable;

    /**
     * The console command name.
     */
    protected ?string $name = 'migrate:fresh';

    /**
     * The console command description.
     */
    protected string $description = 'Drop all tables from migration connections and re-run all migrations';

    /**
     * Create a new fresh command instance.
     */
    public function __construct(Migrator $migrator)
    {
        parent::__construct();

        $this->migrator = $migrator;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->isProhibited()) {
            return Command::FAILURE;
        }

        $database = $this->input->getOption('database');
        $paths = $this->getMigrationPaths();
        $connections = $this->migrator->getMigrationConnections($paths, $database);
        $missingDatabases = $this->inspectMigrationConnections($connections);
        $preExistingConnections = array_values(array_diff($connections, array_keys($missingDatabases)));

        if ($preExistingConnections !== []) {
            $this->components->warn('The following database connections will be wiped:');
            $this->components->bulletList($preExistingConnections);
        }

        if ($missingDatabases !== []) {
            $this->components->warn('The following database connections will be created:');
            $this->components->bulletList(array_keys($missingDatabases));
        }

        if (! $this->confirmToProceed()) {
            return Command::FAILURE;
        }

        $this->createMissingDatabases($missingDatabases);

        foreach ($preExistingConnections as $connection) {
            $this->components->task("Dropping all tables on [{$connection}]", function () use ($connection): bool {
                if ($this->callSilent('db:wipe', array_filter([
                    '--database' => $connection,
                    '--drop-views' => $this->option('drop-views'),
                    '--drop-types' => $this->option('drop-types'),
                    '--force' => true,
                ])) !== Command::SUCCESS) {
                    throw new RuntimeException("Database wipe failed for connection [{$connection}].");
                }

                return true;
            });
        }

        $this->newLine();

        if ($this->call('migrate', array_filter([
            '--database' => $database,
            '--path' => $this->input->getOption('path'),
            '--realpath' => $this->input->getOption('realpath'),
            '--schema-path' => $this->input->getOption('schema-path'),
            '--force' => true,
            '--step' => $this->option('step'),
        ])) !== Command::SUCCESS) {
            throw new RuntimeException('Migration command failed while refreshing the databases.');
        }

        if ($this->hypervel->bound(Dispatcher::class)) {
            $this->hypervel->make(Dispatcher::class)->dispatch(
                new DatabaseRefreshed($database, $this->needsSeeding())
            );
        }

        if ($this->needsSeeding()) {
            $this->runSeeder($database);
        }

        return Command::SUCCESS;
    }

    /**
     * Determine if the developer has requested database seeding.
     */
    protected function needsSeeding(): bool
    {
        return $this->option('seed') || $this->option('seeder');
    }

    /**
     * Run the database seeder command.
     */
    protected function runSeeder(?string $database): void
    {
        if ($this->call('db:seed', array_filter([
            '--database' => $database,
            '--class' => $this->option('seeder') ?: 'Database\Seeders\DatabaseSeeder',
            '--force' => true,
        ])) !== Command::SUCCESS) {
            throw new RuntimeException('Database seeding failed after the databases were refreshed.');
        }
    }

    /**
     * Get the console command options.
     */
    protected function getOptions(): array
    {
        return [
            ['database', null, InputOption::VALUE_OPTIONAL, 'The default database connection to use'],
            ['drop-views', null, InputOption::VALUE_NONE, 'Drop all tables and views'],
            ['drop-types', null, InputOption::VALUE_NONE, 'Drop all tables and types (Postgres only)'],
            ['force', null, InputOption::VALUE_NONE, 'Force the operation to run when in production'],
            ['path', null, InputOption::VALUE_OPTIONAL | InputOption::VALUE_IS_ARRAY, 'The path(s) to the migrations files to be executed'],
            ['realpath', null, InputOption::VALUE_NONE, 'Indicate any provided migration file paths are pre-resolved absolute paths'],
            ['schema-path', null, InputOption::VALUE_OPTIONAL, 'The path to a schema dump file'],
            ['seed', null, InputOption::VALUE_NONE, 'Indicates if the seed task should be re-run'],
            ['seeder', null, InputOption::VALUE_OPTIONAL, 'The class name of the root seeder'],
            ['step', null, InputOption::VALUE_NONE, 'Force the migrations to be run so they can be rolled back individually'],
        ];
    }
}
