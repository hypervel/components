<?php

declare(strict_types=1);

namespace Hypervel\Console;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Engine\Signal;
use Swoole\Coroutine\CanceledException;
use Throwable;

use function Hypervel\Coroutine\parallel;

class SignalRegistry
{
    /**
     * @var array<int, callable[]>
     */
    protected array $signalHandlers = [];

    /**
     * @var int[]
     */
    protected array $handling = [];

    public function __construct(
        protected int $timeout = 1,
        protected int $concurrentLimit = 0,
    ) {
    }

    /**
     * Register a signal handler for one or more signals.
     *
     * @param int|int[] $signo
     * @param (callable(int $signo): void) $signalHandler
     */
    public function register(int|array $signo, callable $signalHandler): void
    {
        if (is_array($signo)) {
            $registered = [];

            try {
                foreach ($signo as $signal) {
                    $signal = (int) $signal;
                    $this->register($signal, $signalHandler);
                    $registered[] = $signal;
                }
            } catch (Throwable $exception) {
                foreach (array_reverse($registered) as $signal) {
                    array_pop($this->signalHandlers[$signal]);

                    if ($this->signalHandlers[$signal] === []) {
                        unset($this->signalHandlers[$signal]);
                        $this->cancelSignal($signal);
                    }
                }

                throw $exception;
            }

            return;
        }

        $this->pushSignalHandler($signo, $signalHandler);

        try {
            $this->waitSignal($signo);
        } catch (Throwable $exception) {
            array_pop($this->signalHandlers[$signo]);

            if ($this->signalHandlers[$signo] === []) {
                unset($this->signalHandlers[$signo]);
            }

            throw $exception;
        }
    }

    /**
     * Unregister signal handlers for one, many, or all signals.
     *
     * @param null|int|int[] $signo
     */
    public function unregister(int|array|null $signo = null): void
    {
        if ($signo === null) {
            foreach (array_keys($this->handling) as $signal) {
                $this->cancelSignal($signal);
            }

            $this->signalHandlers = [];

            return;
        }

        if (is_array($signo)) {
            foreach ($signo as $signal) {
                $this->unregister((int) $signal);
            }

            return;
        }

        $this->signalHandlers[$signo] = [];
        $this->cancelSignal($signo);
    }

    /**
     * Add a signal handler to the stack for the given signal.
     *
     * @param (callable(int $signo): void) $signalHandler
     */
    protected function pushSignalHandler(int $signo, callable $signalHandler): void
    {
        $this->signalHandlers[$signo] ??= [];
        $this->signalHandlers[$signo][] = $signalHandler;
    }

    /**
     * Spawn a coroutine to wait for the given signal and invoke registered handlers.
     */
    protected function waitSignal(int $signo): void
    {
        if (isset($this->handling[$signo])) {
            return;
        }

        $this->handling[$signo] = Coroutine::create(function () use ($signo): void {
            try {
                while (true) {
                    if (! Signal::wait($signo, $this->timeout)) {
                        continue;
                    }

                    unset($this->handling[$signo]);

                    $callbacks = array_map(fn ($callback) => fn () => $callback($signo), $this->signalHandlers[$signo] ?? []);

                    try {
                        parallel($callbacks, $this->concurrentLimit);
                        return;
                    } finally {
                        posix_kill(posix_getpid(), $signo);
                    }
                }
            } catch (CanceledException) {
                // Intentional cancellation; the canceller owns the registry slot.
            }
        });
    }

    /**
     * Cancel the coroutine waiting on the given signal.
     */
    protected function cancelSignal(int $signo): void
    {
        if (! isset($this->handling[$signo])) {
            return;
        }

        EngineCoroutine::cancelById(
            $this->handling[$signo],
            throwException: true,
        );

        unset($this->handling[$signo]);
    }
}
