<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Hypervel\Coroutine\Mutex;
use Hypervel\Coroutine\WaitGroup;
use Hypervel\Engine\Channel;
use Hypervel\Tests\TestCase;
use ReflectionProperty;

use function Hypervel\Coroutine\go;

class MutexTest extends TestCase
{
    public function testMutexLock(): void
    {
        $chan = new Channel(5);
        $func = function (string $value) use ($chan): void {
            if (Mutex::lock('test')) {
                try {
                    usleep(1000);
                    $chan->push($value);
                } finally {
                    Mutex::unlock('test');
                }
            }
        };

        $wg = new WaitGroup(5);
        foreach (['h', 'e', 'l', 'l', 'o'] as $value) {
            go(function () use ($func, $value, $wg) {
                $func($value);
                $wg->done();
            });
        }

        $res = '';
        $wg->wait(1);
        for ($i = 0; $i < 5; ++$i) {
            $res .= $chan->pop(1);
        }

        $this->assertSame('hello', $res);
    }

    public function testLockReturnsTheNativePushResult(): void
    {
        $channel = new RejectingMutexChannel(1);
        $this->publishChannel('rejected', $channel);

        $this->assertFalse(Mutex::lock('rejected'));
        $this->assertSame($channel, $this->channels()['rejected']);
    }

    public function testManyUncontendedMutexesReleaseTheirChannels(): void
    {
        for ($index = 0; $index < 100; ++$index) {
            $key = 'mutex-' . $index;

            $this->assertTrue(Mutex::lock($key));
            $this->assertTrue(Mutex::unlock($key));
        }

        $this->assertSame([], $this->channels());
    }

    public function testInvalidUnlocksFailWithoutPoppingAnEmptyChannel(): void
    {
        $this->assertFalse(Mutex::unlock('absent'));

        $channel = new EmptyMutexChannel(1);
        $this->publishChannel('empty', $channel);

        $this->assertFalse(Mutex::unlock('empty'));
        $this->assertSame(0, $channel->popCalls);

        $this->assertTrue(Mutex::lock('double'));
        $this->assertTrue(Mutex::unlock('double'));
        $this->assertFalse(Mutex::unlock('double'));
    }

    public function testFailedPopRetainsTheHeldChannel(): void
    {
        $channel = new FailingPopMutexChannel(1);
        $channel->push(1);
        $this->publishChannel('held', $channel);

        $this->assertFalse(Mutex::unlock('held'));
        $this->assertSame($channel, $this->channels()['held']);
    }

    public function testContendedUnlockHandsThePublishedChannelToTheWaiter(): void
    {
        $this->assertTrue(Mutex::lock('contended'));
        $channel = $this->channels()['contended'];
        $waiterStarted = new Channel(1);
        $waiterAcquired = new Channel(1);
        $releaseWaiter = new Channel(1);
        $waiterReleased = new Channel(1);

        go(static function () use ($waiterStarted, $waiterAcquired, $releaseWaiter, $waiterReleased): void {
            $waiterStarted->push(true);
            $waiterAcquired->push(Mutex::lock('contended'));
            $releaseWaiter->pop();
            $waiterReleased->push(Mutex::unlock('contended'));
        });

        $this->assertTrue($waiterStarted->pop(1));
        $this->assertTrue(Mutex::unlock('contended'));
        $this->assertTrue($waiterAcquired->pop(1));
        $this->assertSame($channel, $this->channels()['contended']);

        $releaseWaiter->push(true);

        $this->assertTrue($waiterReleased->pop(1));
        $this->assertArrayNotHasKey('contended', $this->channels());
    }

    public function testTimedOutWaiterLeavesTheOwnersChannelIntact(): void
    {
        $this->assertTrue(Mutex::lock('timeout'));
        $channel = $this->channels()['timeout'];
        $waiterResult = new Channel(1);

        go(static function () use ($waiterResult): void {
            $waiterResult->push(Mutex::lock('timeout', 0.001));
        });

        $this->assertFalse($waiterResult->pop(1));
        $this->assertSame($channel, $this->channels()['timeout']);
        $this->assertTrue(Mutex::unlock('timeout'));
        $this->assertArrayNotHasKey('timeout', $this->channels());
    }

