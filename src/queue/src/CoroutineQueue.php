<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use DateInterval;
use DateTimeInterface;
use Hyperf\Coordinator\Timer;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\TransactionManager;
use Throwable;

class CoroutineQueue extends SyncQueue
{
    /**
     * The exception callback that should be used for handling uncaught exceptions in defer.
     *
     * @var null|callable
     */
    protected $exceptionCallback;

    /**
     * Create a new coroutine queue instance.
     */
    public function __construct(
        protected bool $dispatchAfterCommit = false,
        protected ?Timer $timer = null
    ) {
        if (! $this->timer) {
            $this->timer = new Timer();
        }
    }

    /**
     * Push a new job onto the queue.
     */
    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        if (
            $this->shouldDispatchAfterCommit($job)
            && $this->container->has(TransactionManager::class)
        ) {
            return $this->container->get(TransactionManager::class)
                ->addCallback(
                    fn () => $this->executeJob($job, $data, $queue)
                );
        }

        $this->executeJob($job, $data, $queue);

        return null;
    }

    /**
     * Push a new job onto the queue after (n) seconds.
     */
    public function later(DateInterval|DateTimeInterface|int $delay, object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        return $this->timer->after(
            (float) $this->secondsUntil($delay),
            fn () => $this->push($job, $data, $queue)
        );
    }

    /**
     * Set the exception callback for the defer queue.
     */
    public function setExceptionCallback(?callable $callback): static
    {
        $this->exceptionCallback = $callback;

        return $this;
    }

    /**
     * Defer a new job onto the queue.
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
