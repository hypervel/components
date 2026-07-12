<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Console;

use Hypervel\Console\Command as HypervelCommand;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Telescope\Console\InstallCommand;
use Hypervel\Telescope\TelescopeServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Testing\Fixtures\CleanupActions;
use RuntimeException;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

class InstallCommandTest extends TestCase
{
    protected ?string $originalProvidersContents = null;

    protected function setUp(): void
    {
        parent::setUp();

        $path = $this->app->getBootstrapProvidersPath();
        $files = new Filesystem;

        if ($files->isFile($path)) {
            $this->originalProvidersContents = $files->get($path);
        }
    }

    protected function tearDown(): void
    {
        $files = new Filesystem;
        $providersPath = $this->app->getBootstrapProvidersPath();
        $actions = [];

        if ($this->originalProvidersContents !== null) {
            $actions[] = fn () => $files->replace(
                $providersPath,
                $this->originalProvidersContents,
            );
        } else {
            $actions[] = static function () use ($files, $providersPath): void {
                if ($files->isFile($providersPath) && ! $files->delete($providersPath)) {
                    throw new RuntimeException("Unable to delete the owned Telescope providers file [{$providersPath}].");
                }
            };
        }

        foreach ($this->publishedFiles() as $file) {
            $actions[] = static function () use ($file, $files): void {
                if ($files->isFile($file) && ! $files->delete($file)) {
                    throw new RuntimeException("Unable to delete owned Telescope install test file [{$file}].");
                }
            };
        }

        $actions[] = fn () => parent::tearDown();

        CleanupActions::run(...$actions);
    }

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            TelescopeServiceProvider::class,
        ];
    }

    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set('telescope.enabled', false);
    }

    public function testInstallCommandPublishesTelescopeResources(): void
    {
        $this->artisan('telescope:install')->assertSuccessful();

        $this->assertFileExists($this->app->configPath('telescope.php'));
        $this->assertFileExists($this->app->path('Providers/TelescopeServiceProvider.php'));

        $providers = require $this->app->getBootstrapProvidersPath();

        $this->assertContains('App\Providers\TelescopeServiceProvider', $providers);
        $this->assertStringContainsString(
            'namespace App\Providers;',
            file_get_contents($this->app->path('Providers/TelescopeServiceProvider.php'))
        );
        $this->assertCount(1, $this->publishedTelescopeMigrations());
    }

    public function testInstallCommandFailsWhenPublishingFails(): void
    {
        $this->app->singleton(InstallCommand::class, FailingTelescopeInstallCommand::class);

        $this->artisan('telescope:install')
            ->expectsOutputToContain('Unable to publish Telescope Service Provider.')
            ->assertExitCode(HypervelCommand::FAILURE);
    }

    public function testInstallCommandFailsWhenProviderFileWasNotPublished(): void
    {
        $this->app->singleton(InstallCommand::class, MissingProviderTelescopeInstallCommand::class);

        $this->artisan('telescope:install')
            ->expectsOutputToContain('TelescopeServiceProvider file was not published.')
            ->assertExitCode(HypervelCommand::FAILURE);

        $providers = require $this->app->getBootstrapProvidersPath();

        $this->assertNotContains('App\Providers\TelescopeServiceProvider', $providers);
    }

    public function testInstallCommandFailsWhenBootstrapProvidersFileIsMissing(): void
    {
        unlink($this->app->getBootstrapProvidersPath());

        $this->artisan('telescope:install')
            ->expectsOutputToContain('Unable to register TelescopeServiceProvider in bootstrap/providers.php.')
            ->assertExitCode(HypervelCommand::FAILURE);
    }

    public function testInstallCommandDoesNotRepublishExistingTelescopeMigration(): void
    {
        $this->artisan('telescope:install')->assertSuccessful();
        $this->artisan('telescope:install')->assertSuccessful();

        $this->assertCount(1, $this->publishedTelescopeMigrations());
    }

    /**
     * Get published Telescope migrations.
     *
     * @return list<string>
     */
    protected function publishedTelescopeMigrations(): array
    {
        return glob($this->app->databasePath('migrations/*_create_telescope_entries_table.php')) ?: [];
    }

    /**
     * Get files published by the install command.
     *
     * @return list<string>
     */
    protected function publishedFiles(): array
    {
        return [
            $this->app->configPath('telescope.php'),
            $this->app->path('Providers/TelescopeServiceProvider.php'),
            ...$this->publishedTelescopeMigrations(),
        ];
    }
}

class FailingTelescopeInstallCommand extends InstallCommand
{
    /**
     * Call another console command silently.
     */
    public function callSilent(SymfonyCommand|string $command, array $arguments = []): int
    {
        return self::FAILURE;
    }
}

class MissingProviderTelescopeInstallCommand extends InstallCommand
{
    /**
     * Call another console command silently.
     */
    public function callSilent(SymfonyCommand|string $command, array $arguments = []): int
    {
        return self::SUCCESS;
    }
}
