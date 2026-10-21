<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support\OnceTest;

use Hypervel\Support\Once;
use Hypervel\Tests\TestCase;
use stdClass;

use function Hypervel\Coroutine\parallel;
use function Hypervel\Coroutine\run;

class OnceTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testResultMemoization(): void
    {
        $instance = new class {
            /**
             * Get a memoized random number.
             */
            public function rand(): int
            {
                return once(fn (): int => mt_rand(1, PHP_INT_MAX));
            }
        };

        $first = $instance->rand();
        $second = $instance->rand();

        $this->assertSame($first, $second);
    }

    public function testCallableIsCalledOnce(): void
    {
        $instance = new class {
            public int $count = 0;

            /**
             * Increment the count once.
             */
            public function increment(): int
            {
                return once(fn (): int => ++$this->count);
            }
        };

        $first = $instance->increment();
        $second = $instance->increment();

        $this->assertSame(1, $first);
        $this->assertSame(1, $second);
        $this->assertSame(1, $instance->count);
    }

    public function testFlush(): void
    {
        $instance = new MyClass;

        $first = $instance->rand();

        Once::flush();

        $second = $instance->rand();

        $this->assertNotSame($first, $second);

        Once::disable();
        Once::flush();

        $first = $instance->rand();
        $second = $instance->rand();

        $this->assertNotSame($first, $second);
    }

    public function testNotMemoizedWhenObjectIsGarbageCollected(): void
    {
        $instance = new MyClass;

        $first = $instance->rand();
        unset($instance);
        gc_collect_cycles();
        $instance = new MyClass;
        $second = $instance->rand();

        $this->assertNotSame($first, $second);
    }

    public function testIsNotMemoizedWhenCallableUsesChanges(): void
    {
        $instance = new class {
            /**
             * Get a memoized random value for the letter.
             */
            public function rand(string $letter): string
            {
                return once(function () use ($letter): string {
                    return $letter . mt_rand(1, 10000000);
                });
            }
        };

        $first = $instance->rand('a');
        $second = $instance->rand('b');

        $this->assertNotSame($first, $second);

        $first = $instance->rand('a');
        $second = $instance->rand('a');

        $this->assertSame($first, $second);

        $results = [];
        $letter = 'a';

        a:
        $results[] = once(fn (): string => $letter . mt_rand(1, 10000000));

        if (count($results) < 2) {
            goto a;
        }

        $this->assertSame($results[0], $results[1]);
    }

    public function testUsageOfThis(): void
    {
        $instance = new MyClass;

        $first = $instance->callRand();
        $second = $instance->callRand();

        $this->assertSame($first, $second);
    }

    public function testInvokables(): void
    {
        $invokable = new class {
            public static int $count = 0;

            /**
             * Increment the invocation count.
             */
            public function __invoke(): int
            {
                return self::$count = self::$count + 1;
            }
        };

        $instance = new class($invokable) {
            /**
             * Create the callable wrapper.
             *
             * @param callable(): int $invokable
             */
            public function __construct(protected object $invokable)
            {
            }

            /**
             * Invoke the callable once.
             */
            public function call(): int
            {
                return once($this->invokable);
            }
        };

        $first = $instance->call();
        $second = $instance->call();
        $third = $instance->call();

        $this->assertSame($first, $second);
        $this->assertSame($first, $third);
        $this->assertSame(1, $invokable::$count);
    }

    public function testFirstClassCallableSyntax(): void
    {
        $instance = new class {
            /**
             * Get a memoized random number through a first-class callable.
             */
            public function rand(): int
            {
                return once(MyClass::staticRand(...));
            }
        };

        $first = $instance->rand();
        $second = $instance->rand();

        $this->assertSame($first, $second);
    }

    public function testFirstClassCallableSyntaxWithArraySyntax(): void
    {
        $instance = new class {
            /**
             * Get a memoized random number through an array callable.
             */
            public function rand(): int
            {
                return once([MyClass::class, 'staticRand']);
            }
        };

        $first = $instance->rand();
        $second = $instance->rand();

        $this->assertSame($first, $second);
    }

    public function testStaticMemoization(): void
    {
        $first = MyClass::staticRand();
        $second = MyClass::staticRand();

        $this->assertSame($first, $second);
    }

    public function testMemoizationWhenOnceIsWithinClosure(): void
    {
        $resolver = fn (): int => once(fn (): int => mt_rand(1, PHP_INT_MAX));

        $first = $resolver();
        $second = $resolver();

        $this->assertSame($first, $second);
    }

    public function testMemoizationOnGlobalFunctions(): void
    {
        $first = my_rand();
        $second = my_rand();

        $this->assertSame($first, $second);
    }

    public function testDisable(): void
    {
        Once::disable();

        $first = my_rand();
        $second = my_rand();

        $this->assertNotSame($first, $second);
    }

    public function testTemporaryDisable(): void
    {
        $first = my_rand();
        $second = my_rand();

        Once::disable();

        $third = my_rand();

        Once::enable();

        $fourth = my_rand();

        $this->assertSame($first, $second);
        $this->assertNotSame($first, $third);
        $this->assertSame($first, $fourth);
    }

    public function testMemoizationWithinEvals(): void
    {
        $firstResolver = eval('return fn () => once( function () { return random_int(1, PHP_INT_MAX); } ) ;');

        $firstA = $firstResolver();
        $firstB = $firstResolver();

        $secondResolver = eval('return fn () => fn () => once( function () { return random_int(1, PHP_INT_MAX); } ) ;');

        $secondA = $secondResolver()();
        $secondB = $secondResolver()();

        $third = eval('return once( function () { return random_int(1, PHP_INT_MAX); } ) ;');
        $fourth = eval('return once( function () { return random_int(1, PHP_INT_MAX); } ) ;');

        $this->assertNotSame($firstA, $firstB);
        $this->assertNotSame($secondA, $secondB);
        $this->assertNotSame($third, $fourth);
    }

    public function testMemoizationOnSameLine(): void
    {
        $this->markTestSkipped('This test shows a limitation of the current implementation.');

        $result = [once(fn (): int => mt_rand(1, PHP_INT_MAX)), once(fn (): int => mt_rand(1, PHP_INT_MAX))];

        $this->assertNotSame($result[0], $result[1]);
    }

    public function testResultIsDifferentWhenCalledFromDifferentClosures(): void
    {
        $resolver = fn (): int => once(fn (): int => mt_rand(1, PHP_INT_MAX));
        $resolver2 = fn (): int => once(fn (): int => mt_rand(1, PHP_INT_MAX));

        $first = $resolver();
        $second = $resolver2();

        $this->assertNotSame($first, $second);
    }

    public function testResultIsMemoizedWhenCalledFromMethodsWithSameName(): void
    {
        $instanceA = new class {
            /**
             * Get a memoized random number.
             */
            public function rand(): int
            {
                return once(fn (): int => mt_rand(1, PHP_INT_MAX));
            }
        };

        $instanceB = new class {
            /**
             * Get a memoized random number.
             */
            public function rand(): int
            {
                return once(fn (): int => mt_rand(1, PHP_INT_MAX));
            }
        };

        $first = $instanceA->rand();
        $second = $instanceB->rand();

        $this->assertNotSame($first, $second);
    }

    public function testRecursiveOnceCalls(): void
    {
        $instance = new class {
            /**
             * Get a random number through nested once calls.
             */
            public function rand(): int
            {
                return once(fn (): int => once(fn (): int => mt_rand(1, PHP_INT_MAX)));
            }
        };

        $first = $instance->rand();
        $second = $instance->rand();

        $this->assertSame($first, $second);
    }

    public function testGlobalClosures(): void
    {
        $first = $GLOBALS['onceable1']();
        $second = $GLOBALS['onceable1']();

        $this->assertSame($first, $second);

        $third = $GLOBALS['onceable2']();
        $fourth = $GLOBALS['onceable2']();

        $this->assertSame($third, $fourth);

        $this->assertNotSame($first, $third);
    }

    public function testMemoizationNullValues(): void
    {
        $instance = new class {
            public int $i = 0;

            /**
             * Get a memoized null value.
             */
            public function null(): null
            {
                return once(function (): null {
                    ++$this->i;

                    return null;
                });
            }
        };

        $this->assertSame($instance->null(), $instance->null());
        $this->assertSame(1, $instance->i);
    }

    public function testExtendedStaticClassOnceCalls(): void
    {
        $first = MyClass::staticRand();
        $second = MyExtendedClass::staticRand();

        $this->assertNotSame($first, $second);
    }

    public function testOnceCachesWithinCoroutine(): void
    {
        $counter = $this->newCounter();

        $first = $this->runOnceWithCounter($counter);
        $second = $this->runOnceWithCounter($counter);

        $this->assertSame(1, $first);
        $this->assertSame(1, $second);
        $this->assertSame(1, $counter->value);
    }

    public function testOnceDifferentiatesClosureUses(): void
    {
        $results = array_map(
            fn (int $value): int => once(fn (): int => $value),
            [1, 2],
        );

        $this->assertSame([1, 2], $results);
    }

    public function testOnceDifferentiatesObjectsFromScalarAndArrayCaptures(): void
    {
        $object = new stdClass;

        // The first object token after framework cleanup is 1.
        $results = array_map(
            fn (mixed $value): string => once(fn (): string => get_debug_type($value)),
            [$object, 1, spl_object_id($object), '1', ['id' => 1]],
        );

        $this->assertSame(['stdClass', 'int', 'int', 'string', 'array'], $results);
    }

    public function testOnceDoesNotReuseResultsForTemporaryObjects(): void
    {
        $format = static fn (object $value): int => once(fn (): int => $value->number);

        $results = array_map(
            static fn (int $number): int => $format((object) ['number' => $number]),
            [1, 2, 3],
        );

        $this->assertSame([1, 2, 3], $results);
    }

    public function testOnceIsCoroutineScoped(): void
    {
        $counter = $this->newCounter();
        $results = [];

        run(function () use (&$results, $counter): void {
            $results = parallel([
                fn (): int => $this->runOnceWithCounter($counter),
                fn (): int => $this->runOnceWithCounter($counter),
            ]);
        });

        sort($results);

        $this->assertSame([1, 2], $results);
        $this->assertSame(2, $counter->value);
    }

    /**
     * Create a counter for the callback.
     */
    private function newCounter(): object
    {
        return new class {
            public int $value = 0;
        };
    }

    /**
     * Increment the counter once.
     */
    private function runOnceWithCounter(object $counter): int
    {
        return once(function () use ($counter): int {
            return ++$counter->value;
        });
    }
}

$letter = 'a';

$GLOBALS['onceable1'] = fn (): string => once(fn (): string => $letter . mt_rand(1, PHP_INT_MAX));
$GLOBALS['onceable2'] = fn (): string => once(fn (): string => $letter . mt_rand(1, PHP_INT_MAX));

/**
 * Get a memoized random number from a function.
 */
function my_rand(): int
{
    return once(fn (): int => mt_rand(1, PHP_INT_MAX));
}

class MyClass
{
    /**
     * Get a memoized random number.
     */
    public function rand(): int
    {
        return once(fn (): int => mt_rand(1, PHP_INT_MAX));
    }

    /**
     * Get a memoized random number for the called class.
     */
    public static function staticRand(): int
    {
        return once(fn (): int => mt_rand(1, PHP_INT_MAX));
    }

    /**
     * Get a memoized result from rand.
     */
    public function callRand(): int
    {
        return once(fn (): int => $this->rand());
    }
}

class MyExtendedClass extends MyClass
{
}
