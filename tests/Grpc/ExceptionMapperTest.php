<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Grpc\Exceptions\ProtocolException;
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\Server\ExceptionMapper;
use Hypervel\Grpc\StatusCode;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

class ExceptionMapperTest extends TestCase
{
    public function testPreservesExpectedRpcFailuresWithoutReportingThem(): void
    {
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldNotReceive('report');
        $mapper = new ExceptionMapper($handler);
        $failure = (new RpcException(StatusCode::NotFound, 'missing'))
            ->withTrailingMetadata(['x-reason' => 'absent']);

        $this->assertSame($failure, $mapper->map($failure));
    }

    public function testMapsProtocolFailuresToInternalWithoutReportingThem(): void
    {
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldNotReceive('report');
        $failure = new ProtocolException('The request contains a malformed gRPC frame.');

        $mapped = (new ExceptionMapper($handler))->map($failure);

        $this->assertSame(StatusCode::Internal, $mapped->status()->code());
        $this->assertSame($failure->getMessage(), $mapped->getMessage());
    }

    public function testReportsUnexpectedFailuresAndDoesNotExposeTheirMessage(): void
    {
        $handler = m::mock(ExceptionHandler::class);
        $failure = new RuntimeException('database credentials leaked');
        $handler->shouldReceive('report')->once()->with($failure);

        $mapped = (new ExceptionMapper($handler))->map($failure);

        $this->assertSame(StatusCode::Unknown, $mapped->status()->code());
        $this->assertSame('An unknown error occurred while handling the RPC.', $mapped->getMessage());
        $this->assertStringNotContainsString('credentials', $mapped->getMessage());
    }

    public function testReportsInvalidServiceResponsesAsInternal(): void
    {
        $handler = m::mock(ExceptionHandler::class);
        $failure = new RuntimeException('The stream yielded a string.');
        $handler->shouldReceive('report')->once()->with($failure);

        $mapped = (new ExceptionMapper($handler))->invalidResponse($failure);

        $this->assertSame(StatusCode::Internal, $mapped->status()->code());
        $this->assertSame('The gRPC service returned an invalid response.', $mapped->getMessage());
    }

    public function testPreservesCoroutineCancellationForTheOwningCallLifecycle(): void
    {
        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldNotReceive('report');
        $failure = new CanceledException;

        $this->expectExceptionObject($failure);

        (new ExceptionMapper($handler))->map($failure);
    }
}
