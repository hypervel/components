<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use DateInterval;
use DateTimeInterface;
use Hypervel\Coordinator\Timer;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\DatabaseTransactionsManager;
use Throwable;

class DeferredQueue extends SyncQueue
{
    /**
     * The exception callback that should be used for handling uncaught exceptions in defer.
     *
     * @var null|callable
     */
    protected $exceptionCallback;

    /**
     * The timer used to schedule delayed jobs.
     */
    protected Timer $timer;

    /**
     * Create a new deferred queue instance.
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

            $this->addUniqueJobRollbackCallback($transactions, $job);
            $this->addDebouncedJobRollbackCallback($transactions, $job);

            $transactions->addCallback(
                fn () => $this->scheduleTimer(
                    $delay,
                    $this->createPayload($job, $queue, $data),
                    $queue
                )
            );

            return null;
        }

        return $this->scheduleTimer(
            $delay,
            $this->createPayload($job, $queue, $data),
            $queue
        );
    }

    /**
     * Set the exception callback for the deferred queue.
     *
     * Boot-only. The callback persists on the cached queue connection for the
     * worker lifetime and handles every subsequent deferred job exception.
     */
    public function setExceptionCallback(?callable $callback): static
    {
        $this->exceptionCallback = $callback;

        return $this;
    }

    /**
     * Schedule the timer that will execute the job after the delay.
     *
     * Skips execution when the worker is closing — pending delayed jobs are
     * dropped rather than racing against shutdown cleanup. Devs needing
     * durability across worker restarts should use a persistent queue.
     */
    protected function scheduleTimer(DateInterval|DateTimeInterface|int $delay, string $payload, ?string $queue): int
    {
        return $this->timer->after(
            max(0.0, (float) $this->secondsUntil($delay)),
            function (bool $isClosing = false) use ($payload, $queue) {
                if ($isClosing) {
                    return;
                }

                $this->executePayload($payload, $queue);
            }
        );
    }

    /**
     * Defer a serialized job onto the deferred queue.
     */
    protected function executePayload(string $payload, ?string $queue = null): int
    {
        Coroutine::defer(function () use ($payload, $queue) {
            try {
                parent::executePayload($payload, $queue);
            } catch (Throwable $e) {
                if ($this->exceptionCallback) {
                    ($this->exceptionCallback)($e);
                }
            }
        });

        return 0;
    }
}
