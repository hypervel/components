<?php

declare(strict_types=1);

namespace Hypervel\Console\Concerns;

use Hypervel\Console\SignalRegistry;

use function Hypervel\Coroutine\defer;

trait InteractsWithSignals
{
    protected ?SignalRegistry $signalRegistry = null;

    /**
     * Define a callback to be run when the given signal(s) occurs.
     *
     * @param int|int[] $signo
     * @param (callable(int $signo): void) $callback
     */
    protected function trap(array|int $signo, callable $callback): void
    {
        if (! $this->signalRegistry) {
            $this->signalRegistry = new SignalRegistry();
            defer(fn () => $this->signalRegistry->unregister());
        }

        $this->signalRegistry->register($signo, $callback);
    }

    /**
     * Unregister signal handlers for one, many, or all signals.
     *
     * @param null|int|int[] $signo
     */
    protected function untrap(array|int|null $signo = null): void
    {
        $this->signalRegistry?->unregister($signo);
    }
}
