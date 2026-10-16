<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Responses\Data;

use Hypervel\Ai\Responses\Data\RerankingUsage;
use Hypervel\Tests\TestCase;

class RerankingUsageTest extends TestCase
{
    public function testRerankingUsageToArrayAppendsTheSearchUnitsToTheBaseUsageCounts(): void
    {
        $this->assertSame([
            'input_tokens' => 320,
            'output_tokens' => 0,
            'search_units' => 2.5,
        ], (new RerankingUsage(320, 2.5))->toArray());
    }
}
