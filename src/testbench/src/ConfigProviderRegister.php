<?php

declare(strict_types=1);

namespace Hypervel\Testbench;

use Hypervel\Support\Arr;

class ConfigProviderRegister
{
    protected static $configProviders = [
        \Hyperf\Di\ConfigProvider::class,
        \Hypervel\Dispatcher\ConfigProvider::class,
        \Hypervel\Event\ConfigProvider::class,
        \Hypervel\Framework\ConfigProvider::class,
        \Hypervel\Serializer\ConfigProvider::class,
        \Hypervel\Server\ConfigProvider::class,
        \Hypervel\ServerProcess\ConfigProvider::class,
        \Hyperf\Signal\ConfigProvider::class,
        \Hypervel\WebSocketServer\ConfigProvider::class,
        \Hypervel\ConfigProvider::class,
        \Hypervel\Config\ConfigProvider::class,
        \Hypervel\Mail\ConfigProvider::class,
        \Hypervel\Session\ConfigProvider::class,
        \Hypervel\Translation\ConfigProvider::class,
        \Hypervel\Validation\ConfigProvider::class,
    ];

    public static function get(): array
    {
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
