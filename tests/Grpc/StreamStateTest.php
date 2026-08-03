<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Hypervel\Engine\Http\V2\Response;
use Hypervel\Grpc\Client\StreamState;
use Hypervel\Grpc\Compression;
use Hypervel\Grpc\Exceptions\ConnectionException;
use Hypervel\Grpc\Exceptions\ProtocolException;
use Hypervel\Grpc\Protocol\Deadline;
use Hypervel\Grpc\Protocol\FrameEncoder;
use Hypervel\Grpc\Status;
use Hypervel\Grpc\StatusCode;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use stdClass;
use Throwable;
use WeakReference;

use function Hypervel\Coroutine\parallel;

class StreamStateTest extends TestCase
{
    public function testProcessesInitialMetadataFramesAndFinalTrailersAcrossEvents(): void
    {
        $state = $this->state();
        $frame = (new FrameEncoder(1024))->encode('hello');

        $state->handle(new Response(
            1,
            200,
            [
                'content-type' => 'application/grpc+proto',
                'x-initial' => 'one',
            ],
            substr($frame, 0, 3),
            true,
        ));
        $state->handle(new Response(1, 0, [], substr($frame, 3), true));
        $state->handle(new Response(
            1,
            0,
            [
                'grpc-status' => '0',
                'x-trailing' => 'two',
            ],
            '',
            false,
        ));

        $this->assertSame(['x-initial' => ['one']], $state->metadata()->all());
        $this->assertSame('hello', $state->nextMessage());
        $this->assertNull($state->nextMessage());
        $this->assertSame(StatusCode::Ok, $state->status()->code());
        $this->assertSame(['x-trailing' => ['two']], $state->trailers()->all());
        $this->assertTrue($state->committed());
        $this->assertFalse($state->trailersOnly());
    }

    public function testClassifiesFinalFirstEventAsTrailersOnly(): void
    {
        $state = $this->state();

        $state->handle(new Response(
            1,
            200,
            [
                'content-type' => 'application/grpc+proto',
                'grpc-status' => '14',
                'grpc-message' => 'Unavailable',
                'grpc-retry-pushback-ms' => '250',
                'x-trailing' => 'value',
            ],
            '',
            false,
        ));

        $this->assertTrue($state->metadata()->isEmpty());
        $this->assertSame(['x-trailing' => ['value']], $state->trailers()->all());
        $this->assertSame(StatusCode::Unavailable, $state->status()->code());
        $this->assertTrue($state->trailersOnly());
        $this->assertFalse($state->committed());
        $this->assertTrue($state->hasRetryPushback());
        $this->assertSame('250', $state->retryPushback());
    }

    public function testReleasesTheAbandonmentCallbackAfterGrpcCompletionWithoutInvokingIt(): void
    {
        $state = $this->state();
        $invocations = 0;
        $callbackOwner = $this->trackAbandonmentCallback($state, $invocations);

        $state->handle(new Response(
            1,
            200,
            [
                'content-type' => 'application/grpc+proto',
                'grpc-status' => '0',
            ],
            '',
            false,
        ));

        $this->assertSame(StatusCode::Ok, $state->status()->code());
        $this->assertSame(0, $invocations);
        $this->assertNull($callbackOwner->get());
    }

    public function testReleasesTheAbandonmentCallbackAfterNonGrpcCompletionWithoutInvokingIt(): void
    {
        $state = $this->state();
        $invocations = 0;
        $callbackOwner = $this->trackAbandonmentCallback($state, $invocations);

        $state->handle(new Response(
            1,
            503,
            ['content-type' => 'text/plain'],
            'unavailable',
            false,
        ));

        $this->assertSame(StatusCode::Unavailable, $state->status()->code());
        $this->assertSame(0, $invocations);
        $this->assertNull($callbackOwner->get());
    }

    public function testRejectsMessageDataInATrailersOnlyResponse(): void
    {
        $state = $this->state();

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('A trailers-only gRPC response cannot contain message data.');

        $state->handle(new Response(
            1,
            200,
            [
                'content-type' => 'application/grpc+proto',
                'grpc-status' => '0',
            ],
            (new FrameEncoder(1024))->encode('unexpected'),
            false,
        ));
    }

