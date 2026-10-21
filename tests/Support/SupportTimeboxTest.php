<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Exception;
use Hypervel\Support\Timebox;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Swoole\Coroutine\CanceledException;

class SupportTimeboxTest extends TestCase
{
    public function testMakeExecutesCallback(): void
    {
        $callback = function (): void {
            $this->assertTrue(true);
        };

        (new Timebox)->call($callback, 0);
    }

    public function testMakeWaitsForMicroseconds(): void
    {
        $mock = m::spy(Timebox::class)->shouldAllowMockingProtectedMethods()->makePartial();
        $mock->expects('usleep');

        $mock->call(function (): void {
        }, 10000);

        $mock->shouldHaveReceived('usleep')->once();
    }

    public function testMakeShouldNotSleepWhenEarlyReturnHasBeenFlagged(): void
    {
        $mock = m::spy(Timebox::class)->shouldAllowMockingProtectedMethods()->makePartial();
        $mock->call(function (Timebox $timebox): void {
            $timebox->returnEarly();
        }, 10000);

        $mock->shouldNotHaveReceived('usleep');
    }

    public function testMakeShouldSleepWhenDontEarlyReturnHasBeenFlagged(): void
    {
        $mock = m::spy(Timebox::class)->shouldAllowMockingProtectedMethods()->makePartial();
        $mock->expects('usleep');

        $mock->call(function (Timebox $timebox): void {
            $timebox->returnEarly();
            $timebox->dontReturnEarly();
        }, 10000);

        $mock->shouldHaveReceived('usleep')->once();
    }

    public function testMakeWaitsForMicrosecondsWhenExceptionIsThrown(): void
    {
        $mock = m::spy(Timebox::class)->shouldAllowMockingProtectedMethods()->makePartial();
        $mock->expects('usleep');

        try {
            $this->expectExceptionObject(new Exception('Exception within Timebox callback.'));

            $mock->call(function (): never {
                throw new Exception('Exception within Timebox callback.');
            }, 10000);
        } finally {
            $mock->shouldHaveReceived('usleep')->once();
        }
    }

    public function testMakeShouldNotSleepWhenEarlyReturnHasBeenFlaggedAndExceptionIsThrown(): void
    {
        $mock = m::spy(Timebox::class)->shouldAllowMockingProtectedMethods()->makePartial();

        try {
            $this->expectExceptionObject(new Exception('Exception within Timebox callback.'));

            $mock->call(function (Timebox $timebox): never {
                $timebox->returnEarly();
                throw new Exception('Exception within Timebox callback.');
            }, 10000);
        } finally {
            $mock->shouldNotHaveReceived('usleep');
        }
    }

    public function testMakeDoesNotPadCancellationFromCallback(): void
    {
        $cancellation = new CanceledException('canceled');
        $mock = m::spy(Timebox::class)->shouldAllowMockingProtectedMethods()->makePartial();

        try {
            $mock->call(fn () => throw $cancellation, 10000);

            $this->fail('The cancellation was not preserved.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        } finally {
            $mock->shouldNotHaveReceived('usleep');
        }
    }
}
