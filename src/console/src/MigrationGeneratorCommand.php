<?php

declare(strict_types=1);

namespace Hypervel\Console;

use Hypervel\Filesystem\Filesystem;

use function Hypervel\Filesystem\join_paths;

abstract class MigrationGeneratorCommand extends Command
{
    /**
     * Create a new migration generator command instance.
     */
    public function __construct(
        protected Filesystem $files
    ) {
        parent::__construct();
    }

    /**
     * Get the migration table name.
     */
    abstract protected function migrationTableName(): string;

    /**
     * Get the path to the migration stub file.
     */
    abstract protected function migrationStubFile(): string;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $table = $this->migrationTableName();

        if ($this->migrationExists($table)) {
            $this->components->error('Migration already exists.');

            return 1;
        }

        $this->replaceMigrationPlaceholders(
            $this->createBaseMigration($table),
            $table
        );

        $this->components->info('Migration created successfully.');

        return 0;
    }

    /**
     * Create a base migration file for the table.
     */
    protected function createBaseMigration(string $table): string
    {
        return $this->app['migration.creator']->create(
            'create_' . $table . '_table',
            $this->app->databasePath('/migrations')
        );
    }

    /**
     * Replace the placeholders in the generated migration file.
     */
    protected function replaceMigrationPlaceholders(string $path, string $table): void
    {
        $stub = str_replace(
            '{{table}}',
            $table,
            $this->files->get($this->migrationStubFile())
        );

        $this->files->put($path, $stub);
    }

    /**
     * Determine whether a migration for the table already exists.
     */
    protected function migrationExists(string $table): bool
    {
        return count($this->files->glob(
            join_paths($this->app->databasePath('migrations'), '*_*_*_*_create_' . $table . '_table.php')
        )) !== 0;
    }
}
