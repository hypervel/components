<?php

declare(strict_types=1);

namespace Hypervel\Telescope;

use Closure;
use Exception;
use Hyperf\Collection\Arr;
use Hyperf\Collection\Collection;
use Hyperf\Context\ApplicationContext;
use Hyperf\Stringable\Str;
use Hypervel\Context\ParentContext;
use Hypervel\Foundation\Exceptions\Contracts\ExceptionHandler;
use Hypervel\Http\Contracts\RequestContract;
use Hypervel\Log\Events\MessageLogged;
use Hypervel\Support\Facades\Auth;
use Hypervel\Telescope\Contracts\EntriesRepository;
use Hypervel\Telescope\Contracts\TerminableRepository;
use Hypervel\Telescope\Jobs\ProcessPendingUpdates;
use Psr\Container\ContainerInterface;
use Throwable;

use function Hyperf\Coroutine\defer;
use function Hypervel\Cache\cache;
use function Hypervel\Config\config;
use function Hypervel\Event\event;

class Telescope
{
    use AuthorizesRequests;
    use ExtractsMailableTags;
    use ListensForStorageOpportunities;
    use RegistersWatchers;

    public const ENTRIES_QUEUE = 'telescope.entries_queue';

    public const UPDATES_QUEUE = 'telescope.updates_queue';

    public const SHOULD_RECORD = 'telescope.should_record';

    public const IS_RECORDING = 'telescope.is_recording';

    /**
     * The callbacks that filter the entries that should be recorded.
     */
    public static array $filterUsing = [];

    /**
     * The callbacks that filter the batches that should be recorded.
     */
    public static array $filterBatchUsing = [];

    /**
     * The callback executed after queuing a new entry.
     */
    public static ?Closure $afterRecordingHook = null;

    /**
     * The callbacks executed after storing the entries.
     *
     * @var Closure[]
     */
    public static array $afterStoringHooks = [];

    /**
     * The callbacks that add tags to the record.
     *
     * @var Closure[]
     */
    public static array $tagUsing = [];

    /**
     * The list of hidden request headers.
     */
    public static array $hiddenRequestHeaders = [
        'authorization',
        'php-auth-pw',
    ];

    /**
     * The list of hidden request parameters.
     */
    public static array $hiddenRequestParameters = [
        'password',
        'password_confirmation',
    ];

    /**
     * The list of hidden response parameters.
     */
    public static array $hiddenResponseParameters = [];

    /**
     * Indicates if Telescope should ignore events fired by Hypervel.
     */
    public static bool $ignoreFrameworkEvents = true;

    /**
     * Indicates if Telescope should use the dark theme.
     */
    public static bool $useDarkTheme = false;

    /**
     * Indicates if Telescope has started.
     */
    public static bool $started = false;

    /**
     * The URIs that should be ignored.
     */
    protected static array $ignoredUris = [];

    /**
     * Register the Telescope watchers and start recording if necessary.
     */
    public static function start(ContainerInterface $app): void
    {
        if (! config('telescope.enabled')) {
            return;
        }

        static::registerWatchers($app);

        static::registerMailableTagExtractor();

        static::$started = true;
    }

    /**
     * Determine if the application is running an approved command.
     */
    protected static function runningApprovedArtisanCommand(): bool
    {
        return ! in_array(
            $_SERVER['argv'][1] ?? null,
            array_merge([
                // 'migrate',
                'migrate:rollback',
                'migrate:fresh',
                // 'migrate:refresh',
                'migrate:reset',
                'migrate:install',
                'queue:listen',
                'queue:work',
                'horizon',
                'horizon:work',
                'horizon:supervisor',
                'watch',
                'start',
                'serve',
            ], config('telescope.ignore_commands', []))
        );
    }

    /**
     * Determine if the application is handling an approved request.
     */
    public static function handlingApprovedRequest(ContainerInterface $app): bool
    {
        return static::requestIsToApprovedDomain($request = $app->get(RequestContract::class))
            && static::requestIsToApprovedUri($request);
    }

    /**
     * Determine if the request is to an approved domain.
     */
    protected static function requestIsToApprovedDomain(RequestContract $request): bool
    {
        return is_null(config('telescope.domain'))
            || config('telescope.domain') !== $request->getHost();
    }

