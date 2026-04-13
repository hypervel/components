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
 * @method static \Hypervel\Log\LogManager withoutContext(string[]|null $keys = null)
 * @method static \Hypervel\Log\LogManager flushSharedContext()
 * @method static string|null getDefaultDriver()
 * @method static void setDefaultDriver(string $name)
 * @method static \Hypervel\Log\LogManager extend(string $driver, \Closure $callback)
 * @method static void forgetChannel(string|null $driver = null)
 * @method static array getChannels()
 * @method static void emergency(\Hypervel\Contracts\Support\Arrayable|\Hypervel\Contracts\Support\Jsonable|\Stringable|array|string $message, array $context = [])
 * @method static void alert(\Hypervel\Contracts\Support\Arrayable|\Hypervel\Contracts\Support\Jsonable|\Stringable|array|string $message, array $context = [])
 * @method static void critical(\Hypervel\Contracts\Support\Arrayable|\Hypervel\Contracts\Support\Jsonable|\Stringable|array|string $message, array $context = [])
 * @method static void error(\Hypervel\Contracts\Support\Arrayable|\Hypervel\Contracts\Support\Jsonable|\Stringable|array|string $message, array $context = [])
 * @method static void warning(\Hypervel\Contracts\Support\Arrayable|\Hypervel\Contracts\Support\Jsonable|\Stringable|array|string $message, array $context = [])
 * @method static void notice(\Hypervel\Contracts\Support\Arrayable|\Hypervel\Contracts\Support\Jsonable|\Stringable|array|string $message, array $context = [])
 * @method static void info(\Hypervel\Contracts\Support\Arrayable|\Hypervel\Contracts\Support\Jsonable|\Stringable|array|string $message, array $context = [])
 * @method static void debug(\Hypervel\Contracts\Support\Arrayable|\Hypervel\Contracts\Support\Jsonable|\Stringable|array|string $message, array $context = [])
 * @method static void log(mixed $level, \Hypervel\Contracts\Support\Arrayable|\Hypervel\Contracts\Support\Jsonable|\Stringable|array|string $message, array $context = [])
 * @method static \Hypervel\Log\LogManager setApplication(\Hypervel\Contracts\Foundation\Application $app)
 * @method static void write(string $level, \Hypervel\Contracts\Support\Arrayable|\Hypervel\Contracts\Support\Jsonable|\Stringable|array|string $message, array $context = [])
 * @method static \Hypervel\Log\Logger withContext(array $context = [])
 * @method static void listen(\Closure $callback)
 * @method static \Psr\Log\LoggerInterface getLogger()
 * @method static \Hypervel\Contracts\Events\Dispatcher|null getEventDispatcher()
 * @method static void setEventDispatcher(\Hypervel\Contracts\Events\Dispatcher $dispatcher)
 * @method static \Hypervel\Log\Logger|mixed when(\Closure|mixed|null $value = null, callable|null $callback = null, callable|null $default = null)
 * @method static \Hypervel\Log\Logger|mixed unless(\Closure|mixed|null $value = null, callable|null $callback = null, callable|null $default = null)
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
