<?php

declare(strict_types=1);

namespace Hypervel\Testing;

use Closure;
use Hypervel\Contracts\Container\Container;

/**
 * Provides parallel testing lifecycle hooks and token access.
 *
 * When tests run under ParaTest, each worker receives a unique TEST_TOKEN
 * (1, 2, 3...). This service provides access to that token and allows
 * registering callbacks that fire during test setUp/tearDown when running
 * in parallel mode.
 *
 * Callbacks are only invoked when running in parallel (TEST_TOKEN is set).
 * In sequential mode, all callback invocation methods are no-ops.
 */
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
     * All of the registered "setUp" test case callbacks.
     *
     * @var list<callable>
     */
    protected array $setUpTestCaseCallbacks = [];

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
     * Register a "setUp" test case callback.
     */
    public function setUpTestCase(callable $callback): void
    {
        $this->setUpTestCaseCallbacks[] = $callback;
    }

    /**
     * Register a "tearDown" test case callback.
     */
    public function tearDownTestCase(callable $callback): void
    {
        $this->tearDownTestCaseCallbacks[] = $callback;
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
        $resolver = $this->optionsResolver ?: function (string $option) {
            return env('HYPERVEL_PARALLEL_TESTING_' . strtoupper($option), false);
        };

        return $resolver($option);
    }

    /**
     * Get the unique test token.
     */
    public function token(): string|false
    {
        if ($this->tokenResolver) {
            return ($this->tokenResolver)();
        }

        $token = env('TEST_TOKEN');

        return $token !== null ? (string) $token : false;
    }

    /**
     * Check if tests are running in parallel.
     */
    public function inParallel(): bool
    {
        return $this->token() !== false;
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
