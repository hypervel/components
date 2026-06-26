<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope\Console;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Telescope\TelescopeServiceProvider;
use Hypervel\Testbench\TestCase;

class InstallCommandTest extends TestCase
{
    protected ?string $originalProvidersContents = null;

    protected function setUp(): void
    {
        parent::setUp();

        $path = $this->app->getBootstrapProvidersPath();

        if (file_exists($path)) {
            $contents = file_get_contents($path);

            if ($contents !== false) {
                $this->originalProvidersContents = $contents;
            }
        }
    }

    protected function tearDown(): void
    {
        if ($this->originalProvidersContents !== null) {
            file_put_contents(
                $this->app->getBootstrapProvidersPath(),
                $this->originalProvidersContents
            );
        }

        foreach ($this->publishedFiles() as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
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
