<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Console;

use Hypervel\Console\Command;
use Hypervel\Support\Collection;
use Hypervel\Support\ServiceProvider;
use Hypervel\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'telescope:install')]
class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected ?string $signature = 'telescope:install';

    /**
     * The console command description.
     */
    protected string $description = 'Install all of the Telescope resources';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->components->info('Installing Telescope resources.');

        collect([
            'Service Provider' => fn (): bool => $this->callSilent('vendor:publish', ['--tag' => 'telescope-provider']) === 0,
            'Configuration' => fn (): bool => $this->callSilent('vendor:publish', ['--tag' => 'telescope-config']) === 0,
        ])->each(fn ($task, $description) => $this->components->task($description, $task));

        $this->publishMigrations();

        $this->registerTelescopeServiceProvider();

        $this->components->info('Telescope scaffolding installed successfully.');
    }

    /**
     * Publish the Telescope migrations.
     */
    protected function publishMigrations(): void
    {
        if ($this->hasPublishedTelescopeMigration()) {
            $this->components->warn('Telescope migration already exists.');

            return;
        }

        $this->components->task(
            'Migrations',
            fn (): bool => $this->callSilent('vendor:publish', ['--tag' => 'telescope-migrations']) === 0
        );
    }

    /**
     * Determine if the Telescope migration has already been published.
     */
    protected function hasPublishedTelescopeMigration(): bool
    {
        $migrationsPath = $this->hypervel->databasePath('migrations');

        if (! is_dir($migrationsPath)) {
            return false;
        }

        return (new Collection(scandir($migrationsPath)))->contains(function ($migration): bool {
            return is_string($migration)
                && preg_match('/\d{4}_\d{2}_\d{2}_\d{6}_create_telescope_entries_table\.php/', $migration) === 1;
        });
    }

    /**
     * Register the Telescope service provider in the application bootstrap file.
     */
    protected function registerTelescopeServiceProvider(): void
    {
        $namespace = Str::replaceLast('\\', '', $this->hypervel->getNamespace());

        ServiceProvider::addProviderToBootstrapFile("{$namespace}\\Providers\\TelescopeServiceProvider");

        file_put_contents(app_path('Providers/TelescopeServiceProvider.php'), str_replace(
            'namespace App\Providers;',
            "namespace {$namespace}\\Providers;",
            file_get_contents(app_path('Providers/TelescopeServiceProvider.php'))
        ));
    }
}
