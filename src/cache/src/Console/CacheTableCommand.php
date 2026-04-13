<?php

declare(strict_types=1);

namespace Hypervel\Cache\Console;

use Hypervel\Console\MigrationGeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:cache-table', aliases: ['cache:table'])]
class CacheTableCommand extends MigrationGeneratorCommand
{
    /**
     * The console command name.
     */
    protected ?string $name = 'make:cache-table';

    /**
     * The console command name aliases.
     */
    protected array $aliases = ['cache:table'];

    /**
     * The console command description.
     */
    protected string $description = 'Create migrations for the cache database tables';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $tables = [
            'cache' => __DIR__ . '/stubs/cache.stub',
            'cache_locks' => __DIR__ . '/stubs/cache-locks.stub',
        ];

        foreach ($tables as $table => $stub) {
            if ($this->migrationExists($table)) {
                $this->components->warn("Migration for [{$table}] table already exists.");
                continue;
            }

            $this->replaceMigrationPlaceholders(
                $this->createBaseMigration($table),
                $table,
                $stub,
            );
        }

        $this->components->info('Migrations created successfully.');

        return 0;
    }

    /**
     * Replace the placeholders in the generated migration file.
     */
    protected function replaceMigrationPlaceholders(string $path, string $table, ?string $stubPath = null): void
    {
        $stub = str_replace(
            '{{table}}',
            $table,
            $this->files->get($stubPath ?? $this->migrationStubFile())
        );

        $this->files->put($path, $stub);
    }

    /**
     * Get the migration table name.
     */
    protected function migrationTableName(): string
    {
        return 'cache';
    }

    /**
     * Get the path to the migration stub file.
     */
    protected function migrationStubFile(): string
    {
        return __DIR__ . '/stubs/cache.stub';
    }
}
