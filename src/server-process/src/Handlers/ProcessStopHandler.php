<?php

declare(strict_types=1);

namespace Hypervel\ServerProcess\Handlers;

use Hypervel\Contracts\Signal\SignalHandlerInterface;
use Hypervel\ServerProcess\ProcessManager;

class ProcessStopHandler implements SignalHandlerInterface
{
    /**
     * Get the signals this handler listens for.
     */
    public function listen(): array
    {
        return [
            [self::PROCESS, SIGTERM],
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