    public function testDecodesNegotiatedGzipAndRejectsUnknownEncoding(): void
    {
        $state = $this->state();
        $frame = (new FrameEncoder(1024))->encode('compressed', Compression::Gzip);

        $state->handle(new Response(
            1,
            200,
            [
                'content-type' => 'application/grpc+proto',
                'grpc-encoding' => 'gzip',
            ],
            $frame,
            true,
        ));
        $state->handle(new Response(1, 0, ['grpc-status' => '0'], '', false));

        $this->assertSame('compressed', $state->nextMessage());

        $unknown = $this->state();

        try {
            $unknown->handle(new Response(
                1,
                200,
                [
                    'content-type' => 'application/grpc+proto',
                    'grpc-encoding' => 'snappy',
                ],
                '',
                true,
            ));
            $this->fail('Expected the unknown response encoding to be rejected.');
        } catch (ProtocolException $exception) {
            $this->assertSame(
                'The peer returned unsupported gRPC response encoding [snappy].',
                $exception->getMessage(),
            );
        }
    }

    public function testNonGrpcResponsesDiscardBoundedBodiesAndUseHttpFallback(): void
    {
        $state = $this->state();

        $state->handle(new Response(
            1,
            503,
            [
                'content-type' => 'text/html',
                'grpc-status' => '0',
            ],
            'proxy ',
            true,
        ));
        $state->handle(new Response(1, 0, ['grpc-status' => '0'], 'error', false));

        $this->assertTrue($state->metadata()->isEmpty());
        $this->assertTrue($state->trailers()->isEmpty());
        $this->assertSame(StatusCode::Unavailable, $state->status()->code());
        $this->assertTrue($state->committed());
        $this->assertNull($state->nextMessage());
    }

    public function testRejectsAnOversizedNonGrpcBody(): void
    {
        $state = $this->state(maxReceiveMessageSize: 4);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage(
            'The non-gRPC response body exceeds the configured receive limit.',
        );

        $state->handle(new Response(
            1,
            502,
            ['content-type' => 'text/plain'],
            '12345',
            false,
        ));
    }

    public function testRejectsRecognizedUnsupportedGrpcRepresentationsBeforeFraming(): void
    {
        foreach (['application/grpc+json', 'application/grpc+custom'] as $contentType) {
            $state = $this->state();

            try {
                $state->handle(new Response(
                    1,
                    200,
                    ['content-type' => $contentType],
                    'not a frame',
                    false,
                ));
                $this->fail('Expected the unsupported gRPC representation to be rejected.');
            } catch (ProtocolException $exception) {
                $this->assertSame(
                    'The peer returned an unsupported gRPC representation.',
                    $exception->getMessage(),
                );
            }
        }
    }

    public function testExplicitGrpcStatusOverridesANon200HttpStatus(): void
    {
        $state = $this->state();

        $state->handle(new Response(
            1,
            503,
            [
                'content-type' => 'application/grpc+proto',
                'grpc-status' => '0',
            ],
            '',
            false,
        ));

        $this->assertSame(StatusCode::Ok, $state->status()->code());
    }

    public function testRejectsAnOversizedObservableHeaderBlock(): void
    {
        $state = $this->state(maxMetadataSize: 128);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('The peer response metadata exceeds the configured limit.');

        $state->handle(new Response(
            1,
            200,
            [
                'content-type' => 'application/grpc+proto',
                'x-large' => str_repeat('a', 128),
            ],
            '',
            true,
        ));
    }

    public function testMessageAndByteCapsAbandonOnlyTheSlowCallAndReleaseBuffers(): void
    {
        $abandoned = 0;
        $state = $this->state(maxBufferedMessages: 1, maxBufferedBytes: 16);
        $state->onAbandon(function () use (&$abandoned): void {
            ++$abandoned;
        });
        $body = (new FrameEncoder(1024))->encode('first')
            . (new FrameEncoder(1024))->encode('second');

        $state->handle(new Response(
            1,
            200,
            ['content-type' => 'application/grpc+proto'],
            $body,
            true,
        ));

        $this->assertSame(StatusCode::ResourceExhausted, $state->status()->code());
        $this->assertTrue($state->isAbandoned());
        $this->assertSame(1, $abandoned);
        $this->assertSame(0, $state->bufferedMessageCount());
        $this->assertSame(0, $state->bufferedBytes());
    }

