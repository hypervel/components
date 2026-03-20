<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Foundation\Console;

use Hypervel\Console\Command;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Str;
use Hypervel\Testbench\Foundation\Console\Actions\GeneratesFile;
use Symfony\Component\Console\Attribute\AsCommand;

use function Hypervel\Filesystem\join_paths;

#[AsCommand(name: 'package:create-sqlite-db', description: 'Create sqlite database file')]
class CreateSqliteDbCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'package:create-sqlite-db
                                {--database=database.sqlite : Set the database name}
                                {--force : Overwrite the database file}';

    /**
     * Execute the console command.
     */
    public function handle(Filesystem $filesystem): int
    {
        $databasePath = $this->hypervel->databasePath();

        /** @var bool $force */
        $force = $this->option('force');

        $filesystem->ensureDirectoryExists($databasePath);

        $from = $filesystem->exists(join_paths($databasePath, 'database.sqlite.example'))
            ? join_paths($databasePath, 'database.sqlite.example')
            : (string) realpath(join_paths(__DIR__, 'stubs', 'database.sqlite.example'));

        $to = join_paths($databasePath, $this->databaseName());

        (new GeneratesFile(
            filesystem: $filesystem,
            components: $this->components,
            force: $force,
        ))->handle($from, $to);

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
