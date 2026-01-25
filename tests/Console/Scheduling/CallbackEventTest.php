<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console\Scheduling;

use Hypervel\Console\Contracts\EventMutex;
use Hypervel\Console\Scheduling\CallbackEvent;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use LogicException;
use Mockery as m;
use Mockery\MockInterface;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
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
        $event = new CallbackEvent($this->mutex, [new CallbackEventTestCallable(), 'handle']);

        $this->assertInstanceOf(CallbackEvent::class, $event);
    }

    public function testConstructorAcceptsInvokableObject(): void
    {
        $event = new CallbackEvent($this->mutex, new CallbackEventTestInvokable());

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

        new CallbackEvent($this->mutex, new CallbackEventTestNonInvokable());
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

        $this->assertSame('framework/schedule-' . sha1('unique-task-name'), $event->mutexName());
    }

    public function testMutexNameWithoutDescription(): void
    {
        $event = new CallbackEvent($this->mutex, fn () => true);

        $this->assertSame('framework/schedule-' . sha1(''), $event->mutexName());
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
        $invokable = new CallbackEventTestInvokable();
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
