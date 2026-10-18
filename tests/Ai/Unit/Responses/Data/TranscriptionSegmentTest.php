<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Responses\Data;

use Hypervel\Ai\Responses\Data\TranscriptionSegment;
use Hypervel\Tests\TestCase;

class TranscriptionSegmentTest extends TestCase
{
    public function testTranscriptionSegmentStoresTextSpeakerAndTimestamps(): void
    {
        $segment = new TranscriptionSegment('Hello world', 'Speaker 1', 0.0, 2.5);

        $this->assertSame('Hello world', $segment->text);
        $this->assertSame('Speaker 1', $segment->speaker);
        $this->assertSame(0.0, $segment->startSeconds);
        $this->assertSame(2.5, $segment->endSeconds);
    }

    public function testTranscriptionSegmentToArrayReturnsAllProperties(): void
    {
        $segment = new TranscriptionSegment('Test speech', 'Speaker A', 1.0, 3.5);

        $array = $segment->toArray();

        $this->assertSame('Test speech', $array['text']);
        $this->assertSame('Speaker A', $array['speaker']);
        $this->assertSame(1.0, $array['start_seconds']);
        $this->assertSame(3.5, $array['end_seconds']);
    }

    public function testTranscriptionSegmentJsonSerializeReturnsToArray(): void
    {
        $segment = new TranscriptionSegment('Content', 'Speaker', 5.0, 10.0);

        $json = $segment->jsonSerialize();

        $this->assertSame('Content', $json['text']);
        $this->assertSame('Speaker', $json['speaker']);
    }
}
