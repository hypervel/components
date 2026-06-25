<?php

declare(strict_types=1);

namespace Hypervel\Permission\Commands;

use Hypervel\Console\Command;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

#[AsCommand(name: 'permission:setup-teams')]
class UpgradeForTeamsCommand extends Command
{
    protected ?string $signature = 'permission:setup-teams';

    protected string $description = 'Setup the teams feature by generating the associated migration.';

    protected string $migrationSuffix = 'add_teams_fields.php';

    /**
     * Execute the console command.
     */
    public function handle(ConfigRepository $config): int
    {
        if (! $config->boolean('permission.teams', false)) {
            $this->error('Teams feature is disabled in your permission.php file.');
            $this->warn('Please enable the teams setting in your configuration.');

            return self::FAILURE;
        }

        $this->line('');
        $this->info('The teams feature setup is going to add a migration and a model');

        $existingMigrations = $this->alreadyExistingMigrations();

        if ($existingMigrations) {
            $this->line('');

            $this->warn($this->getExistingMigrationsWarning($existingMigrations));
        }

        $this->line('');

        if (! $this->confirm('Proceed with the migration creation?', true)) {
            return self::SUCCESS;
        }

        $this->line('');

        $this->line('Creating migration');

        if ($this->createMigration()) {
            $this->info('Migration created successfully.');
        } else {
            $this->error(
                "Couldn't create migration.\n"
                . 'Check the write permissions within the database/migrations directory.'
            );
        }

        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Create the teams migration.
     */
    protected function createMigration(): bool
    {
        try {
            $migrationStub = __DIR__ . "/../../database/migrations/{$this->migrationSuffix}.stub";
            copy($migrationStub, $this->getMigrationPath());

            return true;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return false;
        }
    }

    /**
     * Get a warning for existing teams migrations.
     *
     * @param array<int, string> $existingMigrations
     */
    protected function getExistingMigrationsWarning(array $existingMigrations): string
    {
        if (count($existingMigrations) > 1) {
            $base = "Setup teams migrations already exist.\nFollowing files were found: ";
        } else {
            $base = "Setup teams migration already exists.\nFollowing file was found: ";
        }

        return $base . array_reduce($existingMigrations, fn ($carry, $fileName) => $carry . "\n - " . $fileName);
    }

    /**
     * Get already existing teams migrations.
     *
     * @return array<int, string>
     */
    protected function alreadyExistingMigrations(): array
    {
        $matchingFiles = glob($this->getMigrationPath('*'));

        if ($matchingFiles === false) {
            return [];
        }

        return array_map(fn ($path) => basename($path), $matchingFiles);
    }

    /**
     * Get the migration path.
     */
    protected function getMigrationPath(?string $date = null): string
    {
        $date = $date ?: date('Y_m_d_His');

        return database_path("migrations/{$date}_{$this->migrationSuffix}");
    }
}
