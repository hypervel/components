<?php

declare(strict_types=1);

namespace Hypervel\Queue\Console;

use Hypervel\Console\MigrationGeneratorCommand;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'make:queue-outbox-table', aliases: ['queue:outbox-table'])]
class OutboxTableCommand extends MigrationGeneratorCommand
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'make:queue-outbox-table';

    /**
     * The console command name aliases.
     *
     * @var string[]
     */
    protected array $aliases = ['queue:outbox-table'];

    /**
     * The console command description.
     */
    protected string $description = 'Create a migration for the transactional queue outbox table';

    /**
     * Get the migration table name.
     */
    protected function migrationTableName(): string
    {
        return $this->hypervel->make('config')->string('queue.outbox.table');
    }

    /**
     * Get the path to the migration stub file.
     */
    protected function migrationStubFile(): string
    {
        return __DIR__ . '/stubs/outbox.stub';
    }
}
