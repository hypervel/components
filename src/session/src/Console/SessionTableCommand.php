<?php

declare(strict_types=1);

namespace Hypervel\Session\Console;

use Hypervel\Console\MigrationGeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:session-table', aliases: ['session:table'])]
class SessionTableCommand extends MigrationGeneratorCommand
{
    /**
     * The console command name.
     */
    protected ?string $name = 'make:session-table';

    /**
     * The console command name aliases.
     */
    protected array $aliases = ['session:table'];

    /**
     * The console command description.
     */
    protected string $description = 'Create a migration for the session database table';

    /**
     * Get the migration table name.
     */
    protected function migrationTableName(): string
    {
        return 'sessions';
    }

    /**
     * Get the path to the migration stub file.
     */
    protected function migrationStubFile(): string
    {
        return __DIR__ . '/stubs/database.stub';
    }
}
