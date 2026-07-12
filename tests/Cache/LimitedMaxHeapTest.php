<?php

declare(strict_types=1);

namespace Hypervel\Tests\Cache;

use Hypervel\Cache\LimitedMaxHeap;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class LimitedMaxHeapTest extends TestCase
{
    #[DataProvider('invalidLimits')]
    public function testLimitMustBeAtLeastOne(int $limit): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Heap limit must be at least 1.');

        new LimitedMaxHeap($limit);
    }

    public static function invalidLimits(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
        ];
    }

    public function testSmallerValueReplacesCurrentMaximumWhenHeapIsFull(): void
    {
        $heap = new LimitedMaxHeap(3);

        foreach ([10, 20, 30, 5] as $value) {
            $heap->insert($value);
        }

        $this->assertRetainedValues([5, 10, 20], $heap);
    }

    public function testLargerValueIsDiscardedWhenHeapIsFull(): void
    {
        $heap = new LimitedMaxHeap(3);

        foreach ([10, 20, 30, 40] as $value) {
            $heap->insert($value);
        }

        $this->assertRetainedValues([10, 20, 30], $heap);
    }

    public function testAscendingInputRetainsSmallestValues(): void
    {
        $heap = new LimitedMaxHeap(4);

        foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $value) {
            $heap->insert($value);
        }

        $this->assertRetainedValues([1, 2, 3, 4], $heap);
    }

    public function testDescendingInputRetainsSmallestValues(): void
    {
        $heap = new LimitedMaxHeap(4);

        foreach ([8, 7, 6, 5, 4, 3, 2, 1] as $value) {
            $heap->insert($value);
        }

        $this->assertRetainedValues([1, 2, 3, 4], $heap);
    }

    public function testShuffledInputRetainsSmallestValues(): void
    {
        $heap = new LimitedMaxHeap(4);

        foreach ([7, 2, 8, 1, 5, 3, 6, 4] as $value) {
            $heap->insert($value);
        }

        $this->assertRetainedValues([1, 2, 3, 4], $heap);
    }

    protected function assertRetainedValues(array $expected, LimitedMaxHeap $heap): void
    {
        $actual = iterator_to_array($heap);
        sort($actual);

        $this->assertSame($expected, $actual);
    }
}
