<?php

declare(strict_types=1);

namespace Hypervel\Notifications\Channels;

use Hypervel\Notifications\Notification;
use Hypervel\Support\Str;
use Hypervel\Contracts\Container\Container;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

class SlackNotificationRouterChannel
{
    /**
     * Create a new Slack notification router channel.
     */
    public function __construct(
        protected Container $container
    ) {
    }

    /**
     * Send the given notification.
     */
    public function send(mixed $notifiable, Notification $notification): ?ResponseInterface
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
            return $this->container->get(SlackWebhookChannel::class);
        }

        if (is_string($route) && Str::startsWith($route, ['http://', 'https://'])) {
            return $this->container->get(SlackWebhookChannel::class);
        }

        return $this->container->get(SlackWebApiChannel::class);
    }
}
