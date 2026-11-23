<?php

declare(strict_types=1);

namespace Hypervel\Grpc;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Grpc\Contracts\GrpcOperationObserver;
use Hypervel\Support\FailureReporter;
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
                // An observer that rethrows the operation's own failure adds none; its owner handles it.
                if ($throwable !== $result->exception) {
                    $observerFailure = FailureReporter::preferCancellation($observerFailure, $throwable);
                }
            }
        }

        if ($observerFailure === null) {
            return;
        }

        // An observer's cancellation propagates unless the operation already carries one;
        // any other observer failure only replaces a successful result, and is reported otherwise.
        if (Coroutine::causedByCancellation($observerFailure)
            ? $result->exception === null || ! Coroutine::causedByCancellation($result->exception)
            : $result->exception === null && ($result->status === null || $result->status->isOk())
        ) {
            throw $observerFailure;
        }

        FailureReporter::report($observerFailure);
    }
}
