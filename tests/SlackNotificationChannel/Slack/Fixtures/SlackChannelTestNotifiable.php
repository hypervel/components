<?php

declare(strict_types=1);

namespace Hypervel\Tests\SlackNotificationChannel\Slack\Fixtures;

use Hypervel\Notifications\Notifiable;
use Hypervel\Notifications\Slack\SlackRoute;
use Psr\Http\Message\UriInterface;

class SlackChannelTestNotifiable
{
    use Notifiable;

    /**
     * Create a notifiable with the given Slack route.
     */
    public function __construct(
        protected SlackRoute|UriInterface|string|false|null $route = null
    ) {
    }

    /**
     * Get the notification routing information for the Slack channel.
     */
    public function routeNotificationForSlack(): SlackRoute|UriInterface|string|false|null
    {
        return $this->route;
    }
}
