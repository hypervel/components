<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications;

use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Bus\Dispatcher as BusDispatcherContract;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Notifications\AnonymousNotifiable;
use Hypervel\Notifications\ChannelManager;
use Hypervel\Notifications\Events\NotificationFailed;
use Hypervel\Notifications\Events\NotificationSending;
use Hypervel\Notifications\Events\NotificationSent;
use Hypervel\Notifications\Notifiable;
use Hypervel\Notifications\Notification;
use Hypervel\Notifications\NotificationSender;
use Hypervel\Queue\Attributes\Delay;
use Hypervel\Queue\Attributes\Queue;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Contracts\HttpClient\ResponseInterface;

class NotificationSenderTest extends TestCase
{
    public function testItCanSendNotificationsWithAStringVia(): void
    {
        $notifiable = m::mock(Notifiable::class);
        $manager = m::mock(ChannelManager::class);
        $manager->shouldReceive('driver')
            ->once()
            ->andReturnSelf();
        $manager->shouldReceive('send')
            ->once();
        $bus = m::mock(BusDispatcherContract::class);
        $bus->shouldNotReceive('dispatch');
        $events = $this->mockEventDispatcher();
        $events->shouldReceive('until')->with(m::type(NotificationSending::class))->andReturn(true);
        $events->shouldReceive('dispatch')->once()->with(m::type(NotificationSent::class));

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, new DummyNotificationWithStringVia);
    }

    public function testItCanSendQueuedNotificationsWithAStringVia(): void
    {
        $notifiable = m::mock(Notifiable::class);
        $manager = m::mock(ChannelManager::class);
        $manager->shouldReceive('getContainer')->andReturn(app());
        $manager->shouldReceive('resolveQueueFromQueueRoute')->andReturn(null);
        $manager->shouldReceive('resolveConnectionFromQueueRoute')->andReturn(null);
        $bus = m::mock(BusDispatcherContract::class);
        $bus->shouldReceive('dispatch');
        $events = m::mock(Dispatcher::class);

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, new DummyQueuedNotificationWithStringVia);
    }

    public function testItCanSendQueuedNotificationsWithAnArrayVia(): void
    {
        $notifiable = m::mock(Notifiable::class);
        $manager = m::mock(ChannelManager::class);
        $manager->shouldReceive('getContainer')->andReturn(app());
        $bus = m::mock(BusDispatcherContract::class);
        $bus->shouldReceive('dispatch')
            ->once()
            ->withArgs(function ($job) {
                return $job->queue === 'dummy' && $job->channels === ['database'] && $job->connection === 'redis';
            });
        $bus->shouldReceive('dispatch')
            ->once()
            ->withArgs(function ($job) {
                return $job->queue === 'dummy' && $job->channels === ['mail'] && $job->connection === 'redis';
            });

        $events = m::mock(Dispatcher::class);

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, new DummyQueuedNotificationWithArrayVia);
    }

    public function testItCanSendNotificationsWithAnEmptyStringVia(): void
    {
        $notifiable = new AnonymousNotifiable;
        $manager = m::mock(ChannelManager::class);
        $bus = m::mock(BusDispatcherContract::class);
        $bus->shouldNotReceive('dispatch');
        $events = m::mock(Dispatcher::class);
        $events->shouldNotReceive('dispatch');

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->sendNow($notifiable, new DummyNotificationWithEmptyStringVia);
    }

    public function testItCannotSendNotificationsViaDatabaseForAnonymousNotifiables(): void
    {
        $notifiable = new AnonymousNotifiable;
        $manager = m::mock(ChannelManager::class);
        $bus = m::mock(BusDispatcherContract::class);
        $bus->shouldNotReceive('dispatch');
        $events = m::mock(Dispatcher::class);
        $events->shouldNotReceive('dispatch');

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->sendNow($notifiable, new DummyNotificationWithDatabaseVia);
    }

    public function testItCanSendQueuedNotificationsThroughMiddleware(): void
    {
        $notifiable = m::mock(Notifiable::class);
        $manager = m::mock(ChannelManager::class);
        $manager->shouldReceive('getContainer')->andReturn(app());
        $manager->shouldReceive('resolveQueueFromQueueRoute')->andReturn(null);
        $manager->shouldReceive('resolveConnectionFromQueueRoute')->andReturn(null);
        $bus = m::mock(BusDispatcherContract::class);
        $bus->shouldReceive('dispatch')
            ->withArgs(function ($job) {
                return $job->middleware[0] instanceof TestNotificationMiddleware;
            });
        $events = m::mock(Dispatcher::class);

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, new DummyNotificationWithMiddleware);
    }

    public function testItCanSendQueuedMultiChannelNotificationsThroughDifferentMiddleware(): void
    {
        $notifiable = m::mock(Notifiable::class);
        $manager = m::mock(ChannelManager::class);
        $manager->shouldReceive('getContainer')->andReturn(app());
        $manager->shouldReceive('resolveQueueFromQueueRoute')->andReturn(null);
        $manager->shouldReceive('resolveConnectionFromQueueRoute')->andReturn(null);
        $bus = m::mock(BusDispatcherContract::class);
        $bus->shouldReceive('dispatch')
            ->once()
            ->withArgs(function ($job) {
                return $job->middleware[0] instanceof TestMailNotificationMiddleware;
            });
        $bus->shouldReceive('dispatch')
            ->once()
            ->withArgs(function ($job) {
                return $job->middleware[0] instanceof TestDatabaseNotificationMiddleware;
            });
        $bus->shouldReceive('dispatch')
            ->once()
            ->withArgs(function ($job) {
                return empty($job->middleware);
            });
        $events = m::mock(Dispatcher::class);

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, new DummyMultiChannelNotificationWithConditionalMiddleware);
    }

    public function testItCanSendQueuedWithViaConnectionsNotifications(): void
    {
        $notifiable = new AnonymousNotifiable;
        $manager = m::mock(ChannelManager::class);
        $manager->shouldReceive('getContainer')->andReturn(app());
        $bus = m::mock(BusDispatcherContract::class);
        $bus->shouldReceive('dispatch')
            ->once()
            ->withArgs(function ($job) {
                return $job->connection === 'sync' && $job->channels === ['database'] && $job->queue === 'dummy';
            });
        $bus->shouldReceive('dispatch')
            ->once()
            ->withArgs(function ($job) {
                return $job->connection === 'redis' && $job->channels === ['mail'] && $job->queue === 'dummy';
            });

        $events = m::mock(Dispatcher::class);

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, new DummyNotificationWithViaConnections);
    }

    public function testItCanSendQueuedWithViaQueuesNotifications(): void
    {
        $notifiable = new AnonymousNotifiable;
        $manager = m::mock(ChannelManager::class);
        $manager->shouldReceive('getContainer')->andReturn(app());
        $bus = m::mock(BusDispatcherContract::class);
        $bus->shouldReceive('dispatch')
            ->once()
            ->withArgs(function ($job) {
                return $job->queue === 'dummy' && $job->channels === ['database'] && $job->connection === 'redis';
            });
        $bus->shouldReceive('dispatch')
            ->once()
            ->withArgs(function ($job) {
                return $job->queue === 'admin_notifications' && $job->channels === ['mail'] && $job->connection === 'redis';
            });

        $events = m::mock(Dispatcher::class);

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, new DummyNotificationWithViaQueues);
    }

    public function testItCanSendQueuedNotificationsWithQueueRoute(): void
    {
        $notifiable = new AnonymousNotifiable;
        $manager = m::mock(ChannelManager::class);
        $manager->shouldReceive('getContainer')->andReturn(app());
        $manager->shouldReceive('resolveQueueFromQueueRoute')->andReturn('notification-queue');
        $manager->shouldReceive('resolveConnectionFromQueueRoute')->andReturn('notification-connection');

        $bus = m::mock(BusDispatcherContract::class);
        $bus->shouldReceive('dispatch')
            ->once()
            ->withArgs(function ($job) {
                return $job->queue === 'notification-queue' && $job->channels === ['mail'] && $job->connection === 'notification-connection';
            });

        $events = m::mock(Dispatcher::class);

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, new DummyQueuedNotificationWithStringVia);
    }

    public function testItCanSendQueuedNotificationsWithDelayAttribute(): void
    {
        $notification = new #[Delay(30)] class extends Notification implements ShouldQueue {
            use Queueable;

            public function via(mixed $notifiable): string
            {
                return 'mail';
            }
        };

        $notifiable = m::mock(Notifiable::class);
        $manager = m::mock(ChannelManager::class);
        $manager->shouldReceive('getContainer')->andReturn(app());
        $manager->shouldReceive('resolveQueueFromQueueRoute')->andReturn(null);
        $manager->shouldReceive('resolveConnectionFromQueueRoute')->andReturn(null);

        $bus = m::mock(BusDispatcherContract::class);
        $bus->shouldReceive('dispatch')
            ->once()
            ->withArgs(function ($job) {
                return $job->delay === 30;
            });

        $events = m::mock(Dispatcher::class);

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, $notification);
    }

    public function testQueuedNotificationWithDelayOverridesDelayAttribute(): void
    {
        $notification = new #[Delay(30)] class extends Notification implements ShouldQueue {
            use Queueable;

            public function via(mixed $notifiable): string
            {
                return 'mail';
            }

            public function withDelay(mixed $notifiable, string $channel): int
            {
                return 60;
            }
        };

        $notifiable = m::mock(Notifiable::class);
        $manager = m::mock(ChannelManager::class);
        $manager->shouldReceive('getContainer')->andReturn(app());
        $manager->shouldReceive('resolveQueueFromQueueRoute')->andReturn(null);
        $manager->shouldReceive('resolveConnectionFromQueueRoute')->andReturn(null);

        $bus = m::mock(BusDispatcherContract::class);
        $bus->shouldReceive('dispatch')
            ->once()
            ->withArgs(function ($job) {
                return $job->delay === 60;
            });

        $events = m::mock(Dispatcher::class);

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, $notification);
    }

    public function testItCanSendQueuedNotificationsWithDelayProperty(): void
    {
        $notifiable = m::mock(Notifiable::class);
        $manager = m::mock(ChannelManager::class);
        $manager->shouldReceive('getContainer')->andReturn(app());
        $manager->shouldReceive('resolveQueueFromQueueRoute')->andReturn(null);
        $manager->shouldReceive('resolveConnectionFromQueueRoute')->andReturn(null);

        $bus = m::mock(BusDispatcherContract::class);
        $bus->shouldReceive('dispatch')
            ->once()
            ->withArgs(function ($job) {
                return $job->delay === 45;
            });

        $events = m::mock(Dispatcher::class);

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, (new DummyQueuedNotificationWithStringVia)->delay(45));
    }

    public function testNotificationFailedSentWithoutHttpTransportException(): void
    {
        $this->expectException(TransportException::class);

        $notifiable = new AnonymousNotifiable;
        $manager = m::mock(ChannelManager::class);
        $manager->shouldReceive('driver')->andReturn($driver = m::mock());
        $response = m::mock(ResponseInterface::class);
        $driver->shouldReceive('send')->andThrow(new HttpTransportException('Transport error', $response));
        $bus = m::mock(BusDispatcherContract::class);

        $events = $this->mockEventDispatcher();
        $events->shouldReceive('until')->with(m::type(NotificationSending::class))->andReturn(true);
        $events->shouldReceive('dispatch')->once()->withArgs(function ($event) {
            return $event instanceof NotificationFailed && $event->data['exception'] instanceof TransportException;
        });

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->sendNow($notifiable, new DummyNotificationWithViaConnections, ['mail']);
    }

    public function testItPreservesNotificationStateMutatedInViaMethod(): void
    {
        $notifiable = new AnonymousNotifiable;
        $manager = m::mock(ChannelManager::class);
        $manager->shouldReceive('driver')->andReturn($driver = m::mock());
        $driver->shouldReceive('send')->once()->withArgs(function ($notifiable, $notification) {
            return $notification->channelData === 'default';
        });
        $bus = m::mock(BusDispatcherContract::class);

        $events = $this->mockEventDispatcher();
        $events->shouldReceive('until')->with(m::type(NotificationSending::class))->andReturn(true);
        $events->shouldReceive('dispatch')->once()->with(m::type(NotificationSent::class));

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->sendNow($notifiable, new DummyNotificationWithViaMutation);
    }

    public function testOnQueueOverridesQueueAttribute(): void
    {
        $notification = new #[Queue('attribute-queue')] class extends Notification implements ShouldQueue {
            use Queueable;

            public function via(mixed $notifiable): string
            {
                return 'mail';
            }
        };

        $notification->onQueue('manual-queue');

        $notifiable = m::mock(Notifiable::class);
        $manager = m::mock(ChannelManager::class);
        $manager->shouldReceive('getContainer')->andReturn(app());
        $manager->shouldReceive('resolveQueueFromQueueRoute')->andReturn(null);
        $manager->shouldReceive('resolveConnectionFromQueueRoute')->andReturn(null);

        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('listen');

        $bus = m::mock(BusDispatcherContract::class);
        $bus->shouldReceive('dispatch')
            ->once()
            ->withArgs(fn ($job) => $job->queue === 'manual-queue');

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, $notification);
    }

    public function testQueueAttributeIsUsedWhenOnQueueIsNotCalled(): void
    {
        $notification = new #[Queue('attribute-queue')] class extends Notification implements ShouldQueue {
            use Queueable;

            public function via(mixed $notifiable): string
            {
                return 'mail';
            }
        };

        $notifiable = m::mock(Notifiable::class);
        $manager = m::mock(ChannelManager::class);
        $manager->shouldReceive('getContainer')->andReturn(app());
        $manager->shouldReceive('resolveQueueFromQueueRoute')->andReturn(null);
        $manager->shouldReceive('resolveConnectionFromQueueRoute')->andReturn(null);

        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('listen');

        $bus = m::mock(BusDispatcherContract::class);
        $bus->shouldReceive('dispatch')
            ->once()
            ->withArgs(fn ($job) => $job->queue === 'attribute-queue');

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, $notification);
    }

    public function testConstructorQueueOverrideTakesPrecedenceOverQueueAttribute(): void
    {
        $notification = new #[Queue('attribute-queue')] class extends Notification implements ShouldQueue {
            use Queueable;

            public function __construct()
            {
                $this->queue = 'constructor-override-queue';
            }

            public function via(mixed $notifiable): string
            {
                return 'mail';
            }
        };

        $notifiable = m::mock(Notifiable::class);
        $manager = m::mock(ChannelManager::class);
        $manager->shouldReceive('getContainer')->andReturn(app());
        $manager->shouldReceive('resolveQueueFromQueueRoute')->andReturn(null);
        $manager->shouldReceive('resolveConnectionFromQueueRoute')->andReturn(null);

        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('listen');

        $bus = m::mock(BusDispatcherContract::class);
        $bus->shouldReceive('dispatch')
            ->once()
            ->withArgs(fn ($job) => $job->queue === 'constructor-override-queue');

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, $notification);
    }

    public function testNotificationEventsAreSkippedWhenNoListenersAreRegistered(): void
    {
        $notifiable = m::mock(Notifiable::class);
        $manager = m::mock(ChannelManager::class);
        $manager->shouldReceive('driver')
            ->once()
            ->andReturnSelf();
        $manager->shouldReceive('send')
            ->once();
        $bus = m::mock(BusDispatcherContract::class);
        $bus->shouldNotReceive('dispatch');
        $events = $this->mockEventDispatcher();
        $events->shouldReceive('hasListeners')->with(NotificationSending::class)->andReturn(false);
        $events->shouldReceive('hasListeners')->with(NotificationSent::class)->andReturn(false);
        $events->shouldNotReceive('until');
        $events->shouldNotReceive('dispatch');

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, new DummyNotificationWithStringVia);
    }

    public function testNotificationFailedStillNormalizesTransportExceptionWithoutListeners(): void
    {
        $this->expectException(TransportException::class);

        $notifiable = new AnonymousNotifiable;
        $manager = m::mock(ChannelManager::class);
        $manager->shouldReceive('driver')->andReturn($driver = m::mock());
        $response = m::mock(ResponseInterface::class);
        $driver->shouldReceive('send')->andThrow(new HttpTransportException('Transport error', $response));
        $bus = m::mock(BusDispatcherContract::class);

        $events = $this->mockEventDispatcher();
        $events->shouldReceive('hasListeners')->with(NotificationSending::class)->andReturn(false);
        $events->shouldReceive('hasListeners')->with(NotificationFailed::class)->andReturn(false);
        $events->shouldNotReceive('until');
        $events->shouldNotReceive('dispatch');

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->sendNow($notifiable, new DummyNotificationWithViaConnections, ['mail']);
    }

    private function mockEventDispatcher(): Dispatcher
    {
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->byDefault()->andReturn(true);

        return $events;
    }
}

