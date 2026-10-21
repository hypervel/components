<?php

declare(strict_types=1);

namespace Hypervel\Notifications;

use Hypervel\Contracts\Container\Container;
use Hypervel\Http\Client\Response;
use Hypervel\Notifications\Channels\SlackWebhookChannel;
use Hypervel\Notifications\Slack\SlackChannel as SlackWebApiChannel;
use Hypervel\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

class SlackNotificationRouterChannel
{
    /**
     * Create a new Slack notification router channel.
     */
    public function __construct(
        protected Container $app
    ) {
    }

    /**
     * Send the given notification.
     */
    public function send(mixed $notifiable, Notification $notification): Response|ResponseInterface|null
    {
        $route = $notifiable->routeNotificationFor('slack', $notification);

        if ($route === false) {
            return null;
        }

        return $this->determineChannel($route)->send($notifiable, $notification);
    }

    /**
     * Determine which channel the Slack notification should be routed to.
     */
    protected function determineChannel(mixed $route): SlackWebApiChannel|SlackWebhookChannel
    {
        if ($route instanceof UriInterface) {
            return $this->app->make(SlackWebhookChannel::class);
        }

        if (is_string($route) && Str::startsWith($route, ['http://', 'https://'])) {
            return $this->app->make(SlackWebhookChannel::class);
        }

        return $this->app->make(SlackWebApiChannel::class);
    }
}
