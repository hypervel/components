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
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Sentry\Features\Concerns\ResolvesEventOrigin;
use Hypervel\Sentry\Features\Concerns\ResolvesSessionKey;
use Hypervel\Sentry\Features\Concerns\TracksPushedScopesAndSpans;
use Hypervel\Sentry\Features\Concerns\WorksWithSpans;
use Hypervel\Sentry\Integration;
use Sentry\Breadcrumb;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;

class CacheFeature extends Feature
{
    use WorksWithSpans;
    use TracksPushedScopesAndSpans;
    use ResolvesEventOrigin;
    use ResolvesSessionKey;

    /**
     * Indicates whether to attempt to detect the session key when running in the console.
     *
     * Tests only. This mutates the feature singleton and should not be used at runtime.
     *
     * @internal this is mainly intended for testing purposes
     */
    public bool $detectSessionKeyOnConsole = false;

    private const FEATURE_KEY = 'cache';

    public function isApplicable(): bool
    {
        return $this->isTracingFeatureEnabled(self::FEATURE_KEY)
            || $this->isBreadcrumbFeatureEnabled(self::FEATURE_KEY);
    }

    public function onBoot(): void
    {
        $config = $this->container->make('config');
        $stores = array_keys($config->array('cache.stores', []));
        foreach ($stores as $store) {
            $config->set("cache.stores.{$store}.events", true);
        }
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
                // Hypervel cache events contain resolved string keys, so upstream normalization is unnecessary.
                $keys = $event instanceof RetrievingKey ? [$event->key] : $event->keys;

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
                $keys = $event instanceof WritingKey ? [$event->key] : $event->keys;

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
        if ($event instanceof CacheHit
            || $event instanceof CacheMissed
            || $event instanceof KeyRetrievalFailed
            || $event instanceof ManyKeysRetrievalFailed) {
            $failed = $event instanceof KeyRetrievalFailed || $event instanceof ManyKeysRetrievalFailed;
            $finishedSpan = $this->maybeFinishSpan(
                $failed ? SpanStatus::internalError() : SpanStatus::ok()
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
            $this->maybeFinishSpan(
                $event instanceof KeyForgotten ? SpanStatus::ok() : SpanStatus::internalError()
            );

            return true;
        }

        return false;
    }
}
