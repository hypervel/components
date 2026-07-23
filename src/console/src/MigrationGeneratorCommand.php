<?php

declare(strict_types=1);

namespace Hypervel\Console;

use Hypervel\Filesystem\Filesystem;
use RuntimeException;

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

        $this->createBaseMigration($table);

        $this->components->info('Migration created successfully.');

        return 0;
    }

    /**
     * Create a base migration file for the table.
     */
    protected function createBaseMigration(string $table, ?string $stubPath = null): string
    {
        return $this->hypervel->make('migration.creator')->create(
            'create_' . $table . '_table',
            $this->hypervel->databasePath('/migrations'),
            $table,
            true,
            $stubPath ?? $this->migrationStubFile(),
        );
    }

    /**
     * Determine whether a migration for the table already exists.
     */
    protected function migrationExists(string $table): bool
    {
        return $this->matchingMigrationFiles(
            join_paths($this->hypervel->databasePath('migrations'), '*_*_*_*_create_' . $table . '_table.php')
        ) !== [];
    }

    /**
     * Get migration files matching the given pattern.
     *
     * @return list<string>
     */
    protected function matchingMigrationFiles(string $pattern): array
    {
        $files = $this->files->glob($pattern);

        if ($files === false) {
            throw new RuntimeException("Unable to read migration files matching [{$pattern}].");
        }

        return array_values($files);
    }
}
