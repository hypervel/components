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

/**
 * @method string name()
 */
trait RunTestsInCoroutine
{
    protected bool $enableCoroutine = true;

    protected bool $copyNonCoroutineContext = true;

    protected string $realTestName = '';

    final protected function runTestsInCoroutine(...$arguments)
    {
        parent::setName($this->realTestName);

        $testResult = null;
        $exception = null;

        /* @phpstan-ignore-next-line */
        run(function () use (&$testResult, &$exception, $arguments) {
            // Clear stale transaction context from previous tests before copying
            $this->clearNonCoroutineTransactionContext();

            if ($this->copyNonCoroutineContext) {
                Context::copyFromNonCoroutine();
            }

            try {
                $this->invokeSetupInCoroutine();
                $testResult = $this->{$this->realTestName}(...$arguments);
            } catch (Throwable $e) {
                $exception = $e;
            } finally {
                $this->invokeTearDownInCoroutine();
                $this->cleanupTestContext();
                Timer::clearAll();
                CoordinatorManager::until(Constants::WORKER_EXIT)->resume();
            }
        });

        if ($exception) {
            throw $exception;
        }

        return $testResult;
    }

    final protected function runTest(): mixed
    {
        if (Coroutine::getCid() === -1 && $this->enableCoroutine) {
            $this->realTestName = $this->name();
            parent::setName('runTestsInCoroutine');
        }

        return parent::runTest();
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
     * Only destroys specific keys known to leak between tests. Does not use
     * Context::destroyAll() because that would destroy data needed by defer
     * callbacks (e.g., Redis connections waiting to be released).
     */
    protected function cleanupTestContext(): void
    {
        // Transaction manager state
        Context::destroy('__db.transactions.committed');
        Context::destroy('__db.transactions.pending');
        Context::destroy('__db.transactions.current');

        // Model guard state
        Context::destroy('__database.model.unguarded');
    }
}
