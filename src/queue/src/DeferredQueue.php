<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use DateInterval;
use DateTimeInterface;
use Hypervel\Coordinator\Timer;
use Hypervel\Engine\Coroutine;
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
     * Create a new deferred queue instance.
     */
    public function __construct(
        protected ?bool $dispatchAfterCommit = false,
        protected ?Timer $timer = null
    ) {
        if (! $this->timer) {
            $this->timer = new Timer;
        }
    }

    /**
     * Push a new job onto the queue.
     */
    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        if ($this->shouldDispatchAfterCommit($job)
            && $this->container->has('db.transactions')
        ) {
            return $this->container->make('db.transactions')
                ->addCallback(
                    fn () => $this->deferJob($job, $data, $queue)
                );
        }

        $this->deferJob($job, $data, $queue);

        return null;
    }

    /**
     * Push a new job onto the queue after (n) seconds.
     */
    public function later(DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->timer->after(
            (float) $this->secondsUntil($delay),
            fn () => $this->deferJob($job, $data, $queue)
        );
    }

    /**
     * Set the exception callback for the deferred queue.
     */
    public function setExceptionCallback(?callable $callback): static
    {
        $this->exceptionCallback = $callback;

        return $this;
    }

    /**
     * Defer a new job onto the deferred queue.
     */
    protected function deferJob(object|string $job, mixed $data = '', ?string $queue = null): void
    {
        Coroutine::defer(function () use ($job, $data, $queue) {
            try {
                $this->executeJob($job, $data, $queue);
            } catch (Throwable $e) {
                if ($this->exceptionCallback) {
                    ($this->exceptionCallback)($e);
                }
            }
        });
    }
}
