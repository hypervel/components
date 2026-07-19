<?php

declare(strict_types=1);

namespace Hypervel\Tests\Filesystem;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Engine\Http\Writable;
use Hypervel\Filesystem\FileResponseBuilder;
use Hypervel\Filesystem\LeasedStream;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\ObjectPool\Lease;
use Hypervel\ObjectPool\PoolOptions;
use Hypervel\ObjectPool\SimpleObjectPool;
use Hypervel\Testing\FakeSwooleSocket;
use Hypervel\Testing\FakeWritableConnection;
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
    public function testBuildStreamsFullContentAndBuildsDefaultHeaders(): void
    {
        $sizeCalls = 0;
        $mimeTypeCalls = 0;
        $writable = new FakeWritableConnection;
        $response = $this->response($writable);

        $result = (new FileResponseBuilder)->build(
            Request::create('/file.txt', 'GET'),
            $response,
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
            fn (?int $start, ?int $end): mixed => $this->stream('Hello World'),
        );

        $this->assertSame($response, $result);
        $this->assertSame('Hello World', $writable->written);
        $this->assertSame('text/plain', $response->headers->get('Content-Type'));
        $this->assertSame('inline; filename=file.txt', $response->headers->get('Content-Disposition'));
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
        $this->assertSame(1, $mimeTypeCalls);
        $this->assertSame(0, $sizeCalls);
    }

    public function testHeadResponseSendsHeadersWithoutResolvingTheBodyOrApplyingARange(): void
    {
        $request = Request::create('/file.txt', 'HEAD', server: [
            'HTTP_RANGE' => 'bytes=1-3',
        ]);
        $writable = new FakeWritableConnection;
        $response = $this->response($writable)->withoutBody();
        $resolverCalls = 0;

        $result = $this->build(
            $request,
            $response,
            function (?int $start, ?int $end) use (&$resolverCalls): mixed {
                ++$resolverCalls;

                return $this->stream('body');
            },
            4,
        );

        $this->assertSame($response, $result);
        $this->assertSame(0, $resolverCalls);
        $this->assertSame('', $writable->written);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->headers->has('Content-Range'));
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
        $this->assertSame('text/plain', $writable->getSocket()->headers['Content-Type']);
        $this->assertSame(200, $writable->getSocket()->statusCode);
    }

    public function testBodyContainingOnlyZeroIsWritten(): void
    {
        $writable = new FakeWritableConnection;

        $this->build(
            Request::create('/zero.txt', 'GET'),
            $this->response($writable),
            fn (?int $start, ?int $end): mixed => $this->stream('0'),
            1,
        );

        $this->assertSame('0', $writable->written);
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
        $writable = new FakeWritableConnection;
        $response = $this->response($writable);

        $this->build(
            $request,
            $response,
            function (?int $start, ?int $end) use ($content, &$resolvedRange): mixed {
                $resolvedRange = [$start, $end];

                return $this->stream(substr($content, $start ?? 0));
            },
            function () use (&$sizeCalls): int {
                ++$sizeCalls;

                return 10;
            },
        );

        $this->assertSame([$expectedStart, $expectedEnd], $resolvedRange);
        $this->assertSame($expectedContent, $writable->written);
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
        $writable = new FakeWritableConnection;
        $response = $this->response($writable);

        $this->build(
            $request,
            $response,
            function (?int $start, ?int $end) use (&$resolvedRange): mixed {
                $resolvedRange = [$start, $end];

                return $this->stream('complete');
            },
            function () use (&$sizeCalls): int {
                ++$sizeCalls;

                return 8;
            },
        );

        $this->assertSame([null, null], $resolvedRange);
        $this->assertSame('complete', $writable->written);
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
                $this->response(new FakeWritableConnection),
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
        $matchingWritable = new FakeWritableConnection;
        $matchingResponse = $this->response($matchingWritable, ['ETag' => '"current"']);

        $this->build(
            $matching,
            $matchingResponse,
            fn (?int $start, ?int $end): mixed => $this->stream(substr('0123456789', $start ?? 0)),
            10,
        );

        $this->assertSame(206, $matchingResponse->getStatusCode());
        $this->assertSame('123', $matchingWritable->written);

        $mismatched = Request::create('/file.txt', 'GET', server: [
            'HTTP_RANGE' => 'bytes=1-3',
            'HTTP_IF_RANGE' => '"stale"',
        ]);
        $mismatchedWritable = new FakeWritableConnection;
        $mismatchedResponse = $this->response($mismatchedWritable, ['ETag' => '"current"']);
        $sizeCalls = 0;

        $this->build(
            $mismatched,
            $mismatchedResponse,
            fn (?int $start, ?int $end): mixed => $this->stream('0123456789'),
            function () use (&$sizeCalls): int {
                ++$sizeCalls;

                return 10;
            },
        );

        $this->assertSame(200, $mismatchedResponse->getStatusCode());
        $this->assertSame('0123456789', $mismatchedWritable->written);
        $this->assertSame(0, $sizeCalls);
    }

    #[DataProvider('weakIfRangeProvider')]
    public function testWeakIfRangeEtagsFallBackToTheFullResponse(string $etag, string $ifRange): void
    {
        $request = Request::create('/file.txt', 'GET', server: [
            'HTTP_RANGE' => 'bytes=1-3',
            'HTTP_IF_RANGE' => $ifRange,
        ]);
        $writable = new FakeWritableConnection;
        $response = $this->response($writable, ['ETag' => $etag]);
        $sizeCalls = 0;

        $this->build(
            $request,
            $response,
            fn (?int $start, ?int $end): mixed => $this->stream('0123456789'),
            function () use (&$sizeCalls): int {
                ++$sizeCalls;

                return 10;
            },
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->headers->has('Content-Range'));
        $this->assertSame('0123456789', $writable->written);
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
        $writable = new FakeWritableConnection;

        $this->build(
            $request,
            $this->response($writable),
            fn (?int $start, ?int $end): mixed => $this->stream('23456789'),
            10,
        );

        $this->assertSame('234', $writable->written);
    }

    #[DataProvider('invalidStreamProvider')]
    public function testNonResourceStreamResultsThrow(mixed $stream): void
    {
        $this->expectException(UnableToReadFile::class);
        $this->expectExceptionMessage('The stream resolver did not return an open resource.');

        $this->build(
            Request::create('/file.txt', 'GET'),
            $this->response(new FakeWritableConnection),
            fn (?int $start, ?int $end): mixed => $stream,
            7,
        );
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

        try {
            $this->build(
                Request::create('/file.txt', 'GET'),
                $this->response(new FakeWritableConnection),
                fn (?int $start, ?int $end): mixed => $this->wrappedStream($state),
                1,
            );
            $this->fail('Expected the read failure to propagate.');
        } catch (UnableToReadFile $exception) {
            $this->assertStringContainsString('Unable to read from the stream.', $exception->getMessage());
        }

        $this->assertSame(1, $state->closeCount);
    }

    public function testEmptyReadBeforeEofThrowsAndClosesTheStream(): void
    {
        $state = new FileResponseBuilderStreamState('unread', emptyRead: true);

        try {
            $this->build(
                Request::create('/file.txt', 'GET'),
                $this->response(new FakeWritableConnection),
                fn (?int $start, ?int $end): mixed => $this->wrappedStream($state),
                6,
            );
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

        try {
            $this->build(
                $request,
                $this->response(new FakeWritableConnection),
                fn (?int $start, ?int $end): mixed => $this->wrappedStream($state),
                4,
            );
            $this->fail('Expected the truncated range to fail.');
        } catch (UnableToReadFile $exception) {
            $this->assertStringContainsString('The stream ended before the requested range was complete.', $exception->getMessage());
        }

        $this->assertSame(1, $state->closeCount);
    }

    public function testOutputFailureStaysPrimaryWhenClosingAlsoFails(): void
    {
        $container = new Container;
        Container::setInstance($container);
        $outputFailure = new RuntimeException('output failed');
        $closeFailure = new RuntimeException('close failed');
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with($closeFailure);
        $container->instance(ExceptionHandler::class, $handler);
        $state = new FileResponseBuilderStreamState('contents', closeFailure: $closeFailure);

        try {
            $this->build(
                Request::create('/file.txt', 'GET'),
                $this->response(new FileResponseBuilderThrowingConnection($outputFailure)),
                fn (?int $start, ?int $end): mixed => $this->wrappedStream($state),
                8,
            );
            $this->fail('Expected the output failure to propagate.');
        } catch (Throwable $exception) {
            $this->assertSame($outputFailure, $exception);
        }

        $this->assertSame(1, $state->closeCount);
    }

    public function testAStreamBackedByALeaseReleasesAfterEmission(): void
    {
        $pool = new SimpleObjectPool(
            static fn (): object => new stdClass,
            PoolOptions::fromArray([]),
        );
        $writable = new FakeWritableConnection;

        $this->build(
            Request::create('/file.txt', 'GET'),
            $this->response($writable),
            function (?int $start, ?int $end) use ($pool): mixed {
                $lease = new Lease($pool, $pool->get());

                return LeasedStream::wrap($this->stream('leased'), $lease);
            },
            6,
        );

        $this->assertSame('leased', $writable->written);
        $this->assertSame(0, $pool->getBorrowedObjectNumber());
        $this->assertSame(1, $pool->getObjectNumberInPool());
        $pool->close();
    }

    /**
     * Build a response with standard metadata resolvers.
     *
     * @param Closure(?int, ?int): mixed $streamResolver
     * @param Closure(): int|int $size
     */
    private function build(
        Request $request,
        Response $response,
        Closure $streamResolver,
        int|Closure $size,
    ): Response {
        return (new FileResponseBuilder)->build(
            $request,
            $response,
            'file.txt',
            null,
            [],
            'inline',
            static fn (): string => 'text/plain',
            $size instanceof Closure ? $size : static fn (): int => $size,
            $streamResolver,
        );
    }

    private function response(Writable $writable, array $headers = []): Response
    {
        $response = new Response(headers: $headers);
        $response->setConnection($writable);

        return $response;
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

class FileResponseBuilderThrowingConnection implements Writable
{
    private readonly FakeSwooleSocket $socket;

    public function __construct(
        private readonly Throwable $failure,
    ) {
        $this->socket = new FakeSwooleSocket;
    }

    public function getSocket(): FakeSwooleSocket
    {
        return $this->socket;
    }

    public function write(string $data): bool
    {
        throw $this->failure;
    }

    public function end(): ?bool
    {
        return true;
    }
}
