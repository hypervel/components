<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Google\Protobuf\Any;
use Google\Rpc\Status as RichStatus;
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\Metadata;
use Hypervel\Grpc\Status;
use Hypervel\Grpc\StatusCode;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class RpcExceptionTest extends TestCase
{
    public function testConstructsExpectedRpcFailure(): void
    {
        $exception = new RpcException(StatusCode::NotFound, 'Missing');

        $this->assertSame('Missing', $exception->getMessage());
        $this->assertSame(StatusCode::NotFound->value, $exception->getCode());
        $this->assertSame(StatusCode::NotFound, $exception->status()->code());
        $this->assertSame('Missing', $exception->status()->message());
        $this->assertTrue($exception->metadata()->isEmpty());
        $this->assertTrue($exception->trailers()->isEmpty());
        $this->assertNull($exception->method());
        $this->assertNull($exception->target());
        $this->assertNull($exception->retryPushbackMilliseconds());
    }

    public function testRejectsSuccessfulStatus(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('An RPC exception requires a non-OK gRPC status.');

        new RpcException(StatusCode::Ok);
    }

    public function testCreatesExceptionFromRichStatus(): void
    {
        $richStatus = (new RichStatus)
            ->setCode(StatusCode::InvalidArgument->value)
            ->setMessage('Invalid')
            ->setDetails([
                (new Any)->setTypeUrl('type.hypervel.org/example.Detail')->setValue('detail'),
            ]);

        $exception = RpcException::fromStatus($richStatus);

        $richStatus->setMessage('mutated');
        $richStatus->getDetails()[0]->setValue('mutated');

        $details = $exception->status()->details();

        $this->assertNotNull($details);
        $this->assertSame(StatusCode::InvalidArgument->value, $details->getCode());
        $this->assertSame('Invalid', $details->getMessage());
        $this->assertSame('detail', $details->getDetails()[0]->getValue());

        $copy = $exception->withTrailingMetadata(['x-tag' => 'value']);
        $copiedDetails = $copy->status()->details();

        $this->assertNotSame($exception, $copy);
        $this->assertNotNull($copiedDetails);
        $this->assertSame(StatusCode::InvalidArgument->value, $copiedDetails->getCode());
        $this->assertSame('Invalid', $copiedDetails->getMessage());
        $this->assertSame('detail', $copiedDetails->getDetails()[0]->getValue());
    }

    public function testRejectsUndefinedOrSuccessfulRichStatus(): void
    {
        foreach ([0, 17] as $code) {
            try {
                RpcException::fromStatus((new RichStatus)->setCode($code));
                $this->fail("Expected rich status code [{$code}] to be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAttachesCompletedClientCallState(): void
    {
        $exception = RpcException::fromCall(
            new Status(StatusCode::Unavailable, 'Unavailable'),
            Metadata::make(['x-initial' => 'one']),
            Metadata::make(['x-trailing' => 'two']),
            '/example.Service/Call',
            'example.test:443',
        );

        $this->assertSame(['x-initial' => ['one']], $exception->metadata()->all());
        $this->assertSame(['x-trailing' => ['two']], $exception->trailers()->all());
        $this->assertSame('/example.Service/Call', $exception->method());
        $this->assertSame('example.test:443', $exception->target());
    }

    public function testRejectsSuccessfulCompletedCallState(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A successful call cannot produce an RPC exception.');

        RpcException::fromCall(
            new Status(StatusCode::Ok),
            Metadata::make(),
            Metadata::make(),
            '/example.Service/Call',
            'example.test:443',
        );
    }

    public function testFluentTrailerAndRetryMethodsAreImmutable(): void
    {
        $original = new RpcException(StatusCode::Unavailable);
        $withMetadata = $original
            ->withTrailingMetadata(['x-tag' => 'one'])
            ->withTrailingMetadata(Metadata::make(['x-tag' => 'two']));
        $withDelay = $withMetadata->withRetryAfter(1.0001);
        $withoutRetry = $withMetadata->withoutRetry();

        $this->assertTrue($original->trailers()->isEmpty());
        $this->assertNull($original->retryPushbackMilliseconds());
        $this->assertSame(['x-tag' => ['one', 'two']], $withMetadata->trailers()->all());
        $this->assertNull($withMetadata->retryPushbackMilliseconds());
        $this->assertSame(1001, $withDelay->retryPushbackMilliseconds());
        $this->assertSame(-1, $withoutRetry->retryPushbackMilliseconds());
        $this->assertSame(0, $original->withRetryAfter(0)->retryPushbackMilliseconds());
        $this->assertSame(1, $original->withRetryAfter(0.0001)->retryPushbackMilliseconds());
    }

    public function testRejectsInvalidRetryDelay(): void
    {
        foreach ([-0.1, INF, NAN, (float) PHP_INT_MAX] as $seconds) {
            try {
                (new RpcException(StatusCode::Unavailable))->withRetryAfter($seconds);
                $this->fail('Expected the retry delay to be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
