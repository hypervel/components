<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Coroutine\Data;

use Closure;
use Hypervel\Coroutine\Coroutine;
use Throwable;

class CancellationPrecedence
{
    private ?Throwable $failure = null;

    /**
     * @param list<Closure(): void> $callbacks
     */
    public function keepsFirstFailure(array $callbacks): ?Throwable
    {
        $failure = null;

        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        return $failure;
    }

    public function keepsFailureSetInTry(): ?Throwable
    {
        $failure = null;

        try {
            $failure = $this->attempt();
            $this->cleanUp();
        } catch (Throwable $exception) {
            $failure ??= $exception;
        }

        return $failure;
    }

    public function keepsFailureInProperty(): void
    {
        $this->failure = null;

        try {
            $this->cleanUp();
        } catch (Throwable $exception) {
            $this->failure ??= $exception;
        }
    }

    /**
     * @param list<Closure(): void> $callbacks
     */
    public function guardsWithoutLeaving(array $callbacks): ?Throwable
    {
        $failure = null;

        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $exception) {
                if (Coroutine::causedByCancellation($exception)) {
                    $this->cleanUp();
                }

                $failure ??= $exception;
            }
        }

        return $failure;
    }

    public function keepsOnlyFailure(): ?Throwable
    {
        $failure = null;

        try {
            $this->cleanUp();
        } catch (Throwable $exception) {
            $failure ??= $exception;
        }

        return $failure;
    }

    /**
     * @param list<Closure(): void> $callbacks
     */
    public function leavesOnCancellation(array $callbacks): ?Throwable
    {
        $failure = null;

        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $exception) {
                if (Coroutine::causedByCancellation($exception)) {
                    throw $exception;
                }

                $failure ??= $exception;
            }
        }

        return $failure;
    }

    /**
     * @param list<Closure(): void> $callbacks
     */
    public function keepsOrdinaryFailuresApart(array $callbacks): ?Throwable
    {
        $failure = null;
        $cancellation = null;

        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $exception) {
                if (Coroutine::causedByCancellation($exception)) {
                    $cancellation = $exception;
                } else {
                    $failure ??= $exception;
                }
            }
        }

        return $cancellation ?? $failure;
    }

    public function keepsCleanupFailure(Closure $work, Closure $cleanUp, ?Throwable $failure): ?Throwable
    {
        try {
            $work();
        } catch (Throwable $exception) {
            try {
                $cleanUp();
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        return $failure;
    }

    public function keepsOrdinaryCleanupFailure(Closure $work, Closure $cleanUp, ?Throwable $failure): ?Throwable
    {
        try {
            $work();
        } catch (Throwable $exception) {
            try {
                $cleanUp();
            } catch (Throwable $exception) {
                if (Coroutine::causedByCancellation($exception)) {
                    throw $exception;
                }

                $failure ??= $exception;
            }
        }

        return $failure;
    }

    private function attempt(): ?Throwable
    {
        return null;
    }

    private function cleanUp(): void
    {
    }
}
