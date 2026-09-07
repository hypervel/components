<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications;

use Closure;
use Hypervel\Bus\Queueable;
use Hypervel\Contracts\Bus\Dispatcher as BusDispatcherContract;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Notifications\AnonymousNotifiable;
use Hypervel\Notifications\ChannelManager;
use Hypervel\Notifications\Events\NotificationDelivered;
use Hypervel\Notifications\Events\NotificationFailed;
use Hypervel\Notifications\Events\NotificationSending;
use Hypervel\Notifications\Events\NotificationSent;
use Hypervel\Notifications\Events\NotificationSkipped;
use Hypervel\Notifications\Notifiable;
use Hypervel\Notifications\Notification;
use Hypervel\Notifications\NotificationSender;
use Hypervel\Notifications\SendQueuedNotifications;
use Hypervel\Queue\Attributes\Delay;
use Hypervel\Queue\Attributes\Queue;
use Hypervel\Tests\TestCase;
use Mockery as m;
use RuntimeException;
use stdClass;
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
        $events->shouldReceive('dispatch')->once()->with(m::type(NotificationDelivered::class));
        $events->shouldReceive('dispatch')->once()->with(m::type(NotificationSent::class));

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, new DummyNotificationWithStringVia);
    }

    public function testNotificationLifecycleUsesTheDeliveryBoundaryBeforePostDeliveryCallbacks(): void
    {
        $order = [];
        $response = new stdClass;
        $notifiable = new AnonymousNotifiable;
        $notification = new DummyNotificationWithAfterSendingCallback(
            static function () use (&$order): void {
                $order[] = 'after-sending';
            }
        );
        $manager = m::mock(ChannelManager::class);
        $manager->shouldReceive('driver')->once()->with('mail')->andReturn($driver = m::mock());
        $driver->shouldReceive('send')->once()->andReturnUsing(function () use (&$order, $response): object {
            $order[] = 'channel';

            return $response;
        });
        $events = $this->mockEventDispatcher();
        $events->shouldReceive('until')->once()->with(m::type(NotificationSending::class))->andReturnUsing(
            function () use (&$order): bool {
                $order[] = 'sending';

                return true;
            }
        );
        $events->shouldReceive('dispatch')->twice()->andReturnUsing(
            function (object $event) use (&$order, $notifiable, $response): void {
                $this->assertSame($notifiable, $event->notifiable);
                $this->assertSame('mail', $event->channel);

                if ($event instanceof NotificationDelivered) {
                    $this->assertSame($response, $event->response);
                    $order[] = 'delivered';

                    return;
                }

                $this->assertInstanceOf(NotificationSent::class, $event);
                $order[] = 'sent';
            }
        );

        (new NotificationSender(
            $manager,
            m::mock(BusDispatcherContract::class),
            $events,
        ))->sendNow($notifiable, $notification, ['mail']);

        $this->assertSame(['sending', 'channel', 'delivered', 'after-sending', 'sent'], $order);
    }

    public function testShouldSendCancellationDispatchesSkippedWithoutResolvingTheChannel(): void
    {
        $notifiable = new AnonymousNotifiable;
        $notification = new class extends Notification {
            public function shouldSend(mixed $notifiable, string $channel): bool
            {
                return false;
            }
        };
        $manager = m::mock(ChannelManager::class);
        $manager->shouldNotReceive('driver');
        $events = $this->mockEventDispatcher();
        $events->shouldNotReceive('until');
        $events->shouldReceive('dispatch')->once()->with(m::on(
            fn (object $event): bool => $event instanceof NotificationSkipped
                && $event->notifiable === $notifiable
                && $event->channel === 'mail'
        ));

        (new NotificationSender(
            $manager,
            m::mock(BusDispatcherContract::class),
            $events,
        ))->sendNow($notifiable, $notification, ['mail']);
    }

    public function testSendingVetoDispatchesSkippedWithoutResolvingTheChannel(): void
    {
        $manager = m::mock(ChannelManager::class);
        $manager->shouldNotReceive('driver');
        $events = $this->mockEventDispatcher();
        $events->shouldReceive('until')->once()->with(m::type(NotificationSending::class))->andReturnFalse();
        $events->shouldReceive('dispatch')->once()->with(m::type(NotificationSkipped::class));

        (new NotificationSender(
            $manager,
            m::mock(BusDispatcherContract::class),
            $events,
        ))->sendNow(new AnonymousNotifiable, new Notification, ['mail']);
    }

    public function testThrowingSendingListenerDispatchesFailedAndRethrows(): void
    {
        $exception = new RuntimeException('sending listener failed');
        $manager = m::mock(ChannelManager::class);
        $manager->shouldNotReceive('driver');
        $events = $this->mockEventDispatcher();
        $events->shouldReceive('until')->once()->with(m::type(NotificationSending::class))->andThrow($exception);
        $events->shouldReceive('dispatch')->once()->with(m::on(
            fn (object $event): bool => $event instanceof NotificationFailed
                && $event->data['exception'] === $exception
        ));
        $sender = new NotificationSender($manager, m::mock(BusDispatcherContract::class), $events);

        try {
            $sender->sendNow(new AnonymousNotifiable, new Notification, ['mail']);
            $this->fail('Expected the sending-listener exception to be rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }
    }

    public function testThrowingDeliveredListenerIsNotRelabeledAsDeliveryFailure(): void
    {
        $exception = new RuntimeException('delivered listener failed');
        $manager = m::mock(ChannelManager::class);
        $manager->shouldReceive('driver')->once()->andReturn($driver = m::mock());
        $driver->shouldReceive('send')->once()->andReturn('response');
        $events = $this->mockEventDispatcher();
        $events->shouldReceive('until')->once()->with(m::type(NotificationSending::class))->andReturnTrue();
        $events->shouldReceive('dispatch')->once()->with(m::type(NotificationDelivered::class))->andThrow($exception);
        $events->shouldReceive('dispatch')->never()->with(m::type(NotificationFailed::class));
        $events->shouldReceive('dispatch')->never()->with(m::type(NotificationSent::class));
        $sender = new NotificationSender($manager, m::mock(BusDispatcherContract::class), $events);

        try {
            $sender->sendNow(new AnonymousNotifiable, new Notification, ['mail']);
            $this->fail('Expected the delivered-listener exception to be rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }
    }

    public function testThrowingAfterSendingCallbackIsNotRelabeledAsDeliveryFailure(): void
    {
        $exception = new RuntimeException('after-sending failed');
        $notification = new DummyNotificationWithAfterSendingCallback(static function () use ($exception): never {
            throw $exception;
        });
        $manager = m::mock(ChannelManager::class);
        $manager->shouldReceive('driver')->once()->andReturn($driver = m::mock());
        $driver->shouldReceive('send')->once()->andReturn('response');
        $events = $this->mockEventDispatcher();
        $events->shouldReceive('until')->once()->with(m::type(NotificationSending::class))->andReturnTrue();
        $events->shouldReceive('dispatch')->once()->with(m::type(NotificationDelivered::class));
        $events->shouldReceive('dispatch')->never()->with(m::type(NotificationFailed::class));
        $events->shouldReceive('dispatch')->never()->with(m::type(NotificationSent::class));
        $sender = new NotificationSender($manager, m::mock(BusDispatcherContract::class), $events);

        try {
            $sender->sendNow(new AnonymousNotifiable, $notification, ['mail']);
            $this->fail('Expected the after-sending exception to be rethrown.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }
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
        $manager->shouldReceive('getContainer')->twice()->andReturn(app());
        $bus = m::mock(BusDispatcherContract::class);
        $bus->shouldReceive('dispatch')
            ->once()
            ->withArgs(function (SendQueuedNotifications $job): bool {
                return $job->queue === 'dummy' && $job->channels === ['database'] && $job->connection === 'redis';
            });
        $bus->shouldReceive('dispatch')
            ->once()
            ->withArgs(function (SendQueuedNotifications $job): bool {
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
        $manager->shouldReceive('getContainer')->twice()->andReturn(app());
        $bus = m::mock(BusDispatcherContract::class);
        $bus->shouldReceive('dispatch')
            ->once()
            ->withArgs(function (SendQueuedNotifications $job): bool {
                return $job->connection === 'sync' && $job->channels === ['database'] && $job->queue === 'dummy';
            });
        $bus->shouldReceive('dispatch')
            ->once()
            ->withArgs(function (SendQueuedNotifications $job): bool {
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
        $manager->shouldReceive('getContainer')->twice()->andReturn(app());
        $bus = m::mock(BusDispatcherContract::class);
        $bus->shouldReceive('dispatch')
            ->once()
            ->withArgs(function (SendQueuedNotifications $job): bool {
                return $job->queue === 'dummy' && $job->channels === ['database'] && $job->connection === 'redis';
            });
        $bus->shouldReceive('dispatch')
            ->once()
            ->withArgs(function (SendQueuedNotifications $job): bool {
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
        $events->shouldReceive('dispatch')->once()->with(m::type(NotificationDelivered::class));
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
        $events->shouldReceive('hasListeners')->with(NotificationDelivered::class)->andReturn(false);
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

class DummyNotificationWithAfterSendingCallback extends Notification
{
    public function __construct(private Closure $callback)
    {
    }

    public function afterSending(mixed $notifiable, string $channel, mixed $response): void
    {
        ($this->callback)($notifiable, $channel, $response);
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

    /**
     * Create a new notification instance.
     */
    public function __construct()
    {
        $this->connection = 'redis';
        $this->queue = 'dummy';
    }

    /**
     * Get the notification channels.
     */
    public function via(mixed $notifiable): array
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

    /**
     * Create a new notification instance.
     */
    public function __construct()
    {
        $this->connection = 'redis';
        $this->queue = 'dummy';
    }

    /**
     * Get the notification channels.
     */
    public function via(mixed $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Determine which connections should be used for each notification channel.
     */
    public function viaConnections(): array
    {
        return [
            'database' => 'sync',
        ];
    }
}

class DummyNotificationWithViaQueues extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct()
    {
        $this->connection = 'redis';
        $this->queue = 'dummy';
    }

    /**
     * Get the notification channels.
     */
    public function via(mixed $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Determine which queues should be used for each notification channel.
     */
    public function viaQueues(): array
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
