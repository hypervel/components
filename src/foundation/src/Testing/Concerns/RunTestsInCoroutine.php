<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hypervel\Context\CoroutineContext;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Database\Eloquent\Model;
use Swoole\Coroutine;
use Swoole\Timer;
use Throwable;

use function Hypervel\Coroutine\run;

trait RunTestsInCoroutine
{
    protected bool $runTestsInCoroutine = true;

    protected bool $copyNonCoroutineContext = true;

    /**
     * Invoke the test method inside a Swoole coroutine container.
     *
     * Uses PHPUnit 13's official extension point for customizing test method
     * invocation. When coroutines are enabled and we're not already inside one,
     * the test method runs inside Hypervel's coroutine container with full
     * lifecycle management (context copying, setup/teardown hooks, cleanup).
     *
     * @param array<mixed> $testArguments
     */
    protected function invokeTestMethod(string $methodName, array $testArguments): mixed
    {
        if (Coroutine::getCid() !== -1 || ! $this->runTestsInCoroutine) {
            return parent::invokeTestMethod($methodName, $testArguments);
        }

        $testResult = null;
        $exception = null;

        $capture = static function (callable $callback) use (&$exception): void {
            try {
                $callback();
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        };

        /* @phpstan-ignore-next-line */
        run(function () use (&$testResult, &$exception, $capture, $methodName, $testArguments): void {
            $this->clearNonCoroutineTransactionContext();

            if ($this->copyNonCoroutineContext) {
                CoroutineContext::copyFromNonCoroutine();
            }

            $shouldBootFramework = $this->shouldBootFrameworkForTest();

            try {
                if ($shouldBootFramework) {
                    $this->invokeSetupInCoroutine();
                }

                $testResult = $this->{$methodName}(...$testArguments);
            } catch (Throwable $e) {
                $exception = $e;
            } finally {
                if ($shouldBootFramework) {
                    $this->invokeTearDownInCoroutine($capture);
                }

                $capture(fn () => $this->cleanupTestContext());
                $capture(fn () => Timer::clearAll());
                $capture(fn () => CoordinatorManager::until(Constants::WORKER_EXIT)->resume());
                $capture(fn () => CoordinatorManager::clear(Constants::WORKER_EXIT));
            }
        });

        if ($exception !== null) {
            throw $exception;
        }

        return $testResult;
    }

    /**
     * Determine if framework lifecycle hooks should run for this test.
     */
    protected function shouldBootFrameworkForTest(): bool
    {
        return true;
    }

    protected function invokeSetupInCoroutine(): void
    {
        // Call trait-specific coroutine setup methods (e.g., setUpDatabaseTransactionsInCoroutine)
        foreach (class_uses_recursive(static::class) as $trait) {
            $method = 'setUp' . class_basename($trait) . 'InCoroutine';
            if (method_exists($this, $method)) {
                $this->{$method}();
            }
        }

        if (method_exists($this, 'setUpInCoroutine')) {
            call_user_func([$this, 'setUpInCoroutine']);
        }
    }

    protected function invokeTearDownInCoroutine(callable $capture): void
    {
        if (method_exists($this, 'tearDownInCoroutine')) {
            $capture(fn () => $this->tearDownInCoroutine());
        }

        // Call trait-specific coroutine teardown methods (e.g., tearDownDatabaseTransactionsInCoroutine)
        foreach (class_uses_recursive(static::class) as $trait) {
            $method = 'tearDown' . class_basename($trait) . 'InCoroutine';
            if (method_exists($this, $method)) {
                $capture(fn () => $this->{$method}());
            }
        }
    }

    /**
     * Clear transaction context from non-coroutine storage before test starts.
     *
     * RefreshDatabase starts its wrapper transaction in setUp() (outside coroutine),
     * storing it in nonCoroutineContext. We must preserve this data for copying into the
     * coroutine. Only clear if there are no pending transactions (meaning any data
     * is stale from a previous test that didn't clean up properly).
     */
    protected function clearNonCoroutineTransactionContext(): void
    {
        if (DatabaseTransactionsManager::hasNonCoroutinePendingTransactions()) {
            return;
        }

        DatabaseTransactionsManager::clearNonCoroutineState();
    }

    /**
     * Clean up Context keys that cause test pollution.
     *
     * Only forgets specific keys known to leak between tests. Does not use
     * CoroutineContext::flush() because that would flush data needed by defer
     * callbacks (e.g., Redis connections waiting to be released).
     */
    protected function cleanupTestContext(): void
    {
        // Model guard state
        CoroutineContext::forget(Model::UNGUARDED_CONTEXT_KEY);
    }
}
