<?php

declare(strict_types=1);

namespace Hypervel\Testbench;

use Closure;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Foundation\Testing\DatabaseTransactions;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Foundation\Testing\TestCase as BaseTestCase;
use Hypervel\Testbench\Pest\WithPest;
use RuntimeException;
use Swoole\Timer;
use Throwable;

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
     * The base URL to use while testing the application.
     */
    protected string $baseUrl = 'http://localhost';

    /**
     * Automatically loads environment variables when available.
     */
    protected bool $loadEnvironmentVariables = true;

    protected static bool $hasBootstrappedTestbench = false;

    /**
     * Setup the test environment.
     */
    protected function setUp(): void
    {
        if ($this->withoutBootingFramework()) {
            parent::setUp();
            return;
        }

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

        /* @phpstan-ignore class.notFound */
        if (static::usesTestingConcern(WithPest::class)) {
            $this->setUpTheEnvironmentUsingPest(); /* @phpstan-ignore method.notFound */
        }

        $setupHasRun = false;
        $setup = function () use (&$setupHasRun): void {
            if ($setupHasRun) {
                return;
            }

            $setupHasRun = true;

            parent::setUp();

            $this->preservePackageManifestCache();

            $this->baseUrl = config()->string('app.url');

            // Execute BeforeEach attributes INSIDE coroutine context
            // (matches where setUpTraits runs in Foundation TestCase)
            $this->runInCoroutine(fn () => $this->setUpTheTestEnvironmentUsingTestCase());
        };

        if ($this->testCaseSetUpCallback instanceof Closure) {
            ($this->testCaseSetUpCallback)($setup);
        }

        if (! $setupHasRun) {
            $setup();
        }
    }

    /**
     * Preserve the package manifest cache for the duration of the test.
     */
    protected function preservePackageManifestCache(): void
    {
        $files = new Filesystem;
        $path = $this->app->getCachedPackagesPath();
        $existed = $files->isFile($path);
        $contents = $existed ? $files->get($path) : '';

        $this->beforeApplicationDestroyed(static function () use ($files, $path, $existed, $contents): void {
            $exists = $files->isFile($path);

            if (! $existed) {
                if ($exists && ! $files->delete($path) && $files->isFile($path)) {
                    throw new RuntimeException("Unable to delete the test-owned package manifest cache [{$path}].");
                }

                return;
            }

            if ($exists && $files->get($path) === $contents) {
                return;
            }

            $files->replace($path, $contents);
        });
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

    /**
     * Clean up the testing environment before the next test.
     */
    protected function tearDown(): void
    {
        if ($this->withoutBootingFramework()) {
            parent::tearDown();
            return;
        }

        $exception = null;

        /* @phpstan-ignore class.notFound */
        if (static::usesTestingConcern(WithPest::class)) {
            try {
                $this->tearDownTheEnvironmentUsingPest(); /* @phpstan-ignore method.notFound */
            } catch (Throwable $throwable) {
                $exception = $throwable;
            }
        }

        $teardownHasRun = false;
        $teardown = function () use (&$teardownHasRun): void {
            if ($teardownHasRun) {
                return;
            }

            $teardownHasRun = true;
            $exception = null;

            try {
                // Execute AfterEach attributes INSIDE coroutine context.
                $this->runInCoroutine(fn () => $this->tearDownTheTestEnvironmentUsingTestCase());
            } catch (Throwable $throwable) {
                $exception = $throwable;
            }

            try {
                parent::tearDown();
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }

            if ($exception !== null) {
                throw $exception;
            }
        };

        try {
            ($this->testCaseTearDownCallback ?? static function (Closure $parent): void {
                $parent();
            })($teardown);
        } catch (Throwable $throwable) {
            $exception ??= $throwable;
        }

        if (! $teardownHasRun) {
            try {
                $teardown();
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        $this->testCaseSetUpCallback = null;
        $this->testCaseTearDownCallback = null;

        if ($exception !== null) {
            throw $exception;
        }
    }

    /**
     * Prepare the testing environment before the running the test case.
     */
    public static function setUpBeforeClass(): void
    {
        static::setUpBeforeClassUsingPHPUnit();

        /* @phpstan-ignore class.notFound */
        if (static::usesTestingConcern(WithPest::class)) {
            static::setUpBeforeClassUsingPest(); /* @phpstan-ignore staticMethod.notFound */
        }

        static::setUpBeforeClassUsingTestCase();
    }

    /**
     * Clean up the testing environment before the next test case.
     */
    public static function tearDownAfterClass(): void
    {
        $exception = null;

        try {
            static::tearDownAfterClassUsingTestCase();
        } catch (Throwable $throwable) {
            $exception = $throwable;
        }

        /* @phpstan-ignore class.notFound */
        if (static::usesTestingConcern(WithPest::class)) {
            try {
                static::tearDownAfterClassUsingPest(); /* @phpstan-ignore staticMethod.notFound */
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        try {
            static::tearDownAfterClassUsingPHPUnit();
        } catch (Throwable $throwable) {
            $exception ??= $throwable;
        }

        if ($exception !== null) {
            throw $exception;
        }
    }
}
