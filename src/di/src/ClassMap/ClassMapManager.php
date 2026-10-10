<?php

declare(strict_types=1);

namespace Hypervel\Di\ClassMap;

use Composer\Autoload\ClassLoader;
use Hypervel\Di\Aop\ProxySource;
use ReflectionClass;
use RuntimeException;

/**
 * Keep package class replacements separate from the application's Composer map.
 *
 * Allows packages to replace classes at the autoloader level,
 * so the replacement file is loaded instead of the original.
 * Already-loaded targets can only be registered again from the same source.
 */
class ClassMapManager
{
    /**
     * @var array<class-string, string> originalClass => replacementPath
     */
    protected static array $entries = [];

    protected static ?ClassLoader $loader = null;

    /**
     * Add class map entries and apply them to the Composer autoloader.
     *
     * Each entry maps an original class name to the path of its replacement file.
     * Fails if a target is already loaded from a different source.
     *
     * Boot-only. Class-map overrides mutate the worker's Composer autoloader and
     * must be registered before any target class is autoloaded.
     *
     * @param array<class-string, string> $map
     */
    public static function add(array $map): void
    {
        foreach ($map as $class => $path) {
            if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)) {
                $reflection = new ReflectionClass($class);
                $attributes = $reflection->getAttributes(ProxySource::class);
                $source = $attributes === []
                    ? $reflection->getFileName()
                    : $attributes[0]->getArguments()[0];

                if ($source !== false && $source === realpath($path)) {
                    continue;
                }

                throw new RuntimeException(
                    "Cannot override class map for [{$class}]: class is already loaded. "
                    . 'Class map entries must be registered before the target class is autoloaded.'
                );
            }
        }

        if (static::$loader === null) {
            static::$loader = new ClassLoader;
            static::$loader->setClassMapAuthoritative(true);
            static::$loader->register(true);
        }

        static::$entries = array_merge(static::$entries, $map);
        static::$loader->addClassMap($map);
    }

    /**
     * Publish generated replacements with the lifetime of their source overrides.
     *
     * Boot-only. These autoload entries affect every request in the worker.
     *
     * @param array<string, string> $proxies
     */
    public static function applyProxies(array $proxies): void
    {
        static::$loader?->addClassMap(array_intersect_key($proxies, static::$entries));
    }

    /**
     * Determine if any class map entries have been registered.
     */
    public static function hasEntries(): bool
    {
        return static::$entries !== [];
    }

    /**
     * Get all registered class map entries.
     *
     * @return array<class-string, string>
     */
    public static function getEntries(): array
    {
        return static::$entries;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$loader?->unregister();
        static::$loader = null;
        static::$entries = [];
    }
}
