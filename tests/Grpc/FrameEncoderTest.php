<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Hypervel\Grpc\Compression;
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\Protocol\FrameEncoder;
use Hypervel\Grpc\StatusCode;
use Hypervel\Tests\TestCase;

class FrameEncoderTest extends TestCase
{
    public function testEncodesAnIdentityFrameWithAnExactFiveByteHeader(): void
    {
        $frame = (new FrameEncoder(3))->encode('abc');

        $this->assertSame("\x00\x00\x00\x00\x03abc", $frame);
    }

    public function testEncodesAZeroLengthPayload(): void
    {
        $frame = (new FrameEncoder(1))->encode('');

        $this->assertSame("\x00\x00\x00\x00\x00", $frame);
    }

    public function testCompressesEachGzipFrame(): void
    {
        $frame = (new FrameEncoder(1024))->encode('compress me', Compression::Gzip);
        $header = unpack('Cflag/Nlength', substr($frame, 0, 5));
        $payload = substr($frame, 5);

        $this->assertIsArray($header);
        $this->assertSame(1, $header['flag']);
        $this->assertSame(strlen($payload), $header['length']);
        $this->assertSame('compress me', gzdecode($payload));
    }

    public function testRejectsAPlaintextPayloadAboveTheSendLimit(): void
    {
        try {
            (new FrameEncoder(2))->encode('abc');
            $this->fail('Expected the plaintext message to exceed the configured limit.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::ResourceExhausted, $exception->status()->code());
            $this->assertSame(
                'The outbound gRPC message exceeds the configured limit.',
                $exception->getMessage(),
            );
        }
    }

    public function testRejectsAnEncodedPayloadAboveTheSendLimit(): void
    {
        try {
            (new FrameEncoder(1))->encode('a', Compression::Gzip);
            $this->fail('Expected the encoded message to exceed the configured limit.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::ResourceExhausted, $exception->status()->code());
            $this->assertSame(
                'The encoded gRPC message exceeds the configured limit.',
                $exception->getMessage(),
            );
        }
    }
}
