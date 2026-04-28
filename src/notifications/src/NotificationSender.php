<?php

declare(strict_types=1);

namespace Hypervel\Notifications;

use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Bus\Dispatcher as BusDispatcherContract;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Contracts\Translation\HasLocalePreference;
use Hypervel\Database\Eloquent\Collection as ModelCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Notifications\Events\NotificationFailed;
use Hypervel\Notifications\Events\NotificationSending;
use Hypervel\Notifications\Events\NotificationSent;
use Hypervel\Queue\Attributes\Connection;
use Hypervel\Queue\Attributes\Queue as QueueAttribute;
use Hypervel\Queue\Attributes\ReadsQueueAttributes;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\Localizable;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Throwable;

use function value;

class NotificationSender
{
    use Localizable;
    use ReadsQueueAttributes;

    /**
     * The context key for tracking whether a NotificationFailed event was already dispatched.
     *
     * Used to prevent double-dispatching when a channel's send() method internally
     * dispatches NotificationFailed and then throws. Stored in coroutine-local Context
     * rather than an instance property because the event dispatcher is process-global
     * in Swoole — a per-instance listener would leak into the persistent $listeners array.
     * The boot-time listener (registered in NotificationServiceProvider) sets this flag;
     * sendToNotifiable() resets it before each attempt.
     */
    public const FAILED_EVENT_DISPATCHED_CONTEXT_KEY = '__notifications.failed_dispatched';

    /**
     * Create a new notification sender instance.
     */
    public function __construct(
        protected ChannelManager $manager,
        protected BusDispatcherContract $bus,
        protected Dispatcher $events,
        protected ?string $locale = null
    ) {
    }

    /**
     * Send the given notification to the given notifiable entities.
     */
    public function send(mixed $notifiables, mixed $notification): void
    {
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
            if (empty($viaChannels = $channels ?: $original->via($notifiable))) {
                continue;
            }

            $this->withLocale($this->preferredLocale($notifiable, $original), function () use ($viaChannels, $notifiable, $original) {
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
     *
     * @throws Throwable
     */
    protected function sendToNotifiable(mixed $notifiable, string $id, mixed $notification, string $channel): void
    {
        if (! $notification->id) {
            $notification->id = $id;
        }

        if (! $this->shouldSendNotification($notifiable, $notification, $channel)) {
            return;
        }

        // Reset per-attempt — must not carry over from a previous channel/notifiable
        CoroutineContext::set(self::FAILED_EVENT_DISPATCHED_CONTEXT_KEY, false);

        try {
            $response = $this->manager->driver($channel)->send($notifiable, $notification);
        } catch (Throwable $exception) {
            if (! CoroutineContext::get(self::FAILED_EVENT_DISPATCHED_CONTEXT_KEY, false)) {
                if ($exception instanceof HttpTransportException) {
                    $exception = new TransportException($exception->getMessage(), $exception->getCode());
                }

                if ($this->events->hasListeners(NotificationFailed::class)) {
                    $this->events->dispatch(
                        new NotificationFailed($notifiable, $notification, $channel, ['exception' => $exception])
                    );
                }
            }

            // Reset so next attempt starts clean
            CoroutineContext::set(self::FAILED_EVENT_DISPATCHED_CONTEXT_KEY, false);

            throw $exception;
        }

        if (method_exists($notification, 'afterSending')) {
            $notification->afterSending($notifiable, $channel, $response);
        }

        if ($this->events->hasListeners(NotificationSent::class)) {
            $this->events->dispatch(
                new NotificationSent($notifiable, $notification, $channel, $response)
            );
        }
    }

    /**
     * Determine if the notification can be sent.
     */
    protected function shouldSendNotification(mixed $notifiable, mixed $notification, string $channel): bool
    {
        if (method_exists($notification, 'shouldSend')
            && $notification->shouldSend($notifiable, $channel) === false
        ) {
            return false;
        }

        if (! $this->events->hasListeners(NotificationSending::class)) {
            return true;
        }

        return $this->events->until(
            new NotificationSending($notifiable, $notification, $channel)
        ) !== false;
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

                $connection = $this->getAttributeValue($notification, Connection::class, 'connection')
                    ?? $this->manager->resolveConnectionFromQueueRoute($notification)
                    ?? null;

                if (method_exists($notification, 'viaConnections')) {
                    $connection = $notification->viaConnections()[$channel] ?? $connection;
                }

                $queue = $this->getAttributeValue($notification, QueueAttribute::class, 'queue')
                    ?? $this->manager->resolveQueueFromQueueRoute($notification)
                    ?? null;

                if (method_exists($notification, 'viaQueues')) {
                    $queue = $notification->viaQueues()[$channel] ?? $queue;
                }

                $delay = $notification->delay;

                if (method_exists($notification, 'withDelay')) {
                    $delay = $notification->withDelay($notifiable, $channel) ?? null;
                }

                $messageGroup = $notification->messageGroup ?? (method_exists($notification, 'messageGroup') ? $notification->messageGroup() : null);

                if (method_exists($notification, 'withMessageGroups')) {
                    $messageGroup = $notification->withMessageGroups($notifiable, $channel) ?? null;
                }

                $deduplicator = $notification->deduplicator ?? (method_exists($notification, 'deduplicationId') ? $notification->deduplicationId(...) : null);

                if (method_exists($notification, 'withDeduplicators')) {
                    $deduplicator = $notification->withDeduplicators($notifiable, $channel) ?? null;
                }

                $middleware = $notification->middleware ?? [];

                if (method_exists($notification, 'middleware')) {
                    $middleware = array_merge(
                        $notification->middleware($notifiable, $channel),
                        $middleware
                    );
                }

                $this->bus->dispatch(
                    $this->manager->getContainer()->make(SendQueuedNotifications::class, [
                        'notifiables' => $notifiable,
                        'notification' => $notification,
                        'channels' => [$channel],
                    ])
                        ->onConnection($connection)
                        ->onQueue($queue)
                        ->delay(is_array($delay) ? ($delay[$channel] ?? null) : $delay)
                        ->onGroup(is_array($messageGroup) ? ($messageGroup[$channel] ?? null) : $messageGroup)
                        ->withDeduplicator(is_array($deduplicator) ? ($deduplicator[$channel] ?? null) : $deduplicator)
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