    /**
     * Determine if the request is to an approved URI.
     */
    protected static function requestIsToApprovedUri(RequestContract $request): bool
    {
        if (! empty($only = config('telescope.only_paths', []))) {
            return $request->is($only);
        }

        return ! $request->is(static::getIgnoredUris());
    }

    /**
     * Get the URIs that should be ignored.
     */
    protected static function getIgnoredUris(): array
    {
        if (static::$ignoredUris) {
            return static::$ignoredUris;
        }

        return static::$ignoredUris = Collection::make([
            'telescope-api*',
            'vendor/telescope*',
            (config('horizon.path') ?? 'horizon') . '*',
            'vendor/horizon*',
        ])->merge(config('telescope.ignore_paths', []))
            ->unless(is_null(config('telescope.path')), function ($paths) {
                return $paths->prepend(config('telescope.path') . '*');
            })->all();
    }

    /**
     * Start recording entries.
     */
    public static function startRecording(): void
    {
        if (ParentContext::get(static::SHOULD_RECORD, null)) {
            return;
        }

        $recordingPaused = false;

        try {
            $recordingPaused = static::withoutRecording(
                fn () => cache('telescope:pause-recording', false)
            );
        } catch (Exception) {
        }

        ParentContext::set(static::SHOULD_RECORD, ! $recordingPaused);
    }

    /**
     * Stop recording entries.
     */
    public static function stopRecording(): void
    {
        ParentContext::set(static::SHOULD_RECORD, false);
    }

    /**
     * Execute the given callback without recording Telescope entries.
     */
    public static function withoutRecording(callable $callback): mixed
    {
        $shouldRecord = static::isRecording();

        static::stopRecording();

        try {
            return call_user_func($callback);
        } finally {
            ParentContext::set(static::SHOULD_RECORD, $shouldRecord);
        }
    }

    /**
     * Determine if Telescope is recording.
     */
    public static function isRecording(): bool
    {
        if (! static::$started) {
            return false;
        }

        return ParentContext::get(static::SHOULD_RECORD, false);
    }

    /**
     * Record the given entry.
     */
    protected static function record(string $type, IncomingEntry $entry): void
    {
        if (! static::isRecording()) {
            return;
        }

        if (ParentContext::get(static::IS_RECORDING, false)) {
            return;
        }

        ParentContext::set(static::IS_RECORDING, true);

        try {
            if (Auth::hasUser()) {
                $entry->user(Auth::user());
            }
        } catch (Throwable $e) {
            // Do nothing.
        }

        $entry->type($type)->tags(Arr::collapse(array_map(function ($tagCallback) use ($entry) {
            return $tagCallback($entry);
        }, static::$tagUsing)));

        static::withoutRecording(function () use ($entry) {
            if (Collection::make(static::$filterUsing)->every->__invoke($entry)) {
                ParentContext::override(static::ENTRIES_QUEUE, function ($entries) use ($entry) {
                    return array_merge($entries ?? [], [$entry]);
                });
            }

            if (static::$afterRecordingHook) {
                call_user_func(static::$afterRecordingHook, new static(), $entry);
            }
        });

        ParentContext::set(static::IS_RECORDING, false);
    }

    /**
     * Get the entries queue.
     */
    public static function getEntriesQueue(): array
    {
        return ParentContext::get(static::ENTRIES_QUEUE, []);
    }

    /**
     * Get the updates queue.
     */
    public static function getUpdatesQueue(): array
    {
        return ParentContext::get(static::UPDATES_QUEUE, []);
    }

    /**
     * Record the given entry update.
     */
    public static function recordUpdate(EntryUpdate $update): void
    {
        if (! static::isRecording()) {
            return;
        }

        ParentContext::override(static::UPDATES_QUEUE, function ($updates) use ($update) {
            return array_merge($updates ?? [], [$update]);
        });
    }

    /**
     * Record the given entry.
     */
    public static function recordBatch(IncomingEntry $entry): void
    {
        static::record(EntryType::BATCH, $entry);
    }

    /**
     * Record the given entry.
     */
    public static function recordCache(IncomingEntry $entry): void
    {
        static::record(EntryType::CACHE, $entry);
    }

