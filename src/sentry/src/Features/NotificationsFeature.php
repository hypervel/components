<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features;

use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Notifications\Events\NotificationSending;
use Hypervel\Notifications\Events\NotificationSent;
use Hypervel\Sentry\Integrations\Integration;
use Hypervel\Sentry\Traits\TracksPushedScopesAndSpans;
use Sentry\Breadcrumb;
use Sentry\SentrySdk;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;

class NotificationsFeature extends Feature
{
    use TracksPushedScopesAndSpans;

    protected const FEATURE_KEY = 'notifications';

    public function isApplicable(): bool
    {
        return $this->switcher->isTracingEnable(static::FEATURE_KEY)
            || $this->switcher->isBreadcrumbEnable(static::FEATURE_KEY);
    }

    public function onBoot(): void
    {
        $dispatcher = $this->container->get(Dispatcher::class);
        if ($this->switcher->isTracingEnable(static::FEATURE_KEY)) {
            $dispatcher->listen(NotificationSending::class, [$this, 'handleNotificationSending']);
        }

        $dispatcher->listen(NotificationSent::class, [$this, 'handleNotificationSent']);
    }

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
            ->setOrigin('auto.laravel.notifications')
            ->setDescription($event->channel);

        $this->pushSpan($parentSpan->startChild($context));
    }

    public function handleNotificationSent(NotificationSent $event): void
    {
        $this->maybeFinishSpan(SpanStatus::ok());

        if ($this->switcher->isBreadcrumbEnable(static::FEATURE_KEY)) {
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
    }

    protected function formatNotifiable($notifiable): string
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
