<?php

declare(strict_types=1);

namespace Hypervel\Notifications\Console;

use Hypervel\Console\MigrationGeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:notifications-table', aliases: ['notifications:table'])]
class NotificationTableCommand extends MigrationGeneratorCommand
{
    /**
     * The console command name.
     */
    protected ?string $name = 'make:notifications-table';

    /**
     * The console command name aliases.
     */
    protected array $aliases = ['notifications:table'];

    /**
     * The console command description.
     */
    protected string $description = 'Create a migration for the notifications table';

    /**
     * Get the migration table name.
     */
    protected function migrationTableName(): string
    {
        return 'notifications';
    }

    /**
     * Get the path to the migration stub file.
     */
    protected function migrationStubFile(): string
    {
        return __DIR__ . '/stubs/notifications.stub';
    }
}
