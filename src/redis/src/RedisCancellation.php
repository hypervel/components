<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use Hypervel\Engine\Coroutine;
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
     */
    public static function cancellationFrom(Throwable $exception, string $message): ?CanceledException
    {
        if ($exception instanceof CanceledException) {
            return $exception;
        }

        if (($exception instanceof RedisException || $exception instanceof RedisClusterException)
            && Coroutine::isCanceled()
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
        if ($operationFailure instanceof CanceledException) {
            throw $operationFailure;
        }

        if ($cleanupFailure instanceof CanceledException) {
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
