<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features;

use Exception;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Cache\Events\CacheEvent;
use Hypervel\Cache\Events\CacheHit;
use Hypervel\Cache\Events\CacheMissed;
use Hypervel\Cache\Events\ForgettingKey;
use Hypervel\Cache\Events\KeyForgetFailed;
use Hypervel\Cache\Events\KeyForgotten;
use Hypervel\Cache\Events\KeyWriteFailed;
use Hypervel\Cache\Events\KeyWritten;
use Hypervel\Cache\Events\RetrievingKey;
use Hypervel\Cache\Events\RetrievingManyKeys;
use Hypervel\Cache\Events\WritingKey;
use Hypervel\Cache\Events\WritingManyKeys;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Contracts\Session\Session;
use Hypervel\Sentry\Integrations\Integration;
use Hypervel\Sentry\Traits\ResolvesEventOrigin;
use Hypervel\Sentry\Traits\TracksPushedScopesAndSpans;
use Hypervel\Sentry\Traits\WorksWithSpans;
use Sentry\Breadcrumb;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;

class CacheFeature extends Feature
{
    use WorksWithSpans;
    use TracksPushedScopesAndSpans;
    use ResolvesEventOrigin;

    protected const FEATURE_KEY = 'cache';

    public function isApplicable(): bool
    {
        return $this->switcher->isTracingEnable(static::FEATURE_KEY)
            || $this->switcher->isBreadcrumbEnable(static::FEATURE_KEY);
    }

    public function onBoot(): void
    {
        $config = $this->container->get(Repository::class);
        $stores = array_keys($config->get('cache.stores', []));
        foreach ($stores as $store) {
            $config->set("cache.stores.{$store}.events", true);
        }
        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->container->get(Dispatcher::class);
        if ($this->switcher->isBreadcrumbEnable(static::FEATURE_KEY)) {
            $dispatcher->listen([
                CacheHit::class,
                CacheMissed::class,
                KeyWritten::class,
                KeyForgotten::class,
            ], [$this, 'handleCacheEventsForBreadcrumbs']);
        }

        if ($this->switcher->isTracingEnable(static::FEATURE_KEY)) {
            $dispatcher->listen([
                RetrievingKey::class,
                RetrievingManyKeys::class,
                CacheHit::class,
                CacheMissed::class,

                WritingKey::class,
                WritingManyKeys::class,
                KeyWritten::class,
                KeyWriteFailed::class,

                ForgettingKey::class,
                KeyForgotten::class,
                KeyForgetFailed::class,
            ], [$this, 'handleCacheEventsForTracing']);
        }
    }

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

    public function handleCacheEventsForTracing(CacheEvent $event): void
    {
        if ($this->maybeHandleCacheEventAsEndOfSpan($event)) {
            return;
        }

        $this->withParentSpanIfSampled(function (Span $parentSpan) use ($event) {
            if ($event instanceof RetrievingKey || $event instanceof RetrievingManyKeys) {
                $keys = $this->normalizeKeyOrKeys(
                    $event instanceof RetrievingKey
                        ? [$event->key]
                        : $event->keys
                );

                $displayKeys = $this->replaceSessionKeys($keys);

                $this->pushSpan(
                    $parentSpan->startChild(
                        SpanContext::make()
                            ->setOp('cache.get')
                            ->setData([
                                'cache.key' => $displayKeys,
                            ])
                            ->setOrigin('auto.cache')
                            ->setDescription(implode(', ', $displayKeys))
                    )
                );
            }

            if ($event instanceof WritingKey || $event instanceof WritingManyKeys) {
                $keys = $this->normalizeKeyOrKeys(
                    $event instanceof WritingKey
                        ? [$event->key]
                        : $event->keys
                );

                $displayKeys = $this->replaceSessionKeys($keys);

                $this->pushSpan(
                    $parentSpan->startChild(
                        SpanContext::make()
                            ->setOp('cache.put')
                            ->setData([
                                'cache.key' => $displayKeys,
                                'cache.ttl' => $event->seconds,
                            ])
                            ->setOrigin('auto.cache')
                            ->setDescription(implode(', ', $displayKeys))
                    )
                );
            }

            if ($event instanceof ForgettingKey) {
                $displayKey = $this->replaceSessionKey($event->key);

                $this->pushSpan(
                    $parentSpan->startChild(
                        SpanContext::make()
                            ->setOp('cache.remove')
                            ->setData([
                                'cache.key' => [$displayKey],
                            ])
                            ->setOrigin('auto.cache')
                            ->setDescription($displayKey)
                    )
                );
            }
        });
    }

