<?php

declare(strict_types=1);

namespace Hypervel\Notifications\Events;

use Hypervel\Bus\Queueable;
use Hypervel\Notifications\Concerns\SerializesTransport;
use Hypervel\Notifications\Notification;

class NotificationFailed
{
    use Queueable;
    use SerializesTransport;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public mixed $notifiable,
        public Notification $notification,
        public string $channel,
        public array $data = []
    ) {
    }
}