    /**
     * Record the given entry.
     */
    public static function recordCommand(IncomingEntry $entry): void
    {
        static::record(EntryType::COMMAND, $entry);
    }

    /**
     * Record the given entry.
     */
    public static function recordDump(IncomingEntry $entry): void
    {
        static::record(EntryType::DUMP, $entry);
    }

    /**
     * Record the given entry.
     */
    public static function recordEvent(IncomingEntry $entry): void
    {
        static::record(EntryType::EVENT, $entry);
    }

    /**
     * Record the given entry.
     */
    public static function recordException(IncomingEntry $entry): void
    {
        static::record(EntryType::EXCEPTION, $entry);
    }

    /**
     * Record the given entry.
     */
    public static function recordGate(IncomingEntry $entry): void
    {
        static::record(EntryType::GATE, $entry);
    }

    /**
     * Record the given entry.
     */
    public static function recordJob(IncomingEntry $entry): void
    {
        static::record(EntryType::JOB, $entry);
    }

    /**
     * Record the given entry.
     */
    public static function recordLog(IncomingEntry $entry): void
    {
        static::record(EntryType::LOG, $entry);
    }

    /**
     * Record the given entry.
     */
    public static function recordMail(IncomingEntry $entry): void
    {
        static::record(EntryType::MAIL, $entry);
    }

    /**
     * Record the given entry.
     */
    public static function recordNotification(IncomingEntry $entry): void
    {
        static::record(EntryType::NOTIFICATION, $entry);
    }

    /**
     * Record the given entry.
     */
    public static function recordQuery(IncomingEntry $entry): void
    {
        static::record(EntryType::QUERY, $entry);
    }

    /**
     * Record the given entry.
     */
    public static function recordModelEvent(IncomingEntry $entry): void
    {
        static::record(EntryType::MODEL, $entry);
    }

    /**
     * Record the given entry.
     */
    public static function recordRedis(IncomingEntry $entry): void
    {
        static::record(EntryType::REDIS, $entry);
    }

    /**
     * Record the given entry.
     */
    public static function recordRequest(IncomingEntry $entry): void
    {
        static::record(EntryType::REQUEST, $entry);
    }

    /**
     * Record the given entry.
     */
    public static function recordScheduledCommand(IncomingEntry $entry): void
    {
        static::record(EntryType::SCHEDULED_TASK, $entry);
    }

    /**
     * Record the given entry.
     */
    public static function recordView(IncomingEntry $entry): void
    {
        static::record(EntryType::VIEW, $entry);
    }

    /**
     * Record the given entry.
     */
    public static function recordClientRequest(IncomingEntry $entry): void
    {
        static::record(EntryType::CLIENT_REQUEST, $entry);
    }

    /**
     * Flush all entries in the queue.
     */
    public static function flushEntries(): static
    {
        ParentContext::set(static::ENTRIES_QUEUE, []);

        return new static();
    }

    /**
     * Flush all updates in the queue.
     */
    public static function flushUpdates(): static
    {
        ParentContext::set(static::UPDATES_QUEUE, []);

        return new static();
    }

    /**
     * Record the given exception.
     */
    public static function catch(Throwable $e, array $tags = []): void
    {
        event(new MessageLogged('error', $e->getMessage(), [
            'exception' => $e,
            'telescope' => $tags,
        ]));
    }

    /**
     * Set the callback that filters the entries that should be recorded.
     */
    public static function filter(Closure $callback): static
    {
        static::$filterUsing[] = $callback;

        return new static();
    }

    /**
     * Set the callback that filters the batches that should be recorded.
     */
    public static function filterBatch(Closure $callback): static
    {
        static::$filterBatchUsing[] = $callback;

        return new static();
    }

    /**
     * Set the callback that will be executed after an entry is recorded in the queue.
     */
    public static function afterRecording(Closure $callback): static
    {
        static::$afterRecordingHook = $callback;

        return new static();
    }

    /**
     * Add a callback that will be executed after an entry is stored.
     */
    public static function afterStoring(Closure $callback): static
    {
        static::$afterStoringHooks[] = $callback;

        return new static();
    }

    /**
     * Add a callback that adds tags to the record.
     */
    public static function tag(Closure $callback): static
    {
        static::$tagUsing[] = $callback;

        return new static();
    }

