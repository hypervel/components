<?php

declare(strict_types=1);

namespace Hypervel\ServerProcess\Listeners;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\Event\Contracts\ListenerInterface;
use Hypervel\ServerProcess\Events\BeforeProcessHandle;

class LogBeforeProcessStartListener implements ListenerInterface
{
    public function __construct(protected Container $container)
    {
    }

    /**
     * Get the events the listener should handle.
     */
    public function listen(): array
    {
        return [
            BeforeProcessHandle::class,
        ];
    }

    /**
     * Log that a server process is starting.
     */
    public function process(object $event): void
    {
        /** @var BeforeProcessHandle $event */
        $message = sprintf('Process[%s.%d] start.', $event->process->name, $event->index);
        if ($this->container->has(StdoutLoggerInterface::class)) {
            $logger = $this->container->make(StdoutLoggerInterface::class);
            $logger->info($message);
        } else {
            echo $message . PHP_EOL;
        }
    }
}