    public function testBufferExhaustionStopsBeforeDecodingLaterFrames(): void
    {
        $abandoned = 0;
        $state = $this->state(maxBufferedBytes: 8);
        $state->onAbandon(function () use (&$abandoned): void {
            ++$abandoned;
        });
        $encoder = new FrameEncoder(1024);
        // The third frame is invalid and would fail if decoding continued past exhaustion.
        $body = $encoder->encode('first')
            . $encoder->encode('second')
            . pack('CN', 1, 3) . 'bad';

        $state->handle(new Response(
            1,
            200,
            [
                'content-type' => 'application/grpc+proto',
                'grpc-encoding' => 'gzip',
            ],
            $body,
            true,
        ));

        $this->assertSame(StatusCode::ResourceExhausted, $state->status()->code());
        $this->assertTrue($state->isAbandoned());
        $this->assertSame(1, $abandoned);
        $this->assertSame(0, $state->bufferedMessageCount());
        $this->assertSame(0, $state->bufferedBytes());
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('oversizedGrpcFrames')]
    public function testReceiveLimitBecomesLocalResourceExhaustedWithoutEscaping(
        array $headers,
        string $body,
        string $message,
    ): void {
        $abandoned = 0;
        $state = $this->state(maxReceiveMessageSize: 64);
        $state->onAbandon(function () use (&$abandoned): void {
            ++$abandoned;
        });

        $state->handle(new Response(
            1,
            200,
            ['content-type' => 'application/grpc+proto', ...$headers],
            $body,
            true,
        ));

        $this->assertSame(StatusCode::ResourceExhausted, $state->status()->code());
        $this->assertSame($message, $state->status()->message());
        $this->assertTrue($state->isAbandoned());
        $this->assertSame(1, $abandoned);
        $this->assertSame(0, $state->bufferedMessageCount());
        $this->assertSame(0, $state->bufferedBytes());
    }

    /**
     * Return frames that exceed the receive limit before and after decompression.
     *
     * @return iterable<string, array{array<string, string>, string, string}>
     */
    public static function oversizedGrpcFrames(): iterable
    {
        yield 'declared wire payload' => [
            [],
            pack('CN', 0, 65),
            'The inbound gRPC message exceeds the configured limit.',
        ];
        yield 'decompressed payload' => [
            ['grpc-encoding' => 'gzip'],
            (new FrameEncoder(1024))->encode(str_repeat('x', 1_000), Compression::Gzip),
            'The decompressed gRPC message exceeds the configured limit.',
        ];
    }

    public function testConsumingAMessageDecrementsBufferedBytes(): void
    {
        $state = $this->state();

        $state->handle(new Response(
            1,
            200,
            ['content-type' => 'application/grpc+proto'],
            (new FrameEncoder(1024))->encode('hello'),
            true,
        ));

        $this->assertSame(5, $state->bufferedBytes());
        $this->assertSame('hello', $state->nextMessage());
        $this->assertSame(0, $state->bufferedBytes());
    }

    public function testConcurrentMetadataAndStatusObserversWakeWithoutBlockingTheReceiver(): void
    {
        $state = $this->state();

        $results = parallel([
            'metadata' => static fn () => $state->metadata()->first('x-initial'),
            'status' => static fn () => $state->status()->code(),
            'receiver' => static function () use ($state): bool {
                usleep(5_000);
                $state->handle(new Response(
                    1,
                    200,
                    [
                        'content-type' => 'application/grpc+proto',
                        'x-initial' => 'value',
                    ],
                    '',
                    true,
                ));
                usleep(5_000);
                $state->handle(new Response(1, 0, ['grpc-status' => '0'], '', false));

                return true;
            },
        ]);

        $this->assertSame('value', $results['metadata']);
        $this->assertSame(StatusCode::Ok, $results['status']);
        $this->assertTrue($results['receiver']);
    }

