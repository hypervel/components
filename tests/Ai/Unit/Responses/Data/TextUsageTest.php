<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Responses\Data;

use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Tests\TestCase;

class TextUsageTest extends TestCase
{
    public function testUsageDefaultsToZeroTokensAndUnreportedDetails(): void
    {
        $usage = new TextUsage;

        $this->assertSame(0, $usage->inputTokens);
        $this->assertSame(0, $usage->outputTokens);
        $this->assertNull($usage->cacheReadInputTokens);
        $this->assertNull($usage->cacheWriteInputTokens);
        $this->assertNull($usage->reasoningTokens);
    }

    public function testUsageDerivesTotalsFromTheInclusiveInputAndOutputCounts(): void
    {
        $usage = new TextUsage(100, 50, cacheReadInputTokens: 30, cacheWriteInputTokens: 20, reasoningTokens: 5);

        $this->assertSame(150, $usage->totalTokens());
        $this->assertSame(50, $usage->uncachedInputTokens());
    }

    public function testUsageTreatsUnreportedCacheCountsAsZeroWhenDerivingTheUncachedInput(): void
    {
        $this->assertSame(100, (new TextUsage(100, 50))->uncachedInputTokens());
    }

    public function testUsageAddSumsEveryCount(): void
    {
        $combined = (new TextUsage(100, 50, 10, 25, 5))->add(new TextUsage(50, 25, 5, 10, 0));

        $this->assertEquals(new TextUsage(150, 75, 15, 35, 5), $combined);
    }

    public function testUsageAddKeepsADetailNullOnlyWhenNeitherSideReportedIt(): void
    {
        $combined = (new TextUsage(1, 1, cacheReadInputTokens: 7))->add(new TextUsage(1, 1, reasoningTokens: 3));

        $this->assertSame(7, $combined->cacheReadInputTokens);
        $this->assertSame(3, $combined->reasoningTokens);
        $this->assertNull($combined->cacheWriteInputTokens);
    }

    public function testUsageFromArrayRestoresWhatToArraySerialized(): void
    {
        $usage = new TextUsage(100, 50, 10, 25, null);

        $this->assertEquals($usage, TextUsage::fromArray($usage->toArray()));
        $this->assertEquals(new TextUsage, TextUsage::fromArray([]));
    }

    public function testUsageToArraySerializesEveryCount(): void
    {
        $usage = new TextUsage(100, 50, 10, 25, null);

        $this->assertSame([
            'input_tokens' => 100,
            'output_tokens' => 50,
            'cache_read_input_tokens' => 10,
            'cache_write_input_tokens' => 25,
            'reasoning_tokens' => null,
        ], $usage->toArray());
        $this->assertSame($usage->toArray(), $usage->jsonSerialize());
    }
}
