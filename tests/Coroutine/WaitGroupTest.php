<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Hypervel\Coroutine\WaitGroup;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Tests\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\CanceledException;

class WaitGroupTest extends TestCase
{
    public function testWaitAgain()
    {
        $wg = new WaitGroup;
        $wg->add(2);
        $result = [];
        $i = 2;
        while ($i--) {
            Coroutine::create(function () use ($wg, &$result) {
                Coroutine::sleep(0.001);
                $result[] = true;
                $wg->done();
            });
        }
        $wg->wait(1);
        $this->assertTrue(count($result) === 2);

        $wg->add();
        $wg->add();
        $result = [];
        $i = 2;
        while ($i--) {
            Coroutine::create(function () use ($wg, &$result) {
                Coroutine::sleep(0.001);
                $result[] = true;
                $wg->done();
            });
        }
        $wg->wait(1);
        $this->assertTrue(count($result) === 2);
    }

    public function testWaitCanBeReusedAfterExactCancellation(): void
    {
        $waitGroup = new WaitGroup(1);
        $cancellation = null;

        $coroutine = EngineCoroutine::create(function () use ($waitGroup, &$cancellation): void {
            try {
                $waitGroup->wait();
            } catch (CanceledException $exception) {
                $cancellation = $exception;
            }
        });

        $this->assertTrue(EngineCoroutine::cancelById($coroutine->getId(), throwException: true));
        $this->assertInstanceOf(CanceledException::class, $cancellation);
        $this->assertFalse($waitGroup->wait(0.001));
    }

    public function testWaitConvertsNonThrowingCancellation(): void
    {
        $waitGroup = new WaitGroup(1);
        $cancellation = null;

        $coroutine = EngineCoroutine::create(function () use ($waitGroup, &$cancellation): void {
            try {
                $waitGroup->wait();
            } catch (CanceledException $exception) {
                $cancellation = $exception;
            }
        });

        $this->assertTrue(EngineCoroutine::cancelById($coroutine->getId()));
        $this->assertInstanceOf(CanceledException::class, $cancellation);
        $this->assertSame('Waiting for the wait group was canceled.', $cancellation->getMessage());
    }
}
