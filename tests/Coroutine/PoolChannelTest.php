<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\PoolChannel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use stdClass;
use Swoole\Coroutine as SwooleCoroutine;
use Swoole\Coroutine\CanceledException;
use Swoole\Event;

use function Hypervel\Coroutine\run;

class PoolChannelTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testObjectsAreVisibleAcrossExecutionModes(): void
    {
        $channel = new PoolChannel(2);
        $outsideObject = new stdClass;
        $insideObject = new stdClass;

        $channel->push($outsideObject);

        run(function () use ($channel, $outsideObject, $insideObject): void {
            $this->assertSame($outsideObject, $channel->pop());
            $channel->push($insideObject);
        });

        $this->assertSame($insideObject, $channel->pop());
    }

    public function testEmptyPopOutsideCoroutineReturnsFalse(): void
    {
        $this->assertFalse((new PoolChannel(1))->pop());
    }

    public function testCoroutineWaiterIsWokenByPush(): void
    {
        $channel = new PoolChannel(1);
        $object = new stdClass;

        run(function () use ($channel, $object): void {
            $result = null;

            Coroutine::create(function () use ($channel, &$result): void {
                $result = [$channel->wait(0.2), $channel->pop()];
            });

            usleep(5_000);
            $this->assertSame(1, $channel->waiters());
            $channel->push($object);
            usleep(5_000);

            $this->assertSame([true, $object], $result);
            $this->assertSame(0, $channel->waiters());
        });
    }

    public function testWaitTimesOut(): void
    {
        $channel = new PoolChannel(1);

        run(function () use ($channel): void {
            $this->assertFalse($channel->wait(0.001));
            $this->assertSame(0, $channel->waiters());
        });
    }

    public function testWaitConvertsNonThrowingCancellation(): void
    {
        $channel = new PoolChannel(1);

        run(function () use ($channel): void {
            $cancellation = null;
            $coroutine = EngineCoroutine::create(function () use ($channel, &$cancellation): void {
                try {
                    $channel->wait(1.0);
                } catch (CanceledException $exception) {
                    $cancellation = $exception;
                }
            });

            $this->assertTrue(EngineCoroutine::cancelById($coroutine->getId()));
            $this->assertInstanceOf(CanceledException::class, $cancellation);
            $this->assertSame('The pool wait was canceled.', $cancellation->getMessage());
            $this->assertSame(0, $channel->waiters());
        });
    }

    public function testSignalNeverBlocksWhenWakeIsAlreadyPending(): void
    {
        $channel = new FullSignalPoolChannel;

        run(function () use ($channel): void {
            $channel->fillSignal();
            $completed = false;

            Coroutine::create(function () use ($channel, &$completed): void {
                $channel->signal();
                $completed = true;
            });

            usleep(5_000);

            $this->assertTrue($completed);
            $channel->drainSignal();
        });
    }

    public function testCloseWakesEveryWaiter(): void
    {
        $channel = new PoolChannel(2);

        run(function () use ($channel): void {
            $results = [];

            foreach ([0, 1] as $index) {
                Coroutine::create(function () use ($channel, &$results, $index): void {
                    $results[$index] = $channel->wait(0.2);
                });
            }

            usleep(5_000);
            $channel->close();
            usleep(5_000);

            ksort($results);
            $this->assertSame([true, true], $results);
            $this->assertSame(0, $channel->waiters());
        });
    }

    public function testCloseIsIdempotentAndLaterSignalOperationsUseLocalState(): void
    {
        $channel = new PoolChannel(1);

        $channel->close();
        $channel->close();
        $channel->signal();

        $this->assertTrue($channel->wait(0.001));
    }

    public function testPushAfterCloseIsRejectedWithoutRetainingTheObject(): void
    {
        $channel = new PoolChannel(1);

        $channel->close();

        $this->assertFalse($channel->push(new stdClass));
        $this->assertSame(0, $channel->length());
        $this->assertFalse($channel->pop());
    }

    public function testCloseRetainsQueuedObjectsForFifoDraining(): void
    {
        $channel = new PoolChannel(2);
        $first = new stdClass;
        $second = new stdClass;

        $channel->push($first);
        $channel->push($second);
        $channel->close();

        $this->assertSame(2, $channel->length());
        $this->assertSame($first, $channel->pop());
        $this->assertSame($second, $channel->pop());
        $this->assertFalse($channel->pop());
        $this->assertSame(0, $channel->length());
    }

    #[RunInSeparateProcess]
    public function testOutsideCoroutinePushCommitsWhenAWakeCoroutineCannotBeCreated(): void
    {
        SwooleCoroutine::set(['max_coroutine' => 1]);
        $channel = new PoolChannel(1);
        $waitResult = null;
        $object = new stdClass;

        SwooleCoroutine::create(function () use ($channel, &$waitResult): void {
            $waitResult = $channel->wait(1.0);
        });

        $this->assertTrue($channel->push($object));
        $this->assertSame($object, $channel->pop());

        $channel->close();
        Event::wait();

        $this->assertTrue($waitResult);
    }
}

class FullSignalPoolChannel extends PoolChannel
{
    public function __construct()
    {
        parent::__construct(1);
    }

    public function fillSignal(): void
    {
        $this->waiters = 1;
        $this->signal->push(true);
    }

    public function drainSignal(): void
    {
        $this->signal->pop(0.001);
        $this->waiters = 0;
    }
}