    protected function maybeHandleCacheEventAsEndOfSpan(CacheEvent $event): bool
    {
        // End of span for RetrievingKey and RetrievingManyKeys events
        if ($event instanceof CacheHit || $event instanceof CacheMissed) {
            $finishedSpan = $this->maybeFinishSpan(SpanStatus::ok());

            if ($finishedSpan !== null && count($finishedSpan->getData()['cache.key'] ?? []) === 1) {
                $finishedSpan->setData([
                    'cache.hit' => $event instanceof CacheHit,
                ]);
            }

            return true;
        }

        // End of span for WritingKey and WritingManyKeys events
        if ($event instanceof KeyWritten || $event instanceof KeyWriteFailed) {
            $finishedSpan = $this->maybeFinishSpan(
                $event instanceof KeyWritten ? SpanStatus::ok() : SpanStatus::internalError()
            );

            $finishedSpan?->setData([
                'cache.success' => $event instanceof KeyWritten,
            ]);

            return true;
        }

        // End of span for ForgettingKey event
        if ($event instanceof KeyForgotten || $event instanceof KeyForgetFailed) {
            $this->maybeFinishSpan();

            return true;
        }

        return false;
    }

    /**
     * Retrieve the current session key if available.
     */
    private function getSessionKey(): ?string
    {
        try {
            /** @var Session $sessionStore */
            $sessionStore = $this->container->get(Session::class);

            // It is safe for us to get the session ID here without checking if the session is started
            // because getting the session ID does not start the session. In addition we need the ID before
            // the session is started because the cache will retrieve the session ID from the cache before the session
            // is considered started. So if we wait for the session to be started, we will not be able to replace the
            // session key in the cache operation that is being executed to retrieve the session data from the cache.
            return $sessionStore->getId();
        } catch (Exception $e) {
            // We can assume the session store is not available here so there is no session key to retrieve
            // We capture a generic exception to avoid breaking the application because some code paths can
            // result in an exception other than the expected `Hypervel\Contracts\Container\BindingResolutionException`
            return null;
        }
    }

    /**
     * Replace a session key with a placeholder.
     */
    private function replaceSessionKey(string $value): string
    {
        return $value === $this->getSessionKey() ? '{sessionKey}' : $value;
    }

    /**
     * Replace session keys in an array of keys with placeholders.
     *
     * @param string[] $values
     *
     * @return mixed[]
     */
    private function replaceSessionKeys(array $values): array
    {
        $sessionKey = $this->getSessionKey();

        return array_map(static function ($value) use ($sessionKey) {
            // @phpstan-ignore function.alreadyNarrowedType (defensive: event data may contain non-strings)
            return is_string($value) && $value === $sessionKey ? '{sessionKey}' : $value;
        }, $values);
    }

    /**
     * Normalize the array of keys to a array of only strings.
     *
     * @param array<array-key, mixed>|string|string[] $keyOrKeys
     *
     * @return string[]
     */
    private function normalizeKeyOrKeys(array|string $keyOrKeys): array
    {
        if (is_string($keyOrKeys)) {
            return [$keyOrKeys];
        }

        return collect($keyOrKeys)->map(function ($value, $key) {
            return is_string($key) ? $key : $value;
        })->values()->all();
    }
}
