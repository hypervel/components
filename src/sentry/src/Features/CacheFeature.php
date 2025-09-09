<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features;

use FriendsOfHyperf\Sentry\Integration;
use Hypervel\Cache\Events\CacheHit;
use Hypervel\Cache\Events\CacheMissed;
use Hypervel\Cache\Events\KeyForgotten;
use Hypervel\Cache\Events\KeyWritten;
use Hypervel\Event\Contracts\Dispatcher;
use Hypervel\Sentry\Traits\TracksPushedScopesAndSpans;
use Sentry\Breadcrumb;
use Sentry\Tracing\SpanStatus;

class CacheFeature extends Feature
{
    use TracksPushedScopesAndSpans;

    protected const FEATURE_KEY = 'cache';

    public function isApplicable(): bool
    {
        return $this->isTracingFeatureEnabled(static::FEATURE_KEY)
            || $this->isBreadcrumbFeatureEnabled(static::FEATURE_KEY);
    }

    public function onBoot(): void
    {
        /** @var Dispatcher $dispatcher */
        $dispatcher = $this->container->get(Dispatcher::class);
        if ($this->isBreadcrumbFeatureEnabled(static::FEATURE_KEY)) {
            $dispatcher->listen([
                CacheHit::class,
                CacheMissed::class,
                KeyWritten::class,
                KeyForgotten::class,
            ], [$this, 'handleCacheEventsForBreadcrumbs']);
        }

        if ($this->isTracingFeatureEnabled(static::FEATURE_KEY)) {
            $dispatcher->listen([
                CacheHit::class,
                CacheMissed::class,
                KeyWritten::class,
                KeyForgotten::class,
            ], [$this, 'handleCacheEventsForTracing']);
        }
    }

    public function handleCacheEventsForBreadcrumbs(CacheHit|CacheMissed|KeyForgotten|KeyWritten $event): void
    {
        $message = match (true) {
            $event instanceof KeyWritten => 'Written',
            $event instanceof KeyForgotten => 'Forgotten',
            $event instanceof CacheMissed => 'Missed',
            $event instanceof CacheHit => 'Read',
        };
        Integration::addBreadcrumb(
            new Breadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_DEFAULT,
                'cache',
                "{$message}: {$event->key}",
                $event->tags ? ['tags' => $event->tags] : []
            )
        );
    }

    public function handleCacheEventsForTracing(CacheHit|CacheMissed|KeyForgotten|KeyWritten $event): void
    {
        // End of span for RetrievingKey and RetrievingManyKeys events
        if ($event instanceof CacheHit || $event instanceof CacheMissed) {
            $finishedSpan = $this->maybeFinishSpan(SpanStatus::ok());

            if ($finishedSpan !== null && count($finishedSpan->getData()['cache.key'] ?? []) === 1) {
                $finishedSpan->setData([
                    'cache.hit' => $event instanceof CacheHit,
                ]);
            }

            return;
        }

        // End of span for WritingKey and WritingManyKeys events
        if ($event instanceof KeyWritten) {
            $finishedSpan = $this->maybeFinishSpan(SpanStatus::ok());

            $finishedSpan?->setData([
                'cache.success' => true,
            ]);

            return;
        }

        $this->maybeFinishSpan();
    }
}
