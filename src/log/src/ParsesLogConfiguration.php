<?php

declare(strict_types=1);

namespace Hypervel\Log;

use InvalidArgumentException;
use Monolog\Level;

trait ParsesLogConfiguration
{
    /**
     * The Log levels.
     */
    protected array $levels = [
        'debug' => Level::Debug,
        'info' => Level::Info,
        'notice' => Level::Notice,
        'warning' => Level::Warning,
        'error' => Level::Error,
        'critical' => Level::Critical,
        'alert' => Level::Alert,
        'emergency' => Level::Emergency,
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
    protected function level(array $config): Level
    {
        $level = $config['level'] ?? 'debug';

        if (isset($this->levels[$level])) {
            return $this->levels[$level];
        }

        throw new InvalidArgumentException('Invalid log level.');
    }

    /**
     * Parse the action level from the given configuration.
     *
     * @throws InvalidArgumentException
     */
    protected function actionLevel(array $config): Level
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
