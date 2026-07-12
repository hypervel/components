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
     * Create a new signal manager instance.
     */
    public function __construct(protected Container $container)
    {
        $this->config = $container->make(ConfigContract::class);
    }

    /**
     * Initialize the signal handlers from config.
     */
    public function init(): void
    {
        $this->handlers = [];

        foreach ($this->getQueue() as $class) {
            /** @var SignalHandler $handler */
            $handler = $this->container->make($class);
            foreach ($handler->listen() as [$process, $signal]) {
                if ($process & SignalHandler::WORKER) {
                    $this->handlers[SignalHandler::WORKER][$signal][] = $handler;
                } elseif ($process & SignalHandler::PROCESS) {
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
     */
    public function listen(?int $process): void
    {
        if ($this->isInvalidProcess($process) || ! Coroutine::inCoroutine()) {
            return;
        }

        $coroutineIds = [];

        try {
            foreach ($this->handlers[$process] ?? [] as $signal => $handlers) {
                $coroutineIds[] = Coroutine::create(function () use ($signal, $handlers): void {
                    try {
                        while (true) {
                            $ret = EngineSignal::wait($signal, $this->config->float('signal.timeout', 5.0));

                            if ($ret) {
                                foreach ($handlers as $handler) {
                                    $handler->handle($signal);
                                }
                            }

                            if ($this->isStopped()) {
                                break;
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
     * Determine if the manager has been stopped.
     */
    public function isStopped(): bool
    {
        return $this->stopped;
    }

    /**
     * Mark the manager as stopped so signal-watcher coroutines exit.
     *
     * Boot-only. Used by SignalDeregisterListener at worker/process exit.
     * Setting this true permanently halts signal handling for the process; any
     * subsequent listen() call also spawns coroutines that exit on their first
     * wait cycle.
     */
    public function setStopped(bool $stopped): self
    {
        $this->stopped = $stopped;
        return $this;
    }

    /**
     * Determine if the given process type is invalid.
     */
    protected function isInvalidProcess(?int $process): bool
    {
        return ! in_array($process, [
            SignalHandler::PROCESS,
            SignalHandler::WORKER,
        ]);
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
