<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Generator;
use Hypervel\Grpc\Compression;
use Hypervel\Grpc\Exceptions\ProtocolException;
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\Protocol\FrameDecoder;
use Hypervel\Grpc\Protocol\FrameEncoder;
use Hypervel\Grpc\StatusCode;
use Hypervel\Tests\TestCase;

class FrameDecoderTest extends TestCase
{
    public function testDecodesAFrameOneByteAtATime(): void
    {
        $decoder = new FrameDecoder(Compression::Identity, 1024);
        $messages = [];

        foreach (str_split((new FrameEncoder(1024))->encode('hello')) as $byte) {
            foreach ($decoder->push($byte) as $message) {
                $messages[] = $message;
            }
        }

        $decoder->finish();

        $this->assertSame(['hello'], $messages);
    }

    public function testDecodesMultipleAndZeroLengthFramesAcrossInputBoundaries(): void
    {
        $decoder = new FrameDecoder(Compression::Identity, 1024);
        $frames = (new FrameEncoder(1024))->encode('first')
            . (new FrameEncoder(1024))->encode('')
            . (new FrameEncoder(1024))->encode('third');

        $messages = iterator_to_array($decoder->push(substr($frames, 0, 13)), false);
        $messages = [
            ...$messages,
            ...iterator_to_array($decoder->push(substr($frames, 13)), false),
        ];

        $decoder->finish();

        $this->assertSame(['first', '', 'third'], $messages);
    }

    public function testYieldsManyTinyFramesWithoutReturningAnIntermediateList(): void
    {
        $decoder = new FrameDecoder(Compression::Identity, 1);
        $frames = str_repeat("\x00\x00\x00\x00\x01x", 1024);
        $messages = $decoder->push($frames);

        $this->assertInstanceOf(Generator::class, $messages);
        $this->assertSame(array_fill(0, 1024, 'x'), iterator_to_array($messages, false));

        $decoder->finish();
    }

    public function testRejectsAnInvalidCompressedFlag(): void
    {
        $decoder = new FrameDecoder(Compression::Identity, 1024);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('The gRPC compressed flag must be 0 or 1.');

        iterator_to_array($decoder->push(pack('CN', 2, 0)));
    }

    public function testRejectsADeclaredPayloadAboveTheReceiveLimitBeforeWaitingForIt(): void
    {
        $decoder = new FrameDecoder(Compression::Identity, 4);

        try {
            iterator_to_array($decoder->push(pack('CN', 0, 5)));
            $this->fail('Expected the declared payload to exceed the configured limit.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::ResourceExhausted, $exception->status()->code());
            $this->assertSame(
                'The inbound gRPC message exceeds the configured limit.',
                $exception->getMessage(),
            );
        }
    }

    public function testRejectsACompressedFrameWithoutNegotiatedCompression(): void
    {
        $decoder = new FrameDecoder(Compression::Identity, 1024);

        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage('A compressed gRPC frame was received without a negotiated encoding.');

        iterator_to_array($decoder->push(pack('CN', 1, 0)));
    }

    public function testDecodesGzipMessages(): void
    {
        $frame = (new FrameEncoder(1024))->encode('compressed', Compression::Gzip);
        $decoder = new FrameDecoder(Compression::Gzip, 1024);

        $this->assertSame(['compressed'], iterator_to_array($decoder->push($frame), false));

        $decoder->finish();
    }

    public function testRejectsCorruptTruncatedAndTrailingGzipData(): void
    {
        $valid = gzencode('message');
        $this->assertIsString($valid);

        foreach (['invalid gzip', substr($valid, 0, -2), $valid . 'trailing'] as $payload) {
            $decoder = new FrameDecoder(Compression::Gzip, 1024);

            try {
                iterator_to_array($decoder->push(pack('CN', 1, strlen($payload)) . $payload));
                $this->fail('Expected the compressed payload to be rejected.');
            } catch (ProtocolException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRejectsACompressionBombBeforeRetainingOversizedOutput(): void
    {
        $payload = gzencode(str_repeat('a', 4096));
        $this->assertIsString($payload);
        $this->assertLessThan(1024, strlen($payload));
        $decoder = new FrameDecoder(Compression::Gzip, 1024);

        try {
            iterator_to_array($decoder->push(pack('CN', 1, strlen($payload)) . $payload));
            $this->fail('Expected the decompressed payload to exceed the configured limit.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::ResourceExhausted, $exception->status()->code());
            $this->assertSame(
                'The decompressed gRPC message exceeds the configured limit.',
                $exception->getMessage(),
            );
        }
    }

    public function testFinishRejectsPartialHeadersAndBodies(): void
    {
        foreach (["\x00\x00", pack('CN', 0, 3) . 'ab'] as $partialFrame) {
            $decoder = new FrameDecoder(Compression::Identity, 1024);
            iterator_to_array($decoder->push($partialFrame));

            try {
                $decoder->finish();
                $this->fail('Expected the incomplete frame to be rejected.');
            } catch (ProtocolException $exception) {
                $this->assertSame(
                    'The gRPC message stream ended with an incomplete frame.',
                    $exception->getMessage(),
                );
            }
        }
    }
}
