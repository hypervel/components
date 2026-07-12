<?php

declare(strict_types=1);

namespace Hypervel\Tests\Engine;

use Hypervel\Engine\Coroutine;
use Hypervel\Engine\Exceptions\RunningInNonCoroutineException;
use Hypervel\Tests\TestCase;

class CoroutineNonCoroutineTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testParentCoroutineIdRequiresCoroutineContext(): void
    {
        $this->expectException(RunningInNonCoroutineException::class);
        $this->expectExceptionMessage('Cannot retrieve a parent coroutine ID outside a coroutine.');

        Coroutine::pid();
    }
}