    public function testOlderUnlockDoesNotRemoveAReplacementChannel(): void
    {
        $channel = new ReplacingMutexChannel('replaced');
        $channel->push(1);
        $this->publishChannel('replaced', $channel);

        $this->assertTrue(Mutex::unlock('replaced'));

        $replacement = $this->channels()['replaced'];
        $this->assertNotSame($channel, $replacement);
        $this->assertSame(1, $replacement->getLength());
        $this->assertTrue(Mutex::unlock('replaced'));
        $this->assertArrayNotHasKey('replaced', $this->channels());
    }

    public function testFlushStateReleasesAbandonedLock(): void
    {
        try {
            $this->assertTrue(Mutex::lock('held'));

            Mutex::flushState();

            $this->assertTrue(Mutex::lock('held', 0.001));
        } finally {
            Mutex::flushState();
        }
    }

    public function testClearCancelsABlockedAcquisition(): void
    {
        $this->assertTrue(Mutex::lock('blocked'));
        $waiterStarted = new Channel(1);
        $waiterResult = new Channel(1);

        go(static function () use ($waiterStarted, $waiterResult): void {
            $waiterStarted->push(true);
            $waiterResult->push(Mutex::lock('blocked'));
        });

        $this->assertTrue($waiterStarted->pop(1));

        Mutex::clear('blocked');

        $this->assertFalse($waiterResult->pop(1));
        $this->assertArrayNotHasKey('blocked', $this->channels());
    }

    public function testFlushStateCancelsBlockedAcquisitions(): void
    {
        $this->assertTrue(Mutex::lock('first'));
        $this->assertTrue(Mutex::lock('second'));
        $waitersStarted = new Channel(2);
        $waiterResults = new Channel(2);

        foreach (['first', 'second'] as $key) {
            go(static function () use ($key, $waitersStarted, $waiterResults): void {
                $waitersStarted->push(true);
                $waiterResults->push(Mutex::lock($key));
            });
        }

        $this->assertTrue($waitersStarted->pop(1));
        $this->assertTrue($waitersStarted->pop(1));

        Mutex::flushState();

        $this->assertFalse($waiterResults->pop(1));
        $this->assertFalse($waiterResults->pop(1));
        $this->assertSame([], $this->channels());
    }

    public function testClearRemovesReleasedKeys(): void
    {
        try {
            $this->assertTrue(Mutex::lock('dynamic'));

            Mutex::clear('dynamic');

            $this->assertArrayNotHasKey(
                'dynamic',
                (new ReflectionProperty(Mutex::class, 'channels'))->getValue(),
            );
        } finally {
            Mutex::flushState();
        }
    }

    /**
     * @return array<string, Channel>
     */
    private function channels(): array
    {
        return (new ReflectionProperty(Mutex::class, 'channels'))->getValue();
    }

    private function publishChannel(string $key, Channel $channel): void
    {
        $channels = $this->channels();
        $channels[$key] = $channel;

        (new ReflectionProperty(Mutex::class, 'channels'))->setValue(null, $channels);
    }
}

class RejectingMutexChannel extends Channel
{
    public function push(mixed $data, float $timeout = -1): bool
    {
        return false;
    }
}

class EmptyMutexChannel extends Channel
{
    public int $popCalls = 0;

    public function pop(float $timeout = -1): mixed
    {
        ++$this->popCalls;

        return parent::pop($timeout);
    }
}

class FailingPopMutexChannel extends Channel
{
    public function pop(float $timeout = -1): mixed
    {
        return false;
    }
}

class ReplacingMutexChannel extends Channel
{
    public function __construct(private readonly string $key)
    {
        parent::__construct(1);
    }

    public function pop(float $timeout = -1): mixed
    {
        $value = parent::pop($timeout);

        Mutex::clear($this->key);
        Mutex::lock($this->key);

        return $value;
    }
}
