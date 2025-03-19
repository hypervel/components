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
