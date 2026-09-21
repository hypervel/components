<?php

declare(strict_types=1);

namespace Hypervel\Console\Concerns;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\SignalRegistry;

trait InteractsWithSignals
{
    /**
     * The signal registry instance.
     */
    protected ?SignalRegistry $signalRegistry = null;

    /**
     * Define a callback to be run when the given signal(s) occurs.
     *
     * @template TSignals of int|iterable<array-key, int>
     *
     * @param (Closure(): TSignals)|TSignals $signals
     * @param callable(int): mixed $callback
     */
    public function trap(Closure|int|iterable $signals, callable $callback): void
    {
        if (! $this->signalRegistry) {
            $registry = $this->signalRegistry = Container::getInstance()->make(SignalRegistry::class);
            Coroutine::defer(fn () => $registry->unregister($this));
        }

        $this->signalRegistry->register($this, value($signals), $callback);
    }

    /**
     * Unregister signal handlers for one, many, or all signals.
     *
     * @param null|int|iterable<array-key, int> $signals
     */
    public function untrap(int|iterable|null $signals = null): void
    {
        $this->signalRegistry?->unregister($this, $signals);

        if ($signals === null) {
            $this->signalRegistry = null;
        }
    }
}
