<?php

declare(strict_types=1);

namespace Hypervel\Testbench;

use Hyperf\Context\ApplicationContext;
use Hyperf\Coordinator\Constants;
use Hyperf\Coordinator\CoordinatorManager;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Console\Kernel as ConsoleKernel;
use Hypervel\Foundation\Testing\Concerns\HandlesAttributes;
use Hypervel\Foundation\Testing\Concerns\InteractsWithTestCase;
use Hypervel\Foundation\Testing\TestCase as BaseTestCase;
use Hypervel\Queue\Queue;
use Swoole\Timer;
use Workbench\App\Exceptions\ExceptionHandler;

/**
 * Base test case for package testing with testbench features.
 *
 * @internal
 * @coversNothing
 */
class TestCase extends BaseTestCase
{
    use Concerns\CreatesApplication;
    use Concerns\HandlesDatabases;
    use Concerns\HandlesRoutes;
    use HandlesAttributes;
    use InteractsWithTestCase;

    protected static bool $hasBootstrappedTestbench = false;

    protected function setUp(): void
    {
        if (! static::$hasBootstrappedTestbench) {
            Bootstrapper::bootstrap();
            static::$hasBootstrappedTestbench = true;
        }

        $this->afterApplicationCreated(function () {
            Timer::clearAll();
            CoordinatorManager::until(Constants::WORKER_EXIT)->resume();

            // Setup routes after application is created (providers are booted)
            $this->setUpApplicationRoutes($this->app);
        });

        parent::setUp();

        // Execute BeforeEach attributes INSIDE coroutine context
        // (matches where setUpTraits runs in Foundation TestCase)
        $this->runInCoroutine(fn () => $this->setUpTheTestEnvironmentUsingTestCase());
    }

    /**
     * Define environment setup.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $this->registerPackageProviders($app);
        $this->registerPackageAliases($app);
    }

    protected function createApplication(): ApplicationContract
    {
        $app = new Application();
        $app->bind(KernelContract::class, ConsoleKernel::class);
        $app->bind(ExceptionHandlerContract::class, ExceptionHandler::class);

        ApplicationContext::setContainer($app);

        return $app;
    }

    protected function tearDown(): void
    {
        // Execute AfterEach attributes INSIDE coroutine context
        $this->runInCoroutine(fn () => $this->tearDownTheTestEnvironmentUsingTestCase());

        parent::tearDown();

        Queue::createPayloadUsing(null);
    }

    /**
     * Reload the application instance.
     */
    protected function reloadApplication(): void
    {
        $this->tearDown();
        $this->setUp();
    }

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        static::setUpBeforeClassUsingTestCase();
    }

    public static function tearDownAfterClass(): void
    {
        static::tearDownAfterClassUsingTestCase();
        parent::tearDownAfterClass();
    }
}
