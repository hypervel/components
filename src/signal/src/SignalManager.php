<?php

declare(strict_types=1);

namespace Hypervel\Signal;

use Hypervel\Contracts\Config\Repository as ConfigContract;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Signal\SignalHandler;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Engine\Signal as EngineSignal;
use Hypervel\Support\SafeCaller;
use Hypervel\Support\SplPriorityQueue;
use InvalidArgumentException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class SignalManager
{
    protected ConfigContract $config;

    protected SafeCaller $safeCaller;

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
        $this->safeCaller = $container->make(SafeCaller::class);
    }

    /**
     * Start listening for signals for the given process type.
     *
     * Boot-only. Call once for each process incarnation. Another call creates
     * competing native waits and strands the earlier watcher for each signal.
     */
    public function listen(string $process): void
    {
        if (! in_array($process, [SignalHandler::WORKER, SignalHandler::SERVER_PROCESS], true)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported signal process [%s]. Supported processes are [%s] and [%s].',
                $process,
                SignalHandler::WORKER,
                SignalHandler::SERVER_PROCESS,
            ));
        }

        if ($this->stopped || ! Coroutine::inCoroutine()) {
            return;
        }

        $signalHandlers = $this->resolveHandlers($process);
        $coroutineIds = [];

        try {
            foreach ($signalHandlers as $signal => $handlers) {
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
                                $this->safeCaller->call(
                                    fn () => $handler->handle($signal),
                                );
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
     * Parked native signal waits keep the Swoole reactor active. The deregister
     * listener calls this at worker or server-process exit so the process can
     * exit normally instead of waiting for forced termination.
     *
     * Stopping is terminal for the current process incarnation and prevents
     * subsequent signal listeners from starting.
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
     * Resolve the signal handlers for the given process type.
     *
     * @return array<int, list<SignalHandler>>
     */
    protected function resolveHandlers(string $process): array
    {
        $signalHandlers = [];

        foreach ($this->getQueue() as $class) {
            $handler = $this->container->make($class);

            if (! $handler instanceof SignalHandler) {
                throw new InvalidArgumentException(sprintf(
                    'Signal handler [%s] must implement [%s].',
                    $class,
                    SignalHandler::class,
                ));
            }

            foreach ($handler->signals() as $handlerProcess => $signals) {
                if (! in_array($handlerProcess, [SignalHandler::WORKER, SignalHandler::SERVER_PROCESS], true)) {
                    throw new InvalidArgumentException(sprintf(
                        'Signal handler [%s] declares unsupported process [%s]. Supported processes are [%s] and [%s].',
                        $class,
                        $handlerProcess,
                        SignalHandler::WORKER,
                        SignalHandler::SERVER_PROCESS,
                    ));
                }

                if (! is_array($signals) || ! array_all($signals, static fn (mixed $signal): bool => is_int($signal))) {
                    throw new InvalidArgumentException(sprintf(
                        'Signal handler [%s] must declare an array of signal numbers for the [%s] process.',
                        $class,
                        $handlerProcess,
                    ));
                }

                if ($handlerProcess !== $process) {
                    continue;
                }

                foreach ($signals as $signal) {
                    $signalHandlers[$signal][] = $handler;
                }
            }
        }

        return $signalHandlers;
    }

    /**
     * Build the priority queue of signal handler classes from config.
     *
     * @return SplPriorityQueue<class-string<SignalHandler>, float|int>
     */
    protected function getQueue(): SplPriorityQueue
    {
        $handlers = $this->config->array('signal.handlers', []);

        $queue = new SplPriorityQueue;
        foreach ($handlers as $handler => $priority) {
            if (is_int($handler)) {
                if (! is_string($priority)) {
                    throw new InvalidArgumentException(sprintf(
                        'Signal handler at index [%d] must be a class name.',
                        $handler,
                    ));
                }

                $handler = $priority;
                $priority = 0;
            } elseif (! is_numeric($priority)) {
                throw new InvalidArgumentException(sprintf(
                    'The priority for signal handler [%s] must be numeric.',
                    $handler,
                ));
            }

            $priority = is_string($priority) ? $priority + 0 : $priority;
            $queue->insert($handler, $priority);
        }

        return $queue;
    }
}
