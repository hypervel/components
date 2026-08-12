<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications\Slack\Fixtures;

use Closure;
use Hypervel\Notifications\Notification;
use Hypervel\Notifications\Slack\SlackMessage;

class SlackChannelTestNotification extends Notification
{
    private Closure $callback;

    public function __construct(?Closure $callback = null)
    {
        $this->callback = $callback ?? function () {
        };
    }

    public function toSlack(mixed $notifiable): SlackMessage
    {
        return tap(new SlackMessage, $this->callback);
    }
}
