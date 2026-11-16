<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Exception;
use Hypervel\Support\Timebox;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class SupportTimeboxTest extends TestCase
{
    use VerifiesDoubles;

    public function testMakeExecutesCallback(): void
    {
        $callback = function (): void {
            $this->assertTrue(true);
        };

        (new Timebox)->call($callback, 0);
    }

    public function testMakeWaitsForMicroseconds(): void
    {
        $mock = Double::for(Timebox::class)->passthru();
        $mock->expects('usleep');

        $mock->call(function (): void {
        }, 10000);

        $mock->received('usleep')->times(1);
    }

    public function testMakeShouldNotSleepWhenEarlyReturnHasBeenFlagged(): void
    {
        $mock = Double::for(Timebox::class)->passthru();
        $mock->call(function (Timebox $timebox): void {
            $timebox->returnEarly();
        }, 10000);

        $mock->received('usleep')->never();
    }

    public function testMakeShouldSleepWhenDontEarlyReturnHasBeenFlagged(): void
    {
        $mock = Double::for(Timebox::class)->passthru();
        $mock->expects('usleep');

        $mock->call(function (Timebox $timebox): void {
            $timebox->returnEarly();
            $timebox->dontReturnEarly();
        }, 10000);

        $mock->received('usleep')->times(1);
    }

    public function testMakeWaitsForMicrosecondsWhenExceptionIsThrown(): void
    {
        $mock = Double::for(Timebox::class)->passthru();
        $mock->expects('usleep');

        try {
            $this->expectExceptionObject(new Exception('Exception within Timebox callback.'));

            $mock->call(function (): never {
                throw new Exception('Exception within Timebox callback.');
            }, 10000);
        } finally {
            $mock->received('usleep')->times(1);
        }
    }

    public function testMakeShouldNotSleepWhenEarlyReturnHasBeenFlaggedAndExceptionIsThrown(): void
    {
        $mock = Double::for(Timebox::class)->passthru();

        try {
            $this->expectExceptionObject(new Exception('Exception within Timebox callback.'));

            $mock->call(function (Timebox $timebox): never {
                $timebox->returnEarly();
                throw new Exception('Exception within Timebox callback.');
            }, 10000);
        } finally {
            $mock->received('usleep')->never();
        }
    }

    #[TestWith([false], 'direct cancellation')]
    #[TestWith([true], 'cleanup wraps cancellation')]
    public function testMakeDoesNotPadCancellationFromCallback(bool $wrapped): void
    {
        $cancellation = new CanceledException('canceled');

        if ($wrapped) {
            $cancellation = new RuntimeException('Callback cleanup failed.', previous: $cancellation);
        }

        $mock = Double::for(Timebox::class)->passthru();

        try {
            $mock->call(fn () => throw $cancellation, 10000);

            $this->fail('The cancellation was not preserved.');
        } catch (Throwable $exception) {
            $this->assertSame($cancellation, $exception);
        } finally {
            $mock->received('usleep')->never();
        }
    }
}
