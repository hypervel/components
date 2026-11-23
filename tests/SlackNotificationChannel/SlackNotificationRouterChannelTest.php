<?php

declare(strict_types=1);

namespace Hypervel\Tests\SlackNotificationChannel;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Uri;
use Hypervel\Container\Container;
use Hypervel\Http\Client\Response as HttpResponse;
use Hypervel\Notifications\Channels\SlackWebhookChannel;
use Hypervel\Notifications\Notification;
use Hypervel\Notifications\Slack\SlackChannel as SlackWebApiChannel;
use Hypervel\Notifications\SlackNotificationRouterChannel;
use Hypervel\Tests\SlackNotificationChannel\Slack\Fixtures\SlackChannelTestNotifiable;
use Hypervel\Tests\SlackNotificationChannel\Slack\Fixtures\SlackChannelTestNotification;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;

class SlackNotificationRouterChannelTest extends TestCase
{
    use VerifiesDoubles;

    public function testItRoutesTheNotificationToTheWebhookChannelWhenTheNotifiableRouteIsAStringUrl(): void
    {
        $app = new Container;
        $webhook = Double::for(SlackWebhookChannel::class)->strict();
        $webApi = Double::for(SlackWebApiChannel::class)->strict();
        $webhook->expects('send')->with(Argument::all(function (SlackChannelTestNotifiable $notifiable, Notification $notification): bool {
            return $notifiable->routeNotificationFor('slack', $notification) === 'http://example.com';
        }))->returns(new Response);
        $webApi->expects('send')->never();
        $app->instance(SlackWebhookChannel::class, $webhook);
        $app->instance(SlackWebApiChannel::class, $webApi);

        $channel = new SlackNotificationRouterChannel($app);

        $channel->send(new SlackChannelTestNotifiable('http://example.com'), new SlackChannelTestNotification);
    }

    public function testItRoutesTheNotificationToTheWebhookChannelWhenTheNotifiableRouteIsAPsrUrlInstance(): void
    {
        $app = new Container;
        $webhook = Double::for(SlackWebhookChannel::class)->strict();
        $webApi = Double::for(SlackWebApiChannel::class)->strict();
        $webhook->expects('send')->with(Argument::all(function (SlackChannelTestNotifiable $notifiable, Notification $notification): bool {
            return $notifiable->routeNotificationFor('slack', $notification) instanceof Uri;
        }))->returns(new Response);
        $webApi->expects('send')->never();
        $app->instance(SlackWebhookChannel::class, $webhook);
        $app->instance(SlackWebApiChannel::class, $webApi);

        $channel = new SlackNotificationRouterChannel($app);

        $channel->send(new SlackChannelTestNotifiable(new Uri('foo')), new SlackChannelTestNotification);
    }

    public function testItRoutesTheNotificationToTheWebApiChannelWhenTheNotifiableRouteIsNotAnUrl(): void
    {
        $app = new Container;
        $webhook = Double::for(SlackWebhookChannel::class)->strict();
        $webApi = Double::for(SlackWebApiChannel::class)->strict();
        $webhook->expects('send')->never();
        $webApi->expects('send')->with(Argument::all(function (SlackChannelTestNotifiable $notifiable, Notification $notification): bool {
            return $notifiable->routeNotificationFor('slack', $notification) === '#general';
        }))->returns($response = new HttpResponse(new Response));
        $app->instance(SlackWebhookChannel::class, $webhook);
        $app->instance(SlackWebApiChannel::class, $webApi);

        $channel = new SlackNotificationRouterChannel($app);

        $this->assertSame($response, $channel->send(
            new SlackChannelTestNotifiable('#general'),
            new SlackChannelTestNotification,
        ));
    }

    public function testItStopsSendingWhenTheNotifiableRouteIsFalse(): void
    {
        $app = new Container;
        $webhook = Double::for(SlackWebhookChannel::class)->strict();
        $webApi = Double::for(SlackWebApiChannel::class)->strict();
        $webhook->expects('send')->never();
        $webApi->expects('send')->never();
        $app->instance(SlackWebhookChannel::class, $webhook);
        $app->instance(SlackWebApiChannel::class, $webApi);

        $channel = new SlackNotificationRouterChannel($app);

        $this->assertNull($channel->send(
            new SlackChannelTestNotifiable(false),
            new SlackChannelTestNotification,
        ));
    }
}