class DummyQueuedNotificationWithStringVia extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Get the notification channels.
     * @param mixed $notifiable
     */
    public function via($notifiable)
    {
        return 'mail';
    }
}

class DummyQueuedNotificationWithArrayVia extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->connection = 'redis';
        $this->queue = 'dummy';
    }

    /**
     * Get the notification channels.
     * @param mixed $notifiable
     */
    public function via($notifiable)
    {
        return ['mail', 'database'];
    }
}

class DummyNotificationWithStringVia extends Notification
{
    use Queueable;

    /**
     * Get the notification channels.
     *
     * @param mixed $notifiable
     * @return array|string
     */
    public function via($notifiable)
    {
        return 'mail';
    }
}

class DummyNotificationWithEmptyStringVia extends Notification
{
    use Queueable;

    /**
     * Get the notification channels.
     *
     * @param mixed $notifiable
     * @return array|string
     */
    public function via($notifiable)
    {
        return '';
    }
}

class DummyNotificationWithDatabaseVia extends Notification
{
    use Queueable;

    /**
     * Get the notification channels.
     *
     * @param mixed $notifiable
     * @return array|string
     */
    public function via($notifiable)
    {
        return 'database';
    }
}

class DummyNotificationWithViaConnections extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->connection = 'redis';
        $this->queue = 'dummy';
    }

    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    public function viaConnections()
    {
        return [
            'database' => 'sync',
        ];
    }
}

