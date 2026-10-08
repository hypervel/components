<?php

declare(strict_types=1);

namespace Hypervel\Tests\SlackNotificationChannel\Slack\Fixtures;

use Closure;
use Hypervel\Notifications\Notification;
use Hypervel\Notifications\Slack\SlackMessage;

class SlackChannelTestNotification extends Notification
{
    /**
     * The callback that builds the Slack message.
     */
    private Closure $callback;

    /**
     * Create a notification with the given message callback.
     */
    public function __construct(?Closure $callback = null)
    {
        $this->callback = $callback ?? function (): void {
        };
    }

    /**
     * Get the Slack representation of the notification.
     */
    public function toSlack(mixed $notifiable): SlackMessage
    {
        return tap(new SlackMessage, $this->callback);
    }
}
