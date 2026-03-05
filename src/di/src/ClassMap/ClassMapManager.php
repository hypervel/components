<?php

declare(strict_types=1);

namespace Hypervel\Di\ClassMap;

use Hypervel\Support\Composer;
use RuntimeException;

/**
 * Manages class map overrides applied to Composer's autoloader.
 *
 * Allows packages to replace classes at the autoloader level,
 * so the replacement file is loaded instead of the original.
 * Entries are applied immediately when added and fail hard
 * if the target class is already loaded.
 */
class ClassMapManager
{
    /**
     * @var array<class-string, string> originalClass => replacementPath
     */
    protected static array $entries = [];

    /**
     * Add class map entries and apply them to the Composer autoloader.
     *
     * Each entry maps an original class name to the path of its replacement file.
     * Fails immediately if any target class is already loaded.
     *
     * @param array<class-string, string> $map
     */
    public static function add(array $map): void
    {
        foreach ($map as $class => $path) {
            if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)) {
                throw new RuntimeException(
                    "Cannot override class map for [{$class}]: class is already loaded. "
                    . 'Class map entries must be registered before the target class is autoloaded.'
                );
            }
        }

        static::$entries = array_merge(static::$entries, $map);

        Composer::getLoader()->addClassMap($map);
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
     * Clear all registered entries.
     */
    public static function clear(): void
    {
        static::$entries = [];
    }
}
