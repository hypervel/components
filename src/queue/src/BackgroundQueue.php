<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use DateInterval;
use DateTimeInterface;
use Hypervel\Coordinator\Timer;
use Hypervel\Coroutine\Coroutine;
use Throwable;

class BackgroundQueue extends SyncQueue
{
    /**
     * The exception callback that should be used for handling uncaught exceptions in background execution.
     *
     * @var null|callable
     */
    protected $exceptionCallback;

    /**
     * The timer used to schedule delayed jobs.
     */
    protected Timer $timer;

    /**
     * Create a new background queue instance.
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
            $this->addUniqueJobRollbackCallback($job);

            return $this->container->make('db.transactions')
                ->addCallback(
                    fn () => $this->scheduleTimer($delay, $job, $data, $queue)
                );
        }

        return $this->scheduleTimer($delay, $job, $data, $queue);
    }

    /**
     * Set the exception callback for the background queue.
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
    protected function scheduleTimer(DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data, ?string $queue): int
    {
        return $this->timer->after(
            max(0.0, (float) $this->secondsUntil($delay)),
            function (bool $isClosing = false) use ($job, $data, $queue) {
                if ($isClosing) {
                    return;
                }

                $this->executeJob($job, $data, $queue);
            }
        );
    }

    /**
     * Execute a new job in the background queue.
     */
    protected function executeJob(object|string $job, mixed $data = '', ?string $queue = null): int
    {
        Coroutine::create(function () use ($job, $data, $queue) {
            try {
                parent::executeJob($job, $data, $queue);
            } catch (Throwable $e) {
                if ($this->exceptionCallback) {
                    ($this->exceptionCallback)($e);
                }
            }
        });

        return 0;
    }
}
