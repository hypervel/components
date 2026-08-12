<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications;

use Hypervel\Notifications\ChannelManager;
use Hypervel\Notifications\Channels\MailChannel;
use Hypervel\Notifications\NotificationServiceProvider;
use Hypervel\Testbench\TestCase;

class NotificationServiceProviderTest extends TestCase
{
    public function testReloadConfigurationRebuildsResolvedChannels(): void
    {
        $manager = $this->app->make(ChannelManager::class);
        $channel = $manager->channel('mail');

        $this->app->getProvider(NotificationServiceProvider::class)->reloadConfiguration();

        $refreshedChannel = $manager->channel('mail');
        $this->assertSame($manager, $this->app->make(ChannelManager::class));
        $this->assertNotSame($channel, $refreshedChannel);
        $this->assertSame($refreshedChannel, $this->app->make(MailChannel::class));
    }
}
