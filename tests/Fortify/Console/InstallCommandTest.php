<?php

declare(strict_types=1);

namespace Hypervel\Tests\Fortify\Console;

use Hypervel\Console\Command as HypervelCommand;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Fortify\Console\InstallCommand;
use Hypervel\Fortify\FortifyServiceProvider;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Testing\Fixtures\CleanupActions;
use Mockery as m;
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
                    throw new RuntimeException("Unable to delete the owned Fortify providers file [{$providersPath}].");
                }
            };
        }

        foreach ($this->publishedFiles() as $file) {
            $actions[] = static function () use ($file, $files): void {
                if ($files->isFile($file) && ! $files->delete($file)) {
                    throw new RuntimeException("Unable to delete owned Fortify install test file [{$file}].");
                }
            };
        }

        $actions[] = fn () => parent::tearDown();

        CleanupActions::run(...$actions);
    }

    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            FortifyServiceProvider::class,
        ];
    }

    public function testInstallCommandPublishesFortifyResources(): void
    {
        $this->artisan('fortify:install')->assertSuccessful();

        $this->assertFileExists($this->app->configPath('fortify.php'));

        foreach ($this->publishedSupportFiles() as $file) {
            $this->assertFileExists($file);
            $this->assertStringContainsString('namespace App\\', file_get_contents($file));
        }

        $this->assertCount(2, $this->publishedFortifyMigrations());

        $providers = require $this->app->getBootstrapProvidersPath();
        $this->assertContains('App\Providers\FortifyServiceProvider', $providers);
    }

    public function testInstallCommandFailsWhenPublishingFails(): void
    {
        $this->app->singleton(InstallCommand::class, FailingFortifyInstallCommand::class);

        $this->artisan('fortify:install')
            ->expectsOutputToContain('Unable to publish Fortify Configuration.')
            ->assertExitCode(HypervelCommand::FAILURE);

        $providers = require $this->app->getBootstrapProvidersPath();
        $this->assertNotContains('App\Providers\FortifyServiceProvider', $providers);
    }

    public function testInstallCommandPreservesPublishedFilePermissions(): void
    {
        $this->artisan('fortify:install')->assertSuccessful();
        $path = $this->publishedSupportFiles()[0];
        chmod($path, 0640);

        $this->artisan('fortify:install')->assertSuccessful();

        clearstatcache(true, $path);
        $this->assertSame(0640, fileperms($path) & 0777);
    }

    public function testInstallCommandFailsWhenPublishedFileIsMissing(): void
    {
        $path = $this->publishedSupportFiles()[0];
        $this->assertFileDoesNotExist($path);
        $this->app->singleton(InstallCommand::class, SuccessfulPublishingFortifyInstallCommand::class);

        $this->artisan('fortify:install')
            ->expectsOutputToContain('was not published.')
            ->assertExitCode(HypervelCommand::FAILURE);

        $providers = require $this->app->getBootstrapProvidersPath();
        $this->assertNotContains('App\Providers\FortifyServiceProvider', $providers);
    }

    public function testInstallCommandFailsWhenPublishedFileCannotBeRead(): void
    {
        $path = $this->publishedSupportFiles()[0];
        $files = new Filesystem;
        $files->ensureDirectoryExists(dirname($path));
        $files->replace($path, '<?php');
        chmod($path, 0000);
        $this->app->singleton(InstallCommand::class, SuccessfulPublishingFortifyInstallCommand::class);

        try {
            if (@file_get_contents($path) !== false) {
                $this->markTestSkipped('The current user can read files without read permission.');
            }

            $this->artisan('fortify:install')
                ->expectsOutputToContain('Unable to read published Fortify file')
                ->assertExitCode(HypervelCommand::FAILURE);

            $providers = require $this->app->getBootstrapProvidersPath();
            $this->assertNotContains('App\Providers\FortifyServiceProvider', $providers);
        } finally {
            chmod($path, 0644);
        }
    }

    public function testInstallCommandStopsAfterPublishedFileReplacementFails(): void
    {
        $this->artisan('fortify:install')->assertSuccessful();
        (new Filesystem)->replace(
            $this->app->getBootstrapProvidersPath(),
            $this->originalProvidersContents,
        );

        $laterFile = $this->publishedSupportFiles()[1];
        (new Filesystem)->replace($laterFile, '<?php // later file');

        $files = m::mock(Filesystem::class);
        $files->shouldReceive('replace')->once()->andThrow(new RuntimeException('replace failed'));
        $this->app->instance(Filesystem::class, $files);

        $this->artisan('fortify:install')
            ->expectsOutputToContain('Unable to update published Fortify file')
            ->assertExitCode(HypervelCommand::FAILURE);

        $this->assertSame('<?php // later file', file_get_contents($laterFile));

        $providers = require $this->app->getBootstrapProvidersPath();
        $this->assertNotContains('App\Providers\FortifyServiceProvider', $providers);
    }

    /**
     * Get support files published by the install command.
     *
     * @return list<string>
     */
    protected function publishedSupportFiles(): array
    {
        return [
            $this->app->path('Actions/Fortify/CreateNewUser.php'),
            $this->app->path('Actions/Fortify/PasswordValidationRules.php'),
            $this->app->path('Actions/Fortify/ResetUserPassword.php'),
            $this->app->path('Actions/Fortify/UpdateUserPassword.php'),
            $this->app->path('Actions/Fortify/UpdateUserProfileInformation.php'),
            $this->app->path('Providers/FortifyServiceProvider.php'),
        ];
    }

    /**
     * Get migrations published by the install command.
     *
     * @return list<string>
     */
    protected function publishedFortifyMigrations(): array
    {
        return glob($this->app->databasePath('migrations/*_*.php')) ?: [];
    }

    /**
     * Get files published by the install command.
     *
     * @return list<string>
     */
    protected function publishedFiles(): array
    {
        return [
            $this->app->configPath('fortify.php'),
            ...$this->publishedSupportFiles(),
            ...$this->publishedFortifyMigrations(),
        ];
    }
}

class FailingFortifyInstallCommand extends InstallCommand
{
    /**
     * Call another console command silently.
     */
    public function callSilent(SymfonyCommand|string $command, array $arguments = []): int
    {
        return self::FAILURE;
    }
}

class SuccessfulPublishingFortifyInstallCommand extends InstallCommand
{
    /**
     * Call another console command silently.
     */
    public function callSilent(SymfonyCommand|string $command, array $arguments = []): int
    {
        return self::SUCCESS;
    }
}
