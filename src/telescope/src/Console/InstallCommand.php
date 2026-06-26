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
    public function handle(): int
    {
        $this->components->info('Installing Telescope resources.');

        if (! $this->publishResource('Service Provider', 'telescope-provider')
            || ! $this->publishResource('Configuration', 'telescope-config')
            || ! $this->publishMigrations()) {
            return self::FAILURE;
        }

        if (! $this->registerTelescopeServiceProvider()) {
            return self::FAILURE;
        }

        $this->components->info('Telescope scaffolding installed successfully.');

        return self::SUCCESS;
    }

    /**
     * Publish a Telescope resource.
     */
    protected function publishResource(string $description, string $tag): bool
    {
        $published = false;

        $this->components->task($description, function () use (&$published, $tag): bool {
            return $published = $this->callSilent('vendor:publish', ['--tag' => $tag]) === 0;
        });

        if (! $published) {
            $this->components->error("Unable to publish Telescope {$description}.");
        }

        return $published;
    }

    /**
     * Publish the Telescope migrations.
     */
    protected function publishMigrations(): bool
    {
        if ($this->hasPublishedTelescopeMigration()) {
            $this->components->warn('Telescope migration already exists.');

            return true;
        }

        $published = false;

        $this->components->task(
            'Migrations',
            function () use (&$published): bool {
                return $published = $this->callSilent('vendor:publish', ['--tag' => 'telescope-migrations']) === 0;
            }
        );

        if (! $published) {
            $this->components->error('Unable to publish Telescope Migrations.');
        }

        return $published;
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
    protected function registerTelescopeServiceProvider(): bool
    {
        $namespace = Str::replaceLast('\\', '', $this->hypervel->getNamespace());

        if (! ServiceProvider::addProviderToBootstrapFile("{$namespace}\\Providers\\TelescopeServiceProvider")) {
            $this->components->error('Unable to register TelescopeServiceProvider in bootstrap/providers.php.');

            return false;
        }

        $providerPath = $this->hypervel->path('Providers/TelescopeServiceProvider.php');

        if (! is_file($providerPath) || ! is_readable($providerPath)) {
            $this->components->error('TelescopeServiceProvider file was not published.');

            return false;
        }

        $contents = file_get_contents($providerPath);

        if ($contents === false) {
            $this->components->error('Unable to read the TelescopeServiceProvider file.');

            return false;
        }

        if (file_put_contents($providerPath, str_replace(
            'namespace App\Providers;',
            "namespace {$namespace}\\Providers;",
            $contents,
        )) === false) {
            $this->components->error('Unable to update the TelescopeServiceProvider namespace.');

            return false;
        }

        return true;
    }
}
