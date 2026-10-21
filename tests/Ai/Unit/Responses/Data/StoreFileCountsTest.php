<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Responses\Data;

use Hypervel\Ai\Responses\Data\StoreFileCounts;
use Hypervel\Tests\TestCase;

class StoreFileCountsTest extends TestCase
{
    public function testStoreFileCountsStoresCompletedPendingAndFailedCounts(): void
    {
        $counts = new StoreFileCounts(10, 5, 2);

        $this->assertSame(10, $counts->completed);
        $this->assertSame(5, $counts->pending);
        $this->assertSame(2, $counts->failed);
    }

    public function testStoreFileCountsToArrayReturnsAllCounts(): void
    {
        $counts = new StoreFileCounts(1, 0, 0);

        $array = $counts->toArray();

        $this->assertSame([
            'completed' => 1,
            'pending' => 0,
            'failed' => 0,
        ], $array);
    }

    public function testStoreFileCountsJsonSerializeReturnsToArray(): void
    {
        $counts = new StoreFileCounts(5, 5, 5);

        $this->assertSame($counts->toArray(), $counts->jsonSerialize());
    }
}
