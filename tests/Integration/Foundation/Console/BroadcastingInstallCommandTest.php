<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Console\BroadcastingInstallCommand;
use Hypervel\Process\Exceptions\ProcessFailedException;
use Hypervel\Process\PendingProcess;
use Hypervel\Support\Facades\Process;
use Hypervel\Tests\Testing\Fixtures\CleanupActions;
use JsonException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Tester\CommandTester;

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

        TestableBroadcastingInstallCommand::$composerRequireCalls = [];

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
        $files = new Filesystem;
        $actions = [fn () => $files->replace(
            $this->app->bootstrapPath('app.php'),
            $this->originalBootstrapContent,
        )];

        $envPath = $this->app->basePath('.env');

        if ($this->originalEnvContent === null) {
            $actions[] = static function () use ($envPath, $files): void {
                if ($files->isFile($envPath) && ! $files->delete($envPath)) {
                    throw new RuntimeException("Unable to delete the owned broadcasting test environment file [{$envPath}].");
                }
            };
        } else {
            $actions[] = fn () => $files->replace($envPath, $this->originalEnvContent);
        }

        $broadcastingConfig = $this->app->configPath('broadcasting.php');

        $actions[] = static function () use ($broadcastingConfig, $files): void {
            if ($files->isFile($broadcastingConfig) && ! $files->delete($broadcastingConfig)) {
                throw new RuntimeException("Unable to delete the owned broadcasting config file [{$broadcastingConfig}].");
            }
        };

        foreach ($this->createdFiles as $file) {
            $actions[] = static function () use ($file, $files): void {
                if ($files->isFile($file) && ! $files->delete($file)) {
                    throw new RuntimeException("Unable to delete owned broadcasting install test file [{$file}].");
                }
            };
        }

        $actions[] = fn () => parent::tearDown();

        CleanupActions::run(...$actions);
    }

    public function testCreatesChannelsRouteFile(): void
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

    public function testSkipsChannelsRouteFileWithoutForce(): void
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

    public function testOverwritesChannelsRouteFileWithForce(): void
    {
        Process::fake();

        $channelsPath = $this->app->basePath('routes/channels.php');
        $this->createdFiles[] = $channelsPath;

        file_put_contents($channelsPath, '<?php // existing');
        chmod($channelsPath, 0640);

        $this->artisan('install:broadcasting', ['--reverb' => true, '--without-reverb' => true, '--without-node' => true, '--force' => true])
            ->expectsOutputToContain("Published 'channels' route file.")
            ->assertSuccessful();

        $contents = file_get_contents($channelsPath);
        $this->assertStringContainsString('Hypervel\Support\Facades\Broadcast', $contents);
        $this->assertSame(0640, fileperms($channelsPath) & 0777);
    }

    public function testInsertsChannelsLineInBootstrapFile(): void
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

    public function testUncommentsChannelsLineWhenCommentedOut(): void
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

    public function testFallsBackToWithRoutingInsertion(): void
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

    public function testWritesBroadcastConnectionEnv(): void
    {
        Process::fake();

        $this->createdFiles[] = $this->app->basePath('routes/channels.php');

        $this->artisan('install:broadcasting', ['--reverb' => true, '--without-reverb' => true, '--without-node' => true])
            ->assertSuccessful();

        $envContent = file_get_contents($this->app->basePath('.env'));
        $this->assertStringContainsString('BROADCAST_CONNECTION=reverb', $envContent);
    }

    public function testCreatesEchoJsFromReverbStub(): void
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

    public function testCreatesEchoJsFromPusherStub(): void
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

    public function testCreatesEchoJsFromAblyStub(): void
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

    public function testAppendsEchoImportToAppJs(): void
    {
        Process::fake();

        $this->createdFiles[] = $this->app->basePath('routes/channels.php');
        $this->createdFiles[] = $this->app->resourcePath('js/echo.js');

        $this->artisan('install:broadcasting', ['--reverb' => true, '--without-reverb' => true, '--without-node' => true])
            ->assertSuccessful();

        $appJsContent = file_get_contents($this->app->resourcePath('js/app.js'));
        $this->assertStringContainsString("import './echo'", $appJsContent);
    }

    public function testDoesNotDuplicateEchoImport(): void
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

    public function testVueAppsUseStandardEchoConfiguration(): void
    {
        Process::fake();

        $this->createdFiles[] = $this->app->basePath('routes/channels.php');
        $this->createdFiles[] = $this->app->resourcePath('js/echo.js');

        // Create a package.json that declares Vue as a dependency.
        $packageJsonPath = $this->app->basePath('package.json');
        file_put_contents($packageJsonPath, json_encode(['dependencies' => ['vue' => '^3.0']]));
        $this->createdFiles[] = $packageJsonPath;

        $this->artisan('install:broadcasting', ['--reverb' => true, '--without-reverb' => true, '--without-node' => true])
            ->assertSuccessful();

        $appJsContent = file_get_contents($this->app->resourcePath('js/app.js'));
        $this->assertStringContainsString("import './echo'", $appJsContent);
        $this->assertStringNotContainsString('@laravel/echo-vue', $appJsContent);

        $this->assertFileExists($this->app->resourcePath('js/echo.js'));
    }

    public function testInjectsEchoConfigIntoReactAppJsx(): void
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

    public function testInstallsReverbPackageAndRunsInstall(): void
    {
        Process::fake();

        $this->createdFiles[] = $this->app->basePath('routes/channels.php');
        $this->createdFiles[] = $this->app->resourcePath('js/echo.js');

        // --reverb WITHOUT --without-reverb: triggers the Reverb install prompt.
        $this->artisan('install:broadcasting', ['--reverb' => true, '--without-node' => true])
            ->expectsConfirmation('Would you like to install Hypervel Reverb?', 'yes')
            ->assertSuccessful();

        // Verify composer require was called for reverb.
        $reverbCalls = array_filter(TestableBroadcastingInstallCommand::$composerRequireCalls, function (array $call): bool {
            return in_array('hypervel/reverb:^0.4', $call['packages'], true);
        });
        $this->assertCount(1, $reverbCalls);

        // Verify reverb:install was run via the Process facade.
        Process::assertRan(function (PendingProcess $process): bool {
            $command = implode(' ', (array) $process->command);

            return str_contains($command, 'reverb:install');
        });
    }

    public function testSkipsNodeDepsWithFlag(): void
    {
        Process::fake();

        $this->createdFiles[] = $this->app->basePath('routes/channels.php');
        $this->createdFiles[] = $this->app->resourcePath('js/echo.js');

        // --without-node should not prompt. If it does, the test fails with "unexpected question".
        $this->artisan('install:broadcasting', ['--reverb' => true, '--without-reverb' => true, '--without-node' => true])
            ->assertSuccessful();
    }

    public function testWritesPusherEnvVariables(): void
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

    public function testWritesAblyEnvVariables(): void
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

    public function testConfigPublishFailureStopsInstallationBeforeWritingRoutes(): void
    {
        Process::fake();

        $channelsPath = $this->app->basePath('routes/channels.php');
        $this->createdFiles[] = $channelsPath;
        $tester = $this->commandTester(new FailingConfigBroadcastingInstallCommand);

        try {
            $tester->execute(['--reverb' => true, '--without-reverb' => true, '--without-node' => true]);
            $this->fail('Expected broadcasting configuration publication to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to publish the broadcasting configuration file.', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($channelsPath);
        $this->assertSame([], TestableBroadcastingInstallCommand::$composerRequireCalls);
        $this->assertStringNotContainsString("Published 'channels' route file.", $tester->getDisplay());
    }

    public function testChannelsSourceReadFailurePreservesExistingRouteAndReportsNoSuccess(): void
    {
        Process::fake();

        $channelsPath = $this->app->basePath('routes/channels.php');
        $this->createdFiles[] = $channelsPath;
        file_put_contents($channelsPath, 'existing route');
        $source = dirname((new ReflectionClass(BroadcastingInstallCommand::class))->getFileName())
            . '/stubs/broadcasting-routes.stub';
        $files = m::mock(Filesystem::class)->makePartial();
        $readException = new FileNotFoundException("File does not exist at path [{$source}].");
        $files->shouldReceive('get')->once()->with($source)->andThrow($readException);
        $this->app->instance('files', $files);
        $tester = $this->commandTester(new TestableBroadcastingInstallCommand);

        try {
            $tester->execute([
                '--reverb' => true,
                '--without-reverb' => true,
                '--without-node' => true,
                '--force' => true,
            ]);
            $this->fail('Expected broadcasting route stub reading to fail.');
        } catch (FileNotFoundException $exception) {
            $this->assertSame($readException, $exception);
        }

        $this->assertSame('existing route', file_get_contents($channelsPath));
        $this->assertStringNotContainsString("Published 'channels' route file.", $tester->getDisplay());
    }

    public function testChannelsReplacementFailurePreservesExistingRouteAndReportsNoSuccess(): void
    {
        Process::fake();

        $channelsPath = $this->app->basePath('routes/channels.php');
        $this->createdFiles[] = $channelsPath;
        file_put_contents($channelsPath, 'existing route');
        chmod($channelsPath, 0640);
        $files = m::mock(Filesystem::class)->makePartial();
        $publicationException = new RuntimeException('Unable to publish broadcasting route file.');
        $files->shouldReceive('replace')->byDefault()->passthru();
        $files->shouldReceive('replace')
            ->once()
            ->with($channelsPath, m::type('string'), 0640)
            ->andThrow($publicationException);
        $this->app->instance('files', $files);
        $tester = $this->commandTester(new TestableBroadcastingInstallCommand);

        try {
            $tester->execute([
                '--reverb' => true,
                '--without-reverb' => true,
                '--without-node' => true,
                '--force' => true,
            ]);
            $this->fail('Expected broadcasting route publication to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($publicationException, $exception);
        }

        $this->assertSame('existing route', file_get_contents($channelsPath));
        $this->assertSame(0640, fileperms($channelsPath) & 0777);
        $this->assertStringNotContainsString("Published 'channels' route file.", $tester->getDisplay());
    }

    public function testBootstrapReadFailureStopsBeforeEnvironmentAndJavaScriptChanges(): void
    {
        Process::fake();

        $channelsPath = $this->app->basePath('routes/channels.php');
        $bootstrapPath = $this->app->bootstrapPath('app.php');
        $appJsPath = $this->app->resourcePath('js/app.js');
        $envPath = $this->app->basePath('.env');
        $originalAppJs = file_get_contents($appJsPath);
        $originalEnv = file_get_contents($envPath);
        $this->createdFiles[] = $channelsPath;
        $files = m::mock(Filesystem::class)->makePartial();
        $readException = new FileNotFoundException("File does not exist at path [{$bootstrapPath}].");
        $files->shouldReceive('get')->once()->with($bootstrapPath)->andThrow($readException);
        $this->app->instance('files', $files);
        $tester = $this->commandTester(new TestableBroadcastingInstallCommand);

        try {
            $tester->execute(['--reverb' => true, '--without-reverb' => true, '--without-node' => true]);
            $this->fail('Expected bootstrap file reading to fail.');
        } catch (FileNotFoundException $exception) {
            $this->assertSame($readException, $exception);
        }

        $this->assertFileExists($channelsPath);
        $this->assertSame($originalAppJs, file_get_contents($appJsPath));
        $this->assertSame($originalEnv, file_get_contents($envPath));
    }

    public function testBootstrapReplacementFailurePreservesTheExistingApplicationBootstrap(): void
    {
        Process::fake();

        $channelsPath = $this->app->basePath('routes/channels.php');
        $bootstrapPath = $this->app->bootstrapPath('app.php');
        $appJsPath = $this->app->resourcePath('js/app.js');
        $envPath = $this->app->basePath('.env');
        $bootstrapContent = file_get_contents($bootstrapPath);
        $bootstrapPermissions = fileperms($bootstrapPath) & 0777;
        $appJsContent = file_get_contents($appJsPath);
        $envContent = file_get_contents($envPath);
        $this->createdFiles[] = $channelsPath;
        $files = m::mock(Filesystem::class)->makePartial();
        $publicationException = new RuntimeException('Unable to update the application bootstrap file.');
        $files->shouldReceive('replace')->byDefault()->passthru();
        $files->shouldReceive('replace')
            ->once()
            ->with($bootstrapPath, m::type('string'), $bootstrapPermissions)
            ->andThrow($publicationException);
        $this->app->instance('files', $files);
        $tester = $this->commandTester(new TestableBroadcastingInstallCommand);

        try {
            $tester->execute(['--reverb' => true, '--without-reverb' => true, '--without-node' => true]);
            $this->fail('Expected application bootstrap publication to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($publicationException, $exception);
        }

        $this->assertSame($bootstrapContent, file_get_contents($bootstrapPath));
        $this->assertSame($bootstrapPermissions, fileperms($bootstrapPath) & 0777);
        $this->assertFileExists($channelsPath);
        $this->assertStringContainsString("Published 'channels' route file.", $tester->getDisplay());
        $this->assertSame($appJsContent, file_get_contents($appJsPath));
        $this->assertSame($envContent, file_get_contents($envPath));
        $this->assertSame([], TestableBroadcastingInstallCommand::$composerRequireCalls);
        Process::assertNothingRan();
    }

    public function testEchoStubReadFailureDoesNotCreateEchoScript(): void
    {
        Process::fake();

        $channelsPath = $this->app->basePath('routes/channels.php');
        $echoPath = $this->app->resourcePath('js/echo.js');
        $source = dirname((new ReflectionClass(BroadcastingInstallCommand::class))->getFileName())
            . '/stubs/echo-js-reverb.stub';
        $this->createdFiles[] = $channelsPath;
        $this->createdFiles[] = $echoPath;
        $files = m::mock(Filesystem::class)->makePartial();
        $readException = new FileNotFoundException("File does not exist at path [{$source}].");
        $files->shouldReceive('get')->once()->with($source)->andThrow($readException);
        $this->app->instance('files', $files);
        $tester = $this->commandTester(new TestableBroadcastingInstallCommand);

        try {
            $tester->execute(['--reverb' => true, '--without-reverb' => true, '--without-node' => true]);
            $this->fail('Expected Echo stub reading to fail.');
        } catch (FileNotFoundException $exception) {
            $this->assertSame($readException, $exception);
        }

        $this->assertFileDoesNotExist($echoPath);
    }

    public function testAppScriptReplacementFailurePreservesExistingScript(): void
    {
        Process::fake();

        $channelsPath = $this->app->basePath('routes/channels.php');
        $echoPath = $this->app->resourcePath('js/echo.js');
        $appJsPath = $this->app->resourcePath('js/app.js');
        $this->createdFiles[] = $channelsPath;
        $this->createdFiles[] = $echoPath;
        file_put_contents($appJsPath, '// existing app script' . PHP_EOL);
        chmod($appJsPath, 0640);
        $files = m::mock(Filesystem::class)->makePartial();
        $publicationException = new RuntimeException('Unable to update application script.');
        $files->shouldReceive('replace')->byDefault()->passthru();
        $files->shouldReceive('replace')
            ->once()
            ->with($appJsPath, m::type('string'), 0640)
            ->andThrow($publicationException);
        $this->app->instance('files', $files);
        $tester = $this->commandTester(new TestableBroadcastingInstallCommand);

        try {
            $tester->execute(['--reverb' => true, '--without-reverb' => true, '--without-node' => true]);
            $this->fail('Expected application script publication to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($publicationException, $exception);
        }

        $this->assertSame('// existing app script' . PHP_EOL, file_get_contents($appJsPath));
        $this->assertSame(0640, fileperms($appJsPath) & 0777);
    }

    public function testMalformedPackageJsonFailsExplicitly(): void
    {
        Process::fake();

        $channelsPath = $this->app->basePath('routes/channels.php');
        $echoPath = $this->app->resourcePath('js/echo.js');
        $packageJsonPath = $this->app->basePath('package.json');
        $this->createdFiles[] = $channelsPath;
        $this->createdFiles[] = $echoPath;
        $this->createdFiles[] = $packageJsonPath;
        file_put_contents($packageJsonPath, '{invalid json');
        $tester = $this->commandTester(new TestableBroadcastingInstallCommand);

        try {
            $tester->execute(['--reverb' => true, '--without-reverb' => true, '--without-node' => true]);
            $this->fail('Expected malformed package JSON to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame("Unable to parse package file [{$packageJsonPath}].", $exception->getMessage());
            $this->assertInstanceOf(JsonException::class, $exception->getPrevious());
        }

        $this->assertFileDoesNotExist($echoPath);
    }

    public function testFailedReverbInstallationDoesNotReportSuccessOrInstallNodeDependencies(): void
    {
        Process::fake(static fn () => Process::result(exitCode: 1));

        $channelsPath = $this->app->basePath('routes/channels.php');
        $echoPath = $this->app->resourcePath('js/echo.js');
        $this->createdFiles[] = $channelsPath;
        $this->createdFiles[] = $echoPath;
        $tester = $this->commandTester(new TestableBroadcastingInstallCommand);
        $tester->setInputs(['yes']);

        try {
            $tester->execute(['--reverb' => true, '--without-node' => true]);
            $this->fail('Expected Reverb installation to fail.');
        } catch (ProcessFailedException) {
        }

        $this->assertStringNotContainsString('Reverb installed successfully.', $tester->getDisplay());
    }

    #[DataProvider('packageManagerCommands')]
    public function testNodePackageManagersUseSafeInstallFlags(
        ?string $lockFile,
        string $expectedInstallCommand,
        bool $expectsIgnoreScripts,
    ): void {
        Process::fake();

        $channelsPath = $this->app->basePath('routes/channels.php');
        $echoPath = $this->app->resourcePath('js/echo.js');
        $this->createdFiles[] = $channelsPath;
        $this->createdFiles[] = $echoPath;

        if ($lockFile !== null) {
            $lockPath = $this->app->basePath($lockFile);
            file_put_contents($lockPath, '');
            $this->createdFiles[] = $lockPath;
        }

        $this->artisan('install:broadcasting', ['--reverb' => true, '--without-reverb' => true])
            ->expectsConfirmation('Would you like to install and build the Node dependencies required for broadcasting?', 'yes')
            ->assertSuccessful();

        Process::assertRan(function (PendingProcess $process) use ($expectedInstallCommand, $expectsIgnoreScripts): bool {
            $command = (string) $process->command;

            return str_contains($command, $expectedInstallCommand)
                && str_contains($command, '--ignore-scripts') === $expectsIgnoreScripts;
        });
    }

    public static function packageManagerCommands(): iterable
    {
        yield 'pnpm' => [
            'pnpm-lock.yaml',
            'pnpm add --save-dev --ignore-scripts laravel-echo pusher-js',
            true,
        ];

        yield 'yarn' => [
            'yarn.lock',
            'yarn add --dev --ignore-scripts laravel-echo pusher-js',
            true,
        ];

        yield 'bun' => [
            'bun.lock',
            'bun add --dev laravel-echo pusher-js',
            false,
        ];

        yield 'npm' => [
            null,
            'npm install --save-dev --ignore-scripts laravel-echo pusher-js',
            true,
        ];
    }

    public function testNodeFailurePrintsCompleteManualRecoveryCommands(): void
    {
        Process::fake(static fn () => Process::result(exitCode: 1));

        $channelsPath = $this->app->basePath('routes/channels.php');
        $echoPath = $this->app->resourcePath('js/echo.js');
        $this->createdFiles[] = $channelsPath;
        $this->createdFiles[] = $echoPath;

        $this->artisan('install:broadcasting', ['--reverb' => true, '--without-reverb' => true])
            ->expectsConfirmation('Would you like to install and build the Node dependencies required for broadcasting?', 'yes')
            ->expectsOutputToContain('npm install --save-dev --ignore-scripts laravel-echo pusher-js && npm run build')
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

    /**
     * Create a tester for a broadcasting installer command.
     */
    private function commandTester(BroadcastingInstallCommand $command): CommandTester
    {
        $command->setHypervel($this->app);
        $application = new ConsoleApplication;
        $application->addCommand($command);

        return new CommandTester($command);
    }
}

/**
 * Testable BroadcastingInstallCommand that overrides requireComposerPackages to
 * record calls without actually running Composer.
 */
class TestableBroadcastingInstallCommand extends BroadcastingInstallCommand
{
    /** @var list<array{composer: string, packages: array<int, string>}> */
    public static array $composerRequireCalls = [];

    public function call(SymfonyCommand|string $command, array $arguments = []): int
    {
        return self::SUCCESS;
    }

    protected function requireComposerPackages(string $composer, array $packages): void
    {
        static::$composerRequireCalls[] = ['composer' => $composer, 'packages' => $packages];
    }
}

class FailingConfigBroadcastingInstallCommand extends TestableBroadcastingInstallCommand
{
    public function call(SymfonyCommand|string $command, array $arguments = []): int
    {
        return self::FAILURE;
    }
}
