<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Grpc\Exceptions\ProtocolException;
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\Server\ExceptionMapper;
use Hypervel\Grpc\StatusCode;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use RuntimeException;
use Swoole\Coroutine\CanceledException;

class ExceptionMapperTest extends TestCase
{
    use VerifiesDoubles;

    public function testPreservesExpectedRpcFailuresWithoutReportingThem(): void
    {
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->never();
        $mapper = new ExceptionMapper($handler);
        $failure = (new RpcException(StatusCode::NotFound, 'missing'))
            ->withTrailingMetadata(['x-reason' => 'absent']);

        $this->assertSame($failure, $mapper->map($failure));
    }

    public function testMapsProtocolFailuresToInternalWithoutReportingThem(): void
    {
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->never();
        $failure = new ProtocolException('The request contains a malformed gRPC frame.');

        $mapped = (new ExceptionMapper($handler))->map($failure);

        $this->assertSame(StatusCode::Internal, $mapped->status()->code());
        $this->assertSame($failure->getMessage(), $mapped->getMessage());
    }

    public function testReportsUnexpectedFailuresAndDoesNotExposeTheirMessage(): void
    {
        $handler = Double::for(ExceptionHandler::class)->strict();
        $failure = new RuntimeException('database credentials leaked');
        $handler->expects('report')->with($failure);

        $mapped = (new ExceptionMapper($handler))->map($failure);

        $this->assertSame(StatusCode::Unknown, $mapped->status()->code());
        $this->assertSame('An unknown error occurred while handling the RPC.', $mapped->getMessage());
        $this->assertStringNotContainsString('credentials', $mapped->getMessage());
    }

    public function testReportsInvalidServiceResponsesAsInternal(): void
    {
        $handler = Double::for(ExceptionHandler::class)->strict();
        $failure = new RuntimeException('The stream yielded a string.');
        $handler->expects('report')->with($failure);

        $mapped = (new ExceptionMapper($handler))->invalidResponse($failure);

        $this->assertSame(StatusCode::Internal, $mapped->status()->code());
        $this->assertSame('The gRPC service returned an invalid response.', $mapped->getMessage());
    }

    public function testPreservesCancellationPassedAsAnInvalidResponse(): void
    {
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->never();
        $cancellation = new CanceledException;

        $this->expectExceptionObject($cancellation);

        (new ExceptionMapper($handler))->invalidResponse($cancellation);
    }

    public function testPreservesCancellationRaisedByTheReporter(): void
    {
        $failure = new RuntimeException('service failed');
        $cancellation = new CanceledException;
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->with($failure)->throws($cancellation);

        $this->expectExceptionObject($cancellation);

        (new ExceptionMapper($handler))->map($failure);
    }

    public function testReporterFailureFallsBackWithoutChangingTheMappedStatus(): void
    {
        $directory = ParallelTesting::tempDir('ExceptionMapperTest');
        (new Filesystem)->deleteDirectory($directory);
        mkdir($directory, 0777, true);
        $errorLog = $directory . '/php-error.log';
        $previousErrorLog = ini_set('error_log', $errorLog);

        try {
            $handler = Double::for(ExceptionHandler::class)->strict();
            $failure = new RuntimeException('The original service failure.');
            $handler->expects('report')
                ->with($failure)
                ->throws(new RuntimeException('The exception reporter failed.'));

            $mapped = (new ExceptionMapper($handler))->map($failure);
            $contents = file_get_contents($errorLog);

            $this->assertSame(StatusCode::Unknown, $mapped->status()->code());
            $this->assertIsString($contents);
            $this->assertStringContainsString('The original service failure.', $contents);
            $this->assertStringNotContainsString('The exception reporter failed.', $contents);
        } finally {
            if ($previousErrorLog !== false) {
                ini_set('error_log', $previousErrorLog);
            }

            (new Filesystem)->deleteDirectory($directory);
        }
    }

    public function testPreservesCoroutineCancellationForTheOwningCallLifecycle(): void
    {
        $handler = Double::for(ExceptionHandler::class)->strict();
        $handler->expects('report')->never();
        $failure = new CanceledException;

        $this->expectExceptionObject($failure);

        (new ExceptionMapper($handler))->map($failure);
    }
}
