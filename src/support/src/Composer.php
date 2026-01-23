<?php

declare(strict_types=1);

namespace Hypervel\Support;

use Composer\Autoload\ClassLoader;
use Hypervel\Support\Collection;
use Hypervel\Filesystem\Filesystem;
use RuntimeException;
use Symfony\Component\Process\Process;

class Composer
{
    protected static ?Collection $content = null;

    protected static ?Collection $json = null;

    protected static array $extra = [];

    protected static array $scripts = [];

    protected static array $versions = [];

    protected static ?ClassLoader $classLoader = null;

    protected static ?string $basePath = null;

    /**
     * @throws RuntimeException When `composer.lock` does not exist.
     */
    public static function getLockContent(): Collection
    {
        if (! static::$content) {
            if (! $path = static::discoverLockFile()) {
                throw new RuntimeException('composer.lock not found.');
            }

            static::$content = collect(json_decode(file_get_contents($path), true));
            $packages = static::$content->offsetGet('packages') ?? [];
            $packagesDev = static::$content->offsetGet('packages-dev') ?? [];

            foreach (array_merge($packages, $packagesDev) as $package) {
                $packageName = '';
                foreach ($package ?? [] as $key => $value) {
                    if ($key === 'name') {
                        $packageName = $value;
                        continue;
                    }

                    $packageName && match ($key) {
                        'extra' => static::$extra[$packageName] = $value,
                        'scripts' => static::$scripts[$packageName] = $value,
                        'version' => static::$versions[$packageName] = $value,
                        default => null,
                    };
                }
            }
        }

        return static::$content;
    }

    public static function getJsonContent(): Collection
    {
        if (static::$json) {
            return static::$json;
        }

        if (! is_readable($path = static::getBasePath() . '/composer.json')) {
            throw new RuntimeException('composer.json is not readable.');
        }

        return static::$json = collect(json_decode(file_get_contents($path), true));
    }

    public static function discoverLockFile(): string
    {
        if (is_readable($path = static::getBasePath() . '/composer.lock')) {
            return $path;
        }

        return '';
    }

    public static function getMergedExtra(?string $key = null)
    {
        if (! static::$extra) {
            static::getLockContent();
        }

        if ($key === null) {
            return static::$extra;
        }

        $extra = [];

        foreach (static::$extra as $project => $config) {
            foreach ($config ?? [] as $configKey => $item) {
                if ($key === $configKey && $item) {
                    foreach ($item as $k => $v) {
                        if (is_array($v)) {
                            $extra[$k] = array_merge($extra[$k] ?? [], $v);
                        } else {
                            $extra[$k][] = $v;
                        }
                    }
                }
            }
        }

        return $extra;
    }

    public static function getLoader(): ClassLoader
    {
        return static::$classLoader ??= static::findLoader();
    }

    public static function setLoader(ClassLoader $classLoader): ClassLoader
    {
        return static::$classLoader = $classLoader;
    }

    public static function getScripts(): array
    {
        if (! static::$scripts) {
            static::getLockContent();
        }

        return static::$scripts;
    }

    public static function getVersions(): array
    {
        if (! static::$versions) {
            static::getLockContent();
        }

        return static::$versions;
    }

    public static function hasPackage(string $packageName): bool
    {
        if (! static::$json) {
            static::getJsonContent();
        }

        if (static::$json['require'][$packageName] ?? static::$json['require-dev'][$packageName] ?? static::$json['replace'][$packageName] ?? '') {
            return true;
        }

        if (! static::$versions) {
            static::getLockContent();
        }

        return isset(static::$versions[$packageName]);
    }

    protected static function findLoader(): ClassLoader
    {
        $loaders = spl_autoload_functions();

        foreach ($loaders as $loader) {
            if (is_array($loader) && $loader[0] instanceof ClassLoader) {
                return $loader[0];
            }
        }

        throw new RuntimeException('Composer loader not found.');
    }

    public static function setBasePath(?string $basePath = null): void
    {
        // Reset content to reload lock file
        static::reset();

        static::$basePath = $basePath;
    }

    public static function getBasePath(): string
    {
        return static::$basePath ?: BASE_PATH;
    }

    /**
     * Get the Composer binary / command for the environment.
     */
    public static function findComposer(?string $composerBinary = null): array
    {
        $filesystem = new Filesystem();
        if (! is_null($composerBinary) && $filesystem->exists($composerBinary)) {
            return [static::phpBinary(), $composerBinary];
        }
        if ($filesystem->exists(getcwd() . '/composer.phar')) {
            return [static::phpBinary(), 'composer.phar'];
        }

        return ['composer'];
    }

    /**
     * Get a new Symfony process instance.
     */
    protected static function getProcess(array $command, array $env = []): Process
    {
        return (new Process($command, null, $env))
            ->setTimeout(null);
    }

    /**
     * Get the PHP binary.
     */
    protected static function phpBinary(): string
    {
        return php_binary();
    }

    /**
     * Get the version of Composer.
     */
    public static function getVersion(): ?string
    {
        $command = array_merge(static::findComposer(), ['-V', '--no-ansi']);

        $process = static::getProcess($command);

        $process->run();

        $output = $process->getOutput();

        if (preg_match('/(\d+(\.\d+){2})/', $output, $version)) {
            return $version[1];
        }

        return explode(' ', $output)[2] ?? null;
    }

    protected static function reset(): void
    {
        static::$content = null;
        static::$json = null;
        static::$extra = [];
        static::$scripts = [];
        static::$versions = [];
    }
}