    /**
     * Store the queued entries and flush the queue.
     */
    public static function store(EntriesRepository $storage): void
    {
        if (empty(static::getEntriesQueue()) && empty(static::getUpdatesQueue())) {
            return;
        }

        if (config('telescope.defer', true)) {
            defer(fn () => static::executeStore($storage));
            return;
        }

        static::executeStore($storage);
    }

    /**
     * Store the queued entries and flush the queue.
     */
    protected static function executeStore(EntriesRepository $storage): void
    {
        static::withoutRecording(function () use ($storage) {
            if (! Collection::make(static::$filterBatchUsing)->every->__invoke(Collection::make(static::getEntriesQueue()))) {
                static::flushEntries();
            }

            try {
                $batchId = Str::orderedUuid()->toString();

                $storage->store(static::collectEntries($batchId));

                $pendingUpdates = $storage->update(static::collectUpdates($batchId)) ?: Collection::make();
                if ($pendingUpdates->isNotEmpty()) {
                    try {
                        $delay = config('telescope.queue.delay');
                        ProcessPendingUpdates::dispatch($pendingUpdates)
                            ->onConnection(config('telescope.queue.connection'))
                            ->onQueue(config('telescope.queue.queue'))
                            ->delay(is_numeric($delay) && $delay > 0 ? now()->addSeconds($delay) : null);
                    } catch (Throwable $e) {
                        ApplicationContext::getContainer()
                            ->get(ExceptionHandler::class)
                            ->report($e);
                    }
                }

                if ($storage instanceof TerminableRepository) {
                    $storage->terminate();
                }

                Collection::make(static::$afterStoringHooks)->every->__invoke(static::getEntriesQueue(), $batchId);
            } catch (Throwable $e) {
                ApplicationContext::getContainer()
                    ->get(ExceptionHandler::class)
                    ->report($e);
            }
        });

        static::flushEntries();
        static::flushUpdates();
    }

    /**
     * Collect the entries for storage.
     */
    protected static function collectEntries(string $batchId): Collection
    {
        return Collection::make(static::getEntriesQueue())
            ->each(function ($entry) use ($batchId) {
                $entry->batchId($batchId);

                if ($entry->isDump()) {
                    $entry->assignEntryPointFromBatch(static::getEntriesQueue());
                }
            });
    }

    /**
     * Collect the updated entries for storage.
     */
    protected static function collectUpdates(string $batchId): Collection
    {
        return Collection::make(static::getUpdatesQueue())
            ->each(function ($entry) use ($batchId) {
                $entry->change(['updated_batch_id' => $batchId]);
            });
    }

    /**
     * Hide the given request header.
     */
    public static function hideRequestHeaders(array $headers): static
    {
        static::$hiddenRequestHeaders = array_values(array_unique(array_merge(
            static::$hiddenRequestHeaders,
            $headers
        )));

        return new static();
    }

    /**
     * Hide the given request parameters.
     */
    public static function hideRequestParameters(array $attributes): static
    {
        static::$hiddenRequestParameters = array_merge(
            static::$hiddenRequestParameters,
            $attributes
        );

        return new static();
    }

    /**
     * Hide the given response parameters.
     */
    public static function hideResponseParameters(array $attributes): static
    {
        static::$hiddenResponseParameters = array_values(array_unique(array_merge(
            static::$hiddenResponseParameters,
            $attributes
        )));

        return new static();
    }

    /**
     * Specifies that Telescope should record events fired by Laravel.
     */
    public static function recordFrameworkEvents(): static
    {
        static::$ignoreFrameworkEvents = false;

        return new static();
    }

    /**
     * Specifies that Telescope should use the dark theme.
     */
    public static function night(): static
    {
        static::$useDarkTheme = true;

        return new static();
    }

    /**
     * Register the Telescope user avatar callback.
     */
    public static function avatar(Closure $callback): static
    {
        Avatar::register($callback);

        return new static();
    }

    /**
     * Get the default JavaScript variables for Telescope.
     */
    public static function scriptVariables(): array
    {
        return [
            'path' => config('telescope.path'),
            'timezone' => config('app.timezone'),
            'recording' => ! cache('telescope:pause-recording'),
        ];
    }
}
