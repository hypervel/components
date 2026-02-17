<?php

declare(strict_types=1);

namespace Hypervel\Notifications\Channels;

use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Notifications\Events\BroadcastNotificationCreated;
use Hypervel\Notifications\Messages\BroadcastMessage;
use Hypervel\Notifications\Notification;
use RuntimeException;

class BroadcastChannel
{
    /**
     * Create a new broadcast channel.
     */
    public function __construct(
        protected Dispatcher $events
    ) {
    }

    /**
     * Send the given notification.
     */
    public function send(mixed $notifiable, Notification $notification): mixed
    {
        $message = $this->getData($notifiable, $notification);

        $event = new BroadcastNotificationCreated(
            $notifiable,
            $notification,
            is_array($message) ? $message : $message->data
        );

        if ($message instanceof BroadcastMessage) {
            $event->onConnection($message->connection)
                ->onQueue($message->queue);
        }

        return $this->events->dispatch($event);
    }

    /**
     * Get the data for the notification.
     *
     * @throws RuntimeException
     */
    protected function getData(mixed $notifiable, Notification $notification): mixed
    {
        if (method_exists($notification, 'toBroadcast')) {
            return $notification->toBroadcast($notifiable); /* @phpstan-ignore-line */
        }

        if (method_exists($notification, 'toArray')) {
            return $notification->toArray($notifiable); /* @phpstan-ignore-line */
        }

        throw new RuntimeException('Notification is missing toBroadcast / toArray method.');
    }
}
