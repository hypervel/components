<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Concerns;

use Hyperf\Coordinator\Constants;
use Hyperf\Coordinator\CoordinatorManager;
use Hypervel\Context\Context;
use Swoole\Coroutine;
use Swoole\Timer;
use Throwable;

use function Hypervel\Coroutine\run;

/**
 * Wraps each test method in a Swoole coroutine so that database connections,
 * channels, and other coroutine-dependent APIs work correctly during tests.
 *
 * PHPUnit 10.5 made runTest() private, so we can no longer override it.
 * Instead, we swap the test method name during setUp() — which runs before
 * PHPUnit's private runTest() calls $this->{$this->name}().
 *
 * @method string name()
 */
trait RunTestsInCoroutine
{
    protected bool $enableCoroutine = true;

    protected bool $copyNonCoroutineContext = true;

    protected string $realTestName = '';

    /**
     * Swap the test method name so PHPUnit's private runTest() calls
     * runTestsInCoroutine() instead of the real test method.
     * The real test method is then executed inside a Swoole coroutine.
     */
    protected function setUpCoroutineTest(): void
    {
        if (Coroutine::getCid() === -1 && $this->enableCoroutine) {
            $this->realTestName = $this->name();
            $this->setName('runTestsInCoroutine');
        }
    }

    final protected function runTestsInCoroutine(...$arguments)
    {
        $this->setName($this->realTestName);

        $testResult = null;
        $exception = null;

        /* @phpstan-ignore-next-line */
        run(function () use (&$testResult, &$exception, $arguments) {
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
                Timer::clearAll();
                CoordinatorManager::until(Constants::WORKER_EXIT)->resume();
            }
        });

        if ($exception) {
            throw $exception;
        }

        return $testResult;
    }

    protected function invokeSetupInCoroutine(): void
    {
        if (method_exists($this, 'setUpInCoroutine')) {
            call_user_func([$this, 'setUpInCoroutine']);
        }
    }

    protected function invokeTearDownInCoroutine(): void
    {
        if (method_exists($this, 'tearDownInCoroutine')) {
            call_user_func([$this, 'tearDownInCoroutine']);
        }
    }
}
