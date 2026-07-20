<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pipeline;

use Hypervel\Pipeline\Pipeline;
use Hypervel\Testbench\TestCase;
use RuntimeException;
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
}
