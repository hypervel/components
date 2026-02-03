<?php

declare(strict_types=1);

namespace Hypervel\Scout;

use Hypervel\Scout\Jobs\MakeSearchable;
use Hypervel\Scout\Jobs\RemoveFromSearch;

/**
 * Scout utility class for job customization and engine access.
 *
 * Provides static configuration for customizing the job classes used
 * when indexing models via the queue. Set these in a service provider
 * boot method to use custom job implementations.
 *
 * Note: These static properties are set at boot time and read during
 * request handling. This is safe in Swoole/coroutine environments
 * because they store class names (strings), not stateful objects.
 */
class Scout
{
    /**
     * The job class that makes models searchable.
     *
     * @var class-string<MakeSearchable>
     */
    public static string $makeSearchableJob = MakeSearchable::class;

    /**
     * The job class that removes models from the search index.
     *
     * @var class-string<RemoveFromSearch>
     */
    public static string $removeFromSearchJob = RemoveFromSearch::class;

    /**
     * Get a Scout engine instance by name.
     */
    public static function engine(?string $name = null): Engine
    {
        return app(EngineManager::class)->engine($name);
    }

    /**
     * Specify the job class that should make models searchable.
     *
     * @param class-string<MakeSearchable> $class
     */
    public static function makeSearchableUsing(string $class): void
    {
        static::$makeSearchableJob = $class;
    }

    /**
     * Specify the job class that should remove models from the search index.
     *
     * @param class-string<RemoveFromSearch> $class
     */
    public static function removeFromSearchUsing(string $class): void
    {
        static::$removeFromSearchJob = $class;
    }

    /**
     * Reset the job classes to their defaults.
     *
     * Useful for testing to ensure clean state between tests.
     */
    public static function resetJobClasses(): void
    {
        static::$makeSearchableJob = MakeSearchable::class;
        static::$removeFromSearchJob = RemoveFromSearch::class;
    }
}
