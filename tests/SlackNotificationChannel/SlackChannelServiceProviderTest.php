<?php

declare(strict_types=1);

namespace Hypervel\Tests\SlackNotificationChannel;

use GuzzleHttp\Client as HttpClient;
use Hypervel\Config\Repository;
use Hypervel\Foundation\Application;
use Hypervel\Http\Client\Factory;
use Hypervel\Notifications\ChannelManager;
use Hypervel\Notifications\Channels\SlackWebhookChannel;
use Hypervel\Notifications\NotificationServiceProvider;
use Hypervel\Notifications\Slack\SlackChannel;
use Hypervel\Notifications\SlackChannelServiceProvider;
use Hypervel\Notifications\SlackNotificationRouterChannel;
use Hypervel\Support\Facades\Facade;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class SlackChannelServiceProviderTest extends TestCase
{
    #[DataProvider('managerResolution')]
    public function testItRegistersSlackBeforeOrAfterTheManagerIsResolved(bool $resolveFirst): void
    {
        $app = new Application;
        $app->instance('config', new Repository([]));
        Facade::setFacadeApplication($app);
        (new NotificationServiceProvider($app))->register();

        if ($resolveFirst) {
            $app->make(ChannelManager::class);
        }

        (new SlackChannelServiceProvider($app))->register();

        $this->assertSame(
            $app->make(SlackNotificationRouterChannel::class),
            $app->make(ChannelManager::class)->channel('slack'),
        );
    }

    /**
     * Provide notification manager resolution order.
     */
    public static function managerResolution(): array
    {
        return [[false], [true]];
    }

    public function testTheWebhookChannelUsesBoundedHttpTimeouts(): void
    {
        $app = new Application;
        Facade::setFacadeApplication($app);
        (new SlackChannelServiceProvider($app))->register();

        $client = (fn (): HttpClient => $this->http)->call($app->make(SlackWebhookChannel::class));

        $this->assertSame(10, $client->getConfig('connect_timeout'));
        $this->assertSame(30, $client->getConfig('timeout'));
    }

    public function testApplicationsCanReplaceTheWebhookHttpClient(): void
    {
        $app = new Application;
        Facade::setFacadeApplication($app);
        (new SlackChannelServiceProvider($app))->register();

        $client = new HttpClient;
        $app->when(SlackWebhookChannel::class)
            ->needs(HttpClient::class)
            ->give(fn (): HttpClient => $client);

        $this->assertSame(
            $client,
            (fn (): HttpClient => $this->http)->call($app->make(SlackWebhookChannel::class)),
        );
    }

    public function testItRegistersTheSlackHttpConnection(): void
    {
        $http = new Factory;
        $provider = new SlackChannelServiceProvider(new Application);

        $provider->boot($http);

        $this->assertTrue($http->hasConnection(SlackChannel::CONNECTION));
        $this->assertSame([], $http->getConnectionOptions(SlackChannel::CONNECTION));
    }
}
