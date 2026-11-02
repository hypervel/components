<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use Error;
use GuzzleHttp\Handler\InflateBodyStream;
use GuzzleHttp\Psr7\CachingStream;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Utils;
use Hypervel\Http\StreamWrapper;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;
use XMLReader;
use XMLWriter;

class StreamWrapperTest extends TestCase
{
    public function testResource(): void
    {
        $stream = Utils::streamFor('foo');
        $handle = StreamWrapper::getResource($stream);
        self::assertSame('foo', fread($handle, 3));
        self::assertSame(3, ftell($handle));
        self::assertSame(3, fwrite($handle, 'bar'));
        self::assertTrue(fflush($handle));
        self::assertSame(0, fseek($handle, 0));
        self::assertSame('foobar', fread($handle, 6));
        self::assertSame('', fread($handle, 1));
        self::assertTrue(feof($handle));

        $stBlksize = PHP_OS_FAMILY === 'Windows' ? -1 : 0;

        self::assertEquals([
            'dev' => 0,
            'ino' => 0,
            'mode' => 33206,
            'nlink' => 0,
            'uid' => 0,
            'gid' => 0,
            'rdev' => 0,
            'size' => 6,
            'atime' => 0,
            'mtime' => 0,
            'ctime' => 0,
            'blksize' => $stBlksize,
            'blocks' => $stBlksize,
            0 => 0,
            1 => 0,
            2 => 33206,
            3 => 0,
            4 => 0,
            5 => 0,
            6 => 0,
            7 => 6,
            8 => 0,
            9 => 0,
            10 => 0,
            11 => $stBlksize,
            12 => $stBlksize,
        ], fstat($handle));

        self::assertTrue(fclose($handle));
        self::assertSame('foobar', (string) $stream);
    }

    public function testStreamContext(): void
    {
        $stream = Utils::streamFor('foo');

        StreamWrapper::register();
        self::assertSame('foo', file_get_contents(StreamWrapper::PROTOCOL . '://stream', false, StreamWrapper::createStreamContext($stream)));
    }

    public function testStreamCast(): void
    {
        $streams = [
            StreamWrapper::getResource(Utils::streamFor('foo')),
            StreamWrapper::getResource(Utils::streamFor('bar')),
        ];
        $write = null;
        $except = null;
        self::assertIsInt(stream_select($streams, $write, $except, 0));
    }

    public function testStreamCastFailureRemovesStreamFromSelection(): void
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('isReadable')->willReturn(true);
        $stream->method('isWritable')->willReturn(false);
        $stream->method('detach')->willThrowException(new RuntimeException('detach failed'));

        $failed = StreamWrapper::getResource($stream);
        $valid = StreamWrapper::getResource(Utils::streamFor('foo'));
        $streams = [$failed, $valid];
        $write = null;
        $except = null;

