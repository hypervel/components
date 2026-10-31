<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Console\Scheduling;

use Exception;
use Hypervel\Console\Scheduling\CallbackEvent;
use Hypervel\Console\Scheduling\EventMutex;
use Hypervel\Support\Stringable;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Console\Fixtures\FakeEventMutex;

class CallbackEventTest extends TestCase
{
    private EventMutex $mutex;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->mutex = new FakeEventMutex;
    }

    public function testDefaultResultIsSuccess(): void
    {
        $success = null;

        $event = (new CallbackEvent($this->mutex, function (): void {
        }))->onSuccess(function () use (&$success): void {
            $success = true;
        })->onFailure(function () use (&$success): void {
            $success = false;
        });

        $event->run($this->app);

        $this->assertTrue($success);
    }

    public function testFalseResponseIsFailure(): void
    {
        $success = null;

        $event = (new CallbackEvent($this->mutex, function (): bool {
            return false;
        }))->onSuccess(function () use (&$success): void {
            $success = true;
        })->onFailure(function () use (&$success): void {
            $success = false;
        });

        $event->run($this->app);

        $this->assertFalse($success);
    }

    public function testExceptionIsFailure(): void
    {
        $success = null;

        $event = (new CallbackEvent($this->mutex, function (): never {
            throw new Exception;
        }))->onSuccess(function () use (&$success): void {
            $success = true;
        })->onFailure(function () use (&$success): void {
            $success = false;
        });

        try {
            $event->run($this->app);
        } catch (Exception) {
        }

        $this->assertFalse($success);
    }

    public function testExceptionBubbles(): void
    {
        $event = new CallbackEvent($this->mutex, function (): never {
            throw new Exception;
        });

        $this->expectException(Exception::class);

        $event->run($this->app);
    }

    public function testExceptionIsNotRethrownOnSubsequentRuns(): void
    {
        $runs = 0;
        $success = null;
        $failure = new Exception('First run failed.');

        $event = (new CallbackEvent($this->mutex, function () use (&$runs, $failure): string {
            if (++$runs === 1) {
                throw $failure;
            }

            return 'recovered';
        }))->onSuccess(function () use (&$success): void {
            $success = true;
        })->onFailure(function () use (&$success): void {
            $success = false;
        });

        try {
            $event->run($this->app);
            $this->fail('The first callback exception was not thrown.');
        } catch (Exception $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertFalse($success);

        $this->assertSame('recovered', $event->run($this->app));

        $this->assertSame(2, $runs);
        $this->assertTrue($success);
    }

    public function testOnSuccessCallbackCanReceiveEvent(): void
    {
        $callbackEvent = null;

        $event = (new CallbackEvent($this->mutex, function (): void {
        }))->onSuccess(function (CallbackEvent $event) use (&$callbackEvent): void {
            $callbackEvent = $event;
        });

        $event->run($this->app);

        $this->assertSame($event, $callbackEvent);
    }

    public function testOnFailureCallbackCanReceiveEvent(): void
    {
        $callbackEvent = null;

        $event = (new CallbackEvent($this->mutex, function (): bool {
            return false;
        }))->onFailure(function (CallbackEvent $event) use (&$callbackEvent): void {
            $callbackEvent = $event;
        });

        $event->run($this->app);

        $this->assertSame($event, $callbackEvent);
    }

    public function testOutputCallbackCanReceiveEvent(): void
    {
        $callbackEvent = null;
        $outputValue = null;

        $event = (new CallbackEvent($this->mutex, function (): void {
        }))->onSuccess(function (Stringable $output, CallbackEvent $event) use (&$callbackEvent, &$outputValue): void {
            $callbackEvent = $event;
            $outputValue = (string) $output;
        });
        $event->sendOutputTo($outputPath = $this->app->storagePath('logs/callback-event-output-test.log'));

        try {
            $event->run($this->app);

            $this->assertSame($event, $callbackEvent);
            $this->assertSame('', $outputValue);
        } finally {
            $this->app->make('files')->delete($outputPath);
        }
    }
}
