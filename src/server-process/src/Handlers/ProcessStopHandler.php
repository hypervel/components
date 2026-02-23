<?php

declare(strict_types=1);

namespace Hypervel\ServerProcess\Handlers;

use Hyperf\Signal\SignalHandlerInterface;
use Hypervel\ServerProcess\ProcessManager;

/**
 * @TODO Update to Hypervel SignalHandlerInterface once the signal package is ported.
 */
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
