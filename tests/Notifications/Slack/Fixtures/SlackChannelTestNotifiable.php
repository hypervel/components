<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications\Slack\Fixtures;

use Hypervel\Notifications\Notifiable;
use Hypervel\Notifications\Slack\SlackRoute;

class SlackChannelTestNotifiable
{
    use Notifiable;

    protected SlackRoute|string|null $route;

    public function __construct(SlackRoute|string|null $route = null)
    {
        $this->route = $route;
    }

    public function routeNotificationForSlack(): SlackRoute|string|null
    {
        return $this->route;
    }
}
