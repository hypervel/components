<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hypervel\Context\Context;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Support\Collection;
use Swoole\Coroutine;
use Swoole\Timer;
use Throwable;

use function Hypervel\Coroutine\run;

trait RunTestsInCoroutine
{
    protected bool $enableCoroutine = true;

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
        if (Coroutine::getCid() !== -1 || ! $this->enableCoroutine) {
            return parent::invokeTestMethod($methodName, $testArguments);
        }

        $testResult = null;
        $exception = null;

        /* @phpstan-ignore-next-line */
        run(function () use (&$testResult, &$exception, $methodName, $testArguments) {
            $this->clearNonCoroutineTransactionContext();

            if ($this->copyNonCoroutineContext) {
                Context::copyFromNonCoroutine();
            }

            try {
                $this->invokeSetupInCoroutine();
                $testResult = $this->{$methodName}(...$testArguments);
            } catch (Throwable $e) {
                $exception = $e;
            } finally {
                $this->invokeTearDownInCoroutine();
                $this->cleanupTestContext();
                Timer::clearAll();
                CoordinatorManager::until(Constants::WORKER_EXIT)->resume();
                CoordinatorManager::clear(Constants::WORKER_EXIT);
            }
        });

        if ($exception) {
            throw $exception;
        }

        return $testResult;
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

    protected function invokeTearDownInCoroutine(): void
    {
        if (method_exists($this, 'tearDownInCoroutine')) {
            call_user_func([$this, 'tearDownInCoroutine']);
        }

        // Call trait-specific coroutine teardown methods (e.g., tearDownDatabaseTransactionsInCoroutine)
        foreach (class_uses_recursive(static::class) as $trait) {
            $method = 'tearDown' . class_basename($trait) . 'InCoroutine';
            if (method_exists($this, $method)) {
                $this->{$method}();
            }
        }
    }

    /**
     * Clear transaction context from non-coroutine storage before test starts.
     *
     * RefreshDatabase starts its wrapper transaction in setUp() (outside coroutine),
     * storing it in nonCoContext. We must preserve this data for copying into the
     * coroutine. Only clear if there are no pending transactions (meaning any data
     * is stale from a previous test that didn't clean up properly).
     */
    protected function clearNonCoroutineTransactionContext(): void
    {
        $pending = Context::getFromNonCoroutine('__db.transactions.pending');

        if ($pending instanceof Collection && $pending->isNotEmpty()) {
            return;
        }

        Context::clearFromNonCoroutine([
            '__db.transactions.committed',
            '__db.transactions.pending',
            '__db.transactions.current',
        ]);
    }

    /**
     * Clean up Context keys that cause test pollution.
     *
     * Only forgets specific keys known to leak between tests. Does not use
     * Context::flush() because that would flush data needed by defer
     * callbacks (e.g., Redis connections waiting to be released).
     */
    protected function cleanupTestContext(): void
    {
        // Transaction manager state
        Context::forget('__db.transactions.committed');
        Context::forget('__db.transactions.pending');
        Context::forget('__db.transactions.current');

        // Model guard state
        Context::forget('__database.model.unguarded');
    }
}
