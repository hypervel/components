<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support\Concerns;

use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;

trait CountsEnumerations
{
    protected function makeGeneratorFunctionWithRecorder(int $numbers = 10): array
    {
        $recorder = new Collection();

        $generatorFunction = function () use ($numbers, $recorder) {
            for ($i = 1; $i <= $numbers; ++$i) {
                $recorder->push($i);

                yield $i;
            }
        };

        return [$generatorFunction, $recorder];
    }

    protected function assertDoesNotEnumerate(callable $executor): void
    {
        $this->assertEnumerates(0, $executor);
    }

    protected function assertDoesNotEnumerateCollection(
        LazyCollection $collection,
        callable $executor
    ): void {
        $this->assertEnumeratesCollection($collection, 0, $executor);
    }

    protected function assertEnumerates(int $count, callable $executor): void
    {
        $this->assertEnumeratesCollection(
            LazyCollection::times(100),
            $count,
            $executor
        );
    }

    protected function assertEnumeratesCollection(
        LazyCollection $collection,
        int $count,
        callable $executor
    ): void {
        $enumerated = 0;

        $data = $this->countEnumerations($collection, $enumerated);

        $executor($data);

        $this->assertEnumerations($count, $enumerated);
    }

    protected function assertEnumeratesOnce(callable $executor): void
    {
        $this->assertEnumeratesCollectionOnce(LazyCollection::times(10), $executor);
    }

    protected function assertEnumeratesCollectionOnce(
        LazyCollection $collection,
        callable $executor
    ): void {
        $enumerated = 0;
        $count = $collection->count();
        $collection = $this->countEnumerations($collection, $enumerated);

        $executor($collection);

        $this->assertEquals(
            $count,
            $enumerated,
            $count > $enumerated ? 'Failed to enumerate in full.' : 'Enumerated more than once.'
        );
    }

    protected function assertEnumerations(int $expected, int $actual): void
    {
        $this->assertEquals(
            $expected,
            $actual,
            "Failed asserting that {$actual} items that were enumerated matches expected {$expected}."
        );
    }

    protected function countEnumerations(LazyCollection $collection, int &$count): LazyCollection
    {
        return $collection->tapEach(function () use (&$count) {
            ++$count;
        });
    }
}
