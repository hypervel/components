<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pipeline;

use Closure;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Pipeline\Pipeline;
use Hypervel\Testbench\TestCase;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Swoole\Coroutine\Channel;

use function Hypervel\Coroutine\parallel;

class CoroutineIsolationTest extends TestCase
{
    public function testConcretePipelineResolutionsAreIsolatedBetweenCoroutines(): void
    {
        $firstPipeline = $this->app->make(Pipeline::class);
        $secondPipeline = $this->app->make(Pipeline::class);
        $firstEntered = new Channel(1);
        $releaseFirst = new Channel(1);
        $finalized = [];

        try {
            $results = parallel([
                'first' => function () use ($firstPipeline, $firstEntered, $releaseFirst, &$finalized): mixed {
                    return $firstPipeline
                        ->send('first')
                        ->through([function (mixed $value, callable $next) use ($firstEntered, $releaseFirst): mixed {
                            if (! $firstEntered->push(true, 1)) {
                                throw new RuntimeException('The second pipeline did not observe the first pipeline.');
                            }

                            if ($releaseFirst->pop(1) === false) {
                                throw new RuntimeException('The second pipeline did not release the first pipeline.');
                            }

                            return $next($value);
                        }])
                        ->finally(function (string $value) use (&$finalized): void {
                            $finalized[] = 'first:' . $value;
                        })
                        ->thenReturn();
                },
                'second' => function () use ($secondPipeline, $firstEntered, $releaseFirst, &$finalized): mixed {
                    if ($firstEntered->pop(1) === false) {
                        throw new RuntimeException('The first pipeline did not enter its pipe.');
                    }

                    try {
                        return $secondPipeline
                            ->send('second')
                            ->through([])
                            ->finally(function (string $value) use (&$finalized): void {
                                $finalized[] = 'second:' . $value;
                            })
                            ->thenReturn();
                    } finally {
                        if (! $releaseFirst->push(true, 1)) {
                            throw new RuntimeException('The first pipeline could not be released.');
                        }
                    }
                },
            ]);
        } finally {
            $firstEntered->close();
            $releaseFirst->close();
        }

        $this->assertSame('first', $results['first']);
        $this->assertSame('second', $results['second']);

        sort($finalized);

        $this->assertSame(['first:first', 'second:second'], $finalized);
    }

    public function testReusablePipelineResolvesScopedMiddlewareInsideEachCoroutine(): void
    {
        $this->app->scoped(ReusablePipelineState::class);
        $pipeline = $this->app->make(Pipeline::class)
            ->through(ReusablePipelineState::class)
            ->toClosure(static fn (string $value): string => $value);

        $results = parallel([
            static fn (): array => $pipeline('first'),
            static fn (): array => $pipeline('second'),
        ]);

        $this->assertSame(['first', 'second'], array_column($results, 0));
        $this->assertNotSame($results[0][1], $results[1][1]);
    }

    public function testReusablePipelinePreservesSharedAndFreshMiddlewareBindings(): void
    {
        $pipeline = $this->app->make(Pipeline::class)
            ->through(ReusablePipelineState::class)
            ->toClosure(static fn (string $value): string => $value);

        $first = $pipeline('first');
        $second = $pipeline('second');
        $this->assertSame($first[1], $second[1]);

        $this->app->bind(ReusablePipelineState::class);
        $third = $pipeline('third');
        $fourth = $pipeline('fourth');
        $this->assertNotSame($first[1], $third[1]);
        $this->assertNotSame($third[1], $fourth[1]);
    }

    public function testCancelingAnInvocationDoesNotDisturbAnotherInvocation(): void
    {
        $blocker = new Channel(1);
        $finalized = [];
        $canceled = null;
        $pipeline = $this->app->make(Pipeline::class)
            ->through([static function (string $value, Closure $next) use ($blocker): string {
                if ($value === 'canceled') {
                    $blocker->pop();
                }

                return $next($value);
            }])
            ->finally(static function (string $value) use (&$finalized): void {
                $finalized[] = $value;
            })
            ->toClosure(static fn (string $value): string => $value);

        $coroutineId = Coroutine::create(static function () use ($pipeline, &$canceled): void {
            try {
                $pipeline('canceled');
            } catch (CanceledException $exception) {
                $canceled = $exception;
            }
        });

        try {
            $this->assertSame('completed', $pipeline('completed'));
            $this->assertTrue(EngineCoroutine::cancelById($coroutineId, throwException: true));
            $this->assertInstanceOf(CanceledException::class, $canceled);
            $this->assertSame('later', $pipeline('later'));
            $this->assertSame(['completed', 'canceled', 'later'], $finalized);
        } finally {
            $blocker->close();
            Coroutine::join([$coroutineId], 1);
        }
    }
}

class ReusablePipelineState
{
    protected string $value;

    /**
     * Retain the input across a suspension to exercise the binding's lifetime.
     */
    public function handle(string $value, Closure $next): array
    {
        $this->value = $value;
        usleep(1000);

        return [$next($this->value), $this];
    }
}
