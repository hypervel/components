<?php

declare(strict_types=1);

namespace Hypervel\Coroutine;

use Closure;
use Hypervel\Coroutine\Exceptions\ChildCancellationException;
use Hypervel\Coroutine\Exceptions\ChildTerminationTimeoutException;
use Hypervel\Coroutine\Exceptions\ExceptionThrower;
use Hypervel\Coroutine\Exceptions\WaitTimeoutException;
use Hypervel\Engine\Channel;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Swoole\Coroutine\CanceledException;
use Throwable;

class Waiter
{
    /**
     * The default timeout for deferred result delivery and canceled child unwind.
     */
    public const float DEFAULT_PUSH_TIMEOUT_SECONDS = 10.0;

    protected const float DEFAULT_POP_TIMEOUT_SECONDS = 10.0;

    protected float $pushTimeout = self::DEFAULT_PUSH_TIMEOUT_SECONDS;

    protected float $popTimeout = self::DEFAULT_POP_TIMEOUT_SECONDS;

    public function __construct(float $timeout = self::DEFAULT_POP_TIMEOUT_SECONDS)
    {
        $this->popTimeout = $timeout;
    }

    /**
     * Execute a closure in a coroutine and wait for the result.
     *
     * @template TReturn
     * @param Closure():TReturn $closure
     * @param null|float $timeout Timeout in seconds (null uses default)
     * @param array<string>|bool $copyContext When set, parent coroutine context is copied to the child.
     *                                        false = fresh context (default), true or empty array = copy all keys, non-empty array = copy listed keys only.
     *                                        Objects stored directly in context are shared by reference by default. Values implementing
     *                                        Hypervel\Context\ReplicableContext are copied via replicate(), while values implementing
     *                                        Hypervel\Context\NonCopyableContext are omitted.
     * @param bool $waitForChildTermination Wait without a limit for a canceled child to terminate: after the cleanup allowance on timeout, and before the waiting coroutine's own cancellation propagates
     * @return TReturn
     * @throws WaitTimeoutException When the wait times out
     * @throws ChildTerminationTimeoutException When a canceled child outlives the cleanup allowance in strict mode
     */
    public function wait(
        Closure $closure,
        ?float $timeout = null,
        bool|array $copyContext = false,
        bool $waitForChildTermination = false,
    ): mixed {
        if ($timeout === null) {
            $timeout = $this->popTimeout;
        }

        $channel = new Channel(1);
        $childCoroutineId = null;
        $childCancellationRequested = false;
        $callable = function () use ($channel, $closure): void {
            $result = null;

            Coroutine::defer(function () use ($channel, &$result): void {
                $channel->push($result, $this->pushTimeout);
            });

            try {
                $result = $closure();
            } catch (Throwable $exception) {
                $result = new ExceptionThrower($exception);
            }
        };
        $wrapper = static function (Closure $run) use (&$childCoroutineId): void {
            $childCoroutineId = Coroutine::id();
            $run();
        };

        try {
            if ($copyContext === false) {
                Coroutine::createOwned($callable, $wrapper);
            } else {
                Coroutine::forkOwned($callable, $wrapper, is_array($copyContext) ? $copyContext : []);
            }
        } catch (CanceledException $exception) {
            $this->cancelChild($childCoroutineId, $childCancellationRequested, $waitForChildTermination);
            throw $exception;
        }

        try {
            $result = $channel->pop($timeout);
        } catch (CanceledException $exception) {
            $this->cancelChild($childCoroutineId, $childCancellationRequested, $waitForChildTermination);
            throw $exception;
        }

        if ($result === false && $channel->isCanceled()) {
            $exception = new CanceledException('Waiting for a child coroutine was canceled.');
            $this->cancelChild($childCoroutineId, $childCancellationRequested, $waitForChildTermination);
            throw $exception;
        }

        if ($result === false && $channel->isTimeout()) {
            try {
                // Throw into the operation so an interrupted wait cannot be ignored accidentally,
                // then give the child a bounded interval to unwind and run deferred cleanup.
                $this->cancelChild($childCoroutineId, $childCancellationRequested);
                $joined = Coroutine::join([$childCoroutineId], $this->pushTimeout);
            } catch (CanceledException $exception) {
                $this->cancelChild($childCoroutineId, $childCancellationRequested, $waitForChildTermination);
                throw $exception;
            }

            if (! $joined && EngineCoroutine::isCanceled()) {
                $exception = new CanceledException('Waiting for a child coroutine was canceled.');
                $this->cancelChild($childCoroutineId, $childCancellationRequested, $waitForChildTermination);
                throw $exception;
            }

            // A false join may mean either timeout or a missing coroutine, so only
            // existence proves that the child survived the cleanup allowance.
            if ($waitForChildTermination && Coroutine::exists($childCoroutineId)) {
                $cancellation = $this->awaitChildTermination($childCoroutineId);

                if ($cancellation !== null) {
                    throw $cancellation;
                }

                // The wait already timed out, so discard any result produced during the extended unwind.
                throw new ChildTerminationTimeoutException(sprintf(
                    'Channel wait failed, reason: Timed out for %s s and child coroutine did not terminate within %s s',
                    $timeout,
                    $this->pushTimeout,
                ));
            }

            throw new WaitTimeoutException(sprintf('Channel wait failed, reason: Timed out for %s s', $timeout));
        }

        try {
            $joined = Coroutine::join([$childCoroutineId]);
        } catch (CanceledException $exception) {
            $this->cancelChild($childCoroutineId, $childCancellationRequested, $waitForChildTermination);
            throw $exception;
        }

        if (! $joined && EngineCoroutine::isCanceled()) {
            $exception = new CanceledException('Waiting for a child coroutine was canceled.');
            $this->cancelChild($childCoroutineId, $childCancellationRequested, $waitForChildTermination);
            throw $exception;
        }

        if ($result instanceof ExceptionThrower) {
            $exception = $result->getThrowable();

            if ($exception instanceof CanceledException) {
                throw new ChildCancellationException(
                    'A child coroutine managed by Waiter was canceled while its owner remained active.',
                    previous: $exception,
                );
            }

            throw $exception;
        }

        return $result;
    }

    /**
     * Cancel the owned child once when it is still active, and wait for it to terminate when required.
     */
    protected function cancelChild(?int $childCoroutineId, bool &$cancellationRequested, bool $waitForTermination = false): void
    {
        if ($childCoroutineId === null) {
            return;
        }

        if (! $cancellationRequested && Coroutine::exists($childCoroutineId)) {
            $cancellationRequested = true;
            EngineCoroutine::cancelById($childCoroutineId, throwException: true);
        }

        if ($waitForTermination) {
            $this->awaitChildTermination($childCoroutineId);
        }
    }

    /**
     * Wait until the child has terminated, however often the waiting coroutine is canceled meanwhile.
     *
     * @return null|CanceledException the waiting coroutine's first cancellation during the wait
     */
    protected function awaitChildTermination(int $childCoroutineId): ?CanceledException
    {
        $cancellation = null;

        // Each cancellation interrupts the join, so join again until the child is gone.
        while (Coroutine::exists($childCoroutineId)) {
            try {
                if (! Coroutine::join([$childCoroutineId]) && EngineCoroutine::isCanceled()) {
                    $cancellation ??= new CanceledException('Waiting for a child coroutine was canceled.');
                }
            } catch (CanceledException $exception) {
                $cancellation ??= $exception;
            }
        }

        return $cancellation;
    }
}
