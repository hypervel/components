<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

/**
 * @method static \Psr\Log\LoggerInterface build(array $config)
 * @method static \Psr\Log\LoggerInterface stack(array $channels, string|null $channel = null)
 * @method static \Psr\Log\LoggerInterface channel(string|null $channel = null)
 * @method static \Psr\Log\LoggerInterface driver(string|null $driver = null)
 * @method static \Hypervel\Log\LogManager shareContext(array $context)
 * @method static array sharedContext()
 * @method static \Hypervel\Log\LogManager withoutContext()
 * @method static \Hypervel\Log\LogManager flushSharedContext()
 * @method static string|null getDefaultDriver()
 * @method static void setDefaultDriver(string $name)
 * @method static \Hypervel\Log\LogManager extend(string $driver, \Closure $callback)
 * @method static void forgetChannel(string|null $driver = null)
 * @method static array getChannels()
 * @method static void emergency(string $message, mixed[] $context = [])
 * @method static void alert(string $message, mixed[] $context = [])
 * @method static void critical(string $message, mixed[] $context = [])
 * @method static void error(string $message, mixed[] $context = [])
 * @method static void warning(string $message, mixed[] $context = [])
 * @method static void notice(string $message, mixed[] $context = [])
 * @method static void info(string $message, mixed[] $context = [])
 * @method static void debug(string $message, mixed[] $context = [])
 * @method static void log(mixed $level, string $message, mixed[] $context = [])
 * @method static void write(string $level, string $message, array $context = [])
 * @method static \Hypervel\Log\Logger withContext(array $context = [])
 * @method static array getContext()
 * @method static void listen(\Closure $callback)
 * @method static \Psr\Log\LoggerInterface getLogger()
 * @method static \Hypervel\Contracts\Event\Dispatcher getEventDispatcher()
 * @method static \Hypervel\Log\Logger setEventDispatcher(\Hypervel\Contracts\Event\Dispatcher $dispatcher)
 *
 * @see \Hypervel\Log\LogManager
 */
class Log extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'log';
    }
}
