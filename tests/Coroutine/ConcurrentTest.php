<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Exception;
use Hypervel\Container\Container;
use Hypervel\Coroutine\Concurrent;
use Hypervel\Tests\TestCase;
use Swoole\Coroutine;

class ConcurrentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->getContainer();
    }

    public function testConcurrent()
    {
        $concurrent = new Concurrent($limit = 10);
        $this->assertSame($limit, $concurrent->getLimit());
        $this->assertTrue($concurrent->isEmpty());
        $this->assertFalse($concurrent->isFull());

        $count = 0;
        for ($i = 0; $i < 15; ++$i) {
            $concurrent->create(function () use (&$count) {
                Coroutine::sleep(0.05);
                ++$count;
            });
        }

        $this->assertTrue($concurrent->isFull());
        $this->assertSame(5, $count);
        $this->assertSame($limit, $concurrent->getRunningCoroutineCount());

        while (! $concurrent->isEmpty()) {
            Coroutine::sleep(0.01);
        }

        $this->assertSame(15, $count);
    }

    public function testException()
    {
        $con = new Concurrent(10);
        $count = 0;

        for ($i = 0; $i < 15; ++$i) {
            $con->create(function () use (&$count) {
                Coroutine::sleep(0.05);
                ++$count;
                throw new Exception('ddd');
            });
        }

        $this->assertSame(5, $count);
        $this->assertSame(10, $con->getRunningCoroutineCount());

        while (! $con->isEmpty()) {
            Coroutine::sleep(0.01);
        }
        $this->assertSame(15, $count);
    }

    public function testWaitForAvailableSlotWakesWhenARunningCoroutineFinishes(): void
    {
        $concurrent = new Concurrent(1);
        $finished = false;

        $concurrent->create(function () use (&$finished): void {
            Coroutine::sleep(0.01);
            $finished = true;
        });

        $this->assertTrue($concurrent->isFull());
        $this->assertTrue($concurrent->waitForAvailableSlot(1));
        $this->assertTrue($finished);
        $this->assertTrue($concurrent->isEmpty());
    }

    public function testWaitForAvailableSlotReturnsFalseAfterTimeout(): void
    {
        $concurrent = new Concurrent(1);

        $concurrent->create(static function (): void {
            Coroutine::sleep(0.2);
        });

        $this->assertTrue($concurrent->isFull());
        $this->assertFalse($concurrent->waitForAvailableSlot(0.01));
        $this->assertSame(1, $concurrent->getRunningCoroutineCount());

        while (! $concurrent->isEmpty()) {
            Coroutine::sleep(0.01);
        }
    }

    protected function getContainer(): void
    {
        Container::setInstance(new Container);
    }
}
