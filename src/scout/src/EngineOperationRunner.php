<?php

declare(strict_types=1);

namespace Hypervel\Scout;

use Closure;
use Hypervel\Scout\Contracts\EngineOperationObserver;
use Hypervel\Support\Attributes\BootOnly;
use Hypervel\Support\FailureReporter;
use Throwable;

class EngineOperationRunner
{
    /**
     * The registered operation observers.
     *
     * @var array<EngineOperationObserver>
     */
    protected array $observers = [];

    /**
     * Register an engine operation observer.
     *
     * Observers persist on this singleton for the worker lifetime and apply
     * to every subsequent engine operation.
     */
    #[BootOnly(whenShared: true)]
    public function observe(EngineOperationObserver $observer): void
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
     * Run an engine operation while notifying observers.
     *
     * @template TResult
     * @param Closure(): TResult $callback
     * @return TResult
     */
    public function run(EngineOperation $operation, Closure $callback): mixed
    {
        if ($this->observers === []) {
            return $callback();
        }

        $startedObservers = [];
        $result = null;
        $operationException = null;

        try {
            foreach ($this->observers as $observer) {
                $startedObservers[] = [$observer, $observer->starting($operation)];
            }

            $result = $callback();
        } catch (Throwable $throwable) {
            // Cancellation is passed on too, so started observers can finish.
            $operationException = $throwable;
        }

        $observerException = null;

        foreach ($startedObservers as [$observer, $token]) {
            try {
                $observer->finished($operation, $token, $operationException);
            } catch (Throwable $throwable) {
                // An observer that rethrows the operation's failure adds none; it is thrown below.
                if ($throwable !== $operationException) {
                    $observerException = FailureReporter::preferCancellation($observerException, $throwable);
                }
            }
        }

        $exception = $observerException === null
            ? $operationException
            : FailureReporter::preferCancellation($operationException, $observerException);

        if ($exception !== null) {
            throw $exception;
        }

        return $result;
    }
}
