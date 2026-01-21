<?php

declare(strict_types=1);

namespace Hypervel\Queue;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Database\DatabaseTransactionsManager;
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
     * Push a new job onto the queue.
     */
    public function push(object|string $job, mixed $data = '', ?string $queue = null): mixed
    {
        if (
            $this->shouldDispatchAfterCommit($job)
            && $this->container->has(DatabaseTransactionsManager::class)
        ) {
            return $this->container->get(DatabaseTransactionsManager::class)
                ->addCallback(
                    fn () => $this->executeJob($job, $data, $queue)
                );
        }

        $this->executeJob($job, $data, $queue);

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
