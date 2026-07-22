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

            $this->createBaseMigration($table, $stub);
        }

        $this->components->info('Migrations created successfully.');

        return 0;
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
