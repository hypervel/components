<?php

declare(strict_types=1);

namespace Hypervel\Notifications;

class Notification
{
    /**
     * The unique identifier for the notification.
     */
    public ?string $id = null;

    /**
     * The locale to be used when sending the notification.
     */
    public ?string $locale = null;

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [];
    }

    /**
     * Set the locale to send this notification in.
     */
    public function locale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }
}
