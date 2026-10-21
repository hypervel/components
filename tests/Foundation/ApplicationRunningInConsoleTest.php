<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Foundation\Application;
use Hypervel\Tests\TestCase;

class ApplicationRunningInConsoleTest extends TestCase
{
    private ?array $originalArgv = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalArgv = $_SERVER['argv'] ?? null;
    }

    protected function tearDown(): void
    {
        // Restore original argv
        if ($this->originalArgv !== null) {
            $_SERVER['argv'] = $this->originalArgv;
        } else {
            unset($_SERVER['argv']);
        }

        // Clear env var if set
        putenv('APP_RUNNING_IN_CONSOLE');

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Default behavior (CLI process, no explicit set)
    // ------------------------------------------------------------------

    public function testDefaultsToTrueInCliProcess(): void
    {
        $app = new Application;

        $this->assertTrue($app->runningInConsole());
    }

    public function testDefaultRemainsTrueOnSubsequentCalls(): void
    {
        $app = new Application;

        $app->runningInConsole();
        $app->runningInConsole();

        $this->assertTrue($app->runningInConsole());
    }

    // ------------------------------------------------------------------
    // Artisan commands (migrate, queue:work, schedule:run, etc.)
    // ------------------------------------------------------------------

    public function testArtisanMigrateCommandRunsInConsole(): void
    {
        $_SERVER['argv'] = ['artisan', 'migrate'];
        $app = new Application;

        $this->assertTrue($app->runningInConsole());
    }

    public function testQueueWorkerRunsInConsole(): void
    {
        $_SERVER['argv'] = ['artisan', 'queue:work'];
        $app = new Application;

        $this->assertTrue($app->runningInConsole());
    }

    public function testSchedulerRunsInConsole(): void
    {
        $_SERVER['argv'] = ['artisan', 'schedule:run'];
        $app = new Application;

        $this->assertTrue($app->runningInConsole());
    }

    // ------------------------------------------------------------------
    // Tests / testbench
    // ------------------------------------------------------------------

    public function testTestRunnerRunsInConsole(): void
    {
        $_SERVER['argv'] = ['vendor/bin/phpunit'];
        $app = new Application;

        $this->assertTrue($app->runningInConsole());
    }

    // ------------------------------------------------------------------
    // Serve / watch commands use env-driven HTTP semantics during bootstrap
    // ------------------------------------------------------------------

    public function testServeCommandCanBootstrapWithHttpSemanticsViaEnvVar(): void
    {
        $_SERVER['argv'] = ['artisan', 'serve'];
        putenv('APP_RUNNING_IN_CONSOLE=false');

        $app = new Application;

        $this->assertFalse($app->runningInConsole());
        $this->assertFalse($app->runningConsoleCommand('serve'));
    }

    public function testWatchCommandCanBootstrapWithHttpSemanticsViaEnvVar(): void
    {
        $_SERVER['argv'] = ['artisan', 'watch'];
        putenv('APP_RUNNING_IN_CONSOLE=false');

        $app = new Application;

        $this->assertFalse($app->runningInConsole());
        $this->assertFalse($app->runningConsoleCommand('watch'));
    }

    // ------------------------------------------------------------------
    // setRunningInConsole
    // ------------------------------------------------------------------

    public function testSetRunningInConsoleToFalse(): void
    {
        $app = new Application;

        $app->setRunningInConsole(false);

        $this->assertFalse($app->runningInConsole());
    }

    public function testSetRunningInConsoleToTrue(): void
    {
        $app = new Application;

        $app->setRunningInConsole(true);

        $this->assertTrue($app->runningInConsole());
    }

    public function testSetRunningInConsoleOverridesCachedValue(): void
    {
        $app = new Application;

        // Cache the default value (true)
        $this->assertTrue($app->runningInConsole());

        // Override it
        $app->setRunningInConsole(false);

        $this->assertFalse($app->runningInConsole());
    }

    public function testSetRunningInConsoleCanBeFlippedMultipleTimes(): void
    {
        $app = new Application;

        $app->setRunningInConsole(false);
        $this->assertFalse($app->runningInConsole());

        $app->setRunningInConsole(true);
        $this->assertTrue($app->runningInConsole());

        $app->setRunningInConsole(false);
        $this->assertFalse($app->runningInConsole());
    }

    // ------------------------------------------------------------------
    // APP_RUNNING_IN_CONSOLE env var
    // ------------------------------------------------------------------

    public function testEnvVarOverridesToFalse(): void
    {
        putenv('APP_RUNNING_IN_CONSOLE=false');

        $app = new Application;

        $this->assertFalse($app->runningInConsole());
    }

    public function testEnvVarOverridesToTrue(): void
    {
        putenv('APP_RUNNING_IN_CONSOLE=true');

        $app = new Application;

        $this->assertTrue($app->runningInConsole());
    }

    public function testSetRunningInConsoleOverridesEnvVar(): void
    {
        putenv('APP_RUNNING_IN_CONSOLE=true');

        $app = new Application;

        // Env says true, but explicit set overrides
        $app->setRunningInConsole(false);

        $this->assertFalse($app->runningInConsole());
    }

    public function testSetRunningInConsolePreventsEnvVarFromBeingRead(): void
    {
        $app = new Application;

        // Set before first call, so env var is never consulted
        $app->setRunningInConsole(false);

        putenv('APP_RUNNING_IN_CONSOLE=true');

        $this->assertFalse($app->runningInConsole());
    }

    // ------------------------------------------------------------------
    // runningConsoleCommand
    // ------------------------------------------------------------------

    public function testRunningConsoleCommandMatchesSingleCommand(): void
    {
        $_SERVER['argv'] = ['artisan', 'migrate'];
        $app = new Application;

        $this->assertTrue($app->runningConsoleCommand('migrate'));
    }

    public function testRunningConsoleCommandMatchesCommandAfterLongOption(): void
    {
        $_SERVER['argv'] = ['artisan', '--env=production', 'migrate'];
        $app = new Application;

        $this->assertTrue($app->runningConsoleCommand('migrate'));
        $this->assertFalse($app->runningConsoleCommand('--env=production'));
    }

    public function testRunningConsoleCommandMatchesCommandAfterSeparatedLongOptionValue(): void
    {
        $_SERVER['argv'] = ['artisan', '--env', 'production', 'migrate'];
        $app = new Application;

        $this->assertTrue($app->runningConsoleCommand('migrate'));
        $this->assertFalse($app->runningConsoleCommand('--env', 'production'));
    }

    public function testRunningConsoleCommandMatchesCommandAfterShortOption(): void
    {
        $_SERVER['argv'] = ['artisan', '-v', 'queue:work'];
        $app = new Application;

        $this->assertTrue($app->runningConsoleCommand('queue:work'));
        $this->assertFalse($app->runningConsoleCommand('-v'));
    }

    public function testRunningConsoleCommandMatchesOneOfMultiple(): void
    {
        $_SERVER['argv'] = ['artisan', 'migrate'];
        $app = new Application;

        $this->assertTrue($app->runningConsoleCommand('serve', 'migrate', 'queue:work'));
    }

    public function testRunningConsoleCommandDoesNotMatchWrongCommand(): void
    {
        $_SERVER['argv'] = ['artisan', 'migrate'];
        $app = new Application;

        $this->assertFalse($app->runningConsoleCommand('serve'));
    }

    public function testRunningConsoleCommandAcceptsArray(): void
    {
        $_SERVER['argv'] = ['artisan', 'queue:work'];
        $app = new Application;

        $this->assertTrue($app->runningConsoleCommand(['serve', 'queue:work']));
    }

    public function testRunningConsoleCommandReturnsFalseWithNoArguments(): void
    {
        $_SERVER['argv'] = ['artisan', 'migrate'];
        $app = new Application;

        $this->assertFalse($app->runningConsoleCommand());
    }

    public function testRunningConsoleCommandReturnsFalseWhenNotInConsole(): void
    {
        $_SERVER['argv'] = ['artisan', 'serve'];
        $app = new Application;

        $app->setRunningInConsole(false);

        // Even though argv says 'serve', we're not in console mode
        $this->assertFalse($app->runningConsoleCommand('serve'));
    }

    public function testRunningConsoleCommandReturnsFalseWhenNoArgvSet(): void
    {
        unset($_SERVER['argv']);
        $app = new Application;

        $this->assertFalse($app->runningConsoleCommand('migrate'));
    }

    // ------------------------------------------------------------------
    // detectEnvironment integration
    // ------------------------------------------------------------------

    public function testDetectEnvironmentUsesArgvWhenInConsole(): void
    {
        $_SERVER['argv'] = ['artisan', '--env=staging'];
        $app = new Application;

        // When in console, EnvironmentDetector parses --env from argv
        $result = $app->detectEnvironment(fn () => 'default');

        $this->assertSame('staging', $result);
        $this->assertSame('staging', $app->environment());
    }

    public function testDetectEnvironmentIgnoresArgvWhenNotInConsole(): void
    {
        $_SERVER['argv'] = ['artisan', '--env=staging'];
        $app = new Application;

        $app->setRunningInConsole(false);

        // When not in console, argv is not passed — callback determines environment
        $result = $app->detectEnvironment(fn () => 'production');

        $this->assertSame('production', $result);
    }

    public function testDetectEnvironmentIgnoresArgvWhenHttpSemanticsAreSetViaEnvVar(): void
    {
        $_SERVER['argv'] = ['artisan', '--env=staging'];
        putenv('APP_RUNNING_IN_CONSOLE=false');

        $app = new Application;

        $result = $app->detectEnvironment(fn () => 'production');

        $this->assertSame('production', $result);
    }
}
