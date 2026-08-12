<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Hypervel\Coroutine\Parallel;
use Hypervel\Engine\Exceptions\RunningInNonCoroutineException;
use Hypervel\Tests\TestCase;

class ParallelNonCoroutineTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testParallelExecutionFailsBeforeExecutingCallbacksOutsideACoroutine(): void
    {
        $callbackExecuted = false;
        $parallel = new Parallel;
        $parallel->add(function () use (&$callbackExecuted): void {
            $callbackExecuted = true;
        });

        try {
            $parallel->wait();
            $this->fail('Parallel execution should require an active coroutine.');
        } catch (RunningInNonCoroutineException $exception) {
            $this->assertSame('Parallel execution requires an active coroutine.', $exception->getMessage());
        }

        $this->assertFalse($callbackExecuted);
    }
}
