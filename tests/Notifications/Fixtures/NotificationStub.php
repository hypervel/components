<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications\Fixtures;

use Hypervel\Notifications\Notification;

class NotificationStub extends Notification
{
    /**
     * Get the notification's delivery channels.
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }
}
