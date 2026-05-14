<?php

declare(strict_types=1);

namespace Hypervel\Scout;

use Hypervel\Context\CoroutineContext;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Engines\Engine;
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
     * Coroutine-local context key indicating that scout:import is currently running.
     *
     * Coroutine-local rather than process-global so concurrent coroutines in the
     * same process don't leak the import flag into each other.
     */
    public const IMPORTING_CONTEXT_KEY = '__scout.importing';

    /**
     * Coroutine-local context key for the active scout:import progress reporter.
     */
    public const IMPORT_PROGRESS_CONTEXT_KEY = '__scout.import_progress';

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
     * Boot-only. The job class persists in a static property for the worker
     * lifetime and is used by every subsequent searchable import.
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
     * Boot-only. The job class persists in a static property for the worker
     * lifetime and is used by every subsequent searchable removal.
     *
     * @param class-string<RemoveFromSearch> $class
     */
    public static function removeFromSearchUsing(string $class): void
    {
        static::$removeFromSearchJob = $class;
    }

    /**
     * Run the given callback with the "importing" flag set in the current coroutine.
     *
     * The previous context state is captured before the call and restored in
     * finally. This makes the helper nesting-safe: an inner whileImporting()
     * call won't clear an outer call's flag when it returns.
     */
    public static function whileImporting(callable $callback): mixed
    {
        $hadFlag = CoroutineContext::has(self::IMPORTING_CONTEXT_KEY);
        $previous = CoroutineContext::get(self::IMPORTING_CONTEXT_KEY);

        CoroutineContext::set(self::IMPORTING_CONTEXT_KEY, true);

        try {
            return $callback();
        } finally {
            if ($hadFlag) {
                CoroutineContext::set(self::IMPORTING_CONTEXT_KEY, $previous);
            } else {
                CoroutineContext::forget(self::IMPORTING_CONTEXT_KEY);
            }
        }
    }

    /**
     * Determine whether scout:import is currently running in this coroutine.
     */
    public static function isImporting(): bool
    {
        return (bool) CoroutineContext::get(self::IMPORTING_CONTEXT_KEY, false);
    }

    /**
     * Run the given callback with an import progress reporter in the current coroutine.
     *
     * @param callable(EloquentCollection<int, Model&SearchableInterface>): void $reporter
     */
    public static function whileReportingImportProgress(callable $reporter, callable $callback): mixed
    {
        $hadReporter = CoroutineContext::has(self::IMPORT_PROGRESS_CONTEXT_KEY);
        $previous = CoroutineContext::get(self::IMPORT_PROGRESS_CONTEXT_KEY);

        CoroutineContext::set(self::IMPORT_PROGRESS_CONTEXT_KEY, $reporter);

        try {
            return $callback();
        } finally {
            if ($hadReporter) {
                CoroutineContext::set(self::IMPORT_PROGRESS_CONTEXT_KEY, $previous);
            } else {
                CoroutineContext::forget(self::IMPORT_PROGRESS_CONTEXT_KEY);
            }
        }
    }

    /**
     * Report imported models to the current coroutine's scout:import progress reporter.
     *
     * @param EloquentCollection<int, Model&SearchableInterface> $models
     */
    public static function reportImportProgress(EloquentCollection $models): void
    {
        $reporter = CoroutineContext::get(self::IMPORT_PROGRESS_CONTEXT_KEY);

        if (is_callable($reporter)) {
            $reporter($models);
        }
    }

    /**
     * Flush all static state back to defaults.
     *
     * Boot or tests only. Resets worker-wide Scout job configuration; concurrent
     * imports may use different job classes depending on timing.
     */
    public static function flushState(): void
    {
        static::$makeSearchableJob = MakeSearchable::class;
        static::$removeFromSearchJob = RemoveFromSearch::class;
        CoroutineContext::forget(self::IMPORTING_CONTEXT_KEY);
        CoroutineContext::forget(self::IMPORT_PROGRESS_CONTEXT_KEY);
    }
}
