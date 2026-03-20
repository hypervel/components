<?php

declare(strict_types=1);

namespace Hypervel\Testbench;

use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Foundation\Testing\DatabaseTransactions;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Foundation\Testing\TestCase as BaseTestCase;
use Hypervel\Testbench\Pest\WithPest;
use Swoole\Timer;

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
class TestCase extends BaseTestCase implements Contracts\TestCase
{
    use Concerns\Testing;

    /**
     * Automatically loads environment variables when available.
     */
    protected bool $loadEnvironmentVariables = true;

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
            CoordinatorManager::clear(Constants::WORKER_EXIT);

            // Setup routes after application is created (providers are booted)
            $this->setUpApplicationRoutes($this->app);
        });

        parent::setUp();

        // Execute BeforeEach attributes INSIDE coroutine context
        // (matches where setUpTraits runs in Foundation TestCase)
        $this->runInCoroutine(fn () => $this->setUpTheTestEnvironmentUsingTestCase());
    }

    /**
     * Set up database-related testing traits.
     *
     * Wraps migration traits in setUpDatabaseRequirements() so that
     * testbench attributes (RequiresDatabase, WithConfig, WithMigration)
     * are processed before migrations run.
     */
    protected function setUpDatabaseTraits(array $uses): void
    {
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
    }

    /**
     * Refresh the application instance.
     */
    protected function refreshApplication(): void
    {
        $this->app = $this->createApplication();
    }

    protected function tearDown(): void
    {
        // Execute AfterEach attributes INSIDE coroutine context
        $this->runInCoroutine(fn () => $this->tearDownTheTestEnvironmentUsingTestCase());

        parent::tearDown();
    }

    public static function setUpBeforeClass(): void
    {
        static::setUpBeforeClassUsingPHPUnit();

        /* @phpstan-ignore class.notFound */
        if (static::usesTestingConcern(WithPest::class)) {
            static::setUpBeforeClassUsingPest(); /* @phpstan-ignore staticMethod.notFound */
        }

        static::setUpBeforeClassUsingTestCase();
        static::setUpBeforeClassUsingWorkbench();
    }

    public static function tearDownAfterClass(): void
    {
        static::tearDownAfterClassUsingWorkbench();
        static::tearDownAfterClassUsingTestCase();

        /* @phpstan-ignore class.notFound */
        if (static::usesTestingConcern(WithPest::class)) {
            static::tearDownAfterClassUsingPest(); /* @phpstan-ignore staticMethod.notFound */
        }

        static::tearDownAfterClassUsingPHPUnit();
    }
}
