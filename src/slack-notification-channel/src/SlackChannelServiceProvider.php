<?php

declare(strict_types=1);

namespace Hypervel\Notifications;

use GuzzleHttp\Client as HttpClient;
use Hypervel\Contracts\Container\Container;
use Hypervel\Http\Client\Factory;
use Hypervel\Notifications\Channels\SlackWebhookChannel;
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

        // Guzzle has no request timeout by default, so a stalled webhook host would hold the send indefinitely.
        $this->app->when(SlackWebhookChannel::class)
            ->needs(HttpClient::class)
            ->give(fn (): HttpClient => new HttpClient(['connect_timeout' => 10, 'timeout' => 30]));
    }

    /**
     * Bootstrap the Slack HTTP connection.
     */
    public function boot(Factory $http): void
    {
        $http->registerConnection(SlackChannel::CONNECTION);
    }
}
