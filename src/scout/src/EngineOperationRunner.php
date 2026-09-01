<?php

declare(strict_types=1);

namespace Hypervel\Scout;

use Closure;
use Hypervel\Scout\Contracts\EngineOperationObserver;
use Swoole\Coroutine\CanceledException;
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
     * Boot-only. Observers persist on this singleton for the worker lifetime
     * and apply to every subsequent engine operation.
     */
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
        } catch (CanceledException $throwable) {
            throw $throwable;
        } catch (Throwable $throwable) {
            $operationException = $throwable;
        }

        $observerException = null;

        foreach ($startedObservers as [$observer, $token]) {
            try {
                $observer->finished($operation, $token, $operationException);
            } catch (CanceledException $throwable) {
                throw $throwable;
            } catch (Throwable $throwable) {
                $observerException ??= $throwable;
            }
        }

        if ($operationException !== null) {
            throw $operationException;
        }

        if ($observerException !== null) {
            throw $observerException;
        }

        return $result;
    }
}
