<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Hyperf\Engine\Coroutine;
use Hypervel\Database\TransactionManager;
use Throwable;

class DeferQueue extends SyncQueue
{
    /**
     * The exception callback that should be used for handling uncaught exceptions in defer.
     *
     * @var null|callable
     */
    protected $exceptionCallback;

    /**
     * Push a new job onto the queue.
     */
    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        if ($this->shouldDispatchAfterCommit($job)
            && $this->container->has(TransactionManager::class)
        ) {
            return $this->container->get(TransactionManager::class)
                ->addCallback(
                    fn () => $this->deferJob($job, $data, $queue)
                );
        }

        $this->deferJob($job, $data, $queue);

        return null;
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
