<?php

declare(strict_types=1);

namespace Hypervel\Database\Console\Migrations;

use Hypervel\Console\ConfirmableTrait;
use Hypervel\Console\Contracts\Isolatable;
use Hypervel\Database\Connection;
use Hypervel\Database\Events\SchemaLoaded;
use Hypervel\Database\Migrations\Migrator;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

class MigrateCommand extends BaseCommand implements Isolatable
{
    use ConfirmableTrait;

    protected ?string $signature = 'migrate
        {--database= : The database connection to use}
        {--force : Force the operation to run when in production}
        {--path=* : The path(s) to the migrations files to be executed}
        {--realpath : Indicate any provided migration file paths are pre-resolved absolute paths}
        {--schema-path= : The path to a schema dump file}
        {--pretend : Dump the SQL queries that would be run}
        {--seed : Indicates if the seed task should be re-run}
        {--seeder= : The class name of the root seeder}
        {--step : Force the migrations to be run so they can be rolled back individually}
        {--graceful : Return a successful exit code even if an error occurs}';

    protected string $description = 'Run the database migrations';

    public function __construct(
        protected Migrator $migrator,
        protected EventDispatcherInterface $dispatcher
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! $this->confirmToProceed()) {
            return 1;
        }

        try {
            $this->runMigrations();
        } catch (Throwable $e) {
            if ($this->option('graceful')) {
                $this->components->warn($e->getMessage());

                return 0;
            }

            throw $e;
        }

        return 0;
    }

    /**
     * Run the pending migrations.
     */
    protected function runMigrations(): void
    {
        $this->migrator->usingConnection($this->option('database'), function () {
            $this->prepareDatabase();

            // Next, we will check to see if a path option has been defined. If it has
            // we will use the path relative to the root of this installation folder
            // so that migrations may be run for any path within the applications.
            $this->migrator->setOutput($this->output)
                ->run($this->getMigrationPaths(), [
                    'pretend' => $this->option('pretend'),
                    'step' => $this->option('step'),
                ]);

            // Finally, if the "seed" option has been given, we will re-run the database
            // seed task to re-populate the database, which is convenient when adding
            // a migration and a seed at the same time, as it is only this command.
            if ($this->option('seed') && ! $this->option('pretend')) {
                $this->call('db:seed', [
                    '--class' => $this->option('seeder') ?: 'Database\\Seeders\\DatabaseSeeder',
                    '--force' => true,
                ]);
            }
        });
    }

    /**
     * Prepare the migration database for running.
     */
    protected function prepareDatabase(): void
    {
        if (! $this->migrator->repositoryExists()) {
            $this->components->info('Preparing database.');

            $this->components->task('Creating migration table', function () {
                return $this->callSilent('migrate:install', array_filter([
                    '--database' => $this->option('database'),
                ])) == 0;
            });

            $this->newLine();
        }

        if (! $this->migrator->hasRunAnyMigrations() && ! $this->option('pretend')) {
            $this->loadSchemaState();
        }
    }

    /**
     * Load the schema state to seed the initial database schema structure.
     */
    protected function loadSchemaState(): void
    {
        $connection = $this->migrator->resolveConnection($this->option('database'));

        // First, we will make sure that the connection supports schema loading and that
        // the schema file exists before we proceed any further. If not, we will just
        // continue with the standard migration operation as normal without errors.
        if (! is_file($path = $this->schemaPath($connection))) {
            return;
        }

        $this->components->info('Loading stored database schemas.');

        $this->components->task($path, function () use ($connection, $path) {
            // Since the schema file will create the "migrations" table and reload it to its
            // proper state, we need to delete it here so we don't get an error that this
            // table already exists when the stored database schema file gets executed.
            $this->migrator->deleteRepository();

            $connection->getSchemaState()->handleOutputUsing(function ($type, $buffer) {
                $this->output->write($buffer);
            })->load($path);
        });

        $this->newLine();

        // Finally, we will fire an event that this schema has been loaded so developers
        // can perform any post schema load tasks that are necessary in listeners for
        // this event, which may seed the database tables with some necessary data.
        $this->dispatcher->dispatch(
            new SchemaLoaded($connection, $path)
        );
    }

    /**
     * Get the path to the stored schema for the given connection.
     */
    protected function schemaPath(Connection $connection): string
    {
        if ($this->option('schema-path')) {
            return $this->option('schema-path');
        }

        if (file_exists($path = database_path('schema/' . $connection->getName() . '-schema.dump'))) {
            return $path;
        }

        return database_path('schema/' . $connection->getName() . '-schema.sql');
    }
}
