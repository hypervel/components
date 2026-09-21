<?php

declare(strict_types=1);

namespace Hypervel\Coroutine;

use Closure;
use Hypervel\Engine\Coroutine as EngineCoroutine;
use Hypervel\Engine\Signal;
use Throwable;

/**
 * Resolve one shared instance per process through the container. Separate instances
 * create competing native waits for the same signal and lose earlier handlers.
 */
class SignalRegistry
{
    /**
     * The callbacks registered for each signal.
     *
     * @var array<int, array<int, array{owner: object, callback: callable(int): mixed}>>
     */
    protected array $signalHandlers = [];

    /**
     * The coroutine responsible for waiting and dispatching each signal.
     *
     * @var array<int, int>
     */
    protected array $handling = [];

    /**
     * The signals whose callbacks are currently running.
     *
     * @var array<int, true>
     */
    protected array $dispatching = [];

    protected int $nextRegistrationId = 0;

    /**
     * Register a signal handler for one or more signals.
     *
     * @param int|iterable<array-key, int> $signals
     * @param callable(int): mixed $callback
     */
    public function register(object $owner, int|iterable $signals, callable $callback): void
    {
        $registered = [];

        try {
            foreach (is_int($signals) ? [$signals] : $signals as $signal) {
                $registrationId = $this->nextRegistrationId++;
                $this->signalHandlers[$signal][$registrationId] = compact('owner', 'callback');
                $registered[$registrationId] = $signal;
                $this->waitSignal($signal);
            }
        } catch (Throwable $exception) {
            // Roll back this call without removing another owner's registrations.
            foreach ($registered as $registrationId => $signal) {
                unset($this->signalHandlers[$signal][$registrationId]);
                $this->removeEmptySignal($signal);
            }

            throw $exception;
        }
    }

    /**
     * Unregister an owner's handlers for one, many, or all signals.
     *
     * @param null|int|iterable<array-key, int> $signals
     */
    public function unregister(object $owner, int|iterable|null $signals = null): void
    {
        foreach (is_int($signals) ? [$signals] : ($signals ?? array_keys($this->signalHandlers)) as $signal) {
            foreach ($this->signalHandlers[$signal] ?? [] as $registrationId => $registration) {
                if ($registration['owner'] === $owner) {
                    unset($this->signalHandlers[$signal][$registrationId]);
                }
            }

            $this->removeEmptySignal($signal);
        }
    }

    /**
     * Release a signal once its last registration has been removed.
     */
    protected function removeEmptySignal(int $signal): void
    {
        if (empty($this->signalHandlers[$signal])) {
            unset($this->signalHandlers[$signal]);
            $this->cancelSignal($signal);
        }
    }

    /**
     * Spawn a coroutine to wait for the given signal and invoke registered handlers.
     */
    protected function waitSignal(int $signo): void
    {
        if (isset($this->handling[$signo])) {
            return;
        }

        Coroutine::createOwned(function () use ($signo): void {
            while (isset($this->signalHandlers[$signo])) {
                // An indefinite wait returns false only after an error or
                // non-exception cancellation; retrying could busy-spin.
                if (! Signal::wait($signo)) {
                    break;
                }

                $this->dispatching[$signo] = true;

                try {
                    // Inner registrations run first, with isolated context and error reporting.
                    foreach (array_reverse($this->signalHandlers[$signo] ?? []) as $registration) {
                        $coroutineId = Coroutine::create(fn () => $registration['callback']($signo));
                        Coroutine::join([$coroutineId]);
                    }
                } finally {
                    unset($this->dispatching[$signo]);
                }

                // Rearm only after callbacks finish so an explicit re-raise can terminate.
            }
        }, function (Closure $run) use ($signo): void {
            $this->handling[$signo] = Coroutine::id();

            try {
                $run();
            } finally {
                unset($this->handling[$signo], $this->dispatching[$signo]);
            }
        });
    }

    /**
     * Cancel the coroutine waiting on the given signal.
     */
    protected function cancelSignal(int $signo): void
    {
        if (! isset($this->handling[$signo]) || isset($this->dispatching[$signo])) {
            return;
        }

        EngineCoroutine::cancelById(
            $this->handling[$signo],
            throwException: true,
        );

        unset($this->handling[$signo]);
    }
}
