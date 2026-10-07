<?php

declare(strict_types=1);

namespace Hypervel\Tests\SlackNotificationChannel;

use Hypervel\Config\Repository;
use Hypervel\Foundation\Application;
use Hypervel\Http\Client\Factory;
use Hypervel\Notifications\ChannelManager;
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

    public function testItRegistersTheSlackHttpConnection(): void
    {
        $http = new Factory;
        $provider = new SlackChannelServiceProvider(new Application);

        $provider->boot($http);

        $this->assertTrue($http->hasConnection(SlackChannel::CONNECTION));
        $this->assertSame([], $http->getConnectionOptions(SlackChannel::CONNECTION));
    }
}