class DummyNotificationWithViaQueues extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
    {
        $this->connection = 'redis';
        $this->queue = 'dummy';
    }

    public function via($notifiable)
    {
        return ['mail', 'database'];
    }

    public function viaQueues()
    {
        return [
            'mail' => 'admin_notifications',
        ];
    }
}

class DummyNotificationWithMiddleware extends Notification implements ShouldQueue
{
    use Queueable;

    public function via($notifiable)
    {
        return 'mail';
    }

    public function middleware()
    {
        return [
            new TestNotificationMiddleware,
        ];
    }
}

class DummyMultiChannelNotificationWithConditionalMiddleware extends Notification implements ShouldQueue
{
    use Queueable;

    public function via($notifiable)
    {
        return [
            'mail',
            'database',
            'broadcast',
        ];
    }

    public function middleware($notifiable, $channel)
    {
        return match ($channel) {
            'mail' => [new TestMailNotificationMiddleware],
            'database' => [new TestDatabaseNotificationMiddleware],
            default => []
        };
    }
}

class TestNotificationMiddleware
{
    public function handle($command, $next)
    {
        return $next($command);
    }
}

class TestMailNotificationMiddleware
{
    public function handle($command, $next)
    {
        return $next($command);
    }
}

class TestDatabaseNotificationMiddleware
{
    public function handle($command, $next)
    {
        return $next($command);
    }
}

class DummyNotificationWithViaMutation extends Notification
{
    public ?string $channelData = null;

    public function via($notifiable)
    {
        $this->channelData = $notifiable->routeConfig ?? 'default';

        return 'mail';
    }
}
