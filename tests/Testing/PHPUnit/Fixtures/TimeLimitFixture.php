<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\PHPUnit\Fixtures;

use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Tests\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

class TimeLimitFixture extends TestCase
{
    public function testNonYieldingWorkIsAborted(): void
    {
        while (true);
    }

    public function testSleepingWorkIsAborted(): void
    {
        Coroutine::sleep(30);
        $this->fail('The sleeping test was not interrupted.');
    }

    public function testPendingChildrenAreAborted(): void
    {
        Coroutine::create(function (): void {
            $this->assertFalse((new Channel)->pop());
        });
    }

    public function testChildrenCompleteNormally(): void
    {
        $root = Coroutine::getCid();
        Coroutine::create(function () use ($root): void {
            $this->assertTrue(EngineCoroutine::join([$root]));
        });

        Coroutine::create(function (): void {
            $child = Coroutine::create(static function (): void {
                Coroutine::sleep(0.01);
            });

            $this->assertTrue(EngineCoroutine::join([$child]));
        });
    }

    public function testWorkWithoutTimeLimitCompletes(): void
    {
        $this->assertTrue(Coroutine::sleep(0.01));
    }
}
