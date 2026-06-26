<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Console;

use Hypervel\Console\Command as HypervelCommand;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Horizon\Console\InstallCommand;
use Hypervel\Horizon\HorizonServiceProvider;
use Hypervel\Testbench\TestCase;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

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
