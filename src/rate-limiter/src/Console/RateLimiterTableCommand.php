<?php

declare(strict_types=1);

namespace Hypervel\RateLimiter\Console;

use Hypervel\Console\MigrationGeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:rate-limiter-table', aliases: ['rate-limiter:table'])]
class RateLimiterTableCommand extends MigrationGeneratorCommand
{
    /**
     * The console command name.
     */
    protected ?string $name = 'make:rate-limiter-table';

    /**
     * The console command aliases.
     *
     * @var string[]
     */
    protected array $aliases = ['rate-limiter:table'];

    /**
     * The console command description.
     */
    protected string $description = 'Create a migration for the rate limiter database table';

    /**
     * Get the migration table name.
     */
    protected function migrationTableName(): string
    {
        return $this->hypervel->make('config')->string('rate-limiter.stores.database.table');
    }

    /**
     * Get the path to the migration stub file.
     */
    protected function migrationStubFile(): string
    {
        return __DIR__ . '/stubs/rate-limits.stub';
    }
}
