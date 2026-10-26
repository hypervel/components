<?php

declare(strict_types=1);

namespace Hypervel\Cache\Console;

use Hypervel\Cache\DatabaseStore;
use Hypervel\Console\MigrationGeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:cache-table', aliases: ['cache:table'])]
class CacheTableCommand extends MigrationGeneratorCommand
{
    public const string TABLE = 'cache';

    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'make:cache-table';

    /**
     * The console command name aliases.
     *
     * @var string[]
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
            self::TABLE => __DIR__ . '/stubs/cache.stub',
            DatabaseStore::DEFAULT_LOCK_TABLE => __DIR__ . '/stubs/cache-locks.stub',
        ];

        foreach ($tables as $table => $stub) {
            if ($this->migrationExists($table)) {
                $this->components->warn("Migration for [{$table}] table already exists.");
                continue;
            }

            $this->createBaseMigration($table, $stub);
        }

        $this->components->info('Migrations created successfully.');

        return self::SUCCESS;
    }

    /**
     * Get the migration table name.
     */
    protected function migrationTableName(): string
    {
        return self::TABLE;
    }

    /**
     * Get the path to the migration stub file.
     */
    protected function migrationStubFile(): string
    {
        return __DIR__ . '/stubs/cache.stub';
    }
}
