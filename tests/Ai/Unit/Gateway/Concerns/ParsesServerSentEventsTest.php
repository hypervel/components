<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Gateway\Concerns\ParsesServerSentEventsTest;

use Generator;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Hypervel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Hypervel\Tests\TestCase;
use Psr\Http\Message\StreamInterface;

class ParsesServerSentEventsTest extends TestCase
{
    /**
     * Create an SSE parser.
     */
    protected function parser(): object
    {
        return new class {
            use ParsesServerSentEvents;

            /**
             * Parse the stream into events.
             */
            public function parse(StreamInterface $streamBody): Generator
            {
                return $this->parseServerSentEvents($streamBody);
            }
        };
    }

    public function testReadsStreamByteByByteToPreventEventBatching(): void
    {
        $payload = implode("\n\n", [
            'data: {"type":"message_start"}',
            'data: {"type":"content_block_delta","delta":{"text":"Hello"}}',
            'data: {"type":"content_block_delta","delta":{"text":" world"}}',
            'data: {"type":"message_delta"}',
        ]) . "\n\n";

        $stream = new FakeStream(Utils::streamFor($payload));

        iterator_to_array($this->parser()->parse($stream));

        $this->assertSame(1, $stream->maxReadSize, 'Parser must read byte-by-byte to prevent SSE event batching.');
    }

    public function testYieldsEachEventBeforeReadingNextEventsData(): void
    {
        $payload = "data: {\"type\":\"event_1\"}\n\ndata: {\"type\":\"event_2\"}\n\ndata: {\"type\":\"event_3\"}\n\n";

        $stream = new FakeStream(Utils::streamFor($payload));
        $generator = $this->parser()->parse($stream);

        $first = $generator->current();
        $this->assertSame('event_1', $first['type']);
        $this->assertFalse($stream->eof(), 'Events must be yielded progressively, not after buffering the entire stream.');

        $generator->next();
        $second = $generator->current();
        $this->assertSame('event_2', $second['type']);
        $this->assertFalse($stream->eof(), 'Stream should not be fully consumed after yielding only two events.');

        $generator->next();
        $third = $generator->current();
        $this->assertSame('event_3', $third['type']);
    }
}

/**
 * A fake PSR-7-compatible stream that tracks read behavior.
 */
class FakeStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    public int $maxReadSize = 0;

    /**
     * Read bytes and record the largest requested read.
     */
    public function read(int $length): string
    {
        if ($length > $this->maxReadSize) {
            $this->maxReadSize = $length;
        }

        return $this->stream->read($length);
    }
}
