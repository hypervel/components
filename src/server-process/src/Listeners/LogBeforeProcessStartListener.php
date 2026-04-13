<?php

declare(strict_types=1);

namespace Hypervel\ServerProcess\Listeners;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Log\StdoutLoggerInterface;
use Hypervel\ServerProcess\Events\BeforeProcessHandle;

class LogBeforeProcessStartListener
{
    public function __construct(protected Container $container)
    {
    }

    /**
     * Log that a server process is starting.
     */
    public function handle(BeforeProcessHandle $event): void
    {
        $message = sprintf('Process[%s.%d] start.', $event->process->name, $event->index);
        if ($this->container->has(StdoutLoggerInterface::class)) {
            $logger = $this->container->make(StdoutLoggerInterface::class);
            $logger->info($message);
        } else {
            echo $message . PHP_EOL;
        }
    }
}
