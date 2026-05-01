<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Database\Pool\PoolFactory;
use Hypervel\Foundation\Bootstrap\HandleExceptions;
use Hypervel\Foundation\Testing\Attributes\SetUp;
use Hypervel\Foundation\Testing\Attributes\TearDown;
use Hypervel\Foundation\Testing\DatabaseConnectionResolver;
use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Foundation\Testing\DatabaseTransactions;
use Hypervel\Foundation\Testing\DatabaseTruncation;
use Hypervel\Foundation\Testing\LazilyRefreshDatabase;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Foundation\Testing\WithFaker;
use Hypervel\Foundation\Testing\WithoutEvents;
use Hypervel\Foundation\Testing\WithoutMiddleware;
use Hypervel\Support\Facades\Facade;
use Hypervel\Support\Facades\ParallelTesting;
use PHPUnit\Metadata\Annotation\Parser\Registry as PHPUnitRegistry;
use ReflectionClass;
use Throwable;

use function Hypervel\Coroutine\run;

trait InteractsWithTestCaseLifecycle
{
    /**
     * The application instance.
     */
    protected ?ApplicationContract $app = null;

    /**
     * The callbacks that should be run after the application is created.
     */
    protected array $afterApplicationCreatedCallbacks = [];

    /**
     * The callbacks that should be run before the application is destroyed.
     */
    protected array $beforeApplicationDestroyedCallbacks = [];

    /**
     * The exception thrown while running an application destruction callback.
     */
    protected ?Throwable $callbackException = null;

    /**
     * Indicates if we have made it through the base setUp function.
     */
    protected bool $setUpHasRun = false;

    /**
     * Set up the test environment.
     *
     * @internal
     */
    protected function setUpTheTestEnvironment(): void
    {
        Facade::clearResolvedInstances();

        /* @phpstan-ignore-next-line */
        if (! $this->app) {
            $this->refreshApplication();

            ParallelTesting::callSetUpTestCaseCallbacks($this);
        }

        // Reset after Application exists so container-change detection works correctly
        // and rebinding hooks are registered on the current container.
        DatabaseConnectionResolver::flushCachedConnections();

        $this->runInCoroutine(function () {
            $this->setUpTraits();

            // Preserve transaction manager context for the test coroutine.
            // RefreshDatabase stores transaction state in Context, but setUpTraits runs
            // in a temporary coroutine. Copy to non-coroutine context so the test
            // coroutine (which copies from nonCoroutineContext) can access it.
            $this->preserveTransactionContext();
        });

        foreach ($this->afterApplicationCreatedCallbacks as $callback) {
            $callback();
        }

        $this->setUpHasRun = true;
    }

    /**
     * Clean up the testing environment before the next test.
     *
     * @internal
     *
     * @throws Throwable
     */
    protected function tearDownTheTestEnvironment(): void
    {
        if ($this->app) {
            $this->runInCoroutine(
                fn () => $this->callBeforeApplicationDestroyedCallbacks()
            );

            // Flush the DB connection pool in a separate coroutine so the
            // pooled connections checked out during the destroyed callbacks
            // (e.g. migrate:rollback) are first released by their Coroutine::defer
            // when the previous coroutine ends. flushAll() can only drain
            // connections already in the channel. Without the second coroutine,
            // the flush runs while connections are still pinned and is a no-op.
            // The resolved() gate skips the work for tests that never touched
            // the DB pool factory.
            if ($this->app->resolved(PoolFactory::class)) {
                $this->runInCoroutine(
                    fn () => $this->app->make(PoolFactory::class)->flushAll()
                );
            }

            ParallelTesting::callTearDownTestCaseCallbacks($this);

            $this->app->flush();

            $this->app = null;
        }

        $this->afterApplicationCreatedCallbacks = [];
        $this->beforeApplicationDestroyedCallbacks = [];

        if ($this->callbackException) {
            throw $this->callbackException;
        }

        HandleExceptions::flushState($this);

        $this->setUpHasRun = false;
    }

