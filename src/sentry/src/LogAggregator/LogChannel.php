<?php

declare(strict_types=1);

namespace Hypervel\Sentry\LogAggregator;

use Hypervel\Log\LogManager;
use Monolog\Logger;

class LogChannel extends LogManager
{
    public function __invoke(array $config = []): Logger
    {
        $handler = new LogsHandler(
            $config['level'] ?? Logger::DEBUG,
            $config['bubble'] ?? true
        );

        return new Logger(
            $this->parseChannel($config),
            [
                $this->prepareHandler($handler, $config),
            ]
        );
    }
}
