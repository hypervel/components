<?php

declare(strict_types=1);

namespace Hypervel\Notifications;

use Hypervel\Contracts\Container\Container;
use Hypervel\Http\Client\Factory;
use Hypervel\Notifications\Slack\SlackChannel;
use Hypervel\Support\Facades\Notification;
use Hypervel\Support\ServiceProvider;

class SlackChannelServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     */
    public function register(): void
    {
        Notification::resolved(function (ChannelManager $service): void {
            $service->extend('slack', function (Container $app): SlackNotificationRouterChannel {
                return $app->make(SlackNotificationRouterChannel::class);
            });
        });
    }

    /**
     * Bootstrap the Slack HTTP connection.
     */
    public function boot(Factory $http): void
    {
        $http->registerConnection(SlackChannel::CONNECTION);
    }
}