    public function testTransportFailureWakesWaitersAndPreservesAlreadyBufferedMessages(): void
    {
        $state = $this->state();
        $state->handle(new Response(
            1,
            200,
            ['content-type' => 'application/grpc+proto'],
            (new FrameEncoder(1024))->encode('received'),
            true,
        ));
        $failure = new ConnectionException('example.test:443', 'connection lost', 104);

        $results = parallel([
            'status' => static function () use ($state): Throwable {
                try {
                    $state->status();

                    throw new RuntimeException('Expected the status observer to fail.');
                } catch (ConnectionException $exception) {
                    return $exception;
                }
            },
            'receiver' => static function () use ($state, $failure): bool {
                usleep(5_000);
                $state->fail($failure);

                return true;
            },
        ]);

        $this->assertSame($failure, $results['status']);
        $this->assertSame('received', $state->nextMessage());

        try {
            $state->nextMessage();
            $this->fail('Expected the transport failure after buffered messages were consumed.');
        } catch (ConnectionException $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    public function testReleasesTheAbandonmentCallbackAfterANonAbandoningFailure(): void
    {
        $state = $this->state();
        $invocations = 0;
        $callbackOwner = $this->trackAbandonmentCallback($state, $invocations);

        $state->fail(new ConnectionException('example.test:443', 'connection lost'));

        $this->assertSame(0, $invocations);
        $this->assertNull($callbackOwner->get());
    }

    public function testInvokesTheAbandonmentCallbackOnceAndReleasesIt(): void
    {
        $state = $this->state();
        $invocations = 0;
        $callbackOwner = $this->trackAbandonmentCallback($state, $invocations);
        $status = new Status(
            StatusCode::DeadlineExceeded,
            'The gRPC deadline was exceeded.',
        );

        $state->failWithStatus($status);
        $state->failWithStatus($status);

        $this->assertSame(1, $invocations);
        $this->assertNull($callbackOwner->get());
    }

    public function testDiscardedResponseStateExposesOnlyTheReplacementFailure(): void
    {
        $state = $this->state();
        $state->attachStream(7);
        $state->handle(new Response(
            7,
            200,
            [
                'content-type' => 'application/grpc+proto',
                'x-initial' => 'one',
            ],
            (new FrameEncoder(1024))->encode('untrusted'),
            true,
        ));
        $state->handle(new Response(
            7,
            0,
            [
                'grpc-status' => '0',
                'grpc-retry-pushback-ms' => '250',
                'x-trailing' => 'two',
            ],
            '',
            false,
        ));
        $failure = new ConnectionException('example.test:443', 'stream identifier mismatch');

        $state->discardAndFail($failure);

        foreach (
            [
                'metadata' => static fn () => $state->metadata(),
                'trailers' => static fn () => $state->trailers(),
                'status' => static fn () => $state->status(),
                'message' => static fn () => $state->nextMessage(),
            ] as $observer => $observe
        ) {
            try {
                $observe();
                $this->fail("Expected the {$observer} observer to fail.");
            } catch (ConnectionException $exception) {
                $this->assertSame($failure, $exception);
            }
        }

        $this->assertSame(7, $state->streamId());
        $this->assertFalse($state->committed());
        $this->assertFalse($state->trailersOnly());
        $this->assertTrue($state->isAbandoned());
        $this->assertFalse($state->hasRetryPushback());
        $this->assertNull($state->retryPushback());
        $this->assertSame(0, $state->bufferedMessageCount());
        $this->assertSame(0, $state->bufferedBytes());
    }

    public function testAnExpiredDeadlineProducesLocalStatusAndAbandonsTheStream(): void
    {
        $now = 2_000_000_000;
        $deadline = Deadline::usingClock(1_000_000_000, static fn (): int => $now);
        $state = $this->state(deadline: $deadline);

        $this->assertTrue($state->expireIfNeeded());
        $this->assertSame(StatusCode::DeadlineExceeded, $state->status()->code());
        $this->assertTrue($state->metadata()->isEmpty());
        $this->assertTrue($state->trailers()->isEmpty());
        $this->assertTrue($state->isAbandoned());
    }

    private function state(
        ?Deadline $deadline = null,
        int $maxReceiveMessageSize = 1024,
        int $maxMetadataSize = 8192,
        int $maxBufferedMessages = 128,
        int $maxBufferedBytes = 4096,
    ): StreamState {
        return new StreamState(
            $deadline ?? Deadline::fromTimeout(null),
            $maxReceiveMessageSize,
            $maxMetadataSize,
            $maxBufferedMessages,
            $maxBufferedBytes,
        );
    }

    private function trackAbandonmentCallback(StreamState $state, int &$invocations): WeakReference
    {
        $owner = new stdClass;
        $reference = WeakReference::create($owner);
        $state->onAbandon(function () use (&$invocations): void {
            ++$invocations;
        });

        return $reference;
    }
}
