<?php

declare(strict_types=1);

namespace Hypervel\Database\Console\Migrations;

use Hypervel\Console\Command;
use Hypervel\Console\ConfirmableTrait;
use Hypervel\Database\Events\DatabaseRefreshed;
use Hypervel\Database\Migrations\Migrator;
use Psr\EventDispatcher\EventDispatcherInterface;

class FreshCommand extends Command
{
    use ConfirmableTrait;

    protected ?string $signature = 'migrate:fresh
        {--database= : The database connection to use}
        {--drop-views : Drop all tables and views}
        {--drop-types : Drop all tables and types (Postgres only)}
        {--force : Force the operation to run when in production}
        {--path=* : The path(s) to the migrations files to be executed}
        {--realpath : Indicate any provided migration file paths are pre-resolved absolute paths}
        {--schema-path= : The path to a schema dump file}
        {--seed : Indicates if the seed task should be re-run}
        {--seeder= : The class name of the root seeder}
        {--step : Force the migrations to be run so they can be rolled back individually}';

    protected string $description = 'Drop all tables and re-run all migrations';

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
            return self::FAILURE;
        }

        $database = $this->option('database');

        $this->migrator->usingConnection($database, function () use ($database) {
            if ($this->migrator->repositoryExists()) {
                $this->newLine();

                $this->components->task('Dropping all tables', fn () => $this->callSilent('db:wipe', array_filter([
                    '--database' => $database,
                    '--drop-views' => $this->option('drop-views'),
                    '--drop-types' => $this->option('drop-types'),
                    '--force' => true,
                ])) == 0);
            }
        });

        $this->newLine();

        $this->call('migrate', array_filter([
            '--database' => $database,
            '--path' => $this->option('path'),
            '--realpath' => $this->option('realpath'),
            '--schema-path' => $this->option('schema-path'),
            '--force' => true,
            '--step' => $this->option('step'),
        ]));

        $this->dispatcher->dispatch(
            new DatabaseRefreshed($database, $this->needsSeeding())
        );

        if ($this->needsSeeding()) {
            $this->runSeeder($database);
        }

        return 0;
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
        $this->call('db:seed', array_filter([
            '--database' => $database,
            '--class' => $this->option('seeder') ?: 'Database\\Seeders\\DatabaseSeeder',
            '--force' => true,
        ]));
    }
}
