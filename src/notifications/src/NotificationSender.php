<?php

declare(strict_types=1);

namespace Hypervel\Notifications;

use Hypervel\Support\Collection;
use Hypervel\Database\Eloquent\Collection as ModelCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Str;
use Hypervel\Contracts\Bus\Dispatcher as BusDispatcherContract;
use Hypervel\Notifications\Events\NotificationSending;
use Hypervel\Notifications\Events\NotificationSent;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Support\Traits\Localizable;
use Hypervel\Contracts\Translation\HasLocalePreference;
use Psr\EventDispatcher\EventDispatcherInterface;

use function Hyperf\Support\value;
use function Hyperf\Tappable\tap;

class NotificationSender
{
    use Localizable;

    /**
     * Create a new notification sender instance.
     */
    public function __construct(
        protected ChannelManager $manager,
        protected BusDispatcherContract $bus,
        protected EventDispatcherInterface $events,
        protected ?string $locale = null
    ) {
    }

    /**
     * Send the given notification to the given notifiable entities.
     */
    public function send(mixed $notifiables, mixed $notification): void
    {
        $notifiables = $this->formatNotifiables($notifiables);

        if ($notification instanceof ShouldQueue) {
            $this->queueNotification($notifiables, $notification);
            return;
        }

        $this->sendNow($notifiables, $notification);
    }

    /**
     * Send the given notification immediately.
     */
    public function sendNow(mixed $notifiables, mixed $notification, ?array $channels = null): void
    {
        $notifiables = $this->formatNotifiables($notifiables);

        $original = clone $notification;

        foreach ($notifiables as $notifiable) {
            if (empty($viaChannels = $channels ?: $notification->via($notifiable))) {
                continue;
            }

            $this->withLocale($this->preferredLocale($notifiable, $notification), function () use ($viaChannels, $notifiable, $original) {
                $notificationId = Str::uuid()->toString();

                foreach ((array) $viaChannels as $channel) {
                    if (! ($notifiable instanceof AnonymousNotifiable && $channel === 'database')) {
                        $this->sendToNotifiable($notifiable, $notificationId, clone $original, $channel);
                    }
                }
            });
        }
    }

    /**
     * Get the notifiable's preferred locale for the notification.
     */
    protected function preferredLocale(mixed $notifiable, mixed $notification): ?string
    {
        return $notification->locale ?? $this->locale ?? value(function () use ($notifiable) {
            if ($notifiable instanceof HasLocalePreference) {
                return $notifiable->preferredLocale();
            }
        });
    }

    /**
     * Send the given notification to the given notifiable via a channel.
     */
    protected function sendToNotifiable(mixed $notifiable, string $id, mixed $notification, string $channel): void
    {
        if (! $notification->id) {
            $notification->id = $id;
        }

        if (! $this->shouldSendNotification($notifiable, $notification, $channel)) {
            return;
        }

        $response = $this->manager->driver($channel)->send($notifiable, $notification);

        $this->events->dispatch(
            new NotificationSent($notifiable, $notification, $channel, $response)
        );
    }

    /**
     * Determines if the notification can be sent.
     */
    protected function shouldSendNotification(mixed $notifiable, mixed $notification, string $channel): bool
    {
        if (method_exists($notification, 'shouldSend')
            && $notification->shouldSend($notifiable, $channel) === false
        ) {
            return false;
        }

        return tap(new NotificationSending($notifiable, $notification, $channel), function ($event) {
            $this->events->dispatch($event);
        })->shouldSend();
    }

    /**
     * Queue the given notification instances.
     */
    protected function queueNotification(mixed $notifiables, mixed $notification): void
    {
        $notifiables = $this->formatNotifiables($notifiables);

        $original = clone $notification;

        foreach ($notifiables as $notifiable) {
            $notificationId = Str::uuid()->toString();

            foreach ((array) $original->via($notifiable) as $channel) {
                $notification = clone $original;

                if (! $notification->id) {
                    $notification->id = $notificationId;
                }

                if (! is_null($this->locale)) {
                    $notification->locale = $this->locale;
                }

                $connection = $notification->connection;

                if (method_exists($notification, 'viaConnections')) {
                    $connection = $notification->viaConnections()[$channel] ?? null;
                }

                $queue = $notification->queue;

                if (method_exists($notification, 'viaQueues')) {
                    $queue = $notification->viaQueues()[$channel] ?? null;
                }

                $delay = $notification->delay;

                if (method_exists($notification, 'withDelay')) {
                    $delay = $notification->withDelay($notifiable, $channel) ?? null;
                }

                $middleware = $notification->middleware ?? [];

                if (method_exists($notification, 'middleware')) {
                    $middleware = array_merge(
                        $notification->middleware($notifiable, $channel),
                        $middleware
                    );
                }

                $this->bus->dispatch(
                    (new SendQueuedNotifications($notifiable, $notification, [$channel]))
                        ->onConnection($connection)
                        ->onQueue($queue)
                        ->delay(is_array($delay) ? ($delay[$channel] ?? null) : $delay)
                        ->through($middleware)
                );
            }
        }
    }

    /**
     * Format the notifiables into a Collection / array if necessary.
     */
    protected function formatNotifiables(mixed $notifiables): array|Collection
    {
        if (! $notifiables instanceof Collection && ! is_array($notifiables)) {
            return $notifiables instanceof Model
                ? new ModelCollection([$notifiables]) : [$notifiables];
        }

        return $notifiables;
    }
}
