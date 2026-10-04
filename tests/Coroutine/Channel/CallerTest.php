<?php

declare(strict_types=1);

namespace Hypervel\Tests\Coroutine\Channel;

use Hypervel\Coroutine\Channel\Caller;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Exceptions\ChannelClosedException;
use Hypervel\Coroutine\Exceptions\WaitTimeoutException;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use stdClass;
use Swoole\Coroutine\CanceledException;
use Throwable;

use function Hypervel\Coroutine\go;

class CallerTest extends TestCase
{
    public function testCallerWithNull()
    {
        $caller = new Caller(static function () {
            return null;
        });

        $id = $caller->call(static function ($instance) {
            return 1;
        });

        $this->assertSame(1, $id);

        $id = $caller->call(static function ($instance) {
            return 2;
        });

        $this->assertSame(2, $id);
    }

    public function testCaller()
    {
        $obj = new stdClass;
        $obj->id = uniqid();
        $caller = new Caller(static function () use ($obj) {
            return $obj;
        });

        $id = $caller->call(static function ($instance) {
            return $instance->id;
        });

        $this->assertSame($obj->id, $id);

        $caller->call(function ($instance) use ($obj) {
            $this->assertSame($instance, $obj);
        });
    }

    public function testCallerPopTimeout()
    {
        $obj = new stdClass;
        $obj->id = uniqid();
        $caller = new Caller(static function () use ($obj) {
            return $obj;
        }, 0.001);

        go(static function () use ($caller) {
            $caller->call(static function ($instance) {
                usleep(10 * 1000);
            });
        });

        $this->expectException(WaitTimeoutException::class);

        $caller->call(static function ($instance) {
            return 1;
        });
    }

    public function testPooledFalseStaysUsableAfterAnotherCallTimesOut(): void
    {
        $caller = new Caller(static fn (): bool => false, 0.001);
        $release = new Channel(1);
        $held = new Channel(1);
        $calls = 0;

        $holderId = go(static function () use ($caller, $release, $held): void {
            $held->push(['value' => $caller->call(static function (bool $instance) use ($release): bool {
                $release->pop();

                return $instance;
            })]);
        });

        try {
            try {
                $caller->call(static function () use (&$calls): void {
                    ++$calls;
                });
                $this->fail('Expected the call to time out while the pooled value is held.');
            } catch (WaitTimeoutException) {
            }

            $release->push(true);

            $this->assertSame(['value' => false], $held->pop(1));
            $this->assertSame(0, $calls);

            // A successful pop of false must not be mistaken for the earlier timeout.
            $this->assertSame(['ran' => false], $caller->call(static fn (bool $instance): array => ['ran' => $instance]));
            $this->assertSame(['ran' => false], $caller->call(static fn (bool $instance): array => ['ran' => $instance]));
        } finally {
            $release->push(true, 0.001);

            if (Coroutine::exists($holderId)) {
                EngineCoroutine::cancelById($holderId, throwException: true);
                Coroutine::join([$holderId], 1);
            }
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testCanceledWaiterRunsNoClosureAndLeavesTheInstanceUsable(bool $throwException): void
    {
        $instance = new stdClass;
        $caller = new Caller(static fn (): stdClass => $instance);
        $release = new Channel(1);
        $held = new Channel(1);
        $canceled = new Channel(1);
        $calls = 0;

        $holderId = go(static function () use ($caller, $release, $held): void {
            $held->push($caller->call(static function (stdClass $current) use ($release): stdClass {
                $release->pop();

                return $current;
            }));
        });

        $waiterId = go(static function () use ($caller, $canceled, &$calls): void {
            try {
                $caller->call(static function () use (&$calls): void {
                    ++$calls;
                });
            } catch (CanceledException $exception) {
                $canceled->push($exception);
            }
        });

        try {
            $this->assertTrue(EngineCoroutine::cancelById($waiterId, $throwException));
            $this->assertInstanceOf(CanceledException::class, $canceled->pop(1));

            $release->push(true);

            $this->assertSame($instance, $held->pop(1));
            $this->assertSame(0, $calls);
            $this->assertSame($instance, $caller->call(static fn (stdClass $current): stdClass => $current));
        } finally {
            $release->push(true, 0.001);

            foreach ([$holderId, $waiterId] as $coroutineId) {
                if (Coroutine::exists($coroutineId)) {
                    EngineCoroutine::cancelById($coroutineId, throwException: true);
                    Coroutine::join([$coroutineId], 1);
                }
            }
        }
    }

    #[DataProvider('closureFailures')]
    public function testAcquiredInstanceIsReturnedWhenTheClosureThrows(Throwable $failure): void
    {
        $instance = new stdClass;
        $caller = new Caller(static fn (): stdClass => $instance, 0.01);

        try {
            $caller->call(static fn (): never => throw $failure);
            $this->fail('Expected the closure failure to propagate.');
        } catch (ChannelClosedException|WaitTimeoutException $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame($instance, $caller->call(static fn (stdClass $current): stdClass => $current));
    }

    public static function closureFailures(): array
    {
        return [
            'wait timeout' => [new WaitTimeoutException('Another wait timed out.')],
            'closed channel' => [new ChannelClosedException('Another channel was closed.')],
        ];
    }

    public function testFailedReinitializationPreservesTheCurrentInstance(): void
    {
        $instance = new stdClass;
        $attempts = 0;
        $caller = new Caller(static function () use ($instance, &$attempts): stdClass {
            if (++$attempts > 1) {
                throw new RuntimeException('Unable to create the replacement instance.');
            }

            return $instance;
        });

        try {
            $caller->initInstance();
            $this->fail('Expected replacement creation to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unable to create the replacement instance.', $exception->getMessage());
        }

        $this->assertSame(
            $instance,
            $caller->call(static fn (stdClass $current): stdClass => $current),
        );
    }
}
