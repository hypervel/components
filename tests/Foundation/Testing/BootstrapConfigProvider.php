<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing;

class BootstrapConfigProvider
{
    protected static $configProviders = [
        \Hyperf\Command\ConfigProvider::class,
        \Hyperf\Database\SQLite\ConfigProvider::class,
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
        \Hyperf\Paginator\ConfigProvider::class,
        \Hyperf\Pool\ConfigProvider::class,
        \Hyperf\Process\ConfigProvider::class,
        \Hyperf\Redis\ConfigProvider::class,
        \Hyperf\Serializer\ConfigProvider::class,
        \Hyperf\Server\ConfigProvider::class,
        \Hyperf\Signal\ConfigProvider::class,
        \Hyperf\Translation\ConfigProvider::class,
        \Hyperf\Validation\ConfigProvider::class,
        \Hypervel\ConfigProvider::class,
        \Hypervel\Auth\ConfigProvider::class,
        \Hypervel\Broadcasting\ConfigProvider::class,
        \Hypervel\Bus\ConfigProvider::class,
        \Hypervel\Cache\ConfigProvider::class,
        \Hypervel\Cookie\ConfigProvider::class,
        \Hypervel\Config\ConfigProvider::class,
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
        \Hypervel\Queue\ConfigProvider::class,
        \Hypervel\Router\ConfigProvider::class,
        \Hypervel\Scheduling\ConfigProvider::class,
        \Hypervel\Session\ConfigProvider::class,
    ];

    public static function get(): array
    {
        if (class_exists($devtoolClass = \Hyperf\Devtool\ConfigProvider::class)) {
            return array_merge(self::$configProviders, [$devtoolClass]);
        }

        return self::$configProviders;
    }
}
