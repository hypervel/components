<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use Hypervel\Container\Container;
use Hypervel\Notifications\Channels\SlackNotificationRouterChannel;
use Hypervel\Notifications\Channels\SlackWebApiChannel;
use Hypervel\Notifications\Channels\SlackWebhookChannel;
use Hypervel\Notifications\Notification;
use Hypervel\Notifications\Slack\SlackRoute;
use Hypervel\Tests\Notifications\Slack\Fixtures\SlackChannelTestNotification;
use Hypervel\Tests\TestCase;
use Mockery as m;

class SlackNotificationRouterChannelTest extends TestCase
{
    public function testItRoutesTheNotificationToTheWebhookChannelWhenTheNotifiableRouteIsAStringUrl(): void
    {
        $app = new Container;
        $webhook = m::mock(SlackWebhookChannel::class);
        $webApi = m::mock(SlackWebApiChannel::class);
        $webhook->shouldReceive('send')->once()->withArgs(function ($notifiable, $notification) {
            return $notifiable->routeNotificationFor('slack', $notification) === 'http://example.com';
        })->andReturn(new Response);
        $webApi->shouldNotReceive('send');
        $app->instance(SlackWebhookChannel::class, $webhook);
        $app->instance(SlackWebApiChannel::class, $webApi);

        $channel = new SlackNotificationRouterChannel($app);

        $channel->send(new SlackNotificationRouterTestNotifiable('http://example.com'), new SlackChannelTestNotification);
    }

    public function testItRoutesTheNotificationToTheWebhookChannelWhenTheNotifiableRouteIsAPsrUrlInstance(): void
    {
        $app = new Container;
        $webhook = m::mock(SlackWebhookChannel::class);
        $webApi = m::mock(SlackWebApiChannel::class);
        $webhook->shouldReceive('send')->once()->withArgs(function ($notifiable, $notification) {
            return $notifiable->routeNotificationFor('slack', $notification) instanceof Uri;
        })->andReturn(new Response);
        $webApi->shouldNotReceive('send');
        $app->instance(SlackWebhookChannel::class, $webhook);
        $app->instance(SlackWebApiChannel::class, $webApi);

        $channel = new SlackNotificationRouterChannel($app);

        $channel->send(new SlackNotificationRouterTestNotifiable(new Uri('foo')), new SlackChannelTestNotification);
    }

    public function testItRoutesTheNotificationToTheWebApiChannelWhenTheNotifiableRouteIsNotAnUrl(): void
    {
        $app = new Container;
        $webhook = m::mock(SlackWebhookChannel::class);
        $webApi = m::mock(SlackWebApiChannel::class);
        $webhook->shouldNotReceive('send');
        $webApi->shouldReceive('send')->once()->withArgs(function ($notifiable, $notification) {
            return $notifiable->routeNotificationFor('slack', $notification) instanceof SlackRoute;
        })->andReturn(new Response);
        $app->instance(SlackWebhookChannel::class, $webhook);
        $app->instance(SlackWebApiChannel::class, $webApi);

        $channel = new SlackNotificationRouterChannel($app);

        $channel->send(
            new SlackNotificationRouterTestNotifiable(SlackRoute::make('#general')),
            new SlackChannelTestNotification,
        );
    }

    public function testItStopsSendingWhenTheNotifiableRouteIsFalse(): void
    {
        $app = new Container;
        $webhook = m::mock(SlackWebhookChannel::class);
        $webApi = m::mock(SlackWebApiChannel::class);
        $webhook->shouldNotReceive('send');
        $webApi->shouldNotReceive('send');
        $app->instance(SlackWebhookChannel::class, $webhook);
        $app->instance(SlackWebApiChannel::class, $webApi);

        $channel = new SlackNotificationRouterChannel($app);

        $this->assertNull($channel->send(
            new SlackNotificationRouterTestNotifiable(false),
            new SlackChannelTestNotification,
        ));
    }
}

class SlackNotificationRouterTestNotifiable
{
    public function __construct(
        private readonly mixed $route,
    ) {
    }

    public function routeNotificationFor(string $driver, ?Notification $notification = null): mixed
    {
        return $this->route;
    }
}
