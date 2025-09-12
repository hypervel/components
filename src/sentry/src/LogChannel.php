<?php

namespace Hypervel\Sentry;

use Hypervel\Log\LogManager;
use Monolog\Logger;
use Sentry\Monolog\Handler;
use Sentry\SentrySdk;

class LogChannel extends LogManager
{
    public function __invoke(array $config = []): Logger
    {
        $handler = new Handler(
            SentrySdk::getCurrentHub(),
            $config['level'] ?? Logger::DEBUG,
        );

        return new Logger(
            $this->parseChannel($config),
            [
                $this->prepareHandler($handler, $config),
            ]
        );
    }
}