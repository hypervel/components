<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Testing\Attributes\UnitTest;
use Hypervel\Foundation\Testing\Concerns\InteractsWithAuthentication;
use Hypervel\Foundation\Testing\Concerns\InteractsWithConsole;
use Hypervel\Foundation\Testing\Concerns\InteractsWithContainer;
use Hypervel\Foundation\Testing\Concerns\InteractsWithDatabase;
use Hypervel\Foundation\Testing\Concerns\InteractsWithDeprecationHandling;
use Hypervel\Foundation\Testing\Concerns\InteractsWithEnvironment;
use Hypervel\Foundation\Testing\Concerns\InteractsWithExceptionHandling;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRouteMiddleware;
use Hypervel\Foundation\Testing\Concerns\InteractsWithSession;
use Hypervel\Foundation\Testing\Concerns\InteractsWithTestCaseLifecycle;
use Hypervel\Foundation\Testing\Concerns\InteractsWithTime;
use Hypervel\Foundation\Testing\Concerns\InteractsWithViews;
use Hypervel\Foundation\Testing\Concerns\MakesHttpRequests;
use Hypervel\Foundation\Testing\Concerns\MocksApplicationServices;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use ReflectionMethod;
use Throwable;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    use InteractsWithContainer;
    use MakesHttpRequests;
    use InteractsWithAuthentication;
    use InteractsWithConsole;
    use InteractsWithDatabase;
    use InteractsWithDeprecationHandling;
    use InteractsWithEnvironment;
    use InteractsWithExceptionHandling;
    use InteractsWithRouteMiddleware;
    use InteractsWithSession;
    use InteractsWithTime;
    use InteractsWithTestCaseLifecycle;
    use InteractsWithViews;
    use MocksApplicationServices;
    use RunTestsInCoroutine;
    use WithFaker;

    /**
     * Memoized result of the withoutBootingFramework check.
     */
    protected ?bool $withoutBootingFramework = null;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        if ($this->withoutBootingFramework()) {
            return;
        }

        $this->setUpTheTestEnvironment();
    }

    /**
     * Refresh the application instance.
     */
    protected function refreshApplication(): void
    {
        $this->app = $this->createApplication();

        // Bootstrap the freshly-created app for the test context. Without this the
        // bootstrappers (RegisterFacades, RegisterProviders, BootProviders, …) never
        // run in tests — only on the HTTP/console boot path — so the facade root is
        // never bound and the very next facade call in setUp fails. HandleExceptions is
        // deliberately omitted so PHPUnit (not the app's Swoole error handler) reports failures.
        if (! $this->app->hasBeenBootstrapped()) {
            $this->app->bootstrapWith(array_values(array_diff(
                Application::DEFAULT_BOOTSTRAPPERS,
                [\Hypervel\Foundation\Bootstrap\HandleExceptions::class],
            )));
        }
    }

    /**
     * Create the application.
     */
    protected function createApplication(): ApplicationContract
    {
        return require Application::inferBasePath() . '/bootstrap/app.php';
    }

    /**
     * Clean up the testing environment before the next test.
     *
     * @throws Throwable
     */
    protected function tearDown(): void
    {
        if ($this->withoutBootingFramework()) {
            return;
        }

        $this->tearDownTheTestEnvironment();
    }

    /**
     * Determine if the test method should boot the framework.
     */
    protected function shouldBootFrameworkForTest(): bool
    {
        return ! $this->withoutBootingFramework();
    }

    /**
     * Determine if the test method should run without booting the framework.
     */
    protected function withoutBootingFramework(): bool
    {
        if ($this->withoutBootingFramework !== null) {
            return $this->withoutBootingFramework;
        }

        try {
            return $this->withoutBootingFramework = (new ReflectionMethod(static::class, $this->name()))->getAttributes(UnitTest::class) !== [];
        } catch (Throwable) {
            return $this->withoutBootingFramework = false;
        }
    }

    /**
     * Clean up the testing environment before the next test case.
     */
    public static function tearDownAfterClass(): void
    {
        static::tearDownAfterClassUsingTestCase();
    }
}
