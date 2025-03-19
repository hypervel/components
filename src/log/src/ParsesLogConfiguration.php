<?php

declare(strict_types=1);

namespace Hypervel\Log;

use InvalidArgumentException;
use Monolog\Logger as Monolog;

trait ParsesLogConfiguration
{
    /**
     * The Log levels.
     */
    protected array $levels = [
        'debug' => Monolog::DEBUG,
        'info' => Monolog::INFO,
        'notice' => Monolog::NOTICE,
        'warning' => Monolog::WARNING,
        'error' => Monolog::ERROR,
        'critical' => Monolog::CRITICAL,
        'alert' => Monolog::ALERT,
        'emergency' => Monolog::EMERGENCY,
    ];

    /**
     * Get fallback log channel name.
     */
    abstract protected function getFallbackChannelName(): string;

    /**
     * Parse the string level into a Monolog constant.
     *
     * @throws InvalidArgumentException
     */
    protected function level(array $config): int
    {
        $level = $config['level'] ?? 'debug';

        if (isset($this->levels[$level])) {
            return $this->levels[$level];
        }

        throw new InvalidArgumentException('Invalid log level.');
    }

    /**
     * Parse the action level from the given configuration.
     */
    protected function actionLevel(array $config): int
    {
        $level = $config['action_level'] ?? 'debug';

        if (isset($this->levels[$level])) {
            return $this->levels[$level];
        }

        throw new InvalidArgumentException('Invalid log action level.');
    }

    /**
     * Extract the log channel from the given configuration.
     */
    protected function parseChannel(array $config): string
    {
        return $config['name'] ?? $this->getFallbackChannelName();
    }
}
