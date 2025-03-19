<?php

declare(strict_types=1);

namespace Hypervel\Telescope;

use Psr\Container\ContainerInterface;

use function Hyperf\Config\config;

trait RegistersWatchers
{
    /**
     * The class names of the registered watchers.
     */
    protected static array $watchers = [];

    /**
     * Determine if a given watcher has been registered.
     */
    public static function hasWatcher(string $class): bool
    {
        return in_array($class, static::$watchers);
    }

    /**
     * Flush the registered watchers.
     */
    public static function flushWatchers(): void
    {
        static::$watchers = [];
    }

    /**
     * Register the configured Telescope watchers.
     */
    protected static function registerWatchers(ContainerInterface $app): void
    {
        foreach (config('telescope.watchers', []) as $key => $watcher) {
            if (is_string($key) && $watcher === false) {
                continue;
            }

            if (is_array($watcher) && ! ($watcher['enabled'] ?? true)) {
                continue;
            }

            $watcher = $app->get(is_string($key) ? $key : $watcher)
                ->setOptions(is_array($watcher) ? $watcher : []);

            static::$watchers[] = get_class($watcher);

            $watcher->register($app);
        }
    }
}
