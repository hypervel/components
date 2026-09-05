<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Closure;
use DateInterval;
use DateTimeInterface;
use Hypervel\Bus\DispatchLockContext;
use Hypervel\Coordinator\Timer;
use Hypervel\Database\DatabaseTransactionsManager;
use Swoole\Coroutine\CanceledException;
use Throwable;

/** @phpstan-import-type LockSnapshot from DispatchLockContext */
abstract class CoroutineQueue extends SyncQueue
{
    /**
     * The exception callback that should be used for handling uncaught exceptions.
     *
     * @var null|callable
     */
    protected $exceptionCallback;

    /**
     * The timer used to schedule delayed jobs.
     */
    protected Timer $timer;

    /**
     * Create a new coroutine queue instance.
     */
    public function __construct(
        bool $dispatchAfterCommit = false,
        ?Timer $timer = null
    ) {
        parent::__construct($dispatchAfterCommit);
        $this->timer = $timer ?? new Timer;
    }

    /**
     * Push a new job onto the queue after (n) seconds.
     */
    public function later(DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        if ($this->shouldDispatchAfterCommit($job)
            && $this->container->has('db.transactions')
        ) {
            /** @var DatabaseTransactionsManager $transactions */
            $transactions = $this->container->make('db.transactions');

            $this->deferEnqueueAfterCommit(
                $transactions,
                $job,
                static function (Queue $owner) use ($delay, $job, $data, $queue): int {
                    /** @var CoroutineQueue $owner */
                    return $owner->scheduleJob($delay, $job, $data, $queue);
                },
            );

            return null;
        }

        return $this->scheduleJob($delay, $job, $data, $queue);
    }

    /**
     * Set the exception callback for the coroutine queue.
     *
     * Boot-only. The callback persists on the cached queue connection for the
     * worker lifetime and handles every subsequent coroutine queue exception.
     */
    public function setExceptionCallback(?callable $callback): static
    {
        $this->exceptionCallback = $callback;

        return $this;
    }

    /**
     * Create and schedule a delayed job.
     */
    protected function scheduleJob(
        DateInterval|DateTimeInterface|int $delay,
        object|string $job,
        mixed $data,
        ?string $queue,
    ): int {
        $payload = $this->createPayload($job, $queue, $data);
        $snapshot = is_object($job) ? DispatchLockContext::snapshot($job) : null;
        $timerId = $this->scheduleTimer($delay, $payload, $queue, $snapshot);

        $this->acceptDispatchLocks($job);

        return $timerId;
    }

    /**
     * Schedule the timer that will execute the job after the delay.
     *
     * Skips execution when the worker is closing — pending delayed jobs are
     * dropped rather than racing against shutdown cleanup. Devs needing
     * durability across worker restarts should use a persistent queue.
     *
     * @param null|LockSnapshot $snapshot
     */
    protected function scheduleTimer(
        DateInterval|DateTimeInterface|int $delay,
        string $payload,
        ?string $queue,
        ?array $snapshot,
    ): int {
        // Timer::clear() does not invoke this callback, so code clearing the timer owns cleanup.
        return $this->timer->after(
            max(0.0, (float) $this->secondsUntil($delay)),
            function (bool $isClosing = false) use ($payload, $queue, $snapshot): void {
                if ($isClosing) {
                    if ($snapshot !== null) {
                        DispatchLockContext::releaseSnapshot($snapshot);
                    }

                    return;
                }

                $this->executePayload($payload, $queue);
            }
        );
    }

    /**
     * Schedule a serialized job for coroutine execution.
     */
    protected function executePayload(string $payload, ?string $queue = null): int
    {
        $this->scheduleExecution(function () use ($payload, $queue): void {
            try {
                parent::executePayload($payload, $queue);
            } catch (CanceledException $exception) {
                throw $exception;
            } catch (Throwable $e) {
                if ($this->exceptionCallback) {
                    ($this->exceptionCallback)($e);
                }
            }
        });

        return 0;
    }

    /**
     * Schedule the given execution callback.
     */
    abstract protected function scheduleExecution(Closure $execution): void;
}
