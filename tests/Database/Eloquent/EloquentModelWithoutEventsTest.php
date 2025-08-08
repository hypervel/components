<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Eloquent;

use Hypervel\Context\Context;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Psr\EventDispatcher\EventDispatcherInterface;
use RuntimeException;
use Swoole\Coroutine\Channel;

/**
 * @internal
 * @coversNothing
 */
class EloquentModelWithoutEventsTest extends TestCase
{
    use RunTestsInCoroutine;

    public function testWithoutEventsExecutesCallback()
    {
        TestModel::withoutEvents(function () {
            return new TestModel();
        });
        $callbackExecuted = false;
        $expectedResult = 'test result';

        $callback = function () use (&$callbackExecuted, $expectedResult) {
            $callbackExecuted = true;

            return $expectedResult;
        };

        $result = TestModel::withoutEvents($callback);

        $this->assertTrue($callbackExecuted);
        $this->assertEquals($expectedResult, $result);
    }

    public function testGetWithoutEventContextKeyReturnsCorrectKey()
    {
        $model = new TestModel();
        $expectedKey = '__database.model.without_events.' . TestModel::class;

        $result = $model->getWithoutEventContextKey();

        $this->assertEquals($expectedKey, $result);
    }

    public function testWithoutEventsInRealCoroutine()
    {
        $callbackExecuted = false;

        $expectedResult = 'coroutine result';

        $callback = function () use (&$callbackExecuted, $expectedResult) {
            $callbackExecuted = true;

            return $expectedResult;
        };

        $result = TestModel::withoutEvents($callback);

        $this->assertTrue($callbackExecuted);
        $this->assertEquals($expectedResult, $result);
        $this->assertTrue(Coroutine::inCoroutine());
    }

    public function testGetEventDispatcherInCoroutineWithoutContext()
    {
        $model = new TestModelWithMockDispatcher();
        $dispatcher = m::mock(EventDispatcherInterface::class);
        $model->setMockDispatcher($dispatcher);

        // Context should not be set initially
        $result = $model->getEventDispatcher();

        $this->assertSame($dispatcher, $result);
        $this->assertTrue(Coroutine::inCoroutine());
    }

    public function testGetEventDispatcherInCoroutineWithWithoutEventsActive()
    {
        $model = new TestModelWithMockDispatcher();
        $dispatcher = m::mock(EventDispatcherInterface::class);
        $model->setMockDispatcher($dispatcher);

        // First, verify normal behavior
        $this->assertSame($dispatcher, $model->getEventDispatcher());

        // Now test within withoutEvents context
        TestModelWithMockDispatcher::withoutEvents(function () use ($model) {
            // Within this callback, getEventDispatcher should return null
            $result = $model->getEventDispatcher();
            $this->assertNull($result);
        });

        // After withoutEvents, context should still be set
        // so getEventDispatcher should still return null
        $this->assertNull($model->getEventDispatcher());
    }

    public function testWithoutEventsNestedInRealCoroutines()
    {
        $model = new TestModelWithMockDispatcher();
        $dispatcher = m::mock(EventDispatcherInterface::class);
        $model->setMockDispatcher($dispatcher);

        $outerExecuted = false;
        $innerExecuted = false;
        $innerResult = null;
        $outerResult = null;

        go(function () use (&$outerExecuted, &$innerExecuted, &$innerResult, &$outerResult) {
            $outerResult = TestModelWithMockDispatcher::withoutEvents(
                function () use (&$outerExecuted, &$innerExecuted, &$innerResult) {
                    $outerExecuted = true;

                    // Create inner coroutine and use a Channel to get the result
                    $channel = new Channel(1);

                    go(function () use (&$innerExecuted, $channel) {
                        $result = TestModelWithMockDispatcher::withoutEvents(function () use (&$innerExecuted) {
                            $innerExecuted = true;

                            return 'nested coroutine result';
                        });
                        $channel->push($result);
                    });

                    // Get result from inner coroutine
                    return $innerResult = $channel->pop();
                }
            );
        });

        // Wait for all coroutines to complete
        \Swoole\Coroutine::sleep(0.1);

        $this->assertTrue($outerExecuted);
        $this->assertTrue($innerExecuted);
        $this->assertEquals('nested coroutine result', $innerResult);
        $this->assertEquals('nested coroutine result', $outerResult);
    }

    public function testWithoutEventsContextIsolationBetweenModels()
    {
        $model1 = null;
        $model2 = new AnotherTestModelWithMockDispatcher();
        $dispatcher2 = m::mock(EventDispatcherInterface::class);
        $model2->setMockDispatcher($dispatcher2);

        $callbackExecuted = false;

        TestModelWithMockDispatcher::withoutEvents(
            function () use (&$model1, $model2, &$callbackExecuted, $dispatcher2) {
                $callbackExecuted = true;

                $model1 = new TestModelWithMockDispatcher();
                // model1 should return null within withoutEvents
                $this->assertNull($model1->getEventDispatcher());

                // model2 should still return its dispatcher (different context key)
                $this->assertSame($dispatcher2, $model2->getEventDispatcher());
            }
        );

        $this->assertTrue($callbackExecuted);

        // After withoutEvents, context is still set for model1 but not model2
        $this->assertNull($model1->getEventDispatcher());
        $this->assertSame($dispatcher2, $model2->getEventDispatcher());
    }