        self::assertSame(1, @stream_select($streams, $write, $except, 0));
        self::assertSame([$valid], array_values($streams));
    }

    public function testValidatesStream(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->expects(self::once())
            ->method('isReadable')
            ->willReturn(false);
        $stream->expects(self::once())
            ->method('isWritable')
            ->willReturn(false);

        $this->expectException(InvalidArgumentException::class);
        StreamWrapper::getResource($stream);
    }

    public function testReturnsFalseWhenStreamDoesNotExist(): void
    {
        $result = @fopen(StreamWrapper::PROTOCOL . '://foo', 'r');
        self::assertFalse($result);
    }

    public function testReturnsFalseWhenStreamContextDoesNotContainStream(): void
    {
        StreamWrapper::register();

        $context = stream_context_create([
            StreamWrapper::PROTOCOL => ['stream' => 'not a stream'],
        ]);

        self::assertFalse(@fopen(StreamWrapper::PROTOCOL . '://stream', 'r', false, $context));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testGetResourceThrowsWhenFopenFails(): void
    {
        StreamWrapper::register();
        stream_wrapper_unregister(StreamWrapper::PROTOCOL);
        self::assertTrue(stream_wrapper_register(StreamWrapper::PROTOCOL, FailingHttpStreamWrapper::class));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to create stream resource');

        StreamWrapper::getResource(Utils::streamFor('foo'));
    }

    public function testCanOpenReadonlyStream(): void
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->expects(self::once())
            ->method('isReadable')
            ->willReturn(false);
        $stream->expects(self::once())
            ->method('isWritable')
            ->willReturn(true);
        $resource = StreamWrapper::getResource($stream);
        self::assertIsResource($resource);
        fclose($resource);
    }

    public function testReadFailureReturnsFalse(): void
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('isReadable')->willReturn(true);
        $stream->method('isWritable')->willReturn(false);
        $stream->method('read')->willThrowException(new RuntimeException('read failed'));

        $resource = StreamWrapper::getResource($stream);

        self::assertFalse(fread($resource, 1));
    }

    public function testWriteFailureReturnsFalse(): void
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('isReadable')->willReturn(false);
        $stream->method('isWritable')->willReturn(true);
        $stream->method('write')->willThrowException(new RuntimeException('write failed'));

        $resource = StreamWrapper::getResource($stream);

        self::assertFalse(fwrite($resource, 'x'));
    }

    public function testSeekFailureReturnsMinusOne(): void
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('isReadable')->willReturn(true);
        $stream->method('isWritable')->willReturn(false);
        $stream->method('seek')->willThrowException(new RuntimeException('seek failed'));

        $resource = StreamWrapper::getResource($stream);

        self::assertSame(-1, fseek($resource, 0));
    }

    public function testTellFailureMakesSeekReturnMinusOne(): void
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('isReadable')->willReturn(true);
        $stream->method('isWritable')->willReturn(false);
        $stream->method('tell')->willThrowException(new RuntimeException('tell failed'));

        $resource = StreamWrapper::getResource($stream);

        self::assertSame(-1, fseek($resource, 0));
    }

    public function testSeekToNegativeTargetReturnsMinusOne(): void
    {
        $resource = StreamWrapper::getResource(new CachingStream(Utils::streamFor('foo')));

        self::assertSame(-1, fseek($resource, -100, SEEK_END));
    }

    public function testSeekToNegativeTargetWithUnknownSizeDoesNotMoveCursor(): void
    {
        $stream = FnStream::decorate(Utils::streamFor('foo'), [
            'getSize' => function (): ?int {
                return null;
            },
        ]);
        $resource = StreamWrapper::getResource(new CachingStream($stream));

        self::assertSame(-1, fseek($resource, -100, SEEK_END));
        self::assertSame('foo', fread($resource, 3));
    }

    public function testEofFailureReturnsTrue(): void
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('isReadable')->willReturn(true);
        $stream->method('isWritable')->willReturn(false);
        $stream->method('eof')->willThrowException(new RuntimeException('eof failed'));

        $resource = StreamWrapper::getResource($stream);

        self::assertTrue(feof($resource));
    }

    public function testStatFailureReturnsFalse(): void
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('isReadable')->willReturn(true);
        $stream->method('isWritable')->willReturn(false);
        $stream->method('getSize')->willThrowException(new RuntimeException('size failed'));

        $resource = StreamWrapper::getResource($stream);

        self::assertFalse(fstat($resource));
    }

    public function testNonRuntimeReadFailuresStillPropagate(): void
    {
        $stream = $this->createStub(StreamInterface::class);
        $stream->method('isReadable')->willReturn(true);
        $stream->method('isWritable')->willReturn(false);
        $stream->method('read')->willThrowException(new Error('read failed'));

        $resource = StreamWrapper::getResource($stream);

        try {
            $this->expectException(Error::class);
            fread($resource, 1);
        } finally {
            fclose($resource);
        }
    }

    public function testUrlStat(): void
    {
        StreamWrapper::register();

        $stBlksize = PHP_OS_FAMILY === 'Windows' ? -1 : 0;

        self::assertEquals(
            [
                'dev' => 0,
                'ino' => 0,
                'mode' => 0,
                'nlink' => 0,
                'uid' => 0,
                'gid' => 0,
                'rdev' => 0,
                'size' => 0,
                'atime' => 0,
                'mtime' => 0,
                'ctime' => 0,
                'blksize' => $stBlksize,
                'blocks' => $stBlksize,
                0 => 0,
                1 => 0,
                2 => 0,
                3 => 0,
                4 => 0,
                5 => 0,
                6 => 0,
                7 => 0,
                8 => 0,
                9 => 0,
                10 => 0,
                11 => $stBlksize,
                12 => $stBlksize,
            ],
            stat(StreamWrapper::PROTOCOL . '://stream')
        );
    }

    #[RequiresPhpExtension('xmlreader')]
    public function testXmlReaderWithStream(): void
    {
        $stream = Utils::streamFor('<?xml version="1.0" encoding="utf-8"?><foo />');

        StreamWrapper::register();
        libxml_set_streams_context(StreamWrapper::createStreamContext($stream));
        $reader = new XMLReader;

        self::assertTrue($reader->open(StreamWrapper::PROTOCOL . '://stream'));
        self::assertTrue($reader->read());
        self::assertSame('foo', $reader->name);
    }

    #[RequiresPhpExtension('xmlwriter')]
    public function testXmlWriterWithStream(): void
    {
        $stream = Utils::streamFor(fopen('php://memory', 'wb'));

        StreamWrapper::register();
        libxml_set_streams_context(StreamWrapper::createStreamContext($stream));
        $writer = new XMLWriter;

        self::assertTrue($writer->openURI(StreamWrapper::PROTOCOL . '://stream'));
        self::assertTrue($writer->startDocument());
        self::assertTrue($writer->writeElement('foo'));
        self::assertTrue($writer->endDocument());

        $stream->rewind();
        self::assertXmlStringEqualsXmlString('<?xml version="1.0"?><foo />', (string) $stream);
    }

    public function testWrappedNullSizedStreamStaysNullSized(): void
    {
        $nullSizedStream = new PumpStream(function (): string { return ''; });
        $this->assertNull($nullSizedStream->getSize());

        $resource = StreamWrapper::getResource($nullSizedStream);
        $stream = Utils::streamFor($resource);

        $this->assertNull($stream->getSize());
    }

    #[TestWith([false], 'borrowed response body')]
    #[TestWith([true], 'owned download body')]
    public function testClosingResourceRespectsOwnershipWithARetainedBody(bool $owned): void
    {
        $native = fopen('php://temp', 'w+b');
        $body = new InflateBodyStream(Utils::streamFor($native));
        $resource = $owned ? StreamWrapper::detach($body) : StreamWrapper::getResource($body);

        try {
            fclose($resource);
            $this->assertSame(! $owned, is_resource($native));
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
            $body->close();
        }
    }

    public function testDetachTransfersTheExistingNativeResource(): void
    {
        $native = fopen('php://temp', 'w+b');
        $body = Utils::streamFor($native);
        $resource = StreamWrapper::detach($body);

        try {
            $this->assertSame($native, $resource);
            $this->assertFalse($body->isReadable());
        } finally {
            fclose($resource);
        }
    }

    public function testDetachRejectsAnAlreadyDetachedNativeStream(): void
    {
        $body = Utils::streamFor('foo');
        fclose($body->detach());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to create stream resource');
        StreamWrapper::detach($body);
    }

    #[TestWith([false], 'read')]
    #[TestWith([true], 'seek')]
    public function testProducerCancellationKeepsItsOriginalException(bool $seek): void
    {
        $failure = new RuntimeException('Cleanup failed.', previous: new CanceledException);
        $body = new PumpStream(static fn () => throw $failure);
        $resource = StreamWrapper::getResource($seek ? new CachingStream($body) : $body);
        $caught = null;

        try {
            $seek ? fseek($resource, 3) : fread($resource, 1);
        } catch (Throwable $exception) {
            $caught = $exception;
        } finally {
            fclose($resource);
            $body->close();
        }

        $this->assertSame($failure, $caught);
    }

    #[TestWith(['write'])]
    #[TestWith(['tell'])]
    #[TestWith(['eof'])]
    #[TestWith(['getSize'])]
    #[TestWith(['detach'])]
    public function testOtherStreamOperationsPreserveCancellation(string $method): void
    {
        $failure = new RuntimeException('Stream cleanup failed.', previous: new CanceledException);
        $body = $this->createStub(StreamInterface::class);
        $body->method('isReadable')->willReturn(true);
        $body->method('isWritable')->willReturn(true);
        $body->method($method)->willThrowException($failure);
        $resource = StreamWrapper::getResource($body);
        $read = [$resource];
        $write = $except = null;
        $caught = null;

        try {
            match ($method) {
                'write' => fwrite($resource, 'x'),
                'tell' => fseek($resource, 0),
                'eof' => feof($resource),
                'getSize' => fstat($resource),
                'detach' => stream_select($read, $write, $except, 0),
            };
        } catch (Throwable $exception) {
            $caught = $exception;
        } finally {
            fclose($resource);
        }

        $this->assertSame($failure, $caught);
    }
}

final class FailingHttpStreamWrapper
{
    /** @var resource */
    public $context;

    /**
     * Reject opening a resource to exercise the factory's failure path.
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath = null): bool
    {
        return false;
    }
}
