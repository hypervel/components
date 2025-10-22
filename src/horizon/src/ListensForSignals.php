<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

use Hypervel\Support\Arr;

trait ListensForSignals
{
    /**
     * The pending signals that need to be processed.
     */
    protected array $pendingSignals = [];

    /**
     * Listen for incoming process signals.
     */
    protected function listenForSignals(): void
    {
        pcntl_async_signals(true);

        pcntl_signal(SIGTERM, function () {
            $this->pendingSignals['terminate'] = 'terminate';
        });

        pcntl_signal(SIGUSR1, function () {
            $this->pendingSignals['restart'] = 'restart';
        });

        pcntl_signal(SIGUSR2, function () {
            $this->pendingSignals['pause'] = 'pause';
        });

        pcntl_signal(SIGCONT, function () {
            $this->pendingSignals['continue'] = 'continue';
        });
    }

    /**
     * Process the pending signals.
     */
    protected function processPendingSignals(): void
    {
        while ($this->pendingSignals) {
            $signal = Arr::first($this->pendingSignals);

            $this->{$signal}();

            unset($this->pendingSignals[$signal]);
        }
    }
}
