<?php

declare(strict_types=1);

namespace Hypervel\Http\Client\Guzzle;

use GuzzleHttp\Promise\TaskQueue;
use GuzzleHttp\Promise\TaskQueueInterface;
use GuzzleHttp\Promise\Utils;
use Hypervel\Engine\Coroutine;

/**
 * Drain each coroutine's callbacks independently, preserving the original queue outside coroutines.
 */
class CoroutineTaskQueue implements TaskQueueInterface
{
    /**
     * Retain the existing non-coroutine queue and its shutdown behavior.
     */
    public function __construct(protected TaskQueueInterface $outside)
    {
    }

    /**
     * Install the routing queue once without replacing an existing installation.
     *
     * Boot or tests only. Guzzle's queue is process-global; install before scheduling work.
     */
    public static function install(): void
    {
        $queue = Utils::queue();

        if (! $queue instanceof self) {
            Utils::queue(new self($queue));
        }
    }

    /**
     * Determine whether the current coroutine has pending tasks.
     */
    public function isEmpty(): bool
    {
        return $this->current()->isEmpty();
    }

    /**
     * Add a task to the current coroutine's queue.
     */
    public function add(callable $task): void
    {
        $this->current()->add($task);
    }

    /**
     * Run the current coroutine's pending tasks.
     */
    public function run(): void
    {
        $this->current()->run();
    }

    /**
     * Discard outside-coroutine test tasks without retaining a shutdown callback.
     *
     * Tests only. The PHPUnit extension seeds a shutdown-disabled delegate before installation.
     */
    public function resetOutside(): void
    {
        $this->outside = new TaskQueue(false);
    }

    /**
     * Resolve the queue owned by the current native coroutine.
     */
    protected function current(): TaskQueueInterface
    {
        if (Coroutine::id() < 0) {
            return $this->outside;
        }

        return CoroutineState::forCurrent()->queue;
    }
}
