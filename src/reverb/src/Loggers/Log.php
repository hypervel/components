<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Loggers;

use Hypervel\Reverb\Contracts\Logger;

/**
 * @method static void info(string $title, ?string $message = null)
 * @method static void error(string $message)
 * @method static void message(string $message)
 * @method static void line(int $lines = 1)
 */
class Log
{
    /**
     * The logger instance.
     */
    protected static Logger $logger;

    /**
     * Proxy method calls to the logger instance.
     */
    public static function __callStatic(string $method, array $arguments): mixed
    {
        static::$logger ??= app(Logger::class);

        return static::$logger->{$method}(...$arguments);
    }
}
