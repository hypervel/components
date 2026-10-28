<?php

declare(strict_types=1);

namespace Hypervel\Grpc;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Grpc\Contracts\GrpcOperationObserver;
use Throwable;

class GrpcOperationRunner
{
    /** @var list<GrpcOperationObserver> */
    protected array $observers = [];

    /**
     * Register a logical gRPC operation observer.
     *
     * Boot-only. Observers persist on this singleton for the worker lifetime
     * and apply to every subsequent gRPC operation.
     */
    public function observe(GrpcOperationObserver $observer): void
    {
        $this->observers[] = $observer;
    }

    /**
     * Determine if any operation observers are registered.
     */
    public function hasObservers(): bool
    {
        return $this->observers !== [];
    }

    /**
     * Start a logical gRPC operation.
     */
    public function start(GrpcOperation $operation): GrpcOperationHandle
    {
        $startedObservers = [];

        try {
            foreach ($this->observers as $observer) {
                $startedObservers[] = [$observer, $observer->starting($operation)];
            }
        } catch (Throwable $throwable) {
            if (Coroutine::isCancellation($throwable)) {
                throw $throwable;
            }

            (new GrpcOperationHandle($operation, $startedObservers))->finish(
                new GrpcOperationResult(null, $throwable, 0),
            );

            throw $throwable;
        }

        return new GrpcOperationHandle($operation, $startedObservers);
    }
}
