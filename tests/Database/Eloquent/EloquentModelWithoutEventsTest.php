<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Eloquent;

use Hypervel\Context\Context;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Events\NullDispatcher;
use Hypervel\Testbench\TestCase;
use RuntimeException;

/**
 * Tests for Model::withoutEvents() coroutine safety.
 *
 * @internal
 * @coversNothing
 */
class EloquentModelWithoutEventsTest extends TestCase
{
    protected function tearDown(): void
    {
        // Ensure context is clean after each test
        Context::destroy('__database.model.events_disabled');
        TestModel::unsetEventDispatcher();
        parent::tearDown();
    }

    public function testWithoutEventsExecutesCallback(): void
    {
        $callbackExecuted = false;
        $expectedResult = 'test result';

        $result = TestModel::withoutEvents(function () use (&$callbackExecuted, $expectedResult) {
            $callbackExecuted = true;
            return $expectedResult;
        });

        $this->assertTrue($callbackExecuted);
        $this->assertSame($expectedResult, $result);
    }

    public function testEventsAreDisabledWithinCallback(): void
    {
        // Events should be enabled initially
        $this->assertFalse(TestModel::eventsDisabled());

        TestModel::withoutEvents(function () {
            // Events should be disabled within callback
            $this->assertTrue(TestModel::eventsDisabled());
        });

        // Events should be re-enabled after callback
        $this->assertFalse(TestModel::eventsDisabled());
    }

    public function testWithoutEventsSupportsNesting(): void
    {
        $this->assertFalse(TestModel::eventsDisabled());

        TestModel::withoutEvents(function () {
            $this->assertTrue(TestModel::eventsDisabled());

            TestModel::withoutEvents(function () {
                // Still disabled in nested call
                $this->assertTrue(TestModel::eventsDisabled());
            });

            // Still disabled after nested call exits
            $this->assertTrue(TestModel::eventsDisabled());
        });

        // Re-enabled after outer call exits
        $this->assertFalse(TestModel::eventsDisabled());
    }

    public function testWithoutEventsRestoresStateAfterException(): void
    {
        $this->assertFalse(TestModel::eventsDisabled());

        try {
            TestModel::withoutEvents(function () {
                $this->assertTrue(TestModel::eventsDisabled());
                throw new RuntimeException('Test exception');
            });
        } catch (RuntimeException) {
            // Expected
        }

        // State should be restored even after exception
        $this->assertFalse(TestModel::eventsDisabled());
    }

    public function testEventsDisabledIsSharedAcrossModelClasses(): void
    {
        // withoutEvents on one model class affects all model classes
        // because it uses a global context key, not per-model-class
        $this->assertFalse(TestModel::eventsDisabled());
        $this->assertFalse(AnotherTestModel::eventsDisabled());

        TestModel::withoutEvents(function () {
            // Both model classes see events as disabled
            $this->assertTrue(TestModel::eventsDisabled());
            $this->assertTrue(AnotherTestModel::eventsDisabled());
        });

        $this->assertFalse(TestModel::eventsDisabled());
        $this->assertFalse(AnotherTestModel::eventsDisabled());
    }

    public function testContextKeyIsCorrect(): void
    {
        $contextKey = '__database.model.events_disabled';

        // Initially not set
        $this->assertNull(Context::get($contextKey));

        TestModel::withoutEvents(function () use ($contextKey) {
            // Set to true within callback
            $this->assertTrue(Context::get($contextKey));
        });

        // Restored after callback (set back to false, which was the initial state)
        $this->assertFalse(Context::get($contextKey));
    }

    public function testWithoutEventsReturnsCallbackResult(): void
    {
        $result = TestModel::withoutEvents(fn () => 42);
        $this->assertSame(42, $result);

        $result = TestModel::withoutEvents(fn () => ['foo' => 'bar']);
        $this->assertSame(['foo' => 'bar'], $result);

        $result = TestModel::withoutEvents(fn () => null);
        $this->assertNull($result);
    }

    public function testGetEventDispatcherReturnsNullDispatcherWhenEventsDisabled(): void
    {
        $realDispatcher = $this->app->make(Dispatcher::class);
        TestModel::setEventDispatcher($realDispatcher);

        // Outside withoutEvents, should return the real dispatcher
        $dispatcher = TestModel::getEventDispatcher();
        $this->assertSame($realDispatcher, $dispatcher);
        $this->assertNotInstanceOf(NullDispatcher::class, $dispatcher);

        TestModel::withoutEvents(function () use ($realDispatcher) {
            // Inside withoutEvents, should return a NullDispatcher
            $dispatcher = TestModel::getEventDispatcher();
            $this->assertInstanceOf(NullDispatcher::class, $dispatcher);
            $this->assertNotSame($realDispatcher, $dispatcher);
        });

        // After withoutEvents, should return the real dispatcher again
        $dispatcher = TestModel::getEventDispatcher();
        $this->assertSame($realDispatcher, $dispatcher);
        $this->assertNotInstanceOf(NullDispatcher::class, $dispatcher);
    }

    public function testManualDispatchViaNullDispatcherIsSuppressed(): void
    {
        $realDispatcher = $this->app->make(Dispatcher::class);
        TestModel::setEventDispatcher($realDispatcher);

        $eventFired = false;
        $realDispatcher->listen('test.event', function () use (&$eventFired) {
            $eventFired = true;
        });

        // Manual dispatch outside withoutEvents should fire
        TestModel::getEventDispatcher()->dispatch('test.event');
        $this->assertTrue($eventFired, 'Event should fire outside withoutEvents');

        $eventFired = false;

        // Manual dispatch inside withoutEvents should be suppressed
        TestModel::withoutEvents(function () {
            TestModel::getEventDispatcher()->dispatch('test.event');
        });
        $this->assertFalse($eventFired, 'Event should be suppressed inside withoutEvents');

        // Manual dispatch after withoutEvents should fire again
        TestModel::getEventDispatcher()->dispatch('test.event');
        $this->assertTrue($eventFired, 'Event should fire after withoutEvents');
    }
}

class TestModel extends Model
{
    protected ?string $table = 'test_models';
}

class AnotherTestModel extends Model
{
    protected ?string $table = 'another_test_models';
}
