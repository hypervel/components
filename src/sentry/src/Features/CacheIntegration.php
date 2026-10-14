<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features;

use Hypervel\Cache\Events\CacheEvent;
use Hypervel\Cache\Events\CacheHit;
use Hypervel\Cache\Events\CacheMissed;
use Hypervel\Cache\Events\ForgettingKey;
use Hypervel\Cache\Events\KeyForgetFailed;
use Hypervel\Cache\Events\KeyForgotten;
use Hypervel\Cache\Events\KeyRetrievalFailed;
use Hypervel\Cache\Events\KeyWriteFailed;
use Hypervel\Cache\Events\KeyWritten;
use Hypervel\Cache\Events\ManyKeysRetrievalFailed;
use Hypervel\Cache\Events\RetrievingKey;
use Hypervel\Cache\Events\RetrievingManyKeys;
use Hypervel\Cache\Events\WritingKey;
use Hypervel\Cache\Events\WritingManyKeys;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Session\Session;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Redis\Events\CommandExecuted;
use Hypervel\Redis\Events\CommandFailed;
use Hypervel\Redis\Pool\PoolManager;
use Hypervel\Redis\RedisConfig;
use Hypervel\Redis\RedisManager;
use Hypervel\Sentry\Features\Concerns\ResolvesEventOrigin;
use Hypervel\Sentry\Features\Concerns\TracksPushedScopesAndSpans;
use Hypervel\Sentry\Features\Concerns\WorksWithSpans;
use Hypervel\Sentry\Http\HypervelRequestFetcher;
use Hypervel\Sentry\Integration;
use Hypervel\Support\Str;
use Sentry\Breadcrumb;
use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Throwable;

class CacheIntegration extends Feature
{
    use WorksWithSpans;
    use TracksPushedScopesAndSpans;
    use ResolvesEventOrigin;

    private const string FEATURE_KEY = 'cache';

    private const string SESSION_KEY_RESOLUTION_CONTEXT_KEY = '__sentry.session_key.resolving';

    private const string SESSION_KEY_PLACEHOLDER = '{sessionKey}';

    /**
     * Indicates whether to attempt to detect the session key when running in the console.
     *
     * Tests only. This mutates the feature singleton and should not be used at runtime.
     *
     * @internal this is mainly intended for testing purposes
     */
    public bool $detectSessionKeyOnConsole = false;

    /**
     * Indicate if the feature is applicable to the current environment.
     */
    public function isApplicable(): bool
    {
        return $this->isTracingFeatureEnabled('redis_commands', false)
            || $this->isTracingFeatureEnabled(self::FEATURE_KEY)
            || $this->isBreadcrumbFeatureEnabled(self::FEATURE_KEY);
    }

    /**
     * Set up the feature in the environment.
     */
    public function onBoot(): void
    {
        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->container->make('events');

        if ($this->isBreadcrumbFeatureEnabled(self::FEATURE_KEY)) {
            $dispatcher->listen([
                CacheHit::class,
                CacheMissed::class,
                KeyWritten::class,
                KeyForgotten::class,
            ], [$this, 'handleCacheEventsForBreadcrumbs']);
        }

        if ($this->isTracingFeatureEnabled(self::FEATURE_KEY)) {
            $dispatcher->listen([
                RetrievingKey::class,
                RetrievingManyKeys::class,
                CacheHit::class,
                CacheMissed::class,
                KeyRetrievalFailed::class,
                ManyKeysRetrievalFailed::class,

                WritingKey::class,
                WritingManyKeys::class,
                KeyWritten::class,
                KeyWriteFailed::class,

                ForgettingKey::class,
                KeyForgotten::class,
                KeyForgetFailed::class,
            ], [$this, 'handleCacheEventsForTracing']);
        }

        if ($this->isTracingFeatureEnabled('redis_commands', false)) {
            $dispatcher->listen([CommandExecuted::class, CommandFailed::class], [$this, 'handleRedisCommands']);

            // Enabled directly because an afterResolving() callback never runs for a manager resolved before Sentry boots
            $this->container->make(RedisManager::class)->enableEvents();
        }
    }

