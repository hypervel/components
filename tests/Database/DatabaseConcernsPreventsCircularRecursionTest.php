<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseConcernsPreventsCircularRecursionTest;

use Hypervel\Database\Eloquent\Concerns\PreventsCircularRecursion;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Tests\TestCase;
use Mockery as m;

class DatabaseConcernsPreventsCircularRecursionTest extends TestCase
{
    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        RecursiveMethodStub::$globalStack = 0;
    }

    public function testRecursiveCallsArePreventedWithoutPreventingSubsequentCalls(): void
    {
        $instance = new RecursiveMethodStub;

        $this->assertEquals(0, RecursiveMethodStub::$globalStack);
        $this->assertEquals(0, $instance->instanceStack);

        $this->assertEquals(0, $instance->callStack());
        $this->assertEquals(1, RecursiveMethodStub::$globalStack);
        $this->assertEquals(1, $instance->instanceStack);

        $this->assertEquals(1, $instance->callStack());
        $this->assertEquals(2, RecursiveMethodStub::$globalStack);
        $this->assertEquals(2, $instance->instanceStack);
    }

    public function testRecursiveDefaultCallbackIsCalledOnlyOnRecursion(): void
    {
        $instance = new RecursiveMethodStub;

        $this->assertEquals(0, RecursiveMethodStub::$globalStack);
        $this->assertEquals(0, $instance->instanceStack);
        $this->assertEquals(0, $instance->defaultStack);

        $this->assertEquals(['instance' => 1, 'default' => 0], $instance->callCallableDefaultStack());
        $this->assertEquals(1, RecursiveMethodStub::$globalStack);
        $this->assertEquals(1, $instance->instanceStack);
        $this->assertEquals(1, $instance->defaultStack);

        $this->assertEquals(['instance' => 2, 'default' => 1], $instance->callCallableDefaultStack());
        $this->assertEquals(2, RecursiveMethodStub::$globalStack);
        $this->assertEquals(2, $instance->instanceStack);
        $this->assertEquals(2, $instance->defaultStack);
    }

    public function testRecursiveDefaultCallbackIsCalledOnlyOncePerCallStack(): void
    {
        $instance = new RecursiveMethodStub;

        $this->assertEquals(0, RecursiveMethodStub::$globalStack);
        $this->assertEquals(0, $instance->instanceStack);
        $this->assertEquals(0, $instance->defaultStack);

        $this->assertEquals(
            [
                ['instance' => 1, 'default' => 0],
                ['instance' => 1, 'default' => 0],
                ['instance' => 1, 'default' => 0],
            ],
            $instance->callCallableDefaultStackRepeatedly(),
        );
        $this->assertEquals(1, RecursiveMethodStub::$globalStack);
        $this->assertEquals(1, $instance->instanceStack);
        $this->assertEquals(1, $instance->defaultStack);

        $this->assertEquals(
            [
                ['instance' => 2, 'default' => 1],
                ['instance' => 2, 'default' => 1],
                ['instance' => 2, 'default' => 1],
            ],
            $instance->callCallableDefaultStackRepeatedly(),
        );
        $this->assertEquals(2, RecursiveMethodStub::$globalStack);
        $this->assertEquals(2, $instance->instanceStack);
        $this->assertEquals(2, $instance->defaultStack);
    }

    public function testRecursiveCallsAreLimitedToIndividualInstances(): void
    {
        $instance = new RecursiveMethodStub;
        $other = $instance->other;

        $this->assertEquals(0, RecursiveMethodStub::$globalStack);
        $this->assertEquals(0, $instance->instanceStack);
        $this->assertEquals(0, $other->instanceStack);

        $instance->callStack();
        $this->assertEquals(1, RecursiveMethodStub::$globalStack);
        $this->assertEquals(1, $instance->instanceStack);
        $this->assertEquals(0, $other->instanceStack);

        $instance->callStack();
        $this->assertEquals(2, RecursiveMethodStub::$globalStack);
        $this->assertEquals(2, $instance->instanceStack);
        $this->assertEquals(0, $other->instanceStack);

        $other->callStack();
        $this->assertEquals(3, RecursiveMethodStub::$globalStack);
        $this->assertEquals(2, $instance->instanceStack);
        $this->assertEquals(1, $other->instanceStack);

        $other->callStack();
        $this->assertEquals(4, RecursiveMethodStub::$globalStack);
        $this->assertEquals(2, $instance->instanceStack);
        $this->assertEquals(2, $other->instanceStack);
    }

    public function testRecursiveCallsToCircularReferenceCallsOtherInstanceOnce(): void
    {
        $instance = new RecursiveMethodStub;
        $other = $instance->other;

        $this->assertEquals(0, RecursiveMethodStub::$globalStack);
        $this->assertEquals(0, $instance->instanceStack);
        $this->assertEquals(0, $other->instanceStack);

        $instance->callOtherStack();
        $this->assertEquals(2, RecursiveMethodStub::$globalStack);
        $this->assertEquals(1, $instance->instanceStack);
        $this->assertEquals(1, $other->instanceStack);

        $instance->callOtherStack();
        $this->assertEquals(4, RecursiveMethodStub::$globalStack);
        $this->assertEquals(2, $instance->instanceStack);
        $this->assertEquals(2, $other->instanceStack);

        $other->callOtherStack();
        $this->assertEquals(6, RecursiveMethodStub::$globalStack);
        $this->assertEquals(3, $other->instanceStack);
        $this->assertEquals(3, $instance->instanceStack);

        $other->callOtherStack();
        $this->assertEquals(8, RecursiveMethodStub::$globalStack);
        $this->assertEquals(4, $other->instanceStack);
        $this->assertEquals(4, $instance->instanceStack);
    }

    public function testRecursiveCallsToCircularLinkedListCallsEachInstanceOnce(): void
    {
        $instance = new RecursiveMethodStub;
        $second = $instance->other;
        $third = new RecursiveMethodStub($second);
        $instance->other = $third;

        $this->assertEquals(0, RecursiveMethodStub::$globalStack);
        $this->assertEquals(0, $instance->instanceStack);
        $this->assertEquals(0, $second->instanceStack);
        $this->assertEquals(0, $third->instanceStack);

        $instance->callOtherStack();
        $this->assertEquals(3, RecursiveMethodStub::$globalStack);
        $this->assertEquals(1, $instance->instanceStack);
        $this->assertEquals(1, $second->instanceStack);
        $this->assertEquals(1, $third->instanceStack);

        $second->callOtherStack();
        $this->assertEquals(6, RecursiveMethodStub::$globalStack);
        $this->assertEquals(2, $instance->instanceStack);
        $this->assertEquals(2, $second->instanceStack);
        $this->assertEquals(2, $third->instanceStack);

        $third->callOtherStack();
        $this->assertEquals(9, RecursiveMethodStub::$globalStack);
        $this->assertEquals(3, $instance->instanceStack);
        $this->assertEquals(3, $second->instanceStack);
        $this->assertEquals(3, $third->instanceStack);
    }

    public function testMockedModelCallToWithoutRecursionMethodWorks(): void
    {
        $mock = m::mock(ModelStub::class)->makePartial();

        // Model toArray method implementation
        $toArray = $mock->withoutRecursion(
            fn (): array => array_merge($mock->attributesToArray(), $mock->relationsToArray()),
            fn (): array => $mock->attributesToArray(),
        );
        $this->assertSame([], $toArray);
    }
}

