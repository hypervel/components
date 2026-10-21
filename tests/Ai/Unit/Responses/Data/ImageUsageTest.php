<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Responses\Data;

use Hypervel\Ai\Responses\Data\ImageUsage;
use Hypervel\Tests\TestCase;

class ImageUsageTest extends TestCase
{
    public function testImageUsageToArrayAppendsTheImageTokenCountsToTheTextUsageCounts(): void
    {
        $usage = new ImageUsage(100, 50, 10, null, 5, 8, 40);

        $this->assertSame([
            'input_tokens' => 100,
            'output_tokens' => 50,
            'cache_read_input_tokens' => 10,
            'cache_write_input_tokens' => null,
            'reasoning_tokens' => 5,
            'image_input_tokens' => 8,
            'image_output_tokens' => 40,
        ], $usage->toArray());
    }
}
