<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Console;

use Hypervel\Console\Command as HypervelCommand;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Horizon\Console\InstallCommand;
use Hypervel\Horizon\HorizonServiceProvider;
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
                    throw new RuntimeException("Unable to delete the owned Horizon providers file [{$providersPath}].");
                }
            };
        }

        foreach ($this->publishedFiles() as $file) {
            $actions[] = static function () use ($file, $files): void {
                if ($files->isFile($file) && ! $files->delete($file)) {
                    throw new RuntimeException("Unable to delete owned Horizon install test file [{$file}].");
                }
            };
        }

        $actions[] = fn () => parent::tearDown();

        CleanupActions::run(...$actions);
    }

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            HorizonServiceProvider::class,
        ];
    }

    public function testInstallCommandPublishesHorizonResources(): void
    {
        $this->artisan('horizon:install')->assertSuccessful();

        $this->assertFileExists($this->app->configPath('horizon.php'));
        $this->assertFileExists($this->app->path('Providers/HorizonServiceProvider.php'));

        $providers = require $this->app->getBootstrapProvidersPath();

        $this->assertContains('App\Providers\HorizonServiceProvider', $providers);
        $this->assertStringContainsString(
            'namespace App\Providers;',
            file_get_contents($this->app->path('Providers/HorizonServiceProvider.php'))
        );
    }

    public function testInstallCommandFailsWhenPublishingFails(): void
    {
        $this->app->singleton(InstallCommand::class, FailingHorizonInstallCommand::class);

        $this->artisan('horizon:install')
            ->expectsOutputToContain('Unable to publish Horizon Service Provider.')
            ->assertExitCode(HypervelCommand::FAILURE);
    }

    public function testInstallCommandFailsWhenProviderFileWasNotPublished(): void
    {
        $this->app->singleton(InstallCommand::class, MissingProviderHorizonInstallCommand::class);

        $this->artisan('horizon:install')
            ->expectsOutputToContain('HorizonServiceProvider file was not published.')
            ->assertExitCode(HypervelCommand::FAILURE);

        $providers = require $this->app->getBootstrapProvidersPath();

        $this->assertNotContains('App\Providers\HorizonServiceProvider', $providers);
    }

    public function testInstallCommandFailsWhenBootstrapProvidersFileIsMissing(): void
    {
        unlink($this->app->getBootstrapProvidersPath());

        $this->artisan('horizon:install')
            ->expectsOutputToContain('Unable to register HorizonServiceProvider in bootstrap/providers.php.')
            ->assertExitCode(HypervelCommand::FAILURE);
    }

    /**
     * Get files published by the install command.
     *
     * @return list<string>
     */
    protected function publishedFiles(): array
    {
        return [
            $this->app->configPath('horizon.php'),
            $this->app->path('Providers/HorizonServiceProvider.php'),
        ];
    }
}

class FailingHorizonInstallCommand extends InstallCommand
{
    /**
     * Call another console command silently.
     */
    public function callSilent(SymfonyCommand|string $command, array $arguments = []): int
    {
        return self::FAILURE;
    }
}

class MissingProviderHorizonInstallCommand extends InstallCommand
{
    /**
     * Call another console command silently.
     */
    public function callSilent(SymfonyCommand|string $command, array $arguments = []): int
    {
        return self::SUCCESS;
    }
}