    /**
     * Boot the testing helper traits.
     *
     * @return array
     */
    protected function setUpTraits()
    {
        $uses = array_flip(class_uses_recursive(static::class));

        if (isset($uses[WithFaker::class])) {
            $this->setUpFaker();
        }

        $this->setUpDatabaseTraits($uses);

        if (isset($uses[WithoutMiddleware::class])) {
            $this->disableMiddlewareForAllTests(); // @phpstan-ignore method.notFound
        }

        if (isset($uses[WithoutEvents::class])) {
            $this->disableEventsForAllTests(); // @phpstan-ignore method.notFound
        }

        foreach ($uses as $trait) {
            if (method_exists($this, $method = 'setUp' . class_basename($trait))) {
                $this->{$method}();
            }

            if (method_exists($this, $method = 'tearDown' . class_basename($trait))) {
                $this->beforeApplicationDestroyed(fn () => $this->{$method}());
            }

            foreach ((new ReflectionClass($trait))->getMethods() as $method) {
                if ($method->getAttributes(SetUp::class) !== []) {
                    $this->{$method->getName()}();
                }

                if ($method->getAttributes(TearDown::class) !== []) {
                    $this->beforeApplicationDestroyed(fn () => $this->{$method->getName()}());
                }
            }
        }

        return $uses;
    }

    /**
     * Set up database-related testing traits.
     *
     * Override this method to customize database trait initialization order,
     * e.g. to process test attributes before migrations run.
     */
    protected function setUpDatabaseTraits(array $uses): void
    {
        if (isset($uses[RefreshDatabase::class])) {
            $this->refreshDatabase(); // @phpstan-ignore method.notFound
        }

        if (isset($uses[LazilyRefreshDatabase::class])) {
            $this->refreshDatabase(); // @phpstan-ignore method.notFound
        }

        if (isset($uses[DatabaseMigrations::class])) {
            $this->runDatabaseMigrations(); // @phpstan-ignore method.notFound
        }

        if (isset($uses[DatabaseTruncation::class])) {
            $this->truncateDatabaseTables(); // @phpstan-ignore method.notFound
        }

        if (isset($uses[DatabaseTransactions::class])) {
            $this->beginDatabaseTransaction(); // @phpstan-ignore method.notFound
        }
    }

    /**
     * Clean up the testing environment before the next test case.
     *
     * @internal
     */
    public static function tearDownAfterClassUsingTestCase(): void
    {
        if (class_exists(PHPUnitRegistry::class)) {
            (function () {
                $this->classDocBlocks = [];
                $this->methodDocBlocks = [];
            })->call(PHPUnitRegistry::getInstance());
        }
    }

    /**
     * Register a callback to be run after the application is created.
     */
    public function afterApplicationCreated(callable $callback)
    {
        $this->afterApplicationCreatedCallbacks[] = $callback;

        if ($this->setUpHasRun) {
            $callback();
        }
    }

    /**
     * Register a callback to be run before the application is destroyed.
     */
    protected function beforeApplicationDestroyed(callable $callback)
    {
        $this->beforeApplicationDestroyedCallbacks[] = $callback;
    }

    /**
     * Execute the application's pre-destruction callbacks.
     */
    protected function callBeforeApplicationDestroyedCallbacks()
    {
        foreach ($this->beforeApplicationDestroyedCallbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $e) {
                if (! $this->callbackException) {
                    $this->callbackException = $e;
                }
            }
        }
    }

    /**
     * Preserve transaction manager context for the test coroutine.
     *
     * RefreshDatabase and DatabaseTransactions store transaction state in Context.
     * Since setUpTraits runs in a temporary coroutine (separate from the test method's
     * coroutine), we must copy this state to non-coroutine context. The test coroutine
     * will then copy from non-coroutine context via copyFromNonCoroutine().
     */
    protected function preserveTransactionContext(): void
    {
        DatabaseTransactionsManager::copyToNonCoroutineState();
    }

    /**
     * Ensure callback is executed in coroutine.
     *
     * Exceptions are captured and re-thrown outside the coroutine context
     * so they propagate correctly to PHPUnit (e.g., for markTestSkipped).
     */
    protected function runInCoroutine(callable $callback): void
    {
        if (Coroutine::inCoroutine()) {
            $callback();
            return;
        }

        $exception = null;

        run(function () use ($callback, &$exception) {
            try {
                $callback();
            } catch (Throwable $e) {
                $exception = $e;
            }
        });

        if ($exception !== null) {
            throw $exception;
        }
    }
}
