<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Gateway\Concerns\ParsesServerSentEventsTest;

use Generator;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Hypervel\Ai\Gateway\Concerns\ParsesServerSentEvents;
use Hypervel\Tests\TestCase;
use Psr\Http\Message\StreamInterface;
use WeakReference;

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

    public function testYieldsTheFirstEventWithoutReadingAhead(): void
    {
        $payload = implode("\n\n", [
            'data: {"type":"message_start"}',
            'data: {"type":"content_block_delta","delta":{"text":"Hello"}}',
            'data: {"type":"content_block_delta","delta":{"text":" world"}}',
            'data: {"type":"message_delta"}',
        ]) . "\n\n";

        $stream = new FakeStream(Utils::streamFor($payload), strpos($payload, "\n\n") + 2);

        $events = $this->parser()->parse($stream);

        $this->assertSame(['type' => 'message_start'], $events->current());
        $this->assertSame(1, $stream->reads);
        $this->assertCount(4, iterator_to_array($events));
    }

    public function testYieldsEachEventBeforeReadingNextEventsData(): void
    {
        $payload = "data: {\"type\":\"event_1\"}\n\ndata: {\"type\":\"event_2\"}\n\ndata: {\"type\":\"event_3\"}\n\n";

        $stream = new FakeStream(Utils::streamFor($payload), strpos($payload, "\n\n") + 2);
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

    public function testInterleavedStreamsKeepTheirBufferedEventsWithoutRetainingAbandonedBodies(): void
    {
        $parser = $this->parser();
        $firstBody = Utils::streamFor("data: {\"value\":1}\n\ndata: {\"value\":2}\n\n");
        $secondBody = Utils::streamFor("data: {\"value\":3}\n\ndata: {\"value\":4}\n\n");
        $first = $parser->parse($firstBody);
        $second = $parser->parse($secondBody);

        $this->assertSame(['value' => 1], $first->current());
        $this->assertSame(['value' => 3], $second->current());
        $this->assertTrue($firstBody->eof());
        $this->assertTrue($secondBody->eof());

        $reference = WeakReference::create($firstBody);
        unset($first, $firstBody);
        $this->assertNull($reference->get());

        $second->next();
        $this->assertSame(['value' => 4], $second->current());
        $second->next();
        $this->assertFalse($second->valid());
    }

    public function testReadLineOverridesReceiveTheOriginalStream(): void
    {
        $stream = Utils::streamFor("data: {\"value\":1}\n\n");
        $parser = new class {
            use ParsesServerSentEvents {
                readLine as private readBufferedLine;
            }

            public ?StreamInterface $received = null;

            /**
             * Parse events through the overridden hook.
             */
            public function parse(StreamInterface $stream): Generator
            {
                return $this->parseServerSentEvents($stream);
            }

            /**
             * Capture the stream passed to the extension point.
             */
            protected function readLine(StreamInterface $streamBody): string
            {
                $this->received = $streamBody;

                return $this->readBufferedLine($streamBody);
            }
        };

        $this->assertSame([['value' => 1]], iterator_to_array($parser->parse($stream)));
        $this->assertSame($stream, $parser->received);
    }
}

/**
 * A fake PSR-7-compatible stream that tracks read behavior.
 */
class FakeStream implements StreamInterface
{
    use StreamDecoratorTrait;

    public int $reads = 0;

    /**
     * Limit available bytes to one arriving event at a time.
     */
    public function __construct(private StreamInterface $stream, private int $chunkSize)
    {
    }

    /**
     * Read the available bytes and count reads.
     */
    public function read(int $length): string
    {
        ++$this->reads;

        return $this->stream->read(min($length, $this->chunkSize));
    }
}
