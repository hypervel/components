<?php

declare(strict_types=1);

namespace Hypervel\Grpc;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Grpc\Contracts\GrpcOperationObserver;
use Throwable;

/**
 * @internal
 */
final class GrpcOperationHandle
{
    private bool $finished = false;

    /**
     * Create an active logical gRPC operation handle.
     *
     * @param list<array{GrpcOperationObserver, mixed}> $observers
     */
    public function __construct(
        private readonly GrpcOperation $operation,
        private readonly array $observers,
    ) {
    }

    /**
     * Determine whether the logical operation has finished.
     */
    public function isFinished(): bool
    {
        return $this->finished;
    }

    /**
     * Finish the logical operation exactly once.
     */
    public function finish(GrpcOperationResult $result): void
    {
        if ($this->finished) {
            return;
        }

        $this->finished = true;
        $observerFailure = null;

        foreach ($this->observers as [$observer, $token]) {
            try {
                $observer->finished($this->operation, $token, $result);
            } catch (Throwable $throwable) {
                if (Coroutine::isCancellation($throwable)) {
                    throw $throwable;
                }

                $observerFailure ??= $throwable;
            }
        }

        if (
            $observerFailure !== null
            && $result->exception === null
            && ($result->status === null || $result->status->isOk())
        ) {
            throw $observerFailure;
        }
    }
}