    /**
     * Record a breadcrumb for the given cache event.
     */
    public function handleCacheEventsForBreadcrumbs(CacheEvent $event): void
    {
        switch (true) {
            case $event instanceof KeyWritten:
                $message = 'Written';
                break;
            case $event instanceof KeyForgotten:
                $message = 'Forgotten';
                break;
            case $event instanceof CacheMissed:
                $message = 'Missed';
                break;
            case $event instanceof CacheHit:
                $message = 'Read';
                break;
            default:
                // In case events are added in the future we do nothing when an unknown event is encountered
                return;
        }

        $displayKey = $this->replaceSessionKey($event->key);

        Integration::addBreadcrumb(
            new Breadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_DEFAULT,
                'cache',
                "{$message}: {$displayKey}",
                $event->tags ? ['tags' => $event->tags] : []
            )
        );
    }

    /**
     * Record a span for the given cache event.
     */
    public function handleCacheEventsForTracing(CacheEvent $event): void
    {
        if ($this->maybeHandleCacheEventAsEndOfSpan($event)) {
            return;
        }

        $this->withParentSpanIfSampled(function (Span $parentSpan) use ($event): void {
            if ($event instanceof RetrievingKey || $event instanceof RetrievingManyKeys) {
                // Hypervel cache events contain resolved string keys, so upstream normalization is unnecessary.
                $keys = $event instanceof RetrievingKey ? [$event->key] : $event->keys;

                $displayKeys = $this->replaceSessionKeys($keys);

                $this->pushCacheSpan(
                    $parentSpan->startChild(
                        SpanContext::make()
                            ->setOp('cache.get')
                            ->setData([
                                'cache.key' => $displayKeys,
                            ])
                            ->setOrigin('auto.cache')
                            ->setDescription(implode(', ', $displayKeys))
                    ),
                    $event instanceof RetrievingManyKeys ? count(array_unique($keys)) : 1,
                );
            }

            if ($event instanceof WritingKey || $event instanceof WritingManyKeys) {
                $keys = $event instanceof WritingKey ? [$event->key] : $event->keys;

                $displayKeys = $this->replaceSessionKeys($keys);

                $this->pushCacheSpan(
                    $parentSpan->startChild(
                        SpanContext::make()
                            ->setOp('cache.put')
                            ->setData([
                                'cache.key' => $displayKeys,
                                'cache.ttl' => $event->seconds,
                            ])
                            ->setOrigin('auto.cache')
                            ->setDescription(implode(', ', $displayKeys))
                    ),
                    count($keys),
                );
            }

            if ($event instanceof ForgettingKey) {
                $displayKey = $this->replaceSessionKey($event->key);

                $this->pushCacheSpan(
                    $parentSpan->startChild(
                        SpanContext::make()
                            ->setOp('cache.remove')
                            ->setData([
                                'cache.key' => [$displayKey],
                            ])
                            ->setOrigin('auto.cache')
                            ->setDescription($displayKey)
                    ),
                    1,
                );
            }
        });
    }

    /**
     * Record a span for the given executed or failed Redis command.
     */
    public function handleRedisCommands(CommandExecuted|CommandFailed $event): void
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        // If there is no sampled span there is no need to handle the event
        if ($parentSpan === null || ! $parentSpan->getSampled()) {
            return;
        }

        $context = SpanContext::make()
            ->setOp('db.redis')
            ->setOrigin('auto.cache.redis');

        $keyForDescription = '';
        $firstParameter = $event->parameters[0] ?? null;

        // If the first parameter is a string and does not contain a newline we use it as the description since it's most likely a key
        // This is not a perfect solution but it's the best we can do without understanding the command that was executed
        if (is_string($firstParameter) && $firstParameter !== '' && ! Str::contains($firstParameter, "\n")) {
            $keyForDescription = $this->replaceSessionKey($firstParameter);
        }

        $context->setDescription(rtrim(strtoupper($event->command) . ' ' . $keyForDescription));

        if ($event->time === null) {
            $now = microtime(true);
            $context->setStartTimestamp($now);
            $context->setEndTimestamp($now);
        } else {
            $context->setStartTimestamp(microtime(true) - $event->time / 1000);
            $context->setEndTimestamp($context->getStartTimestamp() + $event->time / 1000);
        }

        $data = [
            'coroutine.id' => Coroutine::id(),
            'db.system' => 'redis',
            'db.redis.connection' => $event->connectionName,
            'db.redis.database_index' => $this->container->make(RedisConfig::class)->connectionConfig($event->connectionName)['database'] ?? 0,
        ];

        $pool = $this->container->make(PoolManager::class)->getPools()[$event->connectionName] ?? null;

        if ($pool !== null) {
            $options = $pool->getOptions();
            $data += [
                'db.redis.pool.name' => $event->connectionName,
                'db.redis.pool.max' => $options->maxConnections,
                'db.redis.pool.max_idle_time' => $options->maxIdleTime,
                'db.redis.pool.managed' => $pool->getManagedCount(),
                'db.redis.pool.borrowed' => $pool->getBorrowedCount(),
                'db.redis.pool.idle' => $pool->getIdleCount(),
                'db.redis.pool.waiting' => $pool->getWaitingCount(),
            ];
        }

        if ($event instanceof CommandFailed) {
            $context->setStatus(SpanStatus::internalError());
            $data['db.redis.error'] = $event->exception->getMessage();
        }

        $policy = DataCollectionPolicy::fromHub(SentrySdk::getCurrentHub());

        $shouldCollectParameters = $policy->isLegacyMode()
            ? $this->shouldSendDefaultPii()
            : $policy->shouldCollectDatabaseQueryData();

        if ($shouldCollectParameters) {
            $data['db.redis.parameters'] = $this->replaceSessionKeys($event->parameters);
        }

        if ($this->isTracingFeatureEnabled('redis_origin')) {
            $commandOrigin = $this->resolveEventOrigin();

            if ($commandOrigin !== null) {
                $data = array_merge($data, $commandOrigin);
            }
        }

        $context->setData($data);

        $parentSpan->startChild($context);
    }

    /**
     * Finish the span started for the given cache event, if the event ends one.
     */
    protected function maybeHandleCacheEventAsEndOfSpan(CacheEvent $event): bool
    {
        // End of span for RetrievingKey and RetrievingManyKeys events
        if ($event instanceof CacheHit
            || $event instanceof CacheMissed
            || $event instanceof KeyRetrievalFailed
            || $event instanceof ManyKeysRetrievalFailed) {
            $failed = $event instanceof KeyRetrievalFailed || $event instanceof ManyKeysRetrievalFailed;
            $finishedSpan = $this->finishCacheSpan(
                $failed ? SpanStatus::internalError() : SpanStatus::ok(),
                $event instanceof ManyKeysRetrievalFailed,
            );

            if (! $failed
                && $finishedSpan !== null
                && count($finishedSpan->getData()['cache.key'] ?? []) === 1) {
                $finishedSpan->setData([
                    'cache.hit' => $event instanceof CacheHit,
                ]);
            }

            return true;
        }

        // End of span for WritingKey and WritingManyKeys events
        if ($event instanceof KeyWritten || $event instanceof KeyWriteFailed) {
            $finishedSpan = $this->finishCacheSpan(
                $event instanceof KeyWritten ? SpanStatus::ok() : SpanStatus::internalError()
            );

            $finishedSpan?->setData([
                'cache.success' => $event instanceof KeyWritten,
            ]);

            return true;
        }

        // End of span for ForgettingKey event
        if ($event instanceof KeyForgotten || $event instanceof KeyForgetFailed) {
            $this->finishCacheSpan(
                $event instanceof KeyForgotten
                    ? SpanStatus::ok()
                    : ($event->exception === null ? null : SpanStatus::internalError())
            );

            return true;
        }

        return false;
    }

    /**
     * Track the number of terminal events expected for the cache span.
     */
    private function pushCacheSpan(Span $span, int $remainingEvents): void
    {
        $this->pushSpan($span);

        $remaining = CoroutineContext::get($this->contextKey('remaining_events'), []);
        $remaining[] = $remainingEvents;
        CoroutineContext::set($this->contextKey('remaining_events'), $remaining);
    }

    /**
     * Finish the cache span after its last key result or a whole-operation failure.
     */
    private function finishCacheSpan(?SpanStatus $status, bool $failedOperation = false): ?Span
    {
        // If a cache listener throws and its caller continues, tracing may remain
        // inaccurate until coroutine cleanup. We accept this request-local limitation
        // rather than add cache lifecycle hooks or AOP solely for that case.
        $remaining = CoroutineContext::get($this->contextKey('remaining_events'), []);

        if ($remaining === []) {
            return null;
        }

        $index = array_key_last($remaining);

        if (! $failedOperation && --$remaining[$index] > 0) {
            CoroutineContext::set($this->contextKey('remaining_events'), $remaining);

            return null;
        }

        array_pop($remaining);
        CoroutineContext::set($this->contextKey('remaining_events'), $remaining);

        return $this->maybeFinishSpan($status);
    }

    // Upstream's isCacheEventForActiveSession(), getActiveCacheBackedSessionStore() and
    // isCacheBackedSessionStore() are not ported: Hypervel has no cache-backed session
    // handlers, so no cache event is ever a session operation.

    /**
     * Retrieve the current session key if available.
     */
    private function getSessionKey(): ?string
    {
        try {
            // A session key is highly unusual to be available when running in the console
            // So we skip trying to get the session key in that case to prevent booting up the session store unnecessarily
            // Doing this anyway can result in unnecessary database connections for example
            // See: https://github.com/getsentry/sentry-laravel/issues/1057
            if (! $this->detectSessionKeyOnConsole && app()->runningInConsole()) {
                return null;
            }

            if ($this->container->resolved('session.store')) {
                return $this->resolveSessionStoreKey();
            }

            $sessionKey = $this->getSessionKeyFromCurrentRequest();

            if ($sessionKey !== null) {
                return $sessionKey;
            }

            // Constructing the session store can run cache operations that need the session key again
            if (CoroutineContext::get(self::SESSION_KEY_RESOLUTION_CONTEXT_KEY, false) === true) {
                return null;
            }

            CoroutineContext::set(self::SESSION_KEY_RESOLUTION_CONTEXT_KEY, true);

            try {
                // It is safe for us to get the session ID here without checking if the session is started
                // because getting the session ID does not start the session.
                return $this->resolveSessionStoreKey();
            } finally {
                CoroutineContext::forget(self::SESSION_KEY_RESOLUTION_CONTEXT_KEY);
            }
        } catch (Throwable) {
            // We can assume the session store is not available here so there is no session key to retrieve
            // We capture a generic exception to avoid breaking the application because some code paths can
            // result in an exception other than the expected `BindingResolutionException`
            return null;
        }
    }

    /**
     * Retrieve the session key from the current request cookie if available.
     */
    private function getSessionKeyFromCurrentRequest(): ?string
    {
        $request = HypervelRequestFetcher::currentRequest();

        if ($request === null) {
            return null;
        }

        $sessionKey = $request->cookies->get($this->container->make('config')->string('session.cookie'));

        return is_string($sessionKey) && $sessionKey !== '' ? $sessionKey : null;
    }

    /**
     * Retrieve the session key from the session store.
     */
    private function resolveSessionStoreKey(): string
    {
        /** @var Session $sessionStore */
        $sessionStore = $this->container->make('session.store');

        return $sessionStore->getId();
    }

    /**
     * Replace a session key with a placeholder.
     */
    private function replaceSessionKey(string $value): string
    {
        return $value === $this->getSessionKey() ? self::SESSION_KEY_PLACEHOLDER : $value;
    }

    /**
     * Replace session keys in an array of keys with placeholders.
     *
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private function replaceSessionKeys(array $values): array
    {
        $sessionKey = $this->getSessionKey();

        return array_map(
            static fn (mixed $value): mixed => is_string($value) && $value === $sessionKey
                ? self::SESSION_KEY_PLACEHOLDER
                : $value,
            $values
        );
    }
}
