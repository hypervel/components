<?php

declare(strict_types=1);

namespace Hypervel\Saloon\Http\Auth;

use Closure;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Locker;
use Hypervel\Database\ConnectionResolver;
use Hypervel\Saloon\Data\TokenAcquisitionResult;
use Throwable;

class TokenAcquisition
{
    /** @var array<string, TokenAcquisitionResult<mixed>> */
    protected array $active = [];

    /**
     * Share an active acquisition with callers already waiting for it.
     *
     * @template TResult
     * @param Closure(): TResult $acquire
     * @return TResult
     */
    public function run(string $key, Closure $acquire): mixed
    {
        ConnectionResolver::releaseIdleConnections();
        $lockKey = '__saloon.token_acquisition.' . $key;

        while (true) {
            /** @var TokenAcquisitionResult<TResult> $result */
            $result = $this->active[$key] ??= new TokenAcquisitionResult;

            if (! Locker::lock($lockKey, 120)) {
                if ($result->error !== null) {
                    throw $result->error;
                }

                if ($result->completed) {
                    return $result->value;
                }

                // Owner cancellation is not cancellation of its waiters.
                continue;
            }

            try {
                $result->value = $acquire();
                $result->completed = true;

                return $result->value;
            } catch (Throwable $exception) {
                if (! Coroutine::causedByCancellation($exception)) {
                    $result->error = $exception;
                }

                throw $exception;
            } finally {
                unset($this->active[$key]);
                Locker::unlock($lockKey);
            }
        }
    }
}
