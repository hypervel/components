<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Server;

use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Grpc\Exceptions\ProtocolException;
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\StatusCode;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * Map service and protocol failures without invoking the HTTP exception renderer.
 *
 * @internal
 */
class ExceptionMapper
{
    public function __construct(private readonly ExceptionHandler $exceptions)
    {
    }

    /**
     * Map a failure raised while handling a gRPC call.
     */
    public function map(Throwable $throwable): RpcException
    {
        if (Coroutine::causedByCancellation($throwable)) {
            throw $throwable;
        }

        if ($throwable instanceof RpcException) {
            return $throwable;
        }

        if ($throwable instanceof ProtocolException) {
            return new RpcException(StatusCode::Internal, $throwable->getMessage());
        }

        $this->report($throwable);

        return new RpcException(StatusCode::Unknown, 'An unknown error occurred while handling the RPC.');
    }

    /**
     * Map an invalid service return value or streamed item.
     */
    public function invalidResponse(Throwable $throwable): RpcException
    {
        if (Coroutine::causedByCancellation($throwable)) {
            throw $throwable;
        }

        $this->report($throwable);

        return new RpcException(StatusCode::Internal, 'The gRPC service returned an invalid response.');
    }

    /**
     * Report a failure without allowing the reporter to replace it.
     */
    public function report(Throwable $throwable): void
    {
        // @phpstan-ignore hypervel.cancellation.exactType (a direct cancellation is never reported; a failure carrying it is reported)
        if ($throwable instanceof CanceledException) {
            throw $throwable;
        }

        try {
            $this->exceptions->report($throwable);
        } catch (Throwable $reportFailure) {
            if (Coroutine::causedByCancellation($reportFailure)) {
                throw $reportFailure;
            }

            try {
                error_log((string) $throwable);
            } catch (Throwable) {
            }
        }
    }
}
