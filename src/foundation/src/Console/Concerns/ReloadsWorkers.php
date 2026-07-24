<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Console\Concerns;

trait ReloadsWorkers
{
    /**
     * Attempt a best-effort worker reload via SIGUSR1.
     */
    protected function reloadWorkers(): void
    {
        $pidFile = $this->hypervel->make('config')->string('server.settings.pid_file');

        if (empty($pidFile) || ! is_file($pidFile)) {
            return;
        }

        $contents = @file_get_contents($pidFile);

        if ($contents === false) {
            return;
        }

        $pid = (int) $contents;

        if ($pid > 0 && posix_kill($pid, 0)) {
            posix_kill($pid, SIGUSR1);
        }
    }
}
