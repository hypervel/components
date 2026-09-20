<?php

declare(strict_types=1);

namespace Hypervel\Database\Console\Migrations;

use Hypervel\Database\Migrations\Migrator;
use Hypervel\Support\Collection;
use Hypervel\Support\Stringable;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'migrate:status')]
class StatusCommand extends BaseCommand
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'migrate:status
                    {--database= : The database connection to use}
                    {--pending= : Only list pending migrations}
                    {--path=* : The path(s) to the migrations files to use}
                    {--realpath : Indicate any provided migration file paths are pre-resolved absolute paths}';

    /**
     * The console command description.
     */
    protected string $description = 'Show the status of each migration';

    /**
     * Create a new migration rollback command instance.
     */
    public function __construct(Migrator $migrator)
    {
        parent::__construct();

        $this->migrator = $migrator;
    }

    /**
     * Configure the command's default values.
     */
    #[Override]
    protected function configureDefaults(): void
    {
        // Defaults to false (not null) so that handle() can distinguish "not passed
        // at all" from "passed with no value", which can't be expressed as a literal
        // boolean default in the signature above.
        $this->getDefinition()->getOption('pending')->setDefault(false);
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        return $this->migrator->usingConnection($this->option('database'), function () {
            if (! $this->migrator->repositoryExists()) {
                $this->components->error('Migration table not found.');

                return self::FAILURE;
            }

            $ran = $this->migrator->getRepository()->getRan();

            $batches = $this->migrator->getRepository()->getMigrationBatches();

            $migrations = $this->getStatusFor($ran, $batches)
                ->when($this->option('pending') !== false, fn ($collection) => $collection->filter(function ($migration) {
                    return (new Stringable($migration[1]))->contains('Pending');
                }));

            if (count($migrations) > 0) {
                $this->newLine();

                $this->components->twoColumnDetail('<fg=gray>Migration name</>', '<fg=gray>Batch / Status</>');

                $migrations
                    ->each(
                        fn ($migration) => $this->components->twoColumnDetail($migration[0], $migration[1])
                    );

                $this->newLine();
            } elseif ($this->option('pending') !== false) {
                $this->components->info('No pending migrations');
            } else {
                $this->components->info('No migrations found');
            }

            if ($this->option('pending') && $migrations->some(fn ($m) => (new Stringable($m[1]))->contains('Pending'))) {
                return (int) $this->option('pending');
            }

            return self::SUCCESS;
        });
    }

    /**
     * Get the status for the given run migrations.
     */
    protected function getStatusFor(array $ran, array $batches): Collection
    {
        return (new Collection($this->getAllMigrationFiles()))
            ->map(function ($migration) use ($ran, $batches) {
                $migrationName = $this->migrator->getMigrationName($migration);

                $status = in_array($migrationName, $ran)
                    ? '<fg=green;options=bold>Ran</>'
                    : '<fg=yellow;options=bold>Pending</>';

                if (in_array($migrationName, $ran)) {
                    $status = '[' . $batches[$migrationName] . '] ' . $status;
                }

                return [$migrationName, $status];
            });
    }

    /**
     * Get an array of all of the migration files.
     */
    protected function getAllMigrationFiles(): array
    {
        return $this->migrator->getMigrationFiles($this->getMigrationPaths());
    }
}
