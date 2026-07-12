<?php

declare(strict_types=1);

namespace Hypervel\Tests\Engine;

use ArrayObject;
use Hypervel\Contracts\Engine\CoroutineInterface;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Engine\Exceptions\CoroutineDestroyedException;
use Hypervel\Engine\Exceptions\RuntimeException;
use Hypervel\Tests\TestCase;
use Swoole\Coroutine\CanceledException;
use Throwable;

class CoroutineTest extends TestCase
{
    public function testCoroutineIdRequiresExecution(): void
    {
        $coroutine = new Coroutine(fn () => null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Coroutine has not been executed.');

        $coroutine->getId();
    }

    public function testCoroutineCreate(): void
    {
        $coroutine = new Coroutine(function () {
            $this->assertTrue(true);
        });

        $coroutine->execute();

        $this->assertInstanceOf(CoroutineInterface::class, $coroutine);
        $this->assertIsInt($coroutine->getId());
    }

    public function testCoroutineCreateStatic(): void
    {
        $coroutine = Coroutine::create(function () {
            $this->assertTrue(true);
        });

        $this->assertInstanceOf(CoroutineInterface::class, $coroutine);
        $this->assertIsInt($coroutine->getId());
    }

    public function testCoroutineContext(): void
    {
        $id = uniqid();
        $coroutine = Coroutine::create(function () use ($id) {
            $this->assertInstanceOf(ArrayObject::class, Coroutine::getContextFor());
            $this->assertFalse(isset(Coroutine::getContextFor()['name']));
            $this->assertSame(null, Coroutine::getContextFor()['name'] ?? null);
            Coroutine::getContextFor()['name'] = $id;
            $this->assertSame($id, Coroutine::getContextFor()['name']);
            usleep(1000);
        });

        $this->assertSame($id, Coroutine::getContextFor($coroutine->getId())['name']);

        usleep(1000);
        $this->assertNull(Coroutine::getContextFor($coroutine->getId()));
    }

    public function testCoroutineId(): void
    {
        $this->assertIsInt($id = Coroutine::id());
        $this->assertGreaterThan(0, $id);
    }

    public function testCoroutinePid(): void
    {
        $pid = Coroutine::id();
        Coroutine::create(function () use ($pid) {
            $this->assertSame($pid, Coroutine::pid());
            $pid = Coroutine::id();
            $co = Coroutine::create(function () use ($pid) {
                $this->assertSame($pid, Coroutine::pid(Coroutine::id()));
                usleep(1000);
            });
            Coroutine::create(function () use ($pid) {
                $this->assertSame($pid, Coroutine::pid());
            });
            $this->assertSame($pid, Coroutine::pid($co->getId()));
        });
    }

    public function testCoroutinePidHasBeenDestroyed(): void
    {
        $co = Coroutine::create(function () {
        });

        try {
            Coroutine::pid($co->getId());
            $this->assertTrue(false);
        } catch (Throwable $exception) {
            $this->assertInstanceOf(CoroutineDestroyedException::class, $exception);
        }
    }

    public function testCoroutineInTopCoroutine(): void
    {
        $this->assertSame(0, Coroutine::pid());
    }

    public function testCoroutineDefer(): void
    {
        $channel = new Channel(2);
        Coroutine::create(function () use ($channel) {
            Coroutine::defer(function () use ($channel) {
                $channel->push(2);
            });

            $channel->push(1);
        });

        $this->assertSame(1, $channel->pop());
        $this->assertSame(2, $channel->pop());
    }

    public function testTheOrderForCoroutineDefer(): void
    {
        $channel = new Channel(3);
        Coroutine::create(function () use ($channel) {
            Coroutine::defer(function () use ($channel) {
                $channel->push(2);
            });
            Coroutine::defer(function () use ($channel) {
                $channel->push(3);
            });

            $channel->push(1);
        });

        $this->assertSame(1, $channel->pop());
        $this->assertSame(3, $channel->pop());
        $this->assertSame(2, $channel->pop());
    }

    public function testCoroutineResumeById(): void
    {
        $channel = new Channel(10);
        Coroutine::create(function () use ($channel) {
            $channel->push(1);
            $co = Coroutine::create(function () use ($channel) {
                $channel->push(2);
                Coroutine::yield();
                $channel->push(3);
            });
            $channel->push(4);
            $res = Coroutine::resumeById($co->getId());
            $channel->push(5);
        });

        $this->assertSame(1, $channel->pop());
        $this->assertSame(2, $channel->pop());
        $this->assertSame(4, $channel->pop());
        $this->assertSame(3, $channel->pop());
        $this->assertSame(5, $channel->pop());
    }

    public function testCoroutineCancelById(): void
    {
        $channel = new Channel(2);
        $coroutine = Coroutine::create(function () use ($channel) {
            try {
                $channel->push(1);
                usleep(100000);
                $channel->push(2);
            } catch (CanceledException) {
                $channel->push('cancelled');
            }
        });

        $this->assertSame(1, $channel->pop());
        $this->assertTrue(Coroutine::exists($coroutine->getId()));
        $this->assertTrue(Coroutine::cancelById($coroutine->getId(), throwException: true));
        $this->assertFalse(Coroutine::exists($coroutine->getId()));
        $this->assertSame('cancelled', $channel->pop(0.01));
        $this->assertFalse($channel->pop(0.01));
    }

    public function testCoroutineList(): void
    {
        $list = Coroutine::list();
        $this->assertIsIterable($list);
        $this->assertContains(Coroutine::id(), $list);
    }

    public function testCoroutineListCount(): void
    {
        Coroutine::create(function () {
            usleep(100000);
        });
        Coroutine::create(function () {
            usleep(100000);
        });
        Coroutine::create(function () {
            usleep(100000);
        });
        $this->assertEquals(4, iterator_count(Coroutine::list()));
    }
}
