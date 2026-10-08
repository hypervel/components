<?php

declare(strict_types=1);

namespace Hypervel\Http\Client;

use GuzzleHttp\Promise\TaskQueueInterface;
use GuzzleHttp\Promise\Utils;
use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Coroutine;

/**
 * Guzzle's promise task queue, kept separately for each coroutine.
 *
 * Guzzle settles promises through one task queue, and every wait runs it. With
 * one queue for the process, coroutines waiting at the same time run each
 * other's promise callbacks, or find their own taken and reject their promises.
 * Each coroutine queues and runs only its own tasks here, so a promise's
 * callbacks run in the coroutine that settles it or, for a promise already
 * settled, in the coroutine that adds them.
 */
class CoroutineTaskQueue implements TaskQueueInterface
{
    protected const string TASKS_CONTEXT_KEY = '__http.promise_tasks';

    /**
     * Indicates if the queue has been installed for this process.
     *
     * Guzzle keeps the installed queue for the life of the process, so this is
     * never reset.
     */
    protected static bool $installed = false;

    /**
     * The tasks queued outside coroutines.
     */
    protected PromiseTasks $tasks;

    /**
     * Create a new task queue instance.
     */
    public function __construct()
    {
        $this->tasks = new PromiseTasks;
    }

    /**
     * Install the queue as Guzzle's promise task queue for this process.
     *
     * Boot-only. Tasks already waiting in Guzzle's previous queue are not
     * carried over. Later calls, such as from another application instance,
     * keep the queue that is already installed.
     */
    public static function install(): void
    {
        if (static::$installed) {
            return;
        }

        static::$installed = true;

        Utils::queue($queue = new static);

        // Guzzle's default queue runs what is left at exit unless a fatal error ended the script.
        register_shutdown_function(static function () use ($queue): void {
            if ((error_get_last()['type'] ?? null) !== E_ERROR) {
                $queue->run();
            }
        });
    }

    /**
     * Determine if the current coroutine has no queued tasks.
     */
    public function isEmpty(): bool
    {
        return $this->tasks()?->isEmpty() ?? true;
    }

    /**
     * Add a task to the current coroutine's queue.
     */
    public function add(callable $task): void
    {
        if (($tasks = $this->tasks()) === null) {
            CoroutineContext::set(self::TASKS_CONTEXT_KEY, $tasks = new PromiseTasks);
        }

        $tasks->enqueue($task);
    }

    /**
     * Run the current coroutine's tasks, including any they add, until none remain.
     */
    public function run(): void
    {
        $tasks = $this->tasks();

        while ($tasks !== null && ! $tasks->isEmpty()) {
            $tasks->dequeue()();
        }
    }

    /**
     * Get the current coroutine's tasks, or the tasks queued outside coroutines.
     */
    protected function tasks(): ?PromiseTasks
    {
        return Coroutine::inCoroutine()
            ? CoroutineContext::get(self::TASKS_CONTEXT_KEY)
            : $this->tasks;
    }
}
