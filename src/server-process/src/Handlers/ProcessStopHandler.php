<?php

declare(strict_types=1);

namespace Hypervel\ServerProcess\Handlers;

use Hypervel\Contracts\Signal\SignalHandler;
use Hypervel\ServerProcess\ProcessManager;

class ProcessStopHandler implements SignalHandler
{
    /**
     * Get the signals this handler listens for.
     */
    public function signals(): array
    {
        return [
            self::SERVER_PROCESS => [SIGTERM],
        ];
    }

    /**
     * Handle the received signal.
     */
    public function handle(int $signal): void
    {
        ProcessManager::setRunning(false);
    }
}
