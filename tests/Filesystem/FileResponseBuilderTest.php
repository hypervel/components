<?php

declare(strict_types=1);

namespace Hypervel\Tests\Filesystem;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Filesystem\FileResponseBuilder;
use Hypervel\Filesystem\LeasedStream;
use Hypervel\Http\IterableStreamedResponse;
use Hypervel\Http\Request;
use Hypervel\ObjectPool\Lease;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\ObjectPool\SimpleObjectPool;
use Hypervel\Tests\TestCase;
use League\Flysystem\UnableToReadFile;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class FileResponseBuilderTest extends TestCase
{
    public function testBuildsDefaultHeadersAndStreamsFullContentLazily(): void
    {
        $sizeCalls = 0;
        $mimeTypeCalls = 0;
        $resolverCalls = 0;

        $response = (new FileResponseBuilder)->build(
            Request::create('/file.txt', 'GET'),
            'file.txt',
            null,
            [],
            'inline',
            function () use (&$mimeTypeCalls): string {
                ++$mimeTypeCalls;

                return 'text/plain';
            },
            function () use (&$sizeCalls): int {
                ++$sizeCalls;

                return 11;
            },
            function (?int $start, ?int $end) use (&$resolverCalls): mixed {
                ++$resolverCalls;

                return $this->stream('Hello World');
            },
        );

        $this->assertSame('text/plain', $response->headers->get('Content-Type'));
        $this->assertSame('inline; filename=file.txt', $response->headers->get('Content-Disposition'));
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
        $this->assertSame(1, $mimeTypeCalls);
        $this->assertSame(0, $sizeCalls);
        $this->assertSame(0, $resolverCalls);
        $this->assertSame('Hello World', $this->streamedContent($response));
        $this->assertSame(1, $resolverCalls);
    }

    public function testHeadResponseBuildsHeadersWithoutResolvingTheBodyOrApplyingARange(): void
    {
        $request = Request::create('/file.txt', 'HEAD', server: [
            'HTTP_RANGE' => 'bytes=1-3',
        ]);
        $resolverCalls = 0;

        $response = $this->build(
            $request,
            function (?int $start, ?int $end) use (&$resolverCalls): mixed {
                ++$resolverCalls;

                return $this->stream('body');
            },
            4,
        );

        $this->assertSame(0, $resolverCalls);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->headers->has('Content-Range'));
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
    }

    public function testBodyContainingOnlyZeroIsWritten(): void
    {
        $response = $this->build(
            Request::create('/zero.txt', 'GET'),
            fn (?int $start, ?int $end): mixed => $this->stream('0'),
            1,
        );

        $this->assertSame('0', $this->streamedContent($response));
    }

    public function testMissingMimeTypeFallsBackToBinaryContent(): void
    {
        $response = (new FileResponseBuilder)->build(
            Request::create('/file.unknown', 'GET'),
            'file.unknown',
            null,
            [],
            'inline',
            static fn (): false => false,
            static fn (): int => 4,
            fn (?int $start, ?int $end): mixed => $this->stream('body'),
        );

        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
    }

    #[DataProvider('validRangeProvider')]
    public function testValidRangesAreNormalizedAndStreamedExactly(
        string $header,
        int $expectedStart,
        int $expectedEnd,
        string $expectedContent,
    ): void {
        $content = '0123456789';
        $resolvedRange = null;
        $sizeCalls = 0;
        $request = Request::create('/file.txt', 'GET', server: ['HTTP_RANGE' => $header]);
        $response = $this->build(
            $request,
            function (?int $start, ?int $end) use ($content, &$resolvedRange): mixed {
                $resolvedRange = [$start, $end];

                return $this->stream(substr($content, $start ?? 0));
            },
            function () use (&$sizeCalls): int {
                ++$sizeCalls;

                return 10;
            },
        );

        $this->assertNull($resolvedRange);
        $this->assertSame($expectedContent, $this->streamedContent($response));
        $this->assertSame([$expectedStart, $expectedEnd], $resolvedRange);
        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame("bytes {$expectedStart}-{$expectedEnd}/10", $response->headers->get('Content-Range'));
        $this->assertSame(1, $sizeCalls);
    }

    public static function validRangeProvider(): array
    {
        return [
            'bounded' => ['bytes=2-5', 2, 5, '2345'],
            'suffix' => ['bytes=-3', 7, 9, '789'],
            'open ended' => ['bytes=4-', 4, 9, '456789'],
            'oversized suffix' => ['bytes=-999', 0, 9, '0123456789'],
            'case insensitive unit' => ['Bytes=1-2', 1, 2, '12'],
        ];
    }

    #[DataProvider('malformedRangeProvider')]
    public function testMalformedAndUnsupportedRangesFallBackToFullContent(string $header): void
    {
        $sizeCalls = 0;
        $resolvedRange = null;
        $request = Request::create('/file.txt', 'GET', server: ['HTTP_RANGE' => $header]);
        $response = $this->build(
            $request,
            function (?int $start, ?int $end) use (&$resolvedRange): mixed {
                $resolvedRange = [$start, $end];

                return $this->stream('complete');
            },
            function () use (&$sizeCalls): int {
                ++$sizeCalls;

                return 8;
            },
        );

        $this->assertSame('complete', $this->streamedContent($response));
        $this->assertSame([null, null], $resolvedRange);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->headers->has('Content-Range'));
        $this->assertSame(0, $sizeCalls);
    }

    public static function malformedRangeProvider(): array
    {
        return [
            'non-numeric' => ['bytes=abc-def'],
            'multiple ranges' => ['bytes=0-1,3-4'],
            'unsupported unit' => ['items=0-1'],
            'empty range' => ['bytes=-'],
        ];
    }

    #[DataProvider('unsatisfiableRangeProvider')]
    public function testUnsatisfiableRangesThrowWithAContentRangeHeader(string $header, int $fileSize): void
    {
        $request = Request::create('/file.txt', 'GET', server: ['HTTP_RANGE' => $header]);

        try {
            $this->build(
                $request,
                fn (?int $start, ?int $end): mixed => $this->stream('unused'),
                $fileSize,
            );
            $this->fail('Expected the range to be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(416, $exception->getStatusCode());
            $this->assertSame("bytes */{$fileSize}", $exception->getHeaders()['Content-Range']);
        }
    }

    public static function unsatisfiableRangeProvider(): array
    {
        return [
            'zero-length suffix' => ['bytes=-0', 10],
            'start at size' => ['bytes=10-', 10],
            'reversed bounds' => ['bytes=7-3', 10],
            'empty representation' => ['bytes=0-0', 0],
        ];
    }

    public function testIfRangeControlsWhetherTheRangeIsApplied(): void
    {
        $matching = Request::create('/file.txt', 'GET', server: [
            'HTTP_RANGE' => 'bytes=1-3',
            'HTTP_IF_RANGE' => '"current"',
        ]);
        $matchingResponse = $this->build(
            $matching,
            fn (?int $start, ?int $end): mixed => $this->stream(substr('0123456789', $start ?? 0)),
            10,
            ['ETag' => '"current"'],
        );

        $this->assertSame(206, $matchingResponse->getStatusCode());
        $this->assertSame('123', $this->streamedContent($matchingResponse));

        $mismatched = Request::create('/file.txt', 'GET', server: [
            'HTTP_RANGE' => 'bytes=1-3',
            'HTTP_IF_RANGE' => '"stale"',
        ]);
        $sizeCalls = 0;
        $mismatchedResponse = $this->build(
            $mismatched,
            fn (?int $start, ?int $end): mixed => $this->stream('0123456789'),
            function () use (&$sizeCalls): int {
                ++$sizeCalls;

                return 10;
            },
            ['ETag' => '"current"'],
        );

        $this->assertSame(200, $mismatchedResponse->getStatusCode());
        $this->assertSame('0123456789', $this->streamedContent($mismatchedResponse));
        $this->assertSame(0, $sizeCalls);
    }

    #[DataProvider('weakIfRangeProvider')]
    public function testWeakIfRangeEtagsFallBackToTheFullResponse(string $etag, string $ifRange): void
    {
        $request = Request::create('/file.txt', 'GET', server: [
            'HTTP_RANGE' => 'bytes=1-3',
            'HTTP_IF_RANGE' => $ifRange,
        ]);
        $sizeCalls = 0;
        $response = $this->build(
            $request,
            fn (?int $start, ?int $end): mixed => $this->stream('0123456789'),
            function () use (&$sizeCalls): int {
                ++$sizeCalls;

                return 10;
            },
            ['ETag' => $etag],
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->headers->has('Content-Range'));
        $this->assertSame('0123456789', $this->streamedContent($response));
        $this->assertSame(0, $sizeCalls);
    }

    public static function weakIfRangeProvider(): array
    {
        return [
            'matching weak validators' => ['W/"current"', 'W/"current"'],
            'weak request validator' => ['"current"', 'W/"current"'],
            'weak response validator' => ['W/"current"', '"current"'],
        ];
    }

    public function testRangeOutputIsCappedEvenWhenTheResolverReturnsMoreData(): void
    {
        $request = Request::create('/file.txt', 'GET', server: ['HTTP_RANGE' => 'bytes=2-4']);
        $response = $this->build(
            $request,
            fn (?int $start, ?int $end): mixed => $this->stream('23456789'),
            10,
        );

        $this->assertSame('234', $this->streamedContent($response));
    }

    #[DataProvider('invalidStreamProvider')]
    public function testNonResourceStreamResultsThrow(mixed $stream): void
    {
        $response = $this->build(
            Request::create('/file.txt', 'GET'),
            fn (?int $start, ?int $end): mixed => $stream,
            7,
        );

        $this->expectException(UnableToReadFile::class);
        $this->expectExceptionMessage('The stream resolver did not return an open resource.');

        $this->streamedContent($response);
    }

    public static function invalidStreamProvider(): array
    {
        return [
            'false' => [false],
            'null' => [null],
            'string' => ['stream'],
        ];
    }

    public function testReadFailureThrowsAndClosesTheStream(): void
    {
        $state = new FileResponseBuilderStreamState('x', failRead: true);
        $response = $this->build(
            Request::create('/file.txt', 'GET'),
            fn (?int $start, ?int $end): mixed => $this->wrappedStream($state),
            1,
        );

        try {
            $this->streamedContent($response);
            $this->fail('Expected the read failure to propagate.');
        } catch (UnableToReadFile $exception) {
            $this->assertStringContainsString('Unable to read from the stream.', $exception->getMessage());
        }

        $this->assertSame(1, $state->closeCount);
    }

    public function testEmptyReadBeforeEofThrowsAndClosesTheStream(): void
    {
        $state = new FileResponseBuilderStreamState('unread', emptyRead: true);
        $response = $this->build(
            Request::create('/file.txt', 'GET'),
            fn (?int $start, ?int $end): mixed => $this->wrappedStream($state),
            6,
        );

        try {
            $this->streamedContent($response);
            $this->fail('Expected the empty read to fail.');
        } catch (UnableToReadFile $exception) {
            $this->assertStringContainsString('The stream returned no data before reaching end of file.', $exception->getMessage());
        }

        $this->assertSame(1, $state->closeCount);
    }

    public function testPrematureRangeEofThrowsAndClosesTheStream(): void
    {
        $state = new FileResponseBuilderStreamState('12');
        $request = Request::create('/file.txt', 'GET', server: ['HTTP_RANGE' => 'bytes=0-3']);
        $response = $this->build(
            $request,
            fn (?int $start, ?int $end): mixed => $this->wrappedStream($state),
            4,
        );

        try {
            $this->streamedContent($response);
            $this->fail('Expected the truncated range to fail.');
        } catch (UnableToReadFile $exception) {
            $this->assertStringContainsString('The stream ended before the requested range was complete.', $exception->getMessage());
        }

        $this->assertSame(1, $state->closeCount);
    }

    public function testWriterFailureStaysPrimaryWhenClosingAlsoFails(): void
    {
        $container = new Container;
        Container::setInstance($container);
        $writerFailure = new RuntimeException('writer failed');
        $closeFailure = new RuntimeException('close failed');
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with($closeFailure);
        $container->instance(ExceptionHandler::class, $handler);
        $state = new FileResponseBuilderStreamState('contents', closeFailure: $closeFailure);
        $response = $this->build(
            Request::create('/file.txt', 'GET'),
            fn (?int $start, ?int $end): mixed => $this->wrappedStream($state),
            8,
        );

        try {
            $response->streamTo(static function () use ($writerFailure): never {
                throw $writerFailure;
            });
            $this->fail('Expected the writer failure to propagate.');
        } catch (Throwable $exception) {
            $this->assertSame($writerFailure, $exception);
        }

        $this->assertSame(1, $state->closeCount);
    }

    public function testFailedWriteStopsReadingAndClosesTheStream(): void
    {
        $state = new FileResponseBuilderStreamState(str_repeat('x', 128 * 1024));
        $writeCalls = 0;
        $bytesAttempted = 0;
        $response = $this->build(
            Request::create('/file.txt', 'GET'),
            fn (?int $start, ?int $end): mixed => $this->wrappedStream($state),
            128 * 1024,
        );

        $this->assertTrue($response->streamTo(
            static function (string $chunk) use (&$writeCalls, &$bytesAttempted): bool {
                ++$writeCalls;
                $bytesAttempted += strlen($chunk);

                return false;
            },
        ));

        $this->assertSame(1, $writeCalls);
        $this->assertGreaterThan(0, $bytesAttempted);
        $this->assertSame($bytesAttempted, $state->position);
        $this->assertLessThan(128 * 1024, $state->position);
        $this->assertSame(1, $state->closeCount);
    }

    public function testAStreamBackedByALeaseReleasesAfterEmission(): void
    {
        $pool = new SimpleObjectPool(
            static fn (): object => new stdClass,
            PoolOptions::fromArray([]),
        );

        try {
            $response = $this->build(
                Request::create('/file.txt', 'GET'),
                function (?int $start, ?int $end) use ($pool): mixed {
                    $lease = new Lease($pool, $pool->get());

                    return LeasedStream::wrap($this->stream('leased'), $lease);
                },
                6,
            );

            $this->assertSame(0, $pool->getBorrowedObjectNumber());
            $this->assertSame('leased', $this->streamedContent($response));
            $this->assertSame(0, $pool->getBorrowedObjectNumber());
            $this->assertSame(1, $pool->getObjectNumberInPool());
        } finally {
            $pool->close();
        }
    }

    /**
     * Build a response with standard metadata resolvers.
     *
     * @param Closure(?int, ?int): mixed $streamResolver
     * @param (Closure(): int)|int $size
     */
    private function build(
        Request $request,
        Closure $streamResolver,
        int|Closure $size,
        array $headers = [],
    ): IterableStreamedResponse {
        return (new FileResponseBuilder)->build(
            $request,
            'file.txt',
            null,
            $headers,
            'inline',
            static fn (): string => 'text/plain',
            $size instanceof Closure ? $size : static fn (): int => $size,
            $streamResolver,
        );
    }

    /**
     * Consume the response through the same retained-iterable contract as ResponseBridge.
     */
    private function streamedContent(IterableStreamedResponse $response): string
    {
        $content = '';

        $this->assertTrue($response->streamTo(
            static function (string $chunk) use (&$content): bool {
                $content .= $chunk;

                return true;
            },
        ));

        return $content;
    }

    /**
     * Create a temporary stream containing the given bytes.
     *
     * @return resource
     */
    private function stream(string $content): mixed
    {
        $stream = fopen('php://temp', 'r+');
        $this->assertIsResource($stream);
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }

    /**
     * Create a controllable user-space stream.
     *
     * @return resource
     */
    private function wrappedStream(FileResponseBuilderStreamState $state): mixed
    {
        if (! in_array(FileResponseBuilderStreamWrapper::PROTOCOL, stream_get_wrappers(), true)) {
            $this->assertTrue(stream_wrapper_register(
                FileResponseBuilderStreamWrapper::PROTOCOL,
                FileResponseBuilderStreamWrapper::class,
            ));
        }

        $context = stream_context_create([
            FileResponseBuilderStreamWrapper::PROTOCOL => ['state' => $state],
        ]);
        $stream = fopen(FileResponseBuilderStreamWrapper::PROTOCOL . '://stream', 'r', false, $context);
        $this->assertIsResource($stream);

        return $stream;
    }
}

