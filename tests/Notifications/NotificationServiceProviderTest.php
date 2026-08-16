<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications;

use Hypervel\Notifications\AnonymousNotifiable;
use Hypervel\Notifications\ChannelManager;
use Hypervel\Notifications\Channels\MailChannel;
use Hypervel\Notifications\Notification;
use Hypervel\Notifications\NotificationServiceProvider;
use Hypervel\Support\Facades\Notification as NotificationFacade;
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

    public function testReloadConfigurationPreservesNotificationFakeAndItsRecordedState(): void
    {
        $fake = NotificationFacade::fake();
        $notification = new class extends Notification {
            public function via(mixed $notifiable): array
            {
                return ['mail'];
            }
        };
        $fake->sendNow(new AnonymousNotifiable, $notification);
        $recordedNotifications = $fake->sentNotifications();

        $this->app->getProvider(NotificationServiceProvider::class)->reloadConfiguration();

        $this->assertSame($fake, NotificationFacade::getFacadeRoot());
        $this->assertSame($recordedNotifications, $fake->sentNotifications());
    }
}
