<?php

declare(strict_types=1);

namespace Hypervel\Tests\Engine;

use Hypervel\Contracts\Engine\ChannelInterface;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine;
use Hypervel\Tests\TestCase;
use stdClass;

class ChannelTest extends TestCase
{
    public function testChannelPushAndPop(): void
    {
        $result = [
            uniqid(),
            uniqid(),
            uniqid(),
        ];
        /** @var ChannelInterface $channel */
        $channel = new Channel(3);
        $this->assertSame(3, $channel->capacity());
        foreach ($result as $value) {
            $channel->push($value);
        }

        $this->assertSame(3, $channel->length());
        $actual[] = $channel->pop();
        $actual[] = $channel->pop();
        $actual[] = $channel->pop();

        $this->assertSame($result, $actual);
    }

    public function testChannelInCoroutine(): void
    {
        $id = uniqid();
        /** @var ChannelInterface $channel */
        $channel = new Channel(1);
        Coroutine::create(function () use ($channel, $id) {
            usleep(2000);
            $channel->push($id);
        });
        $t = microtime(true);
        $this->assertSame($id, $channel->pop());
        $this->assertTrue((microtime(true) - $t) > 0.001);
    }

    public function testChannelClose(): void
    {
        /** @var ChannelInterface $channel */
        $channel = new Channel;
        $this->assertFalse($channel->isClosed());
        Coroutine::create(function () use ($channel) {
            usleep(1000);
            $channel->close();
        });
        $this->assertFalse($channel->pop());
        $this->assertTrue($channel->isClosed());

        $channel = new Channel(1);
        Coroutine::create(function () use ($channel) {
            $channel->close();
        });
        $this->assertTrue($channel->isClosed());
    }

    public function testChannelCloseAgain(): void
    {
        /** @var ChannelInterface $channel */
        $channel = new Channel(1);
        $this->assertTrue($channel->close());
        $this->assertFalse($channel->close());

        $this->assertTrue($channel->isClosed());
    }

    public function testPushClosedChannel(): void
    {
        /** @var ChannelInterface $channel */
        $channel = new Channel(10);
        $channel->push(111);
        $channel->close();
        $this->assertFalse($channel->isEmpty());
        $this->assertFalse($channel->push(123));
        $this->assertTrue($channel->isClosed());
        $this->assertSame(111, $channel->pop());
        $this->assertSame(false, $channel->pop());
    }

    public function testWaitingProducerResumesWhenSpaceIsAvailable(): void
    {
        $channel = new Channel(1);
        $channel->push('first');

        try {
            Coroutine::create(static fn () => $channel->push('second', 1));

            $this->assertTrue($channel->hasWaitingProducers());
            $this->assertFalse($channel->hasWaitingConsumers());
            $this->assertSame('first', $channel->pop(1));
            $this->assertFalse($channel->hasWaitingProducers());
            $this->assertSame('second', $channel->pop(1));
        } finally {
            $channel->close();
        }
    }

    public function testClosingWakesWaitingConsumersInOrder(): void
    {
        $channel = new Channel;
        $snapshots = [];

        try {
            for ($index = 0; $index < 2; ++$index) {
                Coroutine::create(static function () use ($channel, &$snapshots): void {
                    $channel->pop(1);
                    $snapshots[] = [$channel->isClosed(), $channel->hasWaitingConsumers()];
                });
            }

            $this->assertTrue($channel->hasWaitingConsumers());
            $this->assertFalse($channel->hasWaitingProducers());
        } finally {
            $channel->close();
        }

        $this->assertSame([[true, true], [true, false]], $snapshots);
    }

    public function testChannelTimeout(): void
    {
        /** @var ChannelInterface $channel */
        $channel = new Channel(1);
        $channel->pop(0.001);
        $this->assertTrue($channel->timedOut());
    }

    public function testChannelCancellationIsReportedImmediately(): void
    {
        $channel = new Channel(1);
        $isCanceled = null;

        $coroutine = Coroutine::create(function () use ($channel, &$isCanceled): void {
            $this->assertFalse($channel->pop());
            $isCanceled = $channel->wasCanceled();
        });

        $this->assertTrue(Coroutine::cancelById($coroutine->getId()));
        $this->assertTrue($isCanceled);
    }

    public function testChannelPushTimeout(): void
    {
        /** @var ChannelInterface $channel */
        $channel = new Channel(1);
        $this->assertSame(true, $channel->push(1, 0.05));
        $this->assertSame(false, $channel->push(1, 0.05));
        $this->assertTrue($channel->timedOut());

        $this->assertFalse($channel->isClosed());
        $channel->close();
        $this->assertFalse($channel->push(1));
        $this->assertTrue($channel->isClosed());
        $this->assertFalse($channel->timedOut());
    }

    public function testSplId(): void
    {
        $object = new stdClass;
        $channel = new Channel(1);
        $channel->push($object);

        $this->assertSame(spl_object_id($object), spl_object_id($channel->pop()));
    }
}
