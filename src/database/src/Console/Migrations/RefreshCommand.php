<?php

declare(strict_types=1);

namespace Hypervel\Database\Console\Migrations;

use Hypervel\Console\Command;
use Hypervel\Console\ConfirmableTrait;
use Hypervel\Console\Prohibitable;
use Hypervel\Database\Events\DatabaseRefreshed;
use Hypervel\Contracts\Event\Dispatcher;

class RefreshCommand extends Command
{
    use ConfirmableTrait;
    use Prohibitable;

    protected ?string $signature = 'migrate:refresh
        {--database= : The database connection to use}
        {--force : Force the operation to run when in production}
        {--path=* : The path(s) to the migrations files to be executed}
        {--realpath : Indicate any provided migration file paths are pre-resolved absolute paths}
        {--seed : Indicates if the seed task should be re-run}
        {--seeder= : The class name of the root seeder}
        {--step= : The number of migrations to be reverted & re-run}';

    protected string $description = 'Reset and re-run all migrations';

    public function __construct(
        protected Dispatcher $dispatcher
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->isProhibited() || ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        // Next we'll gather some of the options so that we can have the right options
        // to pass to the commands. This includes options such as which database to
        // use and the path to use for the migration. Then we'll run the command.
        $database = $this->option('database');
        $path = $this->option('path');

        // If the "step" option is specified it means we only want to rollback a small
        // number of migrations before migrating again. For example, the user might
        // only rollback and remigrate the latest four migrations instead of all.
        $step = $this->option('step') ?: 0;

        if ($step > 0) {
            $this->runRollback($database, $path, (int) $step);
        } else {
            $this->runReset($database, $path);
        }

        // The refresh command is essentially just a brief aggregate of a few other of
        // the migration commands and just provides a convenient wrapper to execute
        // them in succession. We'll also see if we need to re-seed the database.
        $this->call('migrate', array_filter([
            '--database' => $database,
            '--path' => $path,
            '--realpath' => $this->option('realpath'),
            '--force' => true,
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
     * Run the rollback command.
     */
    protected function runRollback(?string $database, array|string|null $path, int $step): void
    {
        $this->call('migrate:rollback', array_filter([
            '--database' => $database,
            '--path' => $path,
            '--realpath' => $this->option('realpath'),
            '--step' => $step,
            '--force' => true,
        ]));
    }

    /**
     * Run the reset command.
     */
    protected function runReset(?string $database, array|string|null $path): void
    {
        $this->call('migrate:reset', array_filter([
            '--database' => $database,
            '--path' => $path,
            '--realpath' => $this->option('realpath'),
            '--force' => true,
        ]));
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
            '--class' => $this->option('seeder') ?: 'Database\Seeders\DatabaseSeeder',
            '--force' => true,
        ]));
    }
}