    public function testWithoutEventsHandlesExceptionsInCoroutine()
    {
        $model = new TestModelWithMockDispatcher();
        $dispatcher = m::mock(EventDispatcherInterface::class);
        $model->setMockDispatcher($dispatcher);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Coroutine exception');

        try {
            TestModelWithMockDispatcher::withoutEvents(function () {
                throw new RuntimeException('Coroutine exception');
            });
        } catch (RuntimeException $e) {
            // After exception, context is still set so dispatcher returns null
            $this->assertNull($model->getEventDispatcher());
            throw $e;
        }
    }

    public function testContextBehaviorInCoroutine()
    {
        $model = new TestModelWithMockDispatcher();
        $dispatcher = m::mock(EventDispatcherInterface::class);
        $model->setMockDispatcher($dispatcher);

        $contextKey = $model->getWithoutEventContextKey();

        // Initially, context should not be set (returns 0 for Coroutine::parentId() in root coroutine)
        $this->assertFalse((bool) Context::get($contextKey));
        $this->assertSame($dispatcher, $model->getEventDispatcher());

        TestModelWithMockDispatcher::withoutEvents(function () use ($model, $contextKey) {
            // Within withoutEvents, context should be set
            $this->assertTrue(Context::get($contextKey));
            $this->assertNull($model->getEventDispatcher());
        });

        // After withoutEvents, context should still be set (as per implementation)
        // so getEventDispatcher returns null
        $this->assertNull($model->getEventDispatcher());
    }

    public function testMultipleWithoutEventsCallsInSameCoroutine()
    {
        $model = new TestModelWithMockDispatcher();
        $dispatcher = m::mock(EventDispatcherInterface::class);
        $model->setMockDispatcher($dispatcher);

        $call1Executed = false;
        $call2Executed = false;
        $result1 = null;
        $result2 = null;

        go(function () use (&$call1Executed, &$call2Executed, &$result1, &$result2) {
            // First withoutEvents call using static method
            $result1 = TestModelWithMockDispatcher::withoutEvents(function () use (&$call1Executed) {
                $call1Executed = true;

                return 'first call';
            });

            // Second withoutEvents call using static method
            $result2 = TestModelWithMockDispatcher::withoutEvents(function () use (&$call2Executed) {
                $call2Executed = true;

                return 'second call';
            });
        });

        // Wait for coroutine to complete
        \Swoole\Coroutine::sleep(0.1);

        $this->assertTrue($call1Executed);
        $this->assertTrue($call2Executed);
        $this->assertEquals('first call', $result1);
        $this->assertEquals('second call', $result2);
    }
}

class TestModel extends Model
{
    protected ?string $table = 'test_models';

    public static function withoutEvents(callable $callback): mixed
    {
        Context::set(self::getWithoutEventContextKey(), true);

        return $callback();
    }

    public static function getWithoutEventContextKey(): string
    {
        return parent::getWithoutEventContextKey();
    }
}

class TestModelWithMockDispatcher extends Model
{
    protected ?string $table = 'test_models';

    private ?EventDispatcherInterface $mockDispatcher = null;

    public function setMockDispatcher(EventDispatcherInterface $dispatcher): void
    {
        $this->mockDispatcher = $dispatcher;
    }

    public function getEventDispatcher(): ?EventDispatcherInterface
    {
        if (Coroutine::inCoroutine() && Context::get($this->getWithoutEventContextKey())) {
            return null;
        }

        return $this->mockDispatcher;
    }

    public static function getWithoutEventContextKey(): string
    {
        return parent::getWithoutEventContextKey();
    }
}

class AnotherTestModelWithMockDispatcher extends Model
{
    protected ?string $table = 'another_test_models';

    private ?EventDispatcherInterface $mockDispatcher = null;

    public function setMockDispatcher(EventDispatcherInterface $dispatcher): void
    {
        $this->mockDispatcher = $dispatcher;
    }

    public function getEventDispatcher(): ?EventDispatcherInterface
    {
        if (Coroutine::inCoroutine() && Context::get($this->getWithoutEventContextKey())) {
            return null;
        }

        return $this->mockDispatcher;
    }

    public static function withoutEvents(callable $callback): mixed
    {
        Context::set(self::getWithoutEventContextKey(), true);

        return $callback();
    }

    public static function getWithoutEventContextKey(): string
    {
        return parent::getWithoutEventContextKey();
    }
}
