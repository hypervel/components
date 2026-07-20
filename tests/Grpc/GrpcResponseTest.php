<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Google\Protobuf\GPBEmpty;
use Hypervel\Grpc\Metadata;
use Hypervel\Grpc\Server\GrpcResponse;
use Hypervel\Tests\TestCase;
use LogicException;

class GrpcResponseTest extends TestCase
{
    public function testCreatesAnImmutableUnaryResponseWithMetadata(): void
    {
        $message = new GPBEmpty;
        $response = GrpcResponse::make($message);
        $withMetadata = $response
            ->withInitialMetadata(['x-value' => 'one'])
            ->withInitialMetadata(Metadata::make(['x-value' => 'two']))
            ->withTrailingMetadata(['trace-bin' => "\x01\x02"]);

        $this->assertFalse($response->isStreaming());
        $this->assertSame($message, $response->message());
        $this->assertTrue($response->initialMetadata()->isEmpty());
        $this->assertTrue($response->trailingMetadata()->isEmpty());
        $this->assertNotSame($response, $withMetadata);
        $this->assertSame(['one', 'two'], $withMetadata->initialMetadata()->values('x-value'));
        $this->assertSame(["\x01\x02"], $withMetadata->trailingMetadata()->values('trace-bin'));
    }

    public function testCreatesALazyImmutableStreamingResponse(): void
    {
        $iterations = 0;
        $first = new GPBEmpty;
        $second = new GPBEmpty;
        $messages = (function () use (&$iterations, $first, $second): iterable {
            ++$iterations;
            yield $first;
            ++$iterations;
            yield $second;
        })();
        $response = GrpcResponse::stream($messages)
            ->withTrailingMetadata(['x-node' => 'worker-1']);

        $this->assertTrue($response->isStreaming());
        $this->assertSame(0, $iterations);
        $this->assertSame([$first, $second], iterator_to_array($response->messages(), false));
        $this->assertSame(2, $iterations);
        $this->assertSame('worker-1', $response->trailingMetadata()->first('x-node'));
    }

    public function testRejectsReadingAStreamFromAUnaryResponse(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A unary gRPC response does not contain a message stream.');

        GrpcResponse::make(new GPBEmpty)->messages();
    }

    public function testRejectsReadingAUnaryMessageFromAStreamingResponse(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A server-streaming gRPC response does not contain a unary message.');

        GrpcResponse::stream([])->message();
    }
}
