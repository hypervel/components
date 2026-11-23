<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use RedisClusterException;
use RedisException;
use Swoole\Coroutine\CanceledException;
use Throwable;

/**
 * Classify Redis cancellation and preserve failure precedence during cleanup.
 *
 * @internal
 */
class RedisCancellation
{
    /**
     * Resolve an exact or native phpredis cancellation.
     *
     * Call swoole_clear_error() immediately before the native operation, so an earlier
     * cancellation the coroutine already handled cannot be mistaken for this one.
     */
    public static function cancellationFrom(Throwable $exception, string $message): ?CanceledException
    {
        // @phpstan-ignore hypervel.cancellation.exactType (the classification primitive for phpredis failures)
        if ($exception instanceof CanceledException) {
            return $exception;
        }

        // phpredis keeps a thrown cancellation as the cause; a non-throwing one leaves only the socket error.
        if (($exception instanceof RedisException || $exception instanceof RedisClusterException)
            && (Coroutine::causedByCancellation($exception)
                || (EngineCoroutine::isCanceled() && swoole_last_error() === SOCKET_ECANCELED))
        ) {
            return new CanceledException($message, previous: $exception);
        }

        return null;
    }

    /**
     * Throw an operation or cleanup failure using cancellation precedence.
     */
    public static function throwOperationOrCleanupFailure(
        ?Throwable $operationFailure,
        ?Throwable $cleanupFailure,
    ): void {
        if ($operationFailure !== null && Coroutine::causedByCancellation($operationFailure)) {
            throw $operationFailure;
        }

        if ($cleanupFailure !== null && Coroutine::causedByCancellation($cleanupFailure)) {
            throw $cleanupFailure;
        }

        if ($cleanupFailure !== null) {
            throw $cleanupFailure;
        }

        if ($operationFailure !== null) {
            throw $operationFailure;
        }
    }
}
