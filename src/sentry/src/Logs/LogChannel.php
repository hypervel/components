<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Logs;

use Hypervel\Log\LogManager;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Logger;
use Hypervel\Sentry\Logs\LogsHandler;

class LogChannel extends LogManager
{
    /**
     * Create the Sentry logs channel.
     */
    public function __invoke(array $config = []): Logger
    {
        $handler = new LogsHandler(
            $config['level'] ?? Logger::DEBUG,
            $config['bubble'] ?? true
        );

        if (isset($config['action_level'])) {
            $handler = new FingersCrossedHandler($handler, $config['action_level']);

            // Consume the `action_level` config option since newer Laravel versions also support this option
            // and will wrap the handler again in another `FingersCrossedHandler` if we leave the option set
            unset($config['action_level']);
        }

        return new Logger(
            $this->parseChannel($config),
            [
                $this->prepareHandler($handler, $config),
            ]
        );
    }
}
