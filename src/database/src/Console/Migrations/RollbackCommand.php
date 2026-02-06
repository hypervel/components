<?php

declare(strict_types=1);

namespace Hypervel\Database\Console\Migrations;

use Hypervel\Console\ConfirmableTrait;
use Hypervel\Console\Prohibitable;
use Hypervel\Database\Migrations\Migrator;

class RollbackCommand extends BaseCommand
{
    use ConfirmableTrait;
    use Prohibitable;

    protected ?string $signature = 'migrate:rollback
        {--database= : The database connection to use}
        {--force : Force the operation to run when in production}
        {--path=* : The path(s) to the migrations files to be executed}
        {--realpath : Indicate any provided migration file paths are pre-resolved absolute paths}
        {--pretend : Dump the SQL queries that would be run}
        {--step= : The number of migrations to be reverted}
        {--batch= : The batch of migrations (identified by their batch number) to be reverted}';

    protected string $description = 'Rollback the last database migration';

    public function __construct(
        protected Migrator $migrator
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

        $this->migrator->usingConnection($this->option('database'), function () {
            $this->migrator->setOutput($this->output)->rollback(
                $this->getMigrationPaths(),
                [
                    'pretend' => $this->option('pretend'),
                    'step' => (int) $this->option('step'),
                    'batch' => (int) $this->option('batch'),
                ]
            );
        });

        return 0;
    }
}
