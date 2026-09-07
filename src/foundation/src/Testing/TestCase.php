<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing;

use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Bootstrap\BootProviders;
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
use Hypervel\Testing\Concerns\InteractsWithMockery;
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
    use InteractsWithMockery;
    use WithFaker;

    /**
     * The traits used by the test case, including inherited traits.
     *
     * @var array<string, string>
     */
    protected array $traitsUsedByTest;

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
    }

    /**
     * Create the application.
     */
    protected function createApplication(): ApplicationContract
    {
        // Bootstrap hooks intentionally live on the concrete application, matching Laravel.
        /** @var Application $app */
        $app = require Application::inferBasePath() . '/bootstrap/app.php';

        $this->prepareApplicationForCachedState($app);

        // Install after proxy generation so resolving db cannot preload an aspect
        // target, but before provider boot. Rebinding after a boot query strands the
        // production resolver's pooled wrapper and leaves cached connections outside
        // test transactions. The dispatcher remains stable through this hook;
        // Testbench installs the same binding on its independent bootstrap path.
        $app->beforeBootstrapping(BootProviders::class, static function (ApplicationContract $app): void {
            $app->singleton('db.resolver', DatabaseConnectionResolver::class);
            Model::setConnectionResolver($app->make('db'));
        });

        $app->make(KernelContract::class)->bootstrap();

        return $app;
    }

    /**
     * Prepare the application to reuse cached test state.
     */
    protected function prepareApplicationForCachedState(ApplicationContract $app): void
    {
        $this->traitsUsedByTest ??= class_uses_recursive(static::class);

        if (isset(CachedState::$cachedConfig, $this->traitsUsedByTest[WithCachedConfig::class])) {
            $this->markConfigCached($app); // @phpstan-ignore method.notFound
        }

        if (isset(CachedState::$cachedRoutes, $this->traitsUsedByTest[WithCachedRoutes::class])) {
            $this->markRoutesCached($app); // @phpstan-ignore method.notFound
        }
    }

    /**
     * Clean up the testing environment before the next test.
     *
     * @throws Throwable
     */
    protected function tearDown(): void
    {
        $exception = null;

        try {
            if (! $this->withoutBootingFramework()) {
                $this->tearDownTheTestEnvironment();
            }
        } catch (Throwable $throwable) {
            $exception = $throwable;
        }

        try {
            $this->tearDownTheTestEnvironmentUsingMockery();
        } catch (Throwable $throwable) {
            $exception ??= $throwable;
        }

        if ($exception !== null) {
            throw $exception;
        }
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
