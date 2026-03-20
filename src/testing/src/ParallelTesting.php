<?php

declare(strict_types=1);

namespace Hypervel\Testing;

use Closure;
use Hypervel\Contracts\Container\Container;
use Hypervel\Support\Str;

class ParallelTesting
{
    /**
     * The options resolver callback.
     */
    protected ?Closure $optionsResolver = null;

    /**
     * The token resolver callback.
     */
    protected ?Closure $tokenResolver = null;

    /**
     * All of the registered "setUp" process callbacks.
     *
     * @var list<callable>
     */
    protected array $setUpProcessCallbacks = [];

    /**
     * All of the registered "setUp" test case callbacks.
     *
     * @var list<callable>
     */
    protected array $setUpTestCaseCallbacks = [];

    /**
     * All of the registered "setUp" test database callbacks prior to migrations.
     *
     * @var list<callable>
     */
    protected array $setUpTestDatabaseBeforeMigratingCallbacks = [];

    /**
     * All of the registered "setUp" test database callbacks.
     *
     * @var list<callable>
     */
    protected array $setUpTestDatabaseCallbacks = [];

    /**
     * All of the registered "tearDown" process callbacks.
     *
     * @var list<callable>
     */
    protected array $tearDownProcessCallbacks = [];

    /**
     * All of the registered "tearDown" test case callbacks.
     *
     * @var list<callable>
     */
    protected array $tearDownTestCaseCallbacks = [];

    /**
     * Create a new parallel testing instance.
     */
    public function __construct(
        protected Container $container
    ) {
    }

    /**
     * Set a callback that should be used when resolving options.
     */
    public function resolveOptionsUsing(?Closure $resolver): void
    {
        $this->optionsResolver = $resolver;
    }

    /**
     * Set a callback that should be used when resolving the unique process token.
     */
    public function resolveTokenUsing(?Closure $resolver): void
    {
        $this->tokenResolver = $resolver;
    }

    /**
     * Register a "setUp" process callback.
     */
    public function setUpProcess(callable $callback): void
    {
        $this->setUpProcessCallbacks[] = $callback;
    }

    /**
     * Register a "setUp" test case callback.
     */
    public function setUpTestCase(callable $callback): void
    {
        $this->setUpTestCaseCallbacks[] = $callback;
    }

    /**
     * Register a "setUp" test database callback that runs prior to migrations.
     */
    public function setUpTestDatabaseBeforeMigrating(callable $callback): void
    {
        $this->setUpTestDatabaseBeforeMigratingCallbacks[] = $callback;
    }

    /**
     * Register a "setUp" test database callback.
     */
    public function setUpTestDatabase(callable $callback): void
    {
        $this->setUpTestDatabaseCallbacks[] = $callback;
    }

    /**
     * Register a "tearDown" process callback.
     */
    public function tearDownProcess(callable $callback): void
    {
        $this->tearDownProcessCallbacks[] = $callback;
    }

    /**
     * Register a "tearDown" test case callback.
     */
    public function tearDownTestCase(callable $callback): void
    {
        $this->tearDownTestCaseCallbacks[] = $callback;
    }

    /**
     * Call all of the "setUp" process callbacks.
     */
    public function callSetUpProcessCallbacks(): void
    {
        $this->whenRunningInParallel(function () {
            foreach ($this->setUpProcessCallbacks as $callback) {
                $this->container->call($callback, [
                    'token' => $this->token(),
                ]);
            }
        });
    }

    /**
     * Call all of the "setUp" test case callbacks.
     */
    public function callSetUpTestCaseCallbacks(mixed $testCase): void
    {
        $this->whenRunningInParallel(function () use ($testCase) {
            foreach ($this->setUpTestCaseCallbacks as $callback) {
                $this->container->call($callback, [
                    'testCase' => $testCase,
                    'token' => $this->token(),
                ]);
            }
        });
    }

    /**
     * Call all of the "setUp" test database callbacks that run prior to migrations.
     */
    public function callSetUpTestDatabaseBeforeMigratingCallbacks(string $database): void
    {
        $this->whenRunningInParallel(function () use ($database) {
            foreach ($this->setUpTestDatabaseBeforeMigratingCallbacks as $callback) {
                $this->container->call($callback, [
                    'database' => $database,
                    'token' => $this->token(),
                ]);
            }
        });
    }

    /**
     * Call all of the "setUp" test database callbacks.
     */
    public function callSetUpTestDatabaseCallbacks(string $database): void
    {
        $this->whenRunningInParallel(function () use ($database) {
            foreach ($this->setUpTestDatabaseCallbacks as $callback) {
                $this->container->call($callback, [
                    'database' => $database,
                    'token' => $this->token(),
                ]);
            }
        });
    }

    /**
     * Call all of the "tearDown" process callbacks.
     */
    public function callTearDownProcessCallbacks(): void
    {
        $this->whenRunningInParallel(function () {
            foreach ($this->tearDownProcessCallbacks as $callback) {
                $this->container->call($callback, [
                    'token' => $this->token(),
                ]);
            }
        });
    }

    /**
     * Call all of the "tearDown" test case callbacks.
     */
    public function callTearDownTestCaseCallbacks(mixed $testCase): void
    {
        $this->whenRunningInParallel(function () use ($testCase) {
            foreach ($this->tearDownTestCaseCallbacks as $callback) {
                $this->container->call($callback, [
                    'testCase' => $testCase,
                    'token' => $this->token(),
                ]);
            }
        });
    }

    /**
     * Get a parallel testing option.
     */
    public function option(string $option): mixed
    {
        $optionsResolver = $this->optionsResolver ?: function (string $option) {
            $option = 'HYPERVEL_PARALLEL_TESTING_' . Str::upper($option);

            return $_SERVER[$option] ?? false;
        };

        return $optionsResolver($option);
    }

    /**
     * Get the unique test token.
     */
    public function token(): string|false
    {
        return $this->tokenResolver
            ? call_user_func($this->tokenResolver)
            : ($_SERVER['TEST_TOKEN'] ?? false);
    }

    /**
     * Check if tests are running in parallel.
     */
    public function inParallel(): bool
    {
        return ! empty($_SERVER['HYPERVEL_PARALLEL_TESTING']) && $this->token() !== false;
    }

    /**
     * Apply the callback if tests are running in parallel.
     */
    protected function whenRunningInParallel(callable $callback): void
    {
        if ($this->inParallel()) {
            $callback();
        }
    }
}
