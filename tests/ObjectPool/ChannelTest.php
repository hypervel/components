<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Hypervel\Coroutine\Coroutine;
use Hypervel\ObjectPool\Channel;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use stdClass;
use Swoole\Coroutine as SwooleCoroutine;
use Swoole\Event;

use function Hypervel\Coroutine\run;

class ChannelTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testObjectsAreVisibleAcrossExecutionModes(): void
    {
        $channel = new Channel(2);
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
        $this->assertFalse((new Channel(1))->pop());
    }

    public function testCoroutineWaiterIsWokenByPush(): void
    {
        $channel = new Channel(1);
        $object = new stdClass;

        run(function () use ($channel, $object): void {
            $result = null;

            Coroutine::create(function () use ($channel, &$result): void {
                $result = [$channel->wait(0.2), $channel->pop()];
            });

            usleep(5_000);
            $channel->push($object);
            usleep(5_000);

            $this->assertSame([true, $object], $result);
        });
    }

    public function testWaitTimesOut(): void
    {
        $channel = new Channel(1);

        run(function () use ($channel): void {
            $this->assertFalse($channel->wait(0.001));
        });
    }

    public function testSignalNeverBlocksWhenWakeIsAlreadyPending(): void
    {
        $channel = new FullSignalObjectPoolChannel;

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
        $channel = new Channel(2);

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
        });
    }

    public function testCloseIsIdempotentAndLaterSignalOperationsUseLocalState(): void
    {
        $channel = new Channel(1);

        $channel->close();
        $channel->close();
        $channel->signal();

        $this->assertTrue($channel->wait(0.001));
    }

    public function testPushAfterCloseIsRejectedWithoutRetainingTheObject(): void
    {
        $channel = new Channel(1);

        $channel->close();

        $this->assertFalse($channel->push(new stdClass));
        $this->assertSame(0, $channel->length());
        $this->assertFalse($channel->pop());
    }

    #[RunInSeparateProcess]
    public function testOutsideCoroutinePushCommitsWhenAWakeCoroutineCannotBeCreated(): void
    {
        SwooleCoroutine::set(['max_coroutine' => 1]);
        $channel = new Channel(1);
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

class FullSignalObjectPoolChannel extends Channel
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
