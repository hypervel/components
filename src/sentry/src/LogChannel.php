<?php

namespace Hypervel\Sentry;

use FriendsOfHyperf\Sentry\SentryHandler;
use Hypervel\Log\LogManager;
use Monolog\Logger;

class LogChannel extends LogManager
{
    public function __invoke(array $config = []): Logger
    {
        $handler = new SentryHandler(
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