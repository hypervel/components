<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console;

use Hypervel\Console\MigrationGeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

use function Hypervel\Filesystem\join_paths;

#[AsCommand(name: 'make:queue-failed-table', aliases: ['queue:failed-table'])]
class FailedTableCommand extends MigrationGeneratorCommand
{
    /**
     * The console command name.
     */
    protected ?string $name = 'make:queue-failed-table';

    /**
     * The console command name aliases.
     *
     * @var string[]
     */
    protected array $aliases = ['queue:failed-table'];

    /**
     * The console command description.
     */
    protected string $description = 'Create a migration for the failed queue jobs database table';

    /**
     * Get the migration table name.
     */
    protected function migrationTableName(): string
    {
        return $this->hypervel->make('config')->get('queue.failed.table');
    }

    /**
     * Get the path to the migration stub file.
     */
    protected function migrationStubFile(): string
    {
        return __DIR__ . '/stubs/failed_jobs.stub';
    }

    /**
     * Determine whether a migration for the table already exists.
     */
    protected function migrationExists(string $table): bool
    {
        if ($table !== 'failed_jobs') {
            return parent::migrationExists($table);
        }

        foreach ([
            join_paths($this->hypervel->databasePath('migrations'), '*_*_*_*_create_' . $table . '_table.php'),
            join_paths($this->hypervel->databasePath('migrations'), '0001_01_01_000002_create_jobs_table.php'),
        ] as $path) {
            if (count($this->files->glob($path)) !== 0) {
                return true;
            }
        }

        return false;
    }
}
