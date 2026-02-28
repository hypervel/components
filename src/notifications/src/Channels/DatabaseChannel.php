<?php

declare(strict_types=1);

namespace Hypervel\Notifications\Channels;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Notifications\Notification;
use RuntimeException;

class DatabaseChannel
{
    /**
     * Send the given notification.
     */
    public function send(mixed $notifiable, Notification $notification): Model
    {
        return $notifiable->routeNotificationFor('database', $notification)->create(
            $this->buildPayload($notifiable, $notification)
        );
    }

    /**
     * Build an array payload for the DatabaseNotification Model.
     */
    protected function buildPayload(mixed $notifiable, Notification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => method_exists($notification, 'databaseType')
                ? $notification->databaseType($notifiable) // @phpstan-ignore-line
                : get_class($notification),
            'data' => $this->getData($notifiable, $notification),
            'read_at' => null,
        ];
    }

    /**
     * Get the data for the notification.
     *
     * @throws RuntimeException
     */
    protected function getData(mixed $notifiable, Notification $notification): array
    {
        if (method_exists($notification, 'toDatabase')) {
            return is_array($data = $notification->toDatabase($notifiable)) // @phpstan-ignore-line
                ? $data : $data->data;
        }

        if (method_exists($notification, 'toArray')) {
            /* @phpstan-ignore-next-line */
            return $notification->toArray($notifiable);
        }

        throw new RuntimeException('Notification is missing toDatabase / toArray method.');
    }
}
