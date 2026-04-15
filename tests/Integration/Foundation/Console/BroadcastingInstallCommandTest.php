<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Foundation\Console\BroadcastingInstallCommand;
use Hypervel\Process\PendingProcess;
use Hypervel\Support\Facades\Process;

class BroadcastingInstallCommandTest extends \Hypervel\Testbench\TestCase
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

    /**
     * Original .env content (null if .env didn't exist before the test).
     */
    private ?string $originalEnvContent = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Save the original bootstrap/app.php.
        $this->originalBootstrapContent = file_get_contents(
            $this->app->bootstrapPath('app.php')
        );

        // Write a skeleton-style bootstrap/app.php.
        file_put_contents(
            $this->app->bootstrapPath('app.php'),
            $this->skeletonBootstrapFixture()
        );

        // Singleton so we can inspect composerRequireCalls after the test.
        $this->app->singleton(BroadcastingInstallCommand::class, TestableBroadcastingInstallCommand::class);

        // Ensure routes/ directory exists.
        if (! is_dir($this->app->basePath('routes'))) {
            mkdir($this->app->basePath('routes'), 0755, true);
        }

        // Ensure resources/js/ directory exists.
        if (! is_dir($this->app->resourcePath('js'))) {
            mkdir($this->app->resourcePath('js'), 0755, true);
        }

        // Create resources/js/app.js (the command checks for this to append echo import).
        $appJsPath = $this->app->resourcePath('js/app.js');
        if (! is_file($appJsPath)) {
            file_put_contents($appJsPath, '//' . PHP_EOL);
            $this->createdFiles[] = $appJsPath;
        }

        // Save or create .env (Env::writeVariable requires it to exist).
        $envPath = $this->app->basePath('.env');
        if (is_file($envPath)) {
            $this->originalEnvContent = file_get_contents($envPath);
        } else {
            $this->originalEnvContent = null;
            file_put_contents($envPath, 'BROADCAST_CONNECTION=log' . PHP_EOL);
        }
    }

    protected function tearDown(): void
    {
        // Restore the original bootstrap/app.php.
        file_put_contents(
            $this->app->bootstrapPath('app.php'),
            $this->originalBootstrapContent
        );

        // Restore or remove .env.
        $envPath = $this->app->basePath('.env');
        if ($this->originalEnvContent === null) {
            @unlink($envPath);
        } else {
            file_put_contents($envPath, $this->originalEnvContent);
        }

        // Clean up published config file (config:publish broadcasting creates this).
        $broadcastingConfig = $this->app->configPath('broadcasting.php');
        if (is_file($broadcastingConfig)) {
            @unlink($broadcastingConfig);
        }

        // Clean up any files created during tests.
        foreach ($this->createdFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function testCreatesChannelsRouteFile()
    {
        Process::fake();

        $channelsPath = $this->app->basePath('routes/channels.php');
        $this->createdFiles[] = $channelsPath;

        $this->artisan('install:broadcasting', ['--reverb' => true, '--without-reverb' => true, '--without-node' => true])
            ->expectsOutputToContain("Published 'channels' route file.")
            ->assertSuccessful();

        $this->assertFileExists($channelsPath);

        $contents = file_get_contents($channelsPath);
        $this->assertStringContainsString('Hypervel\Support\Facades\Broadcast', $contents);
        $this->assertStringContainsString('declare(strict_types=1)', $contents);
    }

    public function testSkipsChannelsRouteFileWithoutForce()
    {
        Process::fake();

        $channelsPath = $this->app->basePath('routes/channels.php');
        $this->createdFiles[] = $channelsPath;

        file_put_contents($channelsPath, '<?php // existing');

        $this->artisan('install:broadcasting', ['--reverb' => true, '--without-reverb' => true, '--without-node' => true])
            ->assertSuccessful();

        // File should NOT have been overwritten.
        $this->assertSame('<?php // existing', file_get_contents($channelsPath));
    }

    public function testOverwritesChannelsRouteFileWithForce()
    {
        Process::fake();

        $channelsPath = $this->app->basePath('routes/channels.php');
        $this->createdFiles[] = $channelsPath;

        file_put_contents($channelsPath, '<?php // existing');

        $this->artisan('install:broadcasting', ['--reverb' => true, '--without-reverb' => true, '--without-node' => true, '--force' => true])
            ->expectsOutputToContain("Published 'channels' route file.")
            ->assertSuccessful();

        $contents = file_get_contents($channelsPath);
        $this->assertStringContainsString('Hypervel\Support\Facades\Broadcast', $contents);
    }

    public function testInsertsChannelsLineInBootstrapFile()
    {
        Process::fake();

        $this->createdFiles[] = $this->app->basePath('routes/channels.php');

        $this->artisan('install:broadcasting', ['--reverb' => true, '--without-reverb' => true, '--without-node' => true])
            ->assertSuccessful();

        $bootstrapContent = file_get_contents($this->app->bootstrapPath('app.php'));

        $this->assertStringContainsString("channels: __DIR__ . '/../routes/channels.php',", $bootstrapContent);
        // Verify it was inserted after the commands line.
        $this->assertStringContainsString(
            "commands: __DIR__ . '/../routes/console.php'," . PHP_EOL . "        channels: __DIR__ . '/../routes/channels.php',",
            $bootstrapContent
        );
    }

    public function testUncommentsChannelsLineWhenCommentedOut()
    {
        Process::fake();

        $this->createdFiles[] = $this->app->basePath('routes/channels.php');

        file_put_contents(
            $this->app->bootstrapPath('app.php'),
            $this->skeletonBootstrapFixtureWithCommentedChannels()
        );

        $this->artisan('install:broadcasting', ['--reverb' => true, '--without-reverb' => true, '--without-node' => true])
            ->assertSuccessful();

        $bootstrapContent = file_get_contents($this->app->bootstrapPath('app.php'));

        $this->assertStringContainsString("channels: __DIR__ . '/../routes/channels.php',", $bootstrapContent);
        $this->assertStringNotContainsString('// channels:', $bootstrapContent);
    }

    public function testFallsBackToWithRoutingInsertion()
    {
        Process::fake();

        $this->createdFiles[] = $this->app->basePath('routes/channels.php');

        // Write a bootstrap with withRouting but no commands: line.
        file_put_contents(
            $this->app->bootstrapPath('app.php'),
            $this->skeletonBootstrapFixtureMinimal()
        );

        $this->artisan('install:broadcasting', ['--reverb' => true, '--without-reverb' => true, '--without-node' => true])
            ->assertSuccessful();

        $bootstrapContent = file_get_contents($this->app->bootstrapPath('app.php'));

        $this->assertStringContainsString("channels: __DIR__ . '/../routes/channels.php',", $bootstrapContent);
    }

    public function testWritesBroadcastConnectionEnv()
    {
        Process::fake();

        $this->createdFiles[] = $this->app->basePath('routes/channels.php');

        $this->artisan('install:broadcasting', ['--reverb' => true, '--without-reverb' => true, '--without-node' => true])
            ->assertSuccessful();

        $envContent = file_get_contents($this->app->basePath('.env'));
        $this->assertStringContainsString('BROADCAST_CONNECTION=reverb', $envContent);
    }

    public function testCreatesEchoJsFromReverbStub()
    {
        Process::fake();

        $this->createdFiles[] = $this->app->basePath('routes/channels.php');

        $echoJsPath = $this->app->resourcePath('js/echo.js');
        $this->createdFiles[] = $echoJsPath;

        $this->artisan('install:broadcasting', ['--reverb' => true, '--without-reverb' => true, '--without-node' => true])
            ->assertSuccessful();

        $this->assertFileExists($echoJsPath);
        $contents = file_get_contents($echoJsPath);
        $this->assertStringContainsString("broadcaster: 'reverb'", $contents);
    }

    public function testCreatesEchoJsFromPusherStub()
    {
        Process::fake();

        $this->createdFiles[] = $this->app->basePath('routes/channels.php');

        $echoJsPath = $this->app->resourcePath('js/echo.js');
        $this->createdFiles[] = $echoJsPath;

        // Pusher is already installed (InstalledVersions returns true), so
        // installDriverPackages() skips. collectPusherConfig() prompts for credentials.
        $this->artisan('install:broadcasting', ['--pusher' => true, '--without-node' => true])
            ->expectsQuestion('Pusher App ID', 'test-id')
            ->expectsQuestion('Pusher App Key', 'test-key')
            ->expectsQuestion('Pusher App Secret', 'test-secret')
            ->expectsQuestion('Pusher App Cluster', 'mt1')
            ->assertSuccessful();

        $this->assertFileExists($echoJsPath);
        $contents = file_get_contents($echoJsPath);
        $this->assertStringContainsString('broadcaster: "pusher"', $contents);
    }

    public function testCreatesEchoJsFromAblyStub()
    {
        Process::fake();

        $this->createdFiles[] = $this->app->basePath('routes/channels.php');

        $echoJsPath = $this->app->resourcePath('js/echo.js');
        $this->createdFiles[] = $echoJsPath;

        // Ably is already installed. collectAblyConfig() prompts for key.
        $this->artisan('install:broadcasting', ['--ably' => true, '--without-node' => true])
            ->expectsQuestion('Ably Key', 'test-key:test-public')
            ->assertSuccessful();

        $this->assertFileExists($echoJsPath);
        $contents = file_get_contents($echoJsPath);
        $this->assertStringContainsString('broadcaster: "pusher"', $contents);
        $this->assertStringContainsString('VITE_ABLY_PUBLIC_KEY', $contents);
    }

    public function testAppendsEchoImportToAppJs()
    {
        Process::fake();

        $this->createdFiles[] = $this->app->basePath('routes/channels.php');
        $this->createdFiles[] = $this->app->resourcePath('js/echo.js');

        $this->artisan('install:broadcasting', ['--reverb' => true, '--without-reverb' => true, '--without-node' => true])
            ->assertSuccessful();

        $appJsContent = file_get_contents($this->app->resourcePath('js/app.js'));
        $this->assertStringContainsString("import './echo'", $appJsContent);
    }

    public function testDoesNotDuplicateEchoImport()
    {
        Process::fake();

        $this->createdFiles[] = $this->app->basePath('routes/channels.php');
        $this->createdFiles[] = $this->app->resourcePath('js/echo.js');

        // Pre-populate app.js with the echo import already present.
        file_put_contents(
            $this->app->resourcePath('js/app.js'),
            "import './echo';" . PHP_EOL
        );

        $this->artisan('install:broadcasting', ['--reverb' => true, '--without-reverb' => true, '--without-node' => true])
            ->assertSuccessful();

        $appJsContent = file_get_contents($this->app->resourcePath('js/app.js'));
        // Should appear exactly once.
        $this->assertSame(1, substr_count($appJsContent, './echo'));
    }

    public function testInjectsEchoConfigIntoVueAppJs()
    {
        Process::fake();

        $this->createdFiles[] = $this->app->basePath('routes/channels.php');

        // Create a package.json that declares Vue as a dependency.
        $packageJsonPath = $this->app->basePath('package.json');
        file_put_contents($packageJsonPath, json_encode(['dependencies' => ['vue' => '^3.0']]));
        $this->createdFiles[] = $packageJsonPath;

        // resources/js/app.js already exists from setUp (the second candidate — app.ts does NOT exist).
        // This is the exact scenario the Arr::first fix addresses.

        $this->artisan('install:broadcasting', ['--reverb' => true, '--without-reverb' => true, '--without-node' => true])
            ->expectsOutputToContain('Echo configuration added to [app.js].')
            ->assertSuccessful();

        $appJsContent = file_get_contents($this->app->resourcePath('js/app.js'));
        $this->assertStringContainsString("import { configureEcho } from '@laravel/echo-vue'", $appJsContent);
        $this->assertStringContainsString("broadcaster: 'reverb'", $appJsContent);

        // Framework-specific path should NOT create echo.js.
        $this->assertFileDoesNotExist($this->app->resourcePath('js/echo.js'));
    }

    public function testInjectsEchoConfigIntoReactAppJsx()
    {
        Process::fake();

        $this->createdFiles[] = $this->app->basePath('routes/channels.php');

        // Create a package.json that declares React as a dependency.
        $packageJsonPath = $this->app->basePath('package.json');
        file_put_contents($packageJsonPath, json_encode(['dependencies' => ['react' => '^18.0']]));
        $this->createdFiles[] = $packageJsonPath;

        // Create resources/js/app.jsx (the second candidate — app.tsx does NOT exist).
        $appJsxPath = $this->app->resourcePath('js/app.jsx');
        file_put_contents($appJsxPath, 'import React from "react";' . PHP_EOL);
        $this->createdFiles[] = $appJsxPath;

        $this->artisan('install:broadcasting', ['--reverb' => true, '--without-reverb' => true, '--without-node' => true])
            ->expectsOutputToContain('Echo configuration added to [app.jsx].')
            ->assertSuccessful();

        $appJsxContent = file_get_contents($appJsxPath);
        $this->assertStringContainsString("import { configureEcho } from '@laravel/echo-react'", $appJsxContent);
        $this->assertStringContainsString("broadcaster: 'reverb'", $appJsxContent);
        // Should be inserted after the existing import.
        $this->assertStringContainsString('import React from "react";', $appJsxContent);

        // Framework-specific path should NOT create echo.js.
        $this->assertFileDoesNotExist($this->app->resourcePath('js/echo.js'));
    }

    public function testInstallsReverbPackageAndRunsInstall()
    {
        Process::fake();

        $this->createdFiles[] = $this->app->basePath('routes/channels.php');
        $this->createdFiles[] = $this->app->resourcePath('js/echo.js');

        // --reverb WITHOUT --without-reverb: triggers the Reverb install prompt.
        $this->artisan('install:broadcasting', ['--reverb' => true, '--without-node' => true])
            ->expectsConfirmation('Would you like to install Hypervel Reverb?', 'yes')
            ->assertSuccessful();

        // Verify composer require was called for reverb.
        /** @var TestableBroadcastingInstallCommand $command */
        $command = $this->app->make(BroadcastingInstallCommand::class);
        $reverbCalls = array_filter($command->composerRequireCalls, function (array $call): bool {
            return in_array('hypervel/reverb:^0.4', $call['packages'], true);
        });
        $this->assertCount(1, $reverbCalls);

        // Verify reverb:install was run via the Process facade.
        Process::assertRan(function (PendingProcess $process): bool {
            $command = implode(' ', (array) $process->command);

            return str_contains($command, 'reverb:install');
        });
    }

    public function testSkipsNodeDepsWithFlag()
    {
        Process::fake();

        $this->createdFiles[] = $this->app->basePath('routes/channels.php');
        $this->createdFiles[] = $this->app->resourcePath('js/echo.js');

        // --without-node should not prompt. If it does, the test fails with "unexpected question".
        $this->artisan('install:broadcasting', ['--reverb' => true, '--without-reverb' => true, '--without-node' => true])
            ->assertSuccessful();
    }

    public function testWritesPusherEnvVariables()
    {
        Process::fake();

        $this->createdFiles[] = $this->app->basePath('routes/channels.php');
        $this->createdFiles[] = $this->app->resourcePath('js/echo.js');

        $this->artisan('install:broadcasting', ['--pusher' => true, '--without-node' => true])
            ->expectsQuestion('Pusher App ID', 'my-app-id')
            ->expectsQuestion('Pusher App Key', 'my-key')
            ->expectsQuestion('Pusher App Secret', 'my-secret')
            ->expectsQuestion('Pusher App Cluster', 'eu')
            ->assertSuccessful();

        $envContent = file_get_contents($this->app->basePath('.env'));
        // Hyphens in values trigger quoting in Env::writeVariables.
        $this->assertStringContainsString('PUSHER_APP_ID="my-app-id"', $envContent);
        $this->assertStringContainsString('PUSHER_APP_KEY="my-key"', $envContent);
        $this->assertStringContainsString('PUSHER_APP_SECRET="my-secret"', $envContent);
        $this->assertStringContainsString('PUSHER_APP_CLUSTER=eu', $envContent);
    }

    public function testWritesAblyEnvVariables()
    {
        Process::fake();

        $this->createdFiles[] = $this->app->basePath('routes/channels.php');
        $this->createdFiles[] = $this->app->resourcePath('js/echo.js');

        $this->artisan('install:broadcasting', ['--ably' => true, '--without-node' => true])
            ->expectsQuestion('Ably Key', 'abc123:public456')
            ->assertSuccessful();

        $envContent = file_get_contents($this->app->basePath('.env'));
        // The colon in the key triggers quoting in Env::writeVariables.
        $this->assertStringContainsString('ABLY_KEY="abc123:public456"', $envContent);
        $this->assertStringContainsString('ABLY_PUBLIC_KEY=abc123', $envContent);
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
     * Get a skeleton-style bootstrap/app.php fixture with a commented-out channels line.
     */
    private function skeletonBootstrapFixtureWithCommentedChannels(): string
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
        // channels: __DIR__ . '/../routes/channels.php',
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
     * Get a minimal bootstrap fixture with withRouting but no commands: line.
     * Tests the fallback insertion after ->withRouting(.
     */
    private function skeletonBootstrapFixtureMinimal(): string
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
 * Testable BroadcastingInstallCommand that overrides requireComposerPackages to
 * record calls without actually running Composer.
 *
 * @internal
 */
class TestableBroadcastingInstallCommand extends BroadcastingInstallCommand
{
    /** @var list<array{composer: string, packages: array<int, string>}> */
    public array $composerRequireCalls = [];

    protected function requireComposerPackages(string $composer, array $packages): bool
    {
        $this->composerRequireCalls[] = ['composer' => $composer, 'packages' => $packages];

        return true;
    }
}
