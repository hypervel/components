<?php

declare(strict_types=1);

namespace Hypervel\Foundation;

use Hyperf\Di\Annotation\Scanner as AnnotationScanner;
use Hyperf\Di\LazyLoader\LazyLoader;
use Hyperf\Di\ScanHandler\PcntlScanHandler;
use Hyperf\Di\ScanHandler\ScanHandlerInterface;
use Hyperf\Support\DotenvManager;
use Hypervel\Container\ScanConfig;
use Hypervel\Support\Composer;

class ClassLoader
{
    protected static ?string $proxyFileDirPath = null;

    protected static ?string $configDir = null;

    protected static ?ScanHandlerInterface $handler = null;

    public static function init(?string $proxyFileDirPath = null, ?string $configDir = null, ?ScanHandlerInterface $handler = null): void
    {
        static::setParameters($proxyFileDirPath, $configDir, $handler);

        static::loadEnv();

        static::loadClassMap();

        // Initialize Lazy Loader. This will prepend LazyLoader to the top of autoload queue.
        LazyLoader::bootstrap(static::$configDir);
    }

    public static function loadClassMap(): void
    {
        $composerLoader = Composer::getLoader();

        $config = ScanConfig::instance(static::$configDir);
        $composerLoader->addClassMap($config->getClassMap());

        $scanner = new AnnotationScanner($config, static::$handler);
        $composerLoader->addClassMap(
            $scanner->scan($composerLoader->getClassMap(), static::$proxyFileDirPath)
        );
    }

    protected static function loadEnv(): void
    {
        // Hyperf doesn't support customizing the .env file path.
        if (! file_exists(BASE_PATH . '/.env')) {
            return;
        }

        DotenvManager::load([BASE_PATH]);
    }

    protected static function setParameters(?string $proxyFileDirPath = null, ?string $configDir = null, ?ScanHandlerInterface $handler = null): void
    {
        static::$proxyFileDirPath = $proxyFileDirPath
            ?? BASE_PATH . '/runtime/container/proxy/';

        static::$configDir = $configDir ?? BASE_PATH . '/config/';

        static::$handler = $handler ?? new PcntlScanHandler();
    }
}
