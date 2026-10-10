<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hypervel\Context\CoroutineContext;
use Hypervel\Coordinator\Constants;
use Hypervel\Coordinator\CoordinatorManager;
use Hypervel\Database\DatabaseTransactionsManager;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\PostCondition;
use PHPUnit\Framework\IncompleteTest;
use PHPUnit\Framework\SkippedTest;
use SebastianBergmann\Invoker\TimeoutException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Timer;
use Throwable;

use function Hypervel\Coroutine\run;

trait RunTestsInCoroutine
{
    protected bool $runTestsInCoroutine = true;

    protected bool $copyNonCoroutineContext = true;

    /**
     * The exception the test threw before its child coroutines reached the time limit.
     */
    protected ?Throwable $exceptionOutlivedByChildCoroutines = null;

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
        if (Coroutine::getCid() !== -1 || ! $this->runsTestsInCoroutine()) {
            return parent::invokeTestMethod($methodName, $testArguments);
        }

        $testResult = null;
        $exception = null;
        $timeoutException = null;
        $rootCompleted = false;
        $this->exceptionOutlivedByChildCoroutines = null;

        $capture = static function (callable $callback) use (&$exception): void {
            try {
                $callback();
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        };

        run(function () use (&$testResult, &$exception, &$timeoutException, &$rootCompleted, $capture, $methodName, $testArguments): void {
            $shouldBootFramework = false;

            try {
                $this->enforceCoroutineTestTimeLimit($timeoutException, $rootCompleted);
                $this->clearNonCoroutineTransactionContext();

                if ($this->copyNonCoroutineContext) {
                    CoroutineContext::copyFromNonCoroutine();
                    DatabaseTransactionsManager::copyFromNonCoroutineState();
                }

                $shouldBootFramework = $this->shouldBootFrameworkForTest();

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

        if ($timeoutException !== null) {
            // Children outliving a test that threw must not hide its exception. PHPUnit still matches it against
            // the test's expected exception, and a matched one fails in the post-condition instead. Skipped and
            // incomplete tests never reach the post-condition, so they keep the timeout.
            if ($rootCompleted
                && $exception !== null
                && ! $exception instanceof SkippedTest
                && ! $exception instanceof IncompleteTest
            ) {
                $this->exceptionOutlivedByChildCoroutines = $exception;

                throw $exception;
            }

            throw $timeoutException;
        }

        if ($exception !== null) {
            throw $exception;
        }

        return $testResult;
    }

    /**
     * Enforce PHPUnit's deadline while the test or its children are suspended.
     */
    protected function enforceCoroutineTestTimeLimit(?TimeoutException &$timeoutException, bool &$rootCompleted): void
    {
        if (! function_exists('pcntl_alarm')) {
            return;
        }

        // Keep the native alarm armed so non-yielding tests are still interrupted.
        $remaining = pcntl_alarm(0);
        pcntl_alarm($remaining);

        if ($remaining === 0) {
            return;
        }

        $root = Coroutine::getCid();
        $deadline = hrtime(true) / 1e9 + $remaining;
        // Buffer the exit signal so cleanup cannot block after the deadline has expired.
        $completed = new Channel(1);
        // Register first so the signal follows all other root cleanup defers.
        Coroutine::defer(static function () use ($completed): void {
            $completed->push(true);
        });

        Coroutine::create(static function () use ($root, $deadline, $completed, &$timeoutException, &$rootCompleted): void {
            $current = Coroutine::getCid();

            try {
                $remaining = $deadline - hrtime(true) / 1e9;

                // Native channel and sleep timers survive Timer::clearAll() cleanup.
                if ($remaining > 0 && $completed->pop($remaining)) {
                    $rootCompleted = true;

                    while (true) {
                        $coroutines = array_filter(
                            iterator_to_array(Coroutine::list()),
                            static fn (int $coroutine): bool => $coroutine !== $current && $coroutine !== $root,
                        );

                        if ($coroutines === []) {
                            return;
                        }

                        $remaining = $deadline - hrtime(true) / 1e9;

                        if ($remaining <= 0) {
                            break;
                        }

                        // Joining here would consume the sole join slot needed by the test.
                        Coroutine::sleep(max(0.001, min($remaining, 0.01)));
                    }
                }

                pcntl_signal_dispatch();

                throw new TimeoutException('The coroutine test exceeded its time limit.');
            } catch (TimeoutException $exception) {
                $timeoutException = $exception;
                pcntl_alarm(0);

                foreach (Coroutine::list() as $coroutine) {
                    if ($coroutine !== $current) {
                        // The root catches cancellation; raw child callbacks may not.
                        EngineCoroutine::cancelById($coroutine, $coroutine === $root);
                    }
                }
            }
        });
    }

    /**
     * Fail a test whose expected exception was followed by child coroutines reaching the time limit.
     */
    #[PostCondition]
    protected function assertChildCoroutinesFinishedBeforeTimeLimit(): void
    {
        if ($this->exceptionOutlivedByChildCoroutines !== null) {
            throw new AssertionFailedError(
                'Child coroutines were still running at the time limit after the test threw an expected exception.',
                0,
                $this->exceptionOutlivedByChildCoroutines,
            );
        }
    }

    /**
     * Determine whether tests run in a coroutine.
     */
    protected function runsTestsInCoroutine(): bool
    {
        return $this->runTestsInCoroutine;
    }

    /**
     * Determine if framework lifecycle hooks should run for this test.
     */
    protected function shouldBootFrameworkForTest(): bool
    {
        return true;
    }

    /**
     * Run trait and test setup hooks in the test coroutine.
     */
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

    /**
     * Run test and trait teardown hooks while capturing failures.
     */
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
