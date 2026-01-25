<?php

declare(strict_types=1);

namespace Hypervel\Testbench;

use Hypervel\Support\Arr;

class ConfigProviderRegister
{
    protected static $configProviders = [
        \Hyperf\Command\ConfigProvider::class,
        \Hyperf\DbConnection\ConfigProvider::class,
        \Hyperf\Di\ConfigProvider::class,
        \Hyperf\Dispatcher\ConfigProvider::class,
        \Hyperf\Engine\ConfigProvider::class,
        \Hyperf\Event\ConfigProvider::class,
        \Hyperf\ExceptionHandler\ConfigProvider::class,
        \Hyperf\Framework\ConfigProvider::class,
        \Hyperf\HttpMessage\ConfigProvider::class,
        \Hyperf\HttpServer\ConfigProvider::class,
        \Hyperf\Memory\ConfigProvider::class,
        \Hyperf\ModelListener\ConfigProvider::class,
        \Hyperf\Process\ConfigProvider::class,
        \Hyperf\Redis\ConfigProvider::class,
        \Hyperf\Serializer\ConfigProvider::class,
        \Hyperf\Server\ConfigProvider::class,
        \Hyperf\Signal\ConfigProvider::class,
        \Hypervel\ConfigProvider::class,
        \Hypervel\Database\ConfigProvider::class,
        \Hypervel\Auth\ConfigProvider::class,
        \Hypervel\Broadcasting\ConfigProvider::class,
        \Hypervel\Bus\ConfigProvider::class,
        \Hypervel\Cache\ConfigProvider::class,
        \Hypervel\Cookie\ConfigProvider::class,
        \Hypervel\Config\ConfigProvider::class,
        \Hypervel\Console\ConfigProvider::class,
        \Hypervel\Dispatcher\ConfigProvider::class,
        \Hypervel\Encryption\ConfigProvider::class,
        \Hypervel\Event\ConfigProvider::class,
        \Hypervel\Foundation\ConfigProvider::class,
        \Hypervel\Hashing\ConfigProvider::class,
        \Hypervel\Http\ConfigProvider::class,
        \Hypervel\JWT\ConfigProvider::class,
        \Hypervel\Log\ConfigProvider::class,
        \Hypervel\Mail\ConfigProvider::class,
        \Hypervel\Notifications\ConfigProvider::class,
        \Hypervel\ObjectPool\ConfigProvider::class,
        \Hypervel\Queue\ConfigProvider::class,
        \Hypervel\Redis\ConfigProvider::class,
        \Hypervel\Router\ConfigProvider::class,
        \Hypervel\Session\ConfigProvider::class,
        \Hypervel\Translation\ConfigProvider::class,
        \Hypervel\Validation\ConfigProvider::class,
    ];

    public static function get(): array
    {
        if (class_exists($devtoolClass = \Hyperf\Devtool\ConfigProvider::class)) {
            return array_merge(static::$configProviders, [$devtoolClass]);
        }

        return static::$configProviders;
    }

    public static function filter(callable $callback): array
    {
        return static::$configProviders = array_filter(static::get(), $callback);
    }

    public static function add(array|string $providers): void
    {
        static::$configProviders = array_merge(
            static::$configProviders,
            Arr::wrap($providers)
        );
    }

    public static function except(array|string $providers): void
    {
        static::$configProviders = array_diff(
            static::$configProviders,
            Arr::wrap($providers)
        );
    }
}
