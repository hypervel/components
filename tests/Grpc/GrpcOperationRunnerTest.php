<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Grpc\ClientGrpcOperation;
use Hypervel\Grpc\Contracts\GrpcOperationObserver;
use Hypervel\Grpc\GrpcOperation;
use Hypervel\Grpc\GrpcOperationResult;
use Hypervel\Grpc\GrpcOperationRunner;
use Hypervel\Grpc\Metadata;
use Hypervel\Grpc\Protocol\ServiceMethod;
use Hypervel\Grpc\ServerGrpcOperation;
use Hypervel\Grpc\Status;
use Hypervel\Grpc\StatusCode;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class GrpcOperationRunnerTest extends TestCase
{
    public function testClientObserversReplaceMetadataInRegistrationOrder(): void
    {
        $runner = new GrpcOperationRunner;
        $operation = new ClientGrpcOperation(
            ServiceMethod::parse('/example.Echo/Say'),
            'example.test',
            443,
            Metadata::make(['traceparent' => 'original']),
        );
        $seen = [];

        $runner->observe($this->observer(
            function (GrpcOperation $operation) use (&$seen): string {
                $this->assertInstanceOf(ClientGrpcOperation::class, $operation);
                $seen[] = $operation->metadata()->first('traceparent');
                $operation->withMetadata(
                    $operation->metadata()->without('traceparent')->with('traceparent', 'first'),
                );

                return 'first-token';
            },
        ));
        $runner->observe($this->observer(
            function (GrpcOperation $operation) use (&$seen): string {
                $this->assertInstanceOf(ClientGrpcOperation::class, $operation);
                $seen[] = $operation->metadata()->first('traceparent');
                $operation->withMetadata(
                    $operation->metadata()->without('traceparent')->with('traceparent', 'second'),
                );

                return 'second-token';
            },
        ));

        $handle = $runner->start($operation);

        $this->assertTrue($runner->hasObservers());
        $this->assertSame(['original', 'first'], $seen);
        $this->assertSame('second', $operation->metadata()->first('traceparent'));
        $this->assertSame('example.Echo', $operation->serviceMethod()->service);
        $this->assertSame('Say', $operation->serviceMethod()->method);
        $this->assertSame('example.test', $operation->serverAddress);
        $this->assertSame(443, $operation->serverPort);
        $this->assertFalse($handle->isFinished());
    }

    public function testServerOperationRecognizesOnlyCanonicalServicePaths(): void
    {
        $operation = new ServerGrpcOperation(
            'POST',
            '/example.Echo/Say',
            ['traceparent' => 'parent'],
            'grpc',
            '0.0.0.0',
            50051,
        );
        $invalid = new ServerGrpcOperation(
            'POST',
            '/example.Echo/Say?debug=true',
            [],
            'grpc',
            '0.0.0.0',
            50051,
        );

        $this->assertSame('example.Echo', $operation->serviceMethod()?->service);
        $this->assertSame('Say', $operation->serviceMethod()?->method);
        $this->assertNull($invalid->serviceMethod());
    }

    public function testStartingFailureFinishesPreviouslyStartedObserversAndRemainsPrimary(): void
    {
        $runner = new GrpcOperationRunner;
        $startingFailure = new RuntimeException('starting failed');
        $completionFailure = new RuntimeException('completion failed');
        $receivedResult = null;

        $runner->observe($this->observer(
            fn (): string => 'token',
            function (
                GrpcOperation $operation,
                mixed $token,
                GrpcOperationResult $result,
            ) use (&$receivedResult, $completionFailure): never {
                $receivedResult = $result;

                throw $completionFailure;
            },
        ));
        $runner->observe($this->observer(
            function () use ($startingFailure): never {
                throw $startingFailure;
            },
        ));
        $this->bindReportingHandler()->shouldReceive('report')->once()->with($completionFailure);

        try {
            $runner->start($this->clientOperation());
            $this->fail('Expected observer startup to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($startingFailure, $exception);
        }

        $this->assertInstanceOf(GrpcOperationResult::class, $receivedResult);
        $this->assertSame($startingFailure, $receivedResult->exception);
        $this->assertSame(0, $receivedResult->attemptCount);
    }

    public function testSuccessfulFinishAttemptsEveryObserverThrowsTheFirstFailureOnceAndReportsTheRest(): void
    {
        $runner = new GrpcOperationRunner;
        $firstFailure = new RuntimeException('first failed');
        $secondFailure = new RuntimeException('second failed');
        $finished = [];

        $runner->observe($this->observer(
            fn (): string => 'first',
            function () use (&$finished, $firstFailure): never {
                $finished[] = 'first';

                throw $firstFailure;
            },
        ));
        $runner->observe($this->observer(
            fn (): string => 'second',
            function () use (&$finished, $secondFailure): never {
                $finished[] = 'second';

                throw $secondFailure;
            },
        ));
        $handle = $runner->start($this->clientOperation());
        $result = new GrpcOperationResult(new Status(StatusCode::Ok), null, 2);
        $this->bindReportingHandler()->shouldReceive('report')->once()->with($secondFailure);

        try {
            $handle->finish($result);
            $this->fail('Expected observer completion to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame($firstFailure, $exception);
        }

        $handle->finish($result);

        $this->assertTrue($handle->isFinished());
        $this->assertSame(['first', 'second'], $finished);
    }

    public function testRealFailureReportsOrdinaryObserverFailuresInsteadOfThrowingThem(): void
    {
        foreach ([
            new GrpcOperationResult(new Status(StatusCode::Unavailable), null, 1),
            new GrpcOperationResult(null, new RuntimeException('transport failed'), 1),
        ] as $result) {
            $runner = new GrpcOperationRunner;
            $finished = 0;
            $observerFailure = new RuntimeException('observer failed');

            $runner->observe($this->observer(
                fn (): null => null,
                function () use (&$finished, $observerFailure): never {
                    ++$finished;

                    throw $observerFailure;
                },
            ));
            $this->bindReportingHandler()->shouldReceive('report')->once()->with($observerFailure);

            $runner->start($this->clientOperation())->finish($result);

            $this->assertSame(1, $finished);
        }
    }

    public function testObserverRethrowingTheOperationsFailureIsNotReportedAsItsOwn(): void
    {
        $runner = new GrpcOperationRunner;
        $failure = new RuntimeException('transport failed');
        $runner->observe($this->observer(
            fn (): null => null,
            function (GrpcOperation $operation, mixed $token, GrpcOperationResult $result): never {
                throw $result->exception;
            },
        ));
        $reported = [];
        $this->bindReportingHandler()->shouldReceive('report')->andReturnUsing(function (Throwable $throwable) use (&$reported): void {
            $reported[] = $throwable;
        });

        $runner->start($this->clientOperation())->finish(new GrpcOperationResult(null, $failure, 1));

        $this->assertSame([], $reported);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testStartingCancellationFinishesPreviouslyStartedObservers(bool $cleanupThrows): void
    {
        $runner = new GrpcOperationRunner;
        $cancellation = new CanceledException;
        $failure = $cleanupThrows ? new RuntimeException('Observer cleanup failed.', previous: $cancellation) : $cancellation;
        $receivedResult = null;

        $runner->observe($this->observer(
            fn (): null => null,
            function (GrpcOperation $operation, mixed $token, GrpcOperationResult $result) use (&$receivedResult): void {
                $receivedResult = $result;
            },
        ));
        $runner->observe($this->observer(
            function () use ($failure): never {
                throw $failure;
            },
        ));

        try {
            $runner->start($this->clientOperation());
            $this->fail('Expected observer startup to be cancelled.');
        } catch (Throwable $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertSame($failure, $receivedResult?->exception);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testCompletionCancellationNotifiesLaterObserversAndSupersedesARealFailure(bool $cleanupThrows): void
    {
        $runner = new GrpcOperationRunner;
        $cancellation = new CanceledException;
        $failure = $cleanupThrows ? new RuntimeException('Observer cleanup failed.', previous: $cancellation) : $cancellation;
        $laterFinished = false;

        $runner->observe($this->observer(
            fn (): null => null,
            function () use ($failure): never {
                throw $failure;
            },
        ));
        $runner->observe($this->observer(
            fn (): null => null,
            function () use (&$laterFinished): void {
                $laterFinished = true;
            },
        ));
        $handle = $runner->start($this->clientOperation());

        try {
            $handle->finish(new GrpcOperationResult(
                null,
                new RuntimeException('operation failed'),
                1,
            ));
            $this->fail('Expected observer completion to be cancelled.');
        } catch (Throwable $exception) {
            $this->assertSame($failure, $exception);
        }

        $this->assertTrue($handle->isFinished());
        $this->assertTrue($laterFinished);
    }

    public function testObserverCancellationDoesNotReplaceTheOperationsOwnCancellation(): void
    {
        $runner = new GrpcOperationRunner;
        $observerFailure = new RuntimeException('Observer cleanup failed.', previous: new CanceledException);

        $runner->observe($this->observer(
            fn (): null => null,
            function () use ($observerFailure): never {
                throw $observerFailure;
            },
        ));
        $this->bindReportingHandler()->shouldReceive('report')->once()->with($observerFailure);
        $handle = $runner->start($this->clientOperation());

        $handle->finish(new GrpcOperationResult(null, new CanceledException, 1));

        $this->assertTrue($handle->isFinished());
    }

    private function clientOperation(): ClientGrpcOperation
    {
        return new ClientGrpcOperation(
            ServiceMethod::parse('/example.Echo/Say'),
            'example.test',
            443,
            Metadata::make(),
        );
    }

    private function observer(Closure $starting, ?Closure $finished = null): GrpcOperationObserver
    {
        return new GrpcOperationObserverStub(
            $starting,
            $finished ?? static fn (): null => null,
        );
    }

    /**
     * Bind a mocked exception handler for displaced failure reports.
     */
    private function bindReportingHandler(): MockInterface
    {
        $container = new Container;
        Container::setInstance($container);
        $handler = m::mock(ExceptionHandler::class);
        $container->instance(ExceptionHandler::class, $handler);

        return $handler;
    }
}

class GrpcOperationObserverStub implements GrpcOperationObserver
{
    public function __construct(
        private readonly Closure $startingCallback,
        private readonly Closure $finishedCallback,
    ) {
    }

    public function starting(GrpcOperation $operation): mixed
    {
        return ($this->startingCallback)($operation);
    }

    public function finished(
        GrpcOperation $operation,
        mixed $token,
        GrpcOperationResult $result,
    ): void {
        ($this->finishedCallback)($operation, $token, $result);
    }
}
