<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Foundation\Console\ApiInstallCommand;
use Hypervel\Process\PendingProcess;
use Hypervel\Support\Facades\Process;

/**
 * @internal
 * @coversNothing
 */
class ApiInstallCommandTest extends \Hypervel\Testbench\TestCase
{
    /**
     * Original bootstrap/app.php content to restore after each test.
     */
    private string $originalBootstrapContent;

    /**
     * Files created during tests that need cleanup.
     *
     * @var list<string>
     */
    private array $createdFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Save the original bootstrap/app.php so we can restore it after each test.
        $this->originalBootstrapContent = file_get_contents(
            $this->app->bootstrapPath('app.php')
        );

        // Write a skeleton-style bootstrap/app.php that the install command expects.
        file_put_contents(
            $this->app->bootstrapPath('app.php'),
            $this->skeletonBootstrapFixture()
        );

        // Singleton so the same instance is reused — lets us inspect composerRequireCalls after the test.
        $this->app->singleton(ApiInstallCommand::class, TestableApiInstallCommand::class);

        // Ensure the routes directory exists.
        if (! is_dir($this->app->basePath('routes'))) {
            mkdir($this->app->basePath('routes'), 0755, true);
        }

        // Ensure the database/migrations directory exists.
        if (! is_dir($this->app->databasePath('migrations'))) {
            mkdir($this->app->databasePath('migrations'), 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // Restore the original bootstrap/app.php.
        file_put_contents(
            $this->app->bootstrapPath('app.php'),
            $this->originalBootstrapContent
        );

        // Clean up any files created during tests.
        foreach ($this->createdFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testCreatesApiRoutesFile()
    {
        Process::fake();

        $apiRoutesPath = $this->app->basePath('routes/api.php');
        $this->createdFiles[] = $apiRoutesPath;

        $this->artisan('install:api', ['--without-migration-prompt' => true])
            ->expectsOutputToContain('Published API routes file.')
            ->assertSuccessful();

        $this->assertFileExists($apiRoutesPath);

        $contents = file_get_contents($apiRoutesPath);
        $this->assertStringContainsString('Hypervel\Http\Request', $contents);
        $this->assertStringContainsString('Hypervel\Support\Facades\Route', $contents);
        $this->assertStringContainsString("->middleware('auth:sanctum')", $contents);
    }

    public function testRefusesOverwriteWithoutForce()
    {
        Process::fake();

        $apiRoutesPath = $this->app->basePath('routes/api.php');
        $this->createdFiles[] = $apiRoutesPath;

        file_put_contents($apiRoutesPath, '<?php // existing');

        $this->artisan('install:api', ['--without-migration-prompt' => true])
            ->expectsOutputToContain('API routes file already exists.')
            ->assertSuccessful();

        // File should NOT have been overwritten.
        $this->assertSame('<?php // existing', file_get_contents($apiRoutesPath));
    }

    public function testOverwritesWithForce()
    {
        Process::fake();

        $apiRoutesPath = $this->app->basePath('routes/api.php');
        $this->createdFiles[] = $apiRoutesPath;

        file_put_contents($apiRoutesPath, '<?php // existing');

        $this->artisan('install:api', ['--force' => true, '--without-migration-prompt' => true])
            ->expectsOutputToContain('Published API routes file.')
            ->assertSuccessful();

        $contents = file_get_contents($apiRoutesPath);
        $this->assertStringContainsString('Hypervel\Http\Request', $contents);
    }

    public function testInsertsApiLineInBootstrapFile()
    {
        Process::fake();

        $this->createdFiles[] = $this->app->basePath('routes/api.php');

        $this->artisan('install:api', ['--without-migration-prompt' => true])
            ->assertSuccessful();

        $bootstrapContent = file_get_contents($this->app->bootstrapPath('app.php'));

        $this->assertStringContainsString("api: __DIR__ . '/../routes/api.php',", $bootstrapContent);
        // Verify it was inserted after the web line.
        $this->assertStringContainsString(
            "web: __DIR__ . '/../routes/web.php'," . PHP_EOL . "        api: __DIR__ . '/../routes/api.php',",
            $bootstrapContent
        );
    }

    public function testUncommentsApiLineWhenCommentedOut()
    {
        Process::fake();

        $this->createdFiles[] = $this->app->basePath('routes/api.php');

        // Write a bootstrap with a commented-out api line.
        file_put_contents(
            $this->app->bootstrapPath('app.php'),
            $this->skeletonBootstrapFixtureWithCommentedApi()
        );

        $this->artisan('install:api', ['--without-migration-prompt' => true])
            ->assertSuccessful();

        $bootstrapContent = file_get_contents($this->app->bootstrapPath('app.php'));

        $this->assertStringContainsString("api: __DIR__ . '/../routes/api.php',", $bootstrapContent);
        $this->assertStringNotContainsString('// api:', $bootstrapContent);
    }

    public function testCallsSanctumComposerRequire()
    {
        Process::fake();

        $this->createdFiles[] = $this->app->basePath('routes/api.php');

        $this->artisan('install:api', ['--without-migration-prompt' => true])
            ->assertSuccessful();

        /** @var TestableApiInstallCommand $command */
        $command = $this->app->make(ApiInstallCommand::class);
        $this->assertCount(1, $command->composerRequireCalls);
        $this->assertSame(['hypervel/sanctum:^0.4'], $command->composerRequireCalls[0]['packages']);
    }

    public function testPublishesSanctumMigrationWhenMissing()
    {
        Process::fake();

        $this->createdFiles[] = $this->app->basePath('routes/api.php');

        $this->artisan('install:api', ['--without-migration-prompt' => true])
            ->assertSuccessful();

        Process::assertRan(function (PendingProcess $process): bool {
            $command = implode(' ', (array) $process->command);

            return str_contains($command, 'vendor:publish')
                && str_contains($command, 'Hypervel\Sanctum\SanctumServiceProvider');
        });
    }

    public function testSkipsSanctumMigrationWhenExists()
    {
        Process::fake();

        $this->createdFiles[] = $this->app->basePath('routes/api.php');

        // Pre-create a matching migration file.
        $migrationPath = $this->app->databasePath('migrations/2023_08_03_000000_create_personal_access_tokens_table.php');
        file_put_contents($migrationPath, '<?php // migration');
        $this->createdFiles[] = $migrationPath;

        $this->artisan('install:api', ['--without-migration-prompt' => true])
            ->assertSuccessful();

        Process::assertNotRan(function (PendingProcess $process): bool {
            $command = implode(' ', (array) $process->command);

            return str_contains($command, 'vendor:publish');
        });
    }

    public function testPromptsMigrationByDefault()
    {
        Process::fake();

        $this->createdFiles[] = $this->app->basePath('routes/api.php');

        $this->artisan('install:api')
            ->expectsConfirmation('One new database migration has been published. Would you like to run all pending database migrations?', 'no')
            ->assertSuccessful();
    }

    public function testSkipsMigrationPromptWithFlag()
    {
        Process::fake();

        $this->createdFiles[] = $this->app->basePath('routes/api.php');

        // Should not prompt — if it does, the test will fail with "unexpected question".
        $this->artisan('install:api', ['--without-migration-prompt' => true])
            ->assertSuccessful();
    }

    public function testOutputsHasApiTokensReminder()
    {
        Process::fake();

        $this->createdFiles[] = $this->app->basePath('routes/api.php');

        $this->artisan('install:api', ['--without-migration-prompt' => true])
            ->expectsOutputToContain('Hypervel\Sanctum\HasApiTokens')
            ->assertSuccessful();
    }

    /**
     * Get a skeleton-style bootstrap/app.php fixture with only web + commands + health.
     */
    private function skeletonBootstrapFixture(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

use Hypervel\Foundation\Application;
use Hypervel\Foundation\Configuration\Exceptions;
use Hypervel\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
PHP;
    }

    /**
     * Get a skeleton-style bootstrap/app.php fixture with a commented-out api line.
     */
    private function skeletonBootstrapFixtureWithCommentedApi(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

use Hypervel\Foundation\Application;
use Hypervel\Foundation\Configuration\Exceptions;
use Hypervel\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        // api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
PHP;
    }
}

/**
 * Testable ApiInstallCommand that overrides requireComposerPackages to record
 * calls without actually running Composer (which uses raw Symfony Process,
 * not the fakeable facade).
 *
 * @internal
 */
class TestableApiInstallCommand extends ApiInstallCommand
{
    /** @var list<array{composer: string, packages: array<int, string>}> */
    public array $composerRequireCalls = [];

    protected function requireComposerPackages(string $composer, array $packages): bool
    {
        $this->composerRequireCalls[] = ['composer' => $composer, 'packages' => $packages];

        return true;
    }
}
