<?php

declare(strict_types=1);

namespace Hypervel\Server\Listeners;

use Hypervel\Core\Events\AfterWorkerStart;
use Hypervel\Core\Events\OnManagerStart;
use Hypervel\Core\Events\OnStart;
use Hypervel\Core\Events\OnWorkerExit;
use Hypervel\Coroutine\SignalRegistry;
use Swoole\Process;

/**
 * Shut the server down gracefully on SIGINT (Ctrl+C), the same way Swoole handles SIGTERM.
 *
 * Swoole does not handle SIGINT itself, so without this the default action kills the
 * process that coordinates shutdown and skips its shutdown callbacks. Workers that receive
 * the same signal from the terminal's process group still exit immediately unless they
 * handle SIGINT themselves.
 */
class ShutdownOnInterruptListener
{
    /**
     * Create a new shutdown on interrupt listener instance.
     */
    public function __construct(protected SignalRegistry $signals)
    {
    }

    /**
     * Register the SIGINT shutdown in the process that coordinates server shutdown.
     */
    public function handle(AfterWorkerStart|OnManagerStart|OnStart|OnWorkerExit $event): void
    {
        $server = $event->server;

        // PROCESS mode shuts down from the master and BASE mode from its manager; onStart runs
        // inside a worker in BASE mode.
        if (($event instanceof OnStart && $server->mode === SWOOLE_PROCESS)
            || ($event instanceof OnManagerStart && $server->mode === SWOOLE_BASE)) {
            Process::signal(SIGINT, static fn (): bool => $server->shutdown());

            return;
        }

        // A BASE server without a manager runs as a single worker process. Process::signal would
        // block the coroutine signal waits that worker signal handlers and Artisan traps share.
        if ($server->mode !== SWOOLE_BASE || $server->manager_pid !== 0) {
            return;
        }

        if ($event instanceof AfterWorkerStart) {
            $this->signals->register($this, SIGINT, static fn (): bool => $server->shutdown());
        } elseif ($event instanceof OnWorkerExit) {
            $this->signals->unregister($this);
        }
    }
}
