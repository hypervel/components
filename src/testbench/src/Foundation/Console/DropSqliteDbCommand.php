<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Str;
use Hypervel\Testbench\Foundation\Console\Actions\DeleteFiles;
use Symfony\Component\Console\Attribute\AsCommand;

use function Hypervel\Filesystem\join_paths;

#[AsCommand(name: 'package:drop-sqlite-db', description: 'Drop sqlite database file')]
class DropSqliteDbCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'package:drop-sqlite-db
                                {--database=database.sqlite : Set the database name}
                                {--all : Delete all SQLite databases}';

    /**
     * Execute the console command.
     */
    public function handle(Filesystem $filesystem): int
    {
        $databasePath = $this->hypervel->databasePath();

        (new DeleteFiles(
            filesystem: $filesystem,
            components: $this->components,
        ))->handle(
            match ($this->option('all')) {
                true => [...$filesystem->glob(join_paths($databasePath, '*.sqlite'))],
                default => [join_paths($databasePath, $this->databaseName())],
            }
        );

        return self::SUCCESS;
    }

    /**
     * Resolve the database name.
     */
    protected function databaseName(): string
    {
        /** @var null|string $database */
        $database = $this->option('database');

        if (empty($database)) {
            $database = 'database';
        }

        return sprintf('%s.sqlite', Str::before((string) $database, '.sqlite'));
    }
}
