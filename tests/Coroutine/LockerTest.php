<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine;

use Hypervel\Coroutine\Exceptions\WaitTimeoutException;
use Hypervel\Coroutine\Locker;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Tests\TestCase;
use ReflectionProperty;
use Swoole\Coroutine\CanceledException;

use function Hypervel\Coroutine\go;

class LockerTest extends TestCase
{
    public function testLockAndUnlock(): void
    {
        $chan = new Channel(10);
        go(function () use ($chan) {
            Locker::lock('foo');
            $chan->push(1);
            usleep(10000);
            $chan->push(2);
            Locker::unlock('foo');
        });

        go(function () use ($chan) {
            Locker::lock('foo');
            $chan->push(3);
            usleep(10000);
            $chan->push(4);
        });

        go(function () use ($chan) {
            Locker::lock('foo');
            $chan->push(5);
            $chan->push(6);
        });

        $ret = [];
        for ($i = 0; $i < 6; ++$i) {
            $res = $chan->pop(0.1);
            $this->assertNotFalse($res);
            $ret[] = $res;
        }

        $this->assertSame([1, 2, 3, 5, 6, 4], $ret);
    }

    public function testTimedOutWaiterThrowsAndAnUnlockStillWakesTheRemainingWaiters(): void
    {
        try {
            $this->assertTrue(Locker::lock('timed'));

            $results = new Channel(2);
            go(function () use ($results): void {
                try {
                    Locker::lock('timed', 0.01);
                } catch (WaitTimeoutException $exception) {
                    $results->push($exception);
                }
            });
            go(function () use ($results): void {
                $results->push(['waiter' => Locker::lock('timed')]);
            });

            $this->assertInstanceOf(WaitTimeoutException::class, $results->pop(1));
            $this->assertTrue($results->isEmpty());

            Locker::unlock('timed');

            $this->assertSame(['waiter' => false], $results->pop(1));
        } finally {
            Locker::flushState();
        }
    }

    public function testCanceledWaiterThrowsWhileTheOwnerKeepsTheLock(): void
    {
        try {
            $this->assertTrue(Locker::lock('canceled'));

            $results = new Channel(1);
            $waiterId = go(function () use ($results): void {
                try {
                    Locker::lock('canceled');
                } catch (CanceledException $exception) {
                    $results->push($exception);
                }
            });

            $this->assertTrue(EngineCoroutine::cancelById($waiterId));
            $this->assertInstanceOf(CanceledException::class, $results->pop(1));

            Locker::unlock('canceled');

            $this->assertTrue(Locker::lock('canceled'));
        } finally {
            Locker::flushState();
        }
    }

    public function testWaitersRelockWhenTheOwnerLeftNoResultAndOneBecomesTheNextOwner(): void
    {
        try {
            $this->assertTrue(Locker::lock('retry'));

            $result = null;
            $owners = 0;
            $finished = new Channel(2);
            $resolve = function () use (&$result, &$owners, $finished): void {
                while ($result === null) {
                    if (Locker::lock('retry')) {
                        try {
                            ++$owners;
                            usleep(1000);
                            $result = 'resolved';
                        } finally {
                            Locker::unlock('retry');
                        }
                    }
                }

                $finished->push(true);
            };
            go($resolve);
            go($resolve);

            // The first owner fails and releases the lock without leaving a result.
            Locker::unlock('retry');

            $this->assertTrue($finished->pop(1));
            $this->assertTrue($finished->pop(1));
            $this->assertSame(1, $owners);
            $this->assertSame('resolved', $result);
        } finally {
            Locker::flushState();
        }
    }

    public function testFlushStateReleasesAbandonedLock(): void
    {
        try {
            $this->assertTrue(Locker::lock('held'));

            Locker::flushState();

            $this->assertTrue(Locker::lock('held'));
        } finally {
            Locker::flushState();
        }
    }

    public function testUnlockRemovesReleasedKeys(): void
    {
        try {
            $this->assertTrue(Locker::lock('dynamic'));

            Locker::unlock('dynamic');

            $this->assertArrayNotHasKey(
                'dynamic',
                (new ReflectionProperty(Locker::class, 'channels'))->getValue(),
            );
        } finally {
            Locker::flushState();
        }
    }
}
