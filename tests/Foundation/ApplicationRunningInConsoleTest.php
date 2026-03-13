<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Foundation\Application;
use Hypervel\Tests\Foundation\Concerns\HasMockedApplication;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ApplicationRunningInConsoleTest extends TestCase
{
    use HasMockedApplication;

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

    public function testDefaultsToTrueInCliProcess()
    {
        $app = $this->getApplication();

        $this->assertTrue($app->runningInConsole());
    }

    public function testDefaultRemainsTrueOnSubsequentCalls()
    {
        $app = $this->getApplication();

        $app->runningInConsole();
        $app->runningInConsole();

        $this->assertTrue($app->runningInConsole());
    }

    // ------------------------------------------------------------------
    // Artisan commands (migrate, queue:work, schedule:run, etc.)
    // ------------------------------------------------------------------

    public function testArtisanMigrateCommandRunsInConsole()
    {
        $_SERVER['argv'] = ['artisan', 'migrate'];
        $app = $this->getApplication();

        $this->assertTrue($app->runningInConsole());
    }

    public function testQueueWorkerRunsInConsole()
    {
        $_SERVER['argv'] = ['artisan', 'queue:work'];
        $app = $this->getApplication();

        $this->assertTrue($app->runningInConsole());
    }

    public function testSchedulerRunsInConsole()
    {
        $_SERVER['argv'] = ['artisan', 'schedule:run'];
        $app = $this->getApplication();

        $this->assertTrue($app->runningInConsole());
    }

    // ------------------------------------------------------------------
    // Tests / testbench
    // ------------------------------------------------------------------

    public function testTestRunnerRunsInConsole()
    {
        $_SERVER['argv'] = ['vendor/bin/phpunit'];
        $app = $this->getApplication();

        $this->assertTrue($app->runningInConsole());
    }

    // ------------------------------------------------------------------
    // Serve command: bootstrap vs server phases
    // ------------------------------------------------------------------

    public function testServeCommandBootstrapPhaseRunsInConsole()
    {
        $_SERVER['argv'] = ['artisan', 'serve'];
        $app = $this->getApplication();

        // During bootstrap (before server starts), runningInConsole is true.
        // Providers register commands, publishes, etc. under this value.
        $this->assertTrue($app->runningInConsole());
    }

    public function testServeCommandFlipsToFalseBeforeServerStarts()
    {
        $_SERVER['argv'] = ['artisan', 'serve'];
        $app = $this->getApplication();

        // Simulate what StartServer::execute() does before $serverFactory->start()
        $app->setRunningInConsole(false);

        $this->assertFalse($app->runningInConsole());
    }

    public function testHttpWorkersInheritFalseAfterServerFlip()
    {
        $app = $this->getApplication();

        // Simulate the serve command flipping the flag
        $app->setRunningInConsole(false);

        // Workers forked after this point inherit the Application state.
        // Any code checking runningInConsole() during request handling sees false.
        $this->assertFalse($app->runningInConsole());
    }

    // ------------------------------------------------------------------
    // setRunningInConsole
    // ------------------------------------------------------------------

    public function testSetRunningInConsoleToFalse()
    {
        $app = $this->getApplication();

        $app->setRunningInConsole(false);

        $this->assertFalse($app->runningInConsole());
    }

    public function testSetRunningInConsoleToTrue()
    {
        $app = $this->getApplication();

        $app->setRunningInConsole(true);

        $this->assertTrue($app->runningInConsole());
    }

    public function testSetRunningInConsoleOverridesCachedValue()
    {
        $app = $this->getApplication();

        // Cache the default value (true)
        $this->assertTrue($app->runningInConsole());

        // Override it
        $app->setRunningInConsole(false);

        $this->assertFalse($app->runningInConsole());
    }

    public function testSetRunningInConsoleCanBeFlippedMultipleTimes()
    {
        $app = $this->getApplication();

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

    public function testEnvVarOverridesToFalse()
    {
        putenv('APP_RUNNING_IN_CONSOLE=false');

        $app = $this->getApplication();

        $this->assertFalse($app->runningInConsole());
    }

    public function testEnvVarOverridesToTrue()
    {
        putenv('APP_RUNNING_IN_CONSOLE=true');

        $app = $this->getApplication();

        $this->assertTrue($app->runningInConsole());
    }

    public function testSetRunningInConsoleOverridesEnvVar()
    {
        putenv('APP_RUNNING_IN_CONSOLE=true');

        $app = $this->getApplication();

        // Env says true, but explicit set overrides
        $app->setRunningInConsole(false);

        $this->assertFalse($app->runningInConsole());
    }

    public function testSetRunningInConsolePreventsEnvVarFromBeingRead()
    {
        $app = $this->getApplication();

        // Set before first call, so env var is never consulted
        $app->setRunningInConsole(false);

        putenv('APP_RUNNING_IN_CONSOLE=true');

        $this->assertFalse($app->runningInConsole());
    }

    // ------------------------------------------------------------------
    // runningConsoleCommand
    // ------------------------------------------------------------------

    public function testRunningConsoleCommandMatchesSingleCommand()
    {
        $_SERVER['argv'] = ['artisan', 'migrate'];
        $app = $this->getApplication();

        $this->assertTrue($app->runningConsoleCommand('migrate'));
    }

    public function testRunningConsoleCommandMatchesOneOfMultiple()
    {
        $_SERVER['argv'] = ['artisan', 'migrate'];
        $app = $this->getApplication();

        $this->assertTrue($app->runningConsoleCommand('serve', 'migrate', 'queue:work'));
    }

    public function testRunningConsoleCommandDoesNotMatchWrongCommand()
    {
        $_SERVER['argv'] = ['artisan', 'migrate'];
        $app = $this->getApplication();

        $this->assertFalse($app->runningConsoleCommand('serve'));
    }

    public function testRunningConsoleCommandAcceptsArray()
    {
        $_SERVER['argv'] = ['artisan', 'queue:work'];
        $app = $this->getApplication();

        $this->assertTrue($app->runningConsoleCommand(['serve', 'queue:work']));
    }

    public function testRunningConsoleCommandReturnsFalseWithNoArguments()
    {
        $_SERVER['argv'] = ['artisan', 'migrate'];
        $app = $this->getApplication();

        $this->assertFalse($app->runningConsoleCommand());
    }

    public function testRunningConsoleCommandReturnsFalseWhenNotInConsole()
    {
        $_SERVER['argv'] = ['artisan', 'serve'];
        $app = $this->getApplication();

        $app->setRunningInConsole(false);

        // Even though argv says 'serve', we're not in console mode
        $this->assertFalse($app->runningConsoleCommand('serve'));
    }

    public function testRunningConsoleCommandReturnsFalseWhenNoArgvSet()
    {
        unset($_SERVER['argv']);
        $app = $this->getApplication();

        $this->assertFalse($app->runningConsoleCommand('migrate'));
    }

    // ------------------------------------------------------------------
    // detectEnvironment integration
    // ------------------------------------------------------------------

    public function testDetectEnvironmentUsesArgvWhenInConsole()
    {
        $_SERVER['argv'] = ['artisan', '--env=staging'];
        $app = $this->getApplication();

        // When in console, EnvironmentDetector parses --env from argv
        $result = $app->detectEnvironment(fn () => 'default');

        $this->assertSame('staging', $result);
    }

    public function testDetectEnvironmentIgnoresArgvWhenNotInConsole()
    {
        $_SERVER['argv'] = ['artisan', '--env=staging'];
        $app = $this->getApplication();

        $app->setRunningInConsole(false);

        // When not in console, argv is not passed — callback determines environment
        $result = $app->detectEnvironment(fn () => 'production');

        $this->assertSame('production', $result);
    }

    // ------------------------------------------------------------------
    // Full serve lifecycle simulation
    // ------------------------------------------------------------------

    public function testFullServeLifecycle()
    {
        $_SERVER['argv'] = ['artisan', 'serve'];
        $app = $this->getApplication();

        // Phase 1: Bootstrap — providers register/boot under console mode
        $this->assertTrue($app->runningInConsole());
        $this->assertTrue($app->runningConsoleCommand('serve'));

        // Phase 2: StartServer::execute() flips the flag before server starts
        $app->setRunningInConsole(false);

        // Phase 3: HTTP server is running — request handlers see non-console
        $this->assertFalse($app->runningInConsole());
        $this->assertFalse($app->runningConsoleCommand('serve'));
    }
}
