<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Responses\Data;

use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\TranscriptionUsage;
use Hypervel\Tests\TestCase;

class TranscriptionUsageTest extends TestCase
{
    public function testTranscriptionUsageToArrayExtendsTheBaseUsageArrayWithTheAudioDuration(): void
    {
        $usage = new TranscriptionUsage(14, 8, audioSeconds: 203.5);

        $this->assertSame([
            'input_tokens' => 14,
            'output_tokens' => 8,
            'cache_read_input_tokens' => null,
            'cache_write_input_tokens' => null,
            'reasoning_tokens' => null,
            'audio_seconds' => 203.5,
        ], $usage->toArray());
    }

    public function testTranscriptionUsageCanBeCreatedFromATextUsage(): void
    {
        $usage = TranscriptionUsage::from(new TextUsage(14, 8, 4, 2, 6), 203.5);

        $this->assertSame([
            'input_tokens' => 14,
            'output_tokens' => 8,
            'cache_read_input_tokens' => 4,
            'cache_write_input_tokens' => 2,
            'reasoning_tokens' => 6,
            'audio_seconds' => 203.5,
        ], $usage->toArray());
    }
}
