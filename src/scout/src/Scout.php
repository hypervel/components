<?php

declare(strict_types=1);

namespace Hypervel\Scout;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Engines\Engine;
use Hypervel\Scout\Jobs\MakeSearchable;
use Hypervel\Scout\Jobs\RemoveFromSearch;

/**
 * Scout utility class for lifecycle customization and engine access.
 *
 * Provides static configuration for customizing the job classes used
 * when indexing models via the queue. Set these in a service provider
 * boot method to use custom job implementations.
 *
 * Note: These static properties are set at boot time and read during
 * request handling. Job classes are stable strings, while lifecycle
 * callbacks may capture objects and therefore must never be registered
 * from request handling.
 */
class Scout
{
    /**
     * The default job class that makes models searchable.
     */
    protected const DEFAULT_MAKE_SEARCHABLE_JOB = MakeSearchable::class;

    /**
     * The default job class that removes models from the search index.
     */
    protected const DEFAULT_REMOVE_FROM_SEARCH_JOB = RemoveFromSearch::class;

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
     * The job class that makes models searchable.
     *
     * @var class-string<MakeSearchable>
     */
    public static string $makeSearchableJob = self::DEFAULT_MAKE_SEARCHABLE_JOB;

    /**
     * The job class that removes models from the search index.
     *
     * @var class-string<RemoveFromSearch>
     */
    public static string $removeFromSearchJob = self::DEFAULT_REMOVE_FROM_SEARCH_JOB;

    /**
     * The callback that prepares a Builder before terminal execution.
     *
     * @var null|(Closure(Builder, Engine): void)
     */
    protected static ?Closure $prepareBuilderCallback = null;

    /**
     * The callback that prepares a final searchable document.
     *
     * @var null|(Closure(array<string, mixed>, Model, Engine): array<string, mixed>)
     */
    protected static ?Closure $prepareSearchableDocumentCallback = null;

    /**
     * The callback that prepares final index settings.
     *
     * @var null|(Closure(array<string, mixed>, null|Model, Engine, string): array<string, mixed>)
     */
    protected static ?Closure $prepareIndexSettingsCallback = null;

    /**
     * The callback that guards a whole-model index flush.
     *
     * @var null|(Closure(Model, Engine, bool): void)
     */
    protected static ?Closure $guardModelFlushCallback = null;

    /**
     * Get a Scout engine instance by name.
     */
    public static function engine(?string $name = null): Engine
    {
        return app(EngineManager::class)->engine($name);
    }

    /**
     * Specify the callback that prepares a Builder before terminal execution.
     *
     * Boot-only. The callback persists for the worker lifetime, so registering
     * it per request would leak captured request state into later requests.
     *
     * @param callable(Builder, Engine): void $callback
     */
    public static function prepareBuilderUsing(callable $callback): void
    {
        static::$prepareBuilderCallback = Closure::fromCallable($callback);
    }

    /**
     * Prepare a Builder before terminal execution.
     */
    public static function prepareBuilder(Builder $builder, Engine $engine): void
    {
        if (static::$prepareBuilderCallback !== null) {
            (static::$prepareBuilderCallback)($builder, $engine);
        }
    }

    /**
     * Specify the callback that prepares a final searchable document.
     *
     * Boot-only. The callback persists for the worker lifetime, so registering
     * it per request would leak captured request state into later requests.
     *
     * @param callable(array<string, mixed>, Model, Engine): array<string, mixed> $callback
     */
    public static function prepareSearchableDocumentUsing(callable $callback): void
    {
        static::$prepareSearchableDocumentCallback = Closure::fromCallable($callback);
    }

    /**
     * Prepare a final searchable document.
     *
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    public static function prepareSearchableDocument(array $document, Model $model, Engine $engine): array
    {
        return static::$prepareSearchableDocumentCallback === null
            ? $document
            : (static::$prepareSearchableDocumentCallback)($document, $model, $engine);
    }

    /**
     * Specify the callback that prepares final index settings.
     *
     * Boot-only. The callback persists for the worker lifetime, so registering
     * it per request would leak captured request state into later requests.
     *
     * @param callable(array<string, mixed>, null|Model, Engine, string): array<string, mixed> $callback
     */
    public static function prepareIndexSettingsUsing(callable $callback): void
    {
        static::$prepareIndexSettingsCallback = Closure::fromCallable($callback);
    }

    /**
     * Prepare final index settings.
     *
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    public static function prepareIndexSettings(array $settings, ?Model $model, Engine $engine, string $index): array
    {
        return static::$prepareIndexSettingsCallback === null
            ? $settings
            : (static::$prepareIndexSettingsCallback)($settings, $model, $engine, $index);
    }

    /**
     * Specify the callback that guards a whole-model index flush.
     *
     * Boot-only. The callback persists for the worker lifetime, so registering
     * it per request would leak captured request state into later requests.
     *
     * @param callable(Model, Engine, bool): void $callback
     */
    public static function guardModelFlushUsing(callable $callback): void
    {
        static::$guardModelFlushCallback = Closure::fromCallable($callback);
    }

    /**
     * Guard a whole-model index flush.
     */
    public static function guardModelFlush(Model $model, Engine $engine, bool $force): void
    {
        if (static::$guardModelFlushCallback !== null) {
            (static::$guardModelFlushCallback)($model, $engine, $force);
        }
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
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$makeSearchableJob = self::DEFAULT_MAKE_SEARCHABLE_JOB;
        static::$removeFromSearchJob = self::DEFAULT_REMOVE_FROM_SEARCH_JOB;
        static::$prepareBuilderCallback = null;
        static::$prepareSearchableDocumentCallback = null;
        static::$prepareIndexSettingsCallback = null;
        static::$guardModelFlushCallback = null;
    }
}
