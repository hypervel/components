<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Server;

use Hypervel\Contracts\Debug\ExceptionHandler;
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
        if ($throwable instanceof CanceledException) {
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
        $this->report($throwable);

        return new RpcException(StatusCode::Internal, 'The gRPC service returned an invalid response.');
    }

    /**
     * Report a failure without allowing the reporter to replace it.
     */
    public function report(Throwable $throwable): void
    {
        try {
            $this->exceptions->report($throwable);
        } catch (Throwable) {
            try {
                error_log((string) $throwable);
            } catch (Throwable) {
            }
        }
    }
}