class FileResponseBuilderStreamState
{
    public int $position = 0;

    public int $closeCount = 0;

    public function __construct(
        public string $content,
        public bool $failRead = false,
        public bool $emptyRead = false,
        public ?Throwable $closeFailure = null,
    ) {
    }
}

class FileResponseBuilderStreamWrapper
{
    public const PROTOCOL = 'hypervel-file-response-test';

    /** @var resource */
    public $context;

    private FileResponseBuilderStreamState $state;

    /**
     * Open the test stream from its context state.
     */
    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $state = stream_context_get_options($this->context)[self::PROTOCOL]['state'] ?? null;

        if (! $state instanceof FileResponseBuilderStreamState) {
            return false;
        }

        $this->state = $state;

        return true;
    }

    /**
     * Read from the configured content or simulate failure.
     */
    public function stream_read(int $count): false|string
    {
        if ($this->state->failRead) {
            return false;
        }

        if ($this->state->emptyRead) {
            return '';
        }

        $content = substr($this->state->content, $this->state->position, $count);
        $this->state->position += strlen($content);

        return $content;
    }

    /**
     * Determine if all configured content has been read.
     */
    public function stream_eof(): bool
    {
        return $this->state->position >= strlen($this->state->content);
    }

    /**
     * Record closure and optionally simulate a cleanup failure.
     */
    public function stream_close(): void
    {
        ++$this->state->closeCount;

        if ($this->state->closeFailure !== null) {
            throw $this->state->closeFailure;
        }
    }
}
