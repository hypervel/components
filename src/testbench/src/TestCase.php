<?php

declare(strict_types=1);

namespace Hypervel\Testbench;

use Hypervel\Context\ApplicationContext;
use Hypervel\Contracts\Console\Kernel as KernelContract;
use Hypervel\Contracts\Debug\ExceptionHandler as ExceptionHandlerContract;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Console\Kernel as ConsoleKernel;
use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Foundation\Testing\DatabaseTransactions;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Foundation\Testing\TestCase as BaseTestCase;
use Hypervel\Foundation\Testing\WithoutEvents;
use Hypervel\Foundation\Testing\WithoutMiddleware;
use Hypervel\Queue\Queue;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\Concerns\HandlesAttributes;
use Hypervel\Testbench\Concerns\InteractsWithTestCase;
use Hypervel\Testbench\Contracts\Attributes\Actionable;
use Swoole\Timer;
use Workbench\App\Exceptions\ExceptionHandler;

/**
 * Base test case for package testing with testbench features.
 *
 * Methods below are provided by traits that child test classes may use.
 * The setUpTraits() method checks for trait usage before calling these.
 *
 * @method void refreshDatabase()
 * @method void runDatabaseMigrations()
 * @method void beginDatabaseTransaction()
 * @method void disableMiddlewareForAllTests()
 * @method void disableEventsForAllTests()
 *
 * @internal
 * @coversNothing
 */
class TestCase extends BaseTestCase
{
    use Concerns\CreatesApplication;
    use Concerns\HandlesAssertions;
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
     *
     * DefineEnvironment attributes are processed here (before providers boot)
     * so they can set database config and other settings that must be configured
     * before connections are established. In Swoole, once connections are pooled,
     * you cannot change the underlying config.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        // Process DefineEnvironment attributes at the same time as the method override
        // This must happen BEFORE providers boot so database config can be set
        $this->resolvePhpUnitAttributes()
            ->filter(static fn ($attrs, string $key) => $key === DefineEnvironment::class)
            ->flatten()
            ->filter(static fn ($instance) => $instance instanceof Actionable)
            ->each(fn ($instance) => $instance->handle(
                $app,
                fn ($method, $parameters) => $this->{$method}(...$parameters)
            ));

        $this->registerPackageProviders($app);
        $this->registerPackageAliases($app);
    }

    /**
     * Boot the testing helper traits.
     *
     * Overrides Foundation's setUpTraits to wrap database operations
     * in setUpDatabaseRequirements(), ensuring WithMigration attributes
     * are processed before migrations run.
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits(): array
    {
        $uses = array_flip(class_uses_recursive(static::class));

        // Wrap database-related trait setup in setUpDatabaseRequirements
        // so WithMigration attributes are processed BEFORE migrations run
        $this->setUpDatabaseRequirements(function () use ($uses): void {
            if (isset($uses[RefreshDatabase::class])) {
                $this->refreshDatabase();
            }

            if (isset($uses[DatabaseMigrations::class])) {
                $this->runDatabaseMigrations();
            }
        });

        if (isset($uses[DatabaseTransactions::class])) {
            $this->beginDatabaseTransaction();
        }

        if (isset($uses[WithoutMiddleware::class])) {
            $this->disableMiddlewareForAllTests();
        }

        if (isset($uses[WithoutEvents::class])) {
            $this->disableEventsForAllTests();
        }

        foreach ($uses as $trait) {
            if (method_exists($this, $method = 'setUp' . class_basename($trait))) {
                $this->{$method}();
            }

            if (method_exists($this, $method = 'tearDown' . class_basename($trait))) {
                $this->beforeApplicationDestroyed(fn () => $this->{$method}());
            }
        }

        return $uses;
    }

    protected function createApplication(): ApplicationContract
    {
        $app = new Application();
        $app->singleton(KernelContract::class, ConsoleKernel::class);
        $app->singleton(ExceptionHandlerContract::class, ExceptionHandler::class);

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
