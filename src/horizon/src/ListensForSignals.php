<?php

declare(strict_types=1);

namespace Hypervel\Horizon;

use RuntimeException;

trait ListensForSignals
{
    protected const HANDLED_SIGNALS = [
        SIGTERM,
        SIGUSR1,
        SIGUSR2,
        SIGCONT,
    ];

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

        if (! pcntl_sigprocmask(SIG_UNBLOCK, self::HANDLED_SIGNALS)) {
            throw new RuntimeException('Unable to unblock Horizon process signals.');
        }
    }

    /**
     * Process the pending signals.
     */
    protected function processPendingSignals(): void
    {
        while ($this->pendingSignals) {
            $signal = array_first($this->pendingSignals);

            $this->{$signal}();

            unset($this->pendingSignals[$signal]);
        }
    }
}
