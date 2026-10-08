<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features;

use Hypervel\Context\CoroutineContext;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Notifications\Events\NotificationDelivered;
use Hypervel\Notifications\Events\NotificationFailed;
use Hypervel\Notifications\Events\NotificationSending;
use Hypervel\Notifications\Events\NotificationSent;
use Hypervel\Notifications\Events\NotificationSkipped;
use Hypervel\Sentry\Features\Concerns\TracksPushedScopesAndSpans;
use Hypervel\Sentry\Integration;
use Sentry\Breadcrumb;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;

class NotificationsIntegration extends Feature
{
    use TracksPushedScopesAndSpans;

    private const string FEATURE_KEY = 'notifications';

    private const string SPAN_OWNERS_CONTEXT_KEY = '__sentry.notifications.span_owners';

    /**
     * Indicate if the feature is applicable to the current environment.
     */
    public function isApplicable(): bool
    {
        return $this->isTracingFeatureEnabled(self::FEATURE_KEY)
            || $this->isBreadcrumbFeatureEnabled(self::FEATURE_KEY);
    }

    /**
     * Set up the feature in the environment.
     */
    public function onBoot(): void
    {
        $dispatcher = $this->container->make('events');
        if ($this->isTracingFeatureEnabled(self::FEATURE_KEY)) {
            $dispatcher->listen(NotificationSending::class, [$this, 'handleNotificationSending']);
            $dispatcher->listen([
                NotificationDelivered::class,
                NotificationFailed::class,
                NotificationSkipped::class,
            ], [$this, 'handleNotificationTerminal']);
        }

        if ($this->isBreadcrumbFeatureEnabled(self::FEATURE_KEY)) {
            $dispatcher->listen(NotificationSent::class, [$this, 'handleNotificationSent']);
        }
    }

    /**
     * Start a span for the notification being sent.
     */
    public function handleNotificationSending(NotificationSending $event): void
    {
        $parentSpan = SentrySdk::getCurrentHub()->getSpan();

        // If there is no sampled span there is no need to handle the event
        if ($parentSpan === null || ! $parentSpan->getSampled()) {
            return;
        }

        $context = SpanContext::make()
            ->setOp('notification.send')
            ->setData([
                'id' => $event->notification->id,
                'channel' => $event->channel,
                'notifiable' => $this->formatNotifiable($event->notifiable),
                'notification' => get_class($event->notification),
            ])
            ->setOrigin('auto.hypervel.notifications')
            ->setDescription($event->channel);

        $this->pushSpan($parentSpan->startChild($context));

        $owners = CoroutineContext::get(self::SPAN_OWNERS_CONTEXT_KEY, []);
        $owners[] = $event->notification;
        CoroutineContext::set(self::SPAN_OWNERS_CONTEXT_KEY, $owners);
    }

    /**
     * Record a breadcrumb for the sent notification.
     */
    public function handleNotificationSent(NotificationSent $event): void
    {
        Integration::addBreadcrumb(
            new Breadcrumb(
                Breadcrumb::LEVEL_INFO,
                Breadcrumb::TYPE_DEFAULT,
                'notification.sent',
                'Sent notification',
                [
                    'channel' => $event->channel,
                    'notifiable' => $this->formatNotifiable($event->notifiable),
                    'notification' => get_class($event->notification),
                ]
            )
        );
    }

    /**
     * Finish the span of a delivered, failed or skipped notification.
     */
    public function handleNotificationTerminal(
        NotificationDelivered|NotificationFailed|NotificationSkipped $event
    ): void {
        $owners = CoroutineContext::get(self::SPAN_OWNERS_CONTEXT_KEY, []);

        // The sender also raises terminal events for attempts that started no span, such as
        // skipped notifications, so only the attempt that started the current span may finish it
        if (end($owners) !== $event->notification) {
            return;
        }

        array_pop($owners);
        CoroutineContext::set(self::SPAN_OWNERS_CONTEXT_KEY, $owners);

        $this->maybeFinishSpan(
            $event instanceof NotificationFailed ? SpanStatus::internalError() : SpanStatus::ok()
        );
    }

    /**
     * Format the notifiable for display.
     */
    private function formatNotifiable(mixed $notifiable): string
    {
        if (is_string($notifiable) || is_numeric($notifiable)) {
            return (string) $notifiable;
        }

        if (is_object($notifiable)) {
            $result = get_class($notifiable);

            if ($notifiable instanceof Model) {
                $result .= "({$notifiable->getKey()})";
            }

            return $result;
        }

        return 'unknown';
    }
}
