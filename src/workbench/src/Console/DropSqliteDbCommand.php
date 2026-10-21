<?php

declare(strict_types=1);

namespace Hypervel\Workbench\Console;

use Hypervel\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'workbench:drop-sqlite-db', description: 'Drop sqlite database file')]
class DropSqliteDbCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'workbench:drop-sqlite-db
                                {--database=database.sqlite : Set the database name}
                                {--all : Delete all SQLite databases}
                                {--pretend : Outputs the operations but will not execute anything}';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        return $this->call('package:drop-sqlite-db', [
            '--database' => $this->option('database'),
            '--all' => $this->option('all'),
            '--pretend' => $this->option('pretend'),
        ]);
    }
}
