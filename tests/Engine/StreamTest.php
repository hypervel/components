<?php

declare(strict_types=1);

namespace Hypervel\Tests\Engine;

use Hypervel\Engine\Exceptions\RuntimeException;
use Hypervel\Engine\Http\Stream;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class StreamTest extends TestCase
{
    public function testReadsAndMaintainsItsSize(): void
    {
        $stream = new Stream('hello');

        $this->assertSame('', $stream->read(0));
        $this->assertSame(5, $stream->getSize());
        $this->assertSame('he', $stream->read(2));
        $this->assertSame(3, $stream->getSize());
        $this->assertSame('llo', $stream->read(10));
        $this->assertSame(0, $stream->getSize());
        $this->assertTrue($stream->eof());
    }

    public function testRejectsNegativeReadLengthsWithoutMutatingTheStream(): void
    {
        $stream = new Stream('hello');

        try {
            $stream->read(-1);
            $this->fail('Expected a negative read length to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Cannot read a negative number of bytes', $exception->getMessage());
        }

        $this->assertSame('hello', $stream->getContents());
        $this->assertSame(5, $stream->getSize());
    }

    public function testReturnsPsrMetadataResults(): void
    {
        $stream = new Stream;

        $this->assertSame([], $stream->getMetadata());
        $this->assertNull($stream->getMetadata('uri'));
    }

    #[DataProvider('detachedOperationProvider')]
    public function testDetachedStreamsAreUnusable(callable $operation): void
    {
        $stream = new Stream('hello');

        $this->assertNull($stream->detach());
        $this->assertNull($stream->getSize());
        $this->assertFalse($stream->isReadable());
        $this->assertFalse($stream->isWritable());

        $this->expectException(RuntimeException::class);

        $operation($stream);
    }

    /**
     * Provide operations that require an attached stream.
     *
     * @return array<string, array{callable(Stream): mixed}>
     */
    public static function detachedOperationProvider(): array
    {
        return [
            'read' => [fn (Stream $stream): string => $stream->read(1)],
            'get contents' => [fn (Stream $stream): string => $stream->getContents()],
            'eof' => [fn (Stream $stream): bool => $stream->eof()],
            'write' => [fn (Stream $stream): int => $stream->write('world')],
        ];
    }
}
