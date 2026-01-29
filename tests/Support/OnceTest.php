<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Support\Once;
use Hypervel\Tests\TestCase;

use function Hypervel\Coroutine\parallel;
use function Hypervel\Coroutine\run;

/**
 * @internal
 * @coversNothing
 */
class OnceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Once::flush();
        Once::enable();
    }

    protected function tearDown(): void
    {
        Once::flush();
        Once::enable();

        parent::tearDown();
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
            fn (int $value) => once(fn () => $value),
            [1, 2],
        );

        $this->assertSame([1, 2], $results);
    }

    public function testOnceIsCoroutineScoped(): void
    {
        $counter = $this->newCounter();
        $results = [];

        run(function () use (&$results, $counter): void {
            $results = parallel([
                fn () => $this->runOnceWithCounter($counter),
                fn () => $this->runOnceWithCounter($counter),
            ]);
        });

        sort($results);

        $this->assertSame([1, 2], $results);
        $this->assertSame(2, $counter->value);
    }

    private function newCounter(): object
    {
        return new class {
            public int $value = 0;
        };
    }

    private function runOnceWithCounter(object $counter): int
    {
        return once(function () use ($counter): int {
            return ++$counter->value;
        });
    }
}
