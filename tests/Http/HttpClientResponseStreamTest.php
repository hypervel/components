<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Hypervel\Http\Client\Response;
use Hypervel\Tests\TestCase;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\StreamInterface;

class HttpClientResponseStreamTest extends TestCase
{
    #[DataProvider('lineBodies')]
    public function testLinesPreserveContentsAcrossChunkBoundaries(string $body, array $expected): void
    {
        $stream = new HttpResponseChunkedReadStream(new NoSeekStream(Utils::streamFor($body)), 2);
        $response = new Response(new PsrResponse(body: $stream));

        $this->assertSame($expected, iterator_to_array($response->lines()));
        $this->assertTrue($stream->eof());
    }

    /**
     * Provide line endings and bodies split across stream reads.
     */
    public static function lineBodies(): array
    {
        return [
            'empty body' => ['', []],
            'blank lines' => ["\n\n", ['', '']],
            'mixed endings' => ["a\r\nb\n\r\nlast", ['a', 'b', '', 'last']],
            'carriage return is content without LF' => ["a\rb\r", ["a\rb\r"]],
            'only one CR belongs to CRLF' => ["a\r\r\n", ["a\r"]],
            'long partial line' => [str_repeat('x', 20000) . "\nend", [str_repeat('x', 20000), 'end']],
        ];
    }

    public function testLinesAreLazyAndReadFromTheCurrentPosition(): void
    {
        $stream = new HttpResponseChunkedReadStream(Utils::streamFor("skip\nfirst\nsecond\n"), 2);
        $stream->seek(5);
        $response = new Response(new PsrResponse(body: $stream));
        $lines = $response->lines();

        $this->assertSame(0, $stream->reads);
        $this->assertSame('first', $lines->current());
        $this->assertSame(3, $stream->reads);
        $lines->next();
        $this->assertSame('second', $lines->current());
        $lines->next();
        $this->assertFalse($lines->valid());
        $this->assertSame([], iterator_to_array($response->lines()));
    }

    public function testBodyConsumptionLeavesLinesAtEofUntilExplicitRewind(): void
    {
        $response = new Response(new PsrResponse(body: "one\ntwo\n"));

        $this->assertSame("one\ntwo\n", $response->body());
        $this->assertSame([], iterator_to_array($response->lines()));

        $response->toPsrResponse()->getBody()->rewind();

        $this->assertSame(['one', 'two'], iterator_to_array($response->lines()));
    }

    public function testJsonLinesDecodeObjectsAndScalarsAndSkipWhitespace(): void
    {
        $response = new Response(new PsrResponse(body: " \t\r\n{\"id\":1}\n\n[2]\nnull\nfalse\n0\n\"text\""));

        $this->assertSame([['id' => 1], [2], null, false, 0, 'text'], iterator_to_array($response->jsonLines()));
    }

    public function testJsonLinesFailWhenTheMalformedRecordIsConsumed(): void
    {
        $response = new Response(new PsrResponse(body: "{\"id\":1}\ninvalid\n{\"id\":2}\n"));
        $lines = $response->jsonLines();

        $this->assertSame(['id' => 1], $lines->current());
        $this->expectException(JsonException::class);

        $lines->next();
    }

    public function testJsonLinesUseDefaultFlagsUnlessExplicitlyOverridden(): void
    {
        Response::$defaultJsonDecodingFlags = JSON_BIGINT_AS_STRING;
        $body = "{\"id\":9223372036854775808}\n";

        $this->assertSame([['id' => '9223372036854775808']], iterator_to_array((new Response(new PsrResponse(body: $body)))->jsonLines()));
        $this->assertSame([['id' => 9223372036854775808.0]], iterator_to_array((new Response(new PsrResponse(body: $body)))->jsonLines(flags: 0)));

        Response::$defaultJsonDecodingFlags = 0;

        $this->assertSame([['id' => '9223372036854775808']], iterator_to_array((new Response(new PsrResponse(body: $body)))->jsonLines(flags: JSON_BIGINT_AS_STRING)));
    }

    public function testNullBytesAreNotSkippedAsWhitespace(): void
    {
        $response = new Response(new PsrResponse(body: "\0\n"));

        $this->expectException(JsonException::class);

        iterator_to_array($response->jsonLines());
    }
}

class HttpResponseChunkedReadStream implements StreamInterface
{
    use StreamDecoratorTrait;

    public int $reads = 0;

    /**
     * Create a stream that splits reads into small chunks.
     */
    public function __construct(private StreamInterface $stream, private int $chunkSize)
    {
    }

    /**
     * Read at most one configured chunk.
     */
    public function read(int $length): string
    {
        ++$this->reads;

        return $this->stream->read(min($length, $this->chunkSize));
    }
}
