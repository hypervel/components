<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pool;

use Hypervel\Contracts\Pool\ConnectionInterface;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Pool\Channel;
use Hypervel\Tests\TestCase;
use Mockery as m;

use function Hypervel\Coroutine\run;

class ChannelTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testConnectionsAreVisibleAcrossExecutionModes(): void
    {
        $channel = new Channel(2);
        $outsideConnection = m::mock(ConnectionInterface::class);
        $insideConnection = m::mock(ConnectionInterface::class);

        $channel->push($outsideConnection);

        run(function () use ($channel, $outsideConnection, $insideConnection): void {
            $this->assertSame($outsideConnection, $channel->pop());
            $channel->push($insideConnection);
        });

        $this->assertSame($insideConnection, $channel->pop());
    }

    public function testEmptyPopOutsideCoroutineReturnsFalse(): void
    {
        $this->assertFalse((new Channel(1))->pop());
    }

    public function testCoroutineWaiterIsWokenByPush(): void
    {
        $channel = new Channel(1);
        $connection = m::mock(ConnectionInterface::class);

        run(function () use ($channel, $connection): void {
            $result = null;

            Coroutine::create(function () use ($channel, &$result): void {
                $result = [$channel->wait(0.2), $channel->pop()];
            });

            usleep(5_000);
            $channel->push($connection);
            usleep(5_000);

            $this->assertSame([true, $connection], $result);
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
        $channel = new FullSignalConnectionPoolChannel;

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

    public function testPushAfterCloseIsRejectedWithoutRetainingTheConnection(): void
    {
        $channel = new Channel(1);

        $channel->close();

        $this->assertFalse($channel->push(m::mock(ConnectionInterface::class)));
        $this->assertSame(0, $channel->length());
        $this->assertFalse($channel->pop());
    }
}

class FullSignalConnectionPoolChannel extends Channel
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
