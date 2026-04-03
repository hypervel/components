<?php

declare(strict_types=1);

namespace Hypervel\Notifications\Events;

use Hypervel\Bus\Queueable;
use Hypervel\Notifications\Notification;
use Hypervel\Queue\SerializesModels;

class NotificationSent
{
    use Queueable;
    use SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public mixed $notifiable,
        public Notification $notification,
        public string $channel,
        public mixed $response = null
    ) {
    }
}
