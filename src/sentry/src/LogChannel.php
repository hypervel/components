<?php

declare(strict_types=1);

namespace Hypervel\Sentry;

use Hypervel\Log\LogManager;
use Monolog\Logger;
use Sentry\State\HubInterface;

class LogChannel extends LogManager
{
    /**
     * Create the Sentry log channel.
     */
    public function __invoke(array $config = []): Logger
    {
        $handler = new SentryHandler(
            $this->app->make(HubInterface::class),
            $config['level'] ?? Logger::DEBUG,
            $config['bubble'] ?? true,
            $config['report_exceptions'] ?? true,
            isset($config['formatter']) && $config['formatter'] !== 'default'
        );

        return new Logger(
            $this->parseChannel($config),
            [
                $this->prepareHandler($handler, $config),
            ]
        );
    }
}
