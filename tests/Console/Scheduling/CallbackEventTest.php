<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Scheduling;

use Hypervel\Console\Scheduling\CallbackEvent;
use Hypervel\Console\Scheduling\EventMutex;
use Hypervel\Context\CoroutineContext;
use Hypervel\Engine\Channel;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use LogicException;
use Mockery as m;
use Mockery\MockInterface;
use RuntimeException;

use function Hypervel\Coroutine\parallel;

class CallbackEventTest extends TestCase
{
    protected EventMutex&MockInterface $mutex;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mutex = m::mock(EventMutex::class);
        $this->app->instance(EventMutex::class, $this->mutex);
    }

    public function testConstructorAcceptsClosure(): void
    {
        $event = new CallbackEvent($this->mutex, fn () => true);

        $this->assertInstanceOf(CallbackEvent::class, $event);
    }

    public function testConstructorAcceptsCallableArray(): void
    {
        $event = new CallbackEvent($this->mutex, [new CallbackEventTestCallable, 'handle']);

        $this->assertInstanceOf(CallbackEvent::class, $event);
    }

    public function testConstructorAcceptsInvokableObject(): void
    {
        $event = new CallbackEvent($this->mutex, new CallbackEventTestInvokable);

        $this->assertInstanceOf(CallbackEvent::class, $event);
    }

    public function testConstructorAcceptsStringCallable(): void
    {
        $event = new CallbackEvent($this->mutex, 'strlen');

        $this->assertInstanceOf(CallbackEvent::class, $event);
    }

    public function testConstructorThrowsForNonCallable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid scheduled callback event. Must be a string or callable.');

        new CallbackEvent($this->mutex, ['not', 'callable', 'array']);
    }

    public function testConstructorThrowsForNonInvokableObject(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid scheduled callback event. Must be a string or callable.');

        new CallbackEvent($this->mutex, new CallbackEventTestNonInvokable);
    }

    public function testRunInBackgroundThrowsException(): void
    {
        $event = new CallbackEvent($this->mutex, fn () => true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Scheduled closures can not be run in the background.');

        $event->runInBackground();
    }

    public function testWithoutOverlappingThrowsWhenNoDescription(): void
    {
        $event = new CallbackEvent($this->mutex, fn () => true);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("A scheduled event name is required to prevent overlapping. Use the 'name' method before 'withoutOverlapping'.");

        $event->withoutOverlapping();
    }

    public function testWithoutOverlappingSucceedsWithDescription(): void
    {
        $this->mutex->shouldReceive('exists')->andReturn(false);

        $event = new CallbackEvent($this->mutex, fn () => true);
        $event->name('test-event');

        $result = $event->withoutOverlapping();

        $this->assertSame($event, $result);
    }

    public function testOnOneServerThrowsWhenNoDescription(): void
    {
        $event = new CallbackEvent($this->mutex, fn () => true);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("A scheduled event name is required to only run on one server. Use the 'name' method before 'onOneServer'.");

        $event->onOneServer();
    }

    public function testOnOneServerSucceedsWithDescription(): void
    {
        $event = new CallbackEvent($this->mutex, fn () => true);
        $event->name('test-event');

        $result = $event->onOneServer();

        $this->assertSame($event, $result);
    }

    public function testGetSummaryForDisplayReturnsDescription(): void
    {
        $event = new CallbackEvent($this->mutex, fn () => true);
        $event->name('My Scheduled Task');

        $this->assertSame('My Scheduled Task', $event->getSummaryForDisplay());
    }

    public function testGetSummaryForDisplayReturnsCallbackForClosure(): void
    {
        $event = new CallbackEvent($this->mutex, fn () => true);

        $this->assertSame('Callback', $event->getSummaryForDisplay());
    }

    public function testGetSummaryForDisplayReturnsStringCallback(): void
    {
        $event = new CallbackEvent($this->mutex, 'strlen');

        $this->assertSame('strlen', $event->getSummaryForDisplay());
    }

    public function testMutexNameUsesDescription(): void
    {
        $event = new CallbackEvent($this->mutex, fn () => true);
        $event->name('unique-task-name');

        $this->assertSame('framework/schedule-' . hash('xxh128', 'unique-task-name'), $event->mutexName());
    }

    public function testMutexNameWithoutDescription(): void
    {
        $event = new CallbackEvent($this->mutex, fn () => true);

        $this->assertSame('framework/schedule-' . hash('xxh128', ''), $event->mutexName());
    }

    public function testShouldSkipDueToOverlappingReturnsFalseWithoutDescription(): void
    {
        $this->mutex->shouldReceive('create')->never();

        $event = new CallbackEvent($this->mutex, fn () => true);
        $event->withoutOverlapping = true;

        $this->assertFalse($event->shouldSkipDueToOverlapping());
    }

    public function testShouldSkipDueToOverlappingChecksWhenDescriptionSet(): void
    {
        $this->mutex->shouldReceive('create')->once()->andReturn(false);

        $event = new CallbackEvent($this->mutex, fn () => true);
        $event->name('test-event');
        $event->withoutOverlapping = true;

        $this->assertTrue($event->shouldSkipDueToOverlapping());
    }

    public function testExecuteRunsClosureAndReturnsResult(): void
    {
        $executed = false;
        $event = new CallbackEvent($this->mutex, function () use (&$executed) {
            $executed = true;
            return 'result';
        });

        $result = $event->run($this->app);

        $this->assertTrue($executed);
        $this->assertSame('result', $result);
    }

    public function testExecuteWithInvokableObject(): void
    {
        $invokable = new CallbackEventTestInvokable;
        $event = new CallbackEvent($this->mutex, $invokable);

        $result = $event->run($this->app);

        $this->assertSame('invoked', $result);
    }

    public function testExecuteCapturesExceptionAndRethrowsOnRun(): void
    {
        $event = new CallbackEvent($this->mutex, function () {
            throw new RuntimeException('Callback failed');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Callback failed');

        $event->run($this->app);
    }

    public function testSuccessfulRunDoesNotRethrowExceptionFromPreviousRun(): void
    {
        $calls = 0;
        $event = new CallbackEvent($this->mutex, function () use (&$calls): string {
            if (++$calls === 1) {
                throw new RuntimeException('first run failed');
            }

            return 'recovered';
        });

        try {
            $event->run($this->app);
            $this->fail('The first callback exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('first run failed', $exception->getMessage());
        }

        $this->assertSame('recovered', $event->run($this->app));
    }

    public function testCallbackFailureRemainsPrimaryWhenAfterCallbackFails(): void
    {
        $event = new CallbackEvent($this->mutex, fn () => throw new RuntimeException('callback failed'));
        $event->after(fn () => throw new RuntimeException('after callback failed'));

        try {
            $event->run($this->app);
            $this->fail('The callback exception was not thrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('callback failed', $exception->getMessage());
        }
    }

    public function testConcurrentRunsKeepResultsAndExceptionsIsolated(): void
    {
        $firstReady = new Channel(1);
        $releaseFirst = new Channel(1);
        $event = new CallbackEvent($this->mutex, function () use ($firstReady, $releaseFirst): string {
            if (CoroutineContext::get('__test.callback_event_run') === 'first') {
                $firstReady->push(true);
                $releaseFirst->pop(1.0);

                throw new RuntimeException('first run failed');
            }

            $firstReady->pop(1.0);
            $releaseFirst->push(true);
            usleep(5000);

            return 'second result';
        });

        [$first, $second] = parallel([
            function () use ($event): string {
                CoroutineContext::set('__test.callback_event_run', 'first');

                try {
                    $event->run($this->app);
                } catch (RuntimeException $exception) {
                    return $exception->getMessage();
                }

                return 'did not fail';
            },
            function () use ($event): string {
                CoroutineContext::set('__test.callback_event_run', 'second');

                return $event->run($this->app);
            },
        ]);

        $this->assertSame('first run failed', $first);
        $this->assertSame('second result', $second);
    }

    public function testExecuteWithParameters(): void
    {
        $receivedValue = null;
        $event = new CallbackEvent($this->mutex, function ($value) use (&$receivedValue) {
            $receivedValue = $value;
        }, ['value' => 'test-param']);

        $event->run($this->app);

        $this->assertSame('test-param', $receivedValue);
    }

    public function testExecuteReturnsFalseAsFailure(): void
    {
        $event = new CallbackEvent($this->mutex, fn () => false);

        // When callback returns false, result is false and internal exit code is 1
        $result = $event->run($this->app);

        $this->assertFalse($result);
    }
}

class CallbackEventTestCallable
{
    public function handle(): bool
    {
        return true;
    }
}

class CallbackEventTestInvokable
{
    public function __invoke(): string
    {
        return 'invoked';
    }
}

class CallbackEventTestNonInvokable
{
    public function doSomething(): void
    {
    }
}
