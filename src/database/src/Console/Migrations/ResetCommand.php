<?php

declare(strict_types=1);

namespace Hypervel\Database\Console\Migrations;

use Hypervel\Console\ConfirmableTrait;
use Hypervel\Console\Prohibitable;
use Hypervel\Database\Migrations\Migrator;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'migrate:reset')]
class ResetCommand extends BaseCommand
{
    use ConfirmableTrait;
    use Prohibitable;

    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'migrate:reset
                    {--database= : The database connection to use}
                    {--force : Force the operation to run when in production}
                    {--path=* : The path(s) to the migrations files to be executed}
                    {--realpath : Indicate any provided migration file paths are pre-resolved absolute paths}
                    {--pretend : Dump the SQL queries that would be run}';

    /**
     * The console command description.
     */
    protected string $description = 'Rollback all database migrations';

    /**
     * Create a new migration rollback command instance.
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
        if ($this->isProhibited() || ! $this->confirmToProceed()) {
            return self::FAILURE;
        }

        return $this->migrator->usingConnection($this->option('database'), function () {
            // First, we'll make sure that the migration table actually exists before we
            // start trying to rollback and re-run all of the migrations. If it's not
            // present we'll just bail out with an info message for the developers.
            if (! $this->migrator->repositoryExists()) {
                $this->components->warn('Migration table not found.');

                return self::SUCCESS;
            }

            $this->migrator->setOutput($this->output)->reset(
                $this->getMigrationPaths(),
                $this->option('pretend')
            );

            return self::SUCCESS;
        });
    }
}
