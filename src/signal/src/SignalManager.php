<?php

declare(strict_types=1);

namespace Hypervel\Signal;

use Hypervel\Contracts\Config\Repository as ConfigContract;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Signal\SignalHandlerInterface as SignalHandler;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Engine\Signal as EngineSignal;
use Hypervel\Support\SplPriorityQueue;
use Swoole\Coroutine\CanceledException;
use Throwable;

class SignalManager
{
    /**
     * @var SignalHandler[][][]
     */
    protected array $handlers = [];

    protected ConfigContract $config;

    protected bool $stopped = false;

    /**
     * Coroutine IDs currently blocked in a cancellable native signal wait.
     *
     * @var array<int, true>
     */
    protected array $waiting = [];

    /**
     * Create a new signal manager instance.
     */
    public function __construct(protected Container $container)
    {
        $this->config = $container->make(ConfigContract::class);
    }

    /**
     * Initialize the signal handlers from config.
     *
     * Boot-only. Reinitializing after listening starts leaves existing
     * watchers using the prior handler set until the process exits.
     */
    public function init(): void
    {
        $this->handlers = [];

        foreach ($this->getQueue() as $class) {
            /** @var SignalHandler $handler */
            $handler = $this->container->make($class);
            foreach ($handler->listen() as [$process, $signal]) {
                if ($process === SignalHandler::WORKER) {
                    $this->handlers[SignalHandler::WORKER][$signal][] = $handler;
                } elseif ($process === SignalHandler::PROCESS) {
                    $this->handlers[SignalHandler::PROCESS][$signal][] = $handler;
                }
            }
        }
    }

    /**
     * Get all registered signal handlers.
     */
    public function getHandlers(): array
    {
        return $this->handlers;
    }

    /**
     * Start listening for signals for the given process type.
     *
     * Boot-only. Each call creates another set of process-lifetime watchers
     * that would invoke the configured handlers again for the same signal.
     */
    public function listen(?int $process): void
    {
        if ($this->stopped
            || ! in_array($process, [SignalHandler::PROCESS, SignalHandler::WORKER], true)
            || ! Coroutine::inCoroutine()
        ) {
            return;
        }

        $coroutineIds = [];

        try {
            foreach ($this->handlers[$process] ?? [] as $signal => $handlers) {
                $coroutineIds[] = Coroutine::create(function () use ($signal, $handlers): void {
                    $coroutineId = Coroutine::id();

                    try {
                        while (! $this->stopped) {
                            $this->waiting[$coroutineId] = true;

                            try {
                                $received = EngineSignal::wait($signal);
                            } finally {
                                unset($this->waiting[$coroutineId]);
                            }

                            // An indefinite wait returns false only after an error or
                            // non-exception cancellation; retrying could busy-spin.
                            if (! $received) {
                                break;
                            }

                            foreach ($handlers as $handler) {
                                $handler->handle($signal);
                            }
                        }
                    } catch (CanceledException) {
                        // Intentional cancellation; the manager owns watcher cleanup.
                    }
                });
            }
        } catch (Throwable $exception) {
            foreach ($coroutineIds as $coroutineId) {
                EngineCoroutine::cancelById(
                    $coroutineId,
                    throwException: true,
                );
            }

            throw $exception;
        }
    }

    /**
     * Stop listening for signals in this process.
     *
     * The deregister listener invokes this at worker/process exit. Stopping is
     * terminal and permanently halts signal handling for this process incarnation.
     */
    public function stop(): void
    {
        $this->stopped = true;

        foreach (array_keys($this->waiting) as $coroutineId) {
            EngineCoroutine::cancelById(
                $coroutineId,
                throwException: true,
            );
        }
    }

    /**
     * Build the priority queue of signal handler classes from config.
     */
    protected function getQueue(): SplPriorityQueue
    {
        $handlers = $this->config->array('signal.handlers', []);

        $queue = new SplPriorityQueue;
        foreach ($handlers as $handler => $priority) {
            if (! is_numeric($priority)) {
                $handler = $priority;
                $priority = 0;
            }
            $queue->insert($handler, $priority);
        }

        return $queue;
    }
}
