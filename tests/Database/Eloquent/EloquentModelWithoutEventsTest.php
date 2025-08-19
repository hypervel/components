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

/**
 * @internal
 * @coversNothing
 */
class EloquentModelWithoutEventsTest extends TestCase
{
    use RunTestsInCoroutine;

    public function testWithoutEventsExecutesCallback()
    {
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
        $model = TestModel::withoutEvents(function () {
            return new TestModel();
        });
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

        // After exiting the withoutEvents context, it should return to normal
        $this->assertSame($dispatcher, $model->getEventDispatcher());
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
            $this->assertSame($dispatcher, $model->getEventDispatcher());
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

        $this->assertSame($dispatcher, $model->getEventDispatcher());
    }
}

class TestModel extends Model
{
    protected ?string $table = 'test_models';

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
        if (Context::get($this->getWithoutEventContextKey())) {
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
        if (Context::get($this->getWithoutEventContextKey())) {
            return null;
        }

        return $this->mockDispatcher;
    }

    public static function getWithoutEventContextKey(): string
    {
        return parent::getWithoutEventContextKey();
    }
}