class RecursiveMethodStub
{
    use PreventsCircularRecursion;

    /**
     * Create a circularly linked fixture.
     */
    public function __construct(
        public ?RecursiveMethodStub $other = null,
    ) {
        $this->other ??= new RecursiveMethodStub($this);
    }

    public static int $globalStack = 0;

    public int $instanceStack = 0;

    public int $defaultStack = 0;

    /**
     * Reenter the method with a scalar default.
     */
    public function callStack(): int
    {
        return $this->withoutRecursion(
            function (): int {
                ++static::$globalStack;
                ++$this->instanceStack;

                return $this->callStack();
            },
            $this->instanceStack,
        );
    }

    /**
     * Reenter the method with a callable default.
     */
    public function callCallableDefaultStack(): array
    {
        return $this->withoutRecursion(
            function (): array {
                ++static::$globalStack;
                ++$this->instanceStack;

                return $this->callCallableDefaultStack();
            },
            fn (): array => [
                'instance' => $this->instanceStack,
                'default' => $this->defaultStack++,
            ],
        );
    }

    /**
     * Reenter the method repeatedly within one call stack.
     */
    public function callCallableDefaultStackRepeatedly(): array
    {
        return $this->withoutRecursion(
            function (): array {
                ++static::$globalStack;
                ++$this->instanceStack;

                return [
                    $this->callCallableDefaultStackRepeatedly(),
                    $this->callCallableDefaultStackRepeatedly(),
                    $this->callCallableDefaultStackRepeatedly(),
                ];
            },
            fn (): array => [
                'instance' => $this->instanceStack,
                'default' => $this->defaultStack++,
            ],
        );
    }

    /**
     * Reenter the method through the linked fixture.
     */
    public function callOtherStack(): int
    {
        return $this->withoutRecursion(
            function (): int {
                $this->other->callStack();

                return $this->other->callOtherStack();
            },
            $this->instanceStack,
        );
    }
}

class ModelStub extends Model
{
}
