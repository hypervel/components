<?php

declare(strict_types=1);

namespace Hypervel\Testbench;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\ClassLoader;
use Hypervel\Support\LazyCollection;
use Symfony\Component\Yaml\Yaml;

use function Hypervel\Filesystem\join_paths;

class Bootstrapper
{
    protected static $config = [];

    protected static ?Filesystem $filesystem = null;

    public static function bootstrap(): void
    {
        static::loadConfigFromYaml(
            $workingPath = defined('TESTBENCH_WORKING_PATH') ? TESTBENCH_WORKING_PATH : dirname(__DIR__)
        );

        $basePath = "{$workingPath}/workbench";
        if (static::$config['hypervel'] ?? null) {
            $basePath = static::$config['hypervel'];
        }

        ! defined('BASE_PATH') && define('BASE_PATH', $basePath);
        ! defined('SWOOLE_HOOK_FLAGS') && define('SWOOLE_HOOK_FLAGS', SWOOLE_HOOK_ALL);

        static::generateEnv();
        static::generateComposerLock();
        static::registerPurgeFiles();

        ClassLoader::init();
    }

    public static function getConfig(): array
    {
        return static::$config;
    }

    protected static function getFilesystem(): Filesystem
    {
        if (static::$filesystem) {
            return static::$filesystem;
        }

        return static::$filesystem = new Filesystem();
    }

    protected static function generateComposerLock(): void
    {
        $content = [
            'packages' => [
                [
                    'name' => 'hypervel-testbench',
                    'extra' => [
                        'hypervel' => [
                            'config' => static::getConfigProviders(),
                            'providers' => static::$config['providers'] ?? [],
                            'dont-discover' => static::$config['dont-discover'] ?? [],
                        ],
                    ],
                ],
            ],
            'packages-dev' => [],
        ];

        static::getFilesystem()->replace(
            BASE_PATH . '/composer.lock',
            json_encode($content, JSON_PRETTY_PRINT)
        );
    }

    protected static function loadConfigFromYaml(string $workingPath, ?string $filename = 'testbench.yaml', array $defaults = []): void
    {
        $filename = LazyCollection::make(static function () use ($filename) {
            yield $filename;
            yield "{$filename}.example";
            yield "{$filename}.dist";
        })->map(static function ($file) use ($workingPath) {
            return str_contains($file, DIRECTORY_SEPARATOR) ? $file : join_paths($workingPath, $file);
        })->filter(static fn ($file) => is_file($file))
            ->first();

        if (is_null($filename)) {
            return;
        }

        static::$config = Yaml::parseFile($filename) ?? [];
    }

    protected static function generateEnv(): void
    {
        if (! $env = static::$config['env'] ?? []) {
            return;
        }

        static::getFilesystem()->replace(
            join_paths(BASE_PATH, '/.env'),
            implode(PHP_EOL, $env)
        );
    }

    protected static function getConfigProviders(): array
    {
        ConfigProviderRegister::add(
            static::$config['config-providers'] ?? []
        );

        return ConfigProviderRegister::get();
    }

    protected static function registerPurgeFiles(): void
    {
        $purge = static::$config['purge'] ?? [];
        $files = $purge['files'] ?? [];
        $directories = $purge['directories'] ?? [];

        if (! $files && ! $directories) {
            return;
        }

        register_shutdown_function(function () use ($files, $directories) {
            $filesystem = static::getFilesystem();
            foreach ($files as $file) {
                if (! $filesystem->exists($file = BASE_PATH . "/{$file}")) {
                    continue;
                }
                $filesystem->delete($file);
            }

            foreach ($directories as $directory) {
                if (! $filesystem->exists($directory = BASE_PATH . "/{$directory}")) {
                    continue;
                }
                $filesystem->deleteDirectory($directory);
            }
        });
    }
}
