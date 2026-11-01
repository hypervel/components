<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications;

use Closure;
use Hypervel\Bus\Dispatcher as BusDispatcher;
use Hypervel\Bus\Queueable;
use Hypervel\Config\Repository as Config;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Bus\Dispatcher as BusDispatcherContract;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Events\Dispatcher as EventDispatcher;
use Hypervel\Foundation\Application;
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
use Hypervel\Notifications\NotificationServiceProvider;
use Hypervel\Notifications\SendQueuedNotifications;
use Hypervel\Queue\Attributes\Delay;
use Hypervel\Queue\Attributes\Queue;
use Hypervel\Queue\QueueRoutes;
use Hypervel\Support\Testing\Fakes\BusFake;
use Hypervel\Support\Testing\Fakes\EventFake;
use Hypervel\Tests\Notifications\Fixtures\ChannelSpy;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\TestWith;
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
        $manager->expects('driver')
            ->andReturnSelf();
        $manager->expects('send');
        $bus = m::mock(BusDispatcherContract::class);
        $bus->shouldNotReceive('dispatch');
        $events = $this->mockEventDispatcher();
        $events->shouldReceive('until')->with(m::type(NotificationSending::class))->andReturn(true);
        $events->expects('dispatch')->with(m::type(NotificationDelivered::class));
        $events->expects('dispatch')->with(m::type(NotificationSent::class));

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
        $manager->expects('driver')->with('mail')->andReturn($driver = m::mock());
        $driver->expects('send')->andReturnUsing(function () use (&$order, $response): object {
            $order[] = 'channel';

            return $response;
        });
        $events = $this->mockEventDispatcher();
        $events->expects('until')->with(m::type(NotificationSending::class))->andReturnUsing(
            function () use (&$order): bool {
                $order[] = 'sending';

                return true;
            }
        );
        $events->expects('dispatch')->twice()->andReturnUsing(
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
            /**
             * Determine whether the notification should be sent.
             */
            public function shouldSend(mixed $notifiable, string $channel): bool
            {
                return false;
            }
        };
        $manager = m::mock(ChannelManager::class);
        $manager->shouldNotReceive('driver');
        $events = $this->mockEventDispatcher();
        $events->shouldNotReceive('until');
        $events->expects('dispatch')->with(m::on(
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
        $events->expects('until')->with(m::type(NotificationSending::class))->andReturnFalse();
        $events->expects('dispatch')->with(m::type(NotificationSkipped::class));

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
        $events->expects('until')->with(m::type(NotificationSending::class))->andThrow($exception);
        $events->expects('dispatch')->with(m::on(
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
        $manager->expects('driver')->andReturn($driver = m::mock());
        $driver->expects('send')->andReturn('response');
        $events = $this->mockEventDispatcher();
        $events->expects('until')->with(m::type(NotificationSending::class))->andReturnTrue();
        $events->expects('dispatch')->with(m::type(NotificationDelivered::class))->andThrow($exception);
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
        $manager->expects('driver')->andReturn($driver = m::mock());
        $driver->expects('send')->andReturn('response');
        $events = $this->mockEventDispatcher();
        $events->expects('until')->with(m::type(NotificationSending::class))->andReturnTrue();
        $events->expects('dispatch')->with(m::type(NotificationDelivered::class));
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
        $notifiable = new DummyNotifiable;
        $manager = $this->getManager();
        $bus = new BusFake(new BusDispatcher(new Container));
        $events = m::mock(Dispatcher::class);

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, new DummyQueuedNotificationWithStringVia);

        $bus->assertDispatchedOnce(SendQueuedNotifications::class);
    }

    public function testItCanSendQueuedNotificationsWithAnArrayVia(): void
    {
        $notifiable = new DummyNotifiable;
        $manager = $this->getManager();
        $bus = new BusFake(new BusDispatcher(new Container));

        $events = m::mock(Dispatcher::class);

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, new DummyQueuedNotificationWithArrayVia);

        $bus->assertDispatchedTimes(SendQueuedNotifications::class, 2);
        $bus->assertDispatched(SendQueuedNotifications::class, function (SendQueuedNotifications $job): bool {
            return $job->queue === 'dummy' && $job->channels === ['database'] && $job->connection === 'redis';
        });
        $bus->assertDispatched(SendQueuedNotifications::class, function (SendQueuedNotifications $job): bool {
            return $job->queue === 'dummy' && $job->channels === ['mail'] && $job->connection === 'redis';
        });
    }

    public function testItCanSendNotificationsWithAnEmptyStringVia(): void
    {
        $notifiable = new AnonymousNotifiable;
        $manager = m::mock(ChannelManager::class);
        $bus = new BusFake(new BusDispatcher(new Container));
        $events = m::mock(Dispatcher::class);
        $events->shouldNotReceive('dispatch');

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->sendNow($notifiable, new DummyNotificationWithEmptyStringVia);

        $bus->assertNothingDispatched();
    }

    public function testItCannotSendNotificationsViaDatabaseForAnonymousNotifiables(): void
    {
        $notifiable = new AnonymousNotifiable;
        $manager = m::mock(ChannelManager::class);
        $bus = new BusFake(new BusDispatcher(new Container));
        $events = m::mock(Dispatcher::class);
        $events->shouldNotReceive('dispatch');

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->sendNow($notifiable, new DummyNotificationWithDatabaseVia);

        $bus->assertNothingDispatched();
    }

    public function testItCanSendQueuedNotificationsThroughMiddleware(): void
    {
        $notifiable = new DummyNotifiable;
        $manager = $this->getManager();
        $bus = new BusFake(new BusDispatcher(new Container));
        $events = m::mock(Dispatcher::class);

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, new DummyNotificationWithMiddleware);

        $bus->assertDispatchedOnce(SendQueuedNotifications::class);
        $bus->assertDispatched(SendQueuedNotifications::class, function (SendQueuedNotifications $job): bool {
            return ($job->middleware[0] ?? null) instanceof TestNotificationMiddleware;
        });
    }

    public function testItCanSendQueuedMultiChannelNotificationsThroughDifferentMiddleware(): void
    {
        $notifiable = new DummyNotifiable;
        $manager = $this->getManager();
        $bus = new BusFake(new BusDispatcher(new Container));
        $events = m::mock(Dispatcher::class);

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, new DummyMultiChannelNotificationWithConditionalMiddleware);

        $bus->assertDispatchedTimes(SendQueuedNotifications::class, 3);
        $bus->assertDispatched(SendQueuedNotifications::class, function (SendQueuedNotifications $job): bool {
            return ($job->middleware[0] ?? null) instanceof TestMailNotificationMiddleware;
        });
        $bus->assertDispatched(SendQueuedNotifications::class, function (SendQueuedNotifications $job): bool {
            return ($job->middleware[0] ?? null) instanceof TestDatabaseNotificationMiddleware;
        });
        $bus->assertDispatched(SendQueuedNotifications::class, function (SendQueuedNotifications $job): bool {
            return empty($job->middleware);
        });
    }

    public function testItCanSendQueuedWithViaConnectionsNotifications(): void
    {
        $notifiable = new AnonymousNotifiable;
        $manager = $this->getManager();
        $bus = new BusFake(new BusDispatcher(new Container));

        $events = m::mock(Dispatcher::class);

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, new DummyNotificationWithViaConnections);

        $bus->assertDispatchedTimes(SendQueuedNotifications::class, 2);
        $bus->assertDispatched(SendQueuedNotifications::class, function (SendQueuedNotifications $job): bool {
            return $job->connection === 'sync' && $job->channels === ['database'] && $job->queue === 'dummy';
        });
        $bus->assertDispatched(SendQueuedNotifications::class, function (SendQueuedNotifications $job): bool {
            return $job->connection === 'redis' && $job->channels === ['mail'] && $job->queue === 'dummy';
        });
    }

    public function testItCanSendQueuedWithViaQueuesNotifications(): void
    {
        $notifiable = new AnonymousNotifiable;
        $manager = $this->getManager();
        $bus = new BusFake(new BusDispatcher(new Container));

        $events = m::mock(Dispatcher::class);

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, new DummyNotificationWithViaQueues);

        $bus->assertDispatchedTimes(SendQueuedNotifications::class, 2);
        $bus->assertDispatched(SendQueuedNotifications::class, function (SendQueuedNotifications $job): bool {
            return $job->queue === 'dummy' && $job->channels === ['database'] && $job->connection === 'redis';
        });
        $bus->assertDispatched(SendQueuedNotifications::class, function (SendQueuedNotifications $job): bool {
            return $job->queue === 'admin_notifications' && $job->channels === ['mail'] && $job->connection === 'redis';
        });
    }

    public function testItCanSendQueuedNotificationsWithQueueRoute(): void
    {
        $notifiable = new AnonymousNotifiable;
        $manager = $this->getManager([
            DummyQueuedNotificationWithStringVia::class => ['notification-queue', 'notification-connection'],
        ]);

        $bus = new BusFake(new BusDispatcher(new Container));

        $events = m::mock(Dispatcher::class);

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, new DummyQueuedNotificationWithStringVia);

        $bus->assertDispatchedOnce(SendQueuedNotifications::class);
        $bus->assertDispatched(SendQueuedNotifications::class, function (SendQueuedNotifications $job): bool {
            return $job->queue === 'notification-queue' && $job->channels === ['mail'] && $job->connection === 'notification-connection';
        });
    }

    #[TestWith([null, 'cloud'])]
    #[TestWith(['explicit', 'explicit'])]
    public function testForwardedConnectionsUseTheSelectedChannelQueue(?string $connection, string $expectedConnection): void
    {
        $container = Container::getInstance();
        $container->instance('config', new Config);
        $routes = new QueueRoutes;
        $routes->forward('dummy', 'unused', 'wrong-connection');
        $routes->forward('admin_notifications', 'notifications', 'cloud');
        $container->instance('queue.routes', $routes);
        $notification = new class extends DummyNotificationWithViaQueues {
            /**
             * Select an explicit connection for the database channel.
             */
            public function viaConnections(): array
            {
                return ['database' => 'database-connection'];
            }
        };
        $notification->onConnection($connection);
        $bus = m::mock(BusDispatcherContract::class);
        $bus->expects('dispatch')->with(m::on(
            fn (SendQueuedNotifications $job): bool => $job->channels === ['mail']
                && $job->queue === 'admin_notifications'
                && $job->connection === $expectedConnection
        ));
        $bus->expects('dispatch')->with(m::on(
            fn (SendQueuedNotifications $job): bool => $job->channels === ['database']
                && $job->queue === 'dummy'
                && $job->connection === 'database-connection'
        ));

        (new NotificationSender(new ChannelManager($container), $bus, m::mock(Dispatcher::class)))
            ->send(new AnonymousNotifiable, $notification);
    }

    public function testItCanSendQueuedNotificationsWithDelayAttribute(): void
    {
        $notification = new #[Delay(30)] class extends Notification implements ShouldQueue {
            use Queueable;

            /**
             * Get the notification channels.
             */
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
        $bus->expects('dispatch')
            ->withArgs(function (SendQueuedNotifications $job): bool {
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

            /**
             * Get the notification channels.
             */
            public function via(mixed $notifiable): string
            {
                return 'mail';
            }

            /**
             * Get the delay for the notification channel.
             */
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
        $bus->expects('dispatch')
            ->withArgs(function (SendQueuedNotifications $job): bool {
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
        $bus->expects('dispatch')
            ->withArgs(function (SendQueuedNotifications $job): bool {
                return $job->delay === 45;
            });

        $events = m::mock(Dispatcher::class);

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, (new DummyQueuedNotificationWithStringVia)->delay(45));
    }

    public function testNotificationFailedSentWithoutHttpTransportException(): void
    {
        $notifiable = new AnonymousNotifiable;
        $container = new Container;
        $container->instance('config', new Config(['app' => ['name' => 'Name', 'logo' => 'Logo']]));
        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);
        $driver = new ChannelSpy;
        $response = m::mock(ResponseInterface::class);
        $driver->exception = $exception = new HttpTransportException('Transport error', $response);
        $manager->expects('driver')->andReturn($driver);
        $bus = m::mock(BusDispatcherContract::class);

        $events = new EventDispatcher;
        $failed = null;
        $events->listen(NotificationFailed::class, function (NotificationFailed $event) use (&$failed): void {
            $failed = $event;
        });

        $sender = new NotificationSender($manager, $bus, $events);

        try {
            $sender->sendNow($notifiable, new DummyNotificationWithViaConnections, ['mail']);

            $this->fail('Expected the transport exception to be rethrown.');
        } catch (TransportException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame(TransportException::class, $failed->data['exception']::class);
    }

    public function testNotificationFailedIsDispatchedAfterAChannelDispatchedItWithoutThrowing(): void
    {
        $notifiable = new AnonymousNotifiable;
        $container = new Application;
        $container->instance('config', new Config(['app' => ['name' => 'Name', 'logo' => 'Logo']]));
        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);
        $bus = new BusFake(new BusDispatcher(new Container));
        $events = new EventDispatcher;
        $container->instance('events', $events);
        (new NotificationServiceProvider($container))->boot();

        $failed = [];
        $events->listen(NotificationFailed::class, function (NotificationFailed $event) use (&$failed): void {
            $failed[] = $event->channel;
        });

        $pushChannel = new class($events) {
            /**
             * Create a channel that reports failures without throwing.
             */
            public function __construct(protected Dispatcher $events)
            {
            }

            /**
             * Send the notification.
             */
            public function send(mixed $notifiable, Notification $notification): void
            {
                $this->events->dispatch(new NotificationFailed($notifiable, $notification, 'push'));
            }
        };

        $smsChannel = new ChannelSpy;
        $smsChannel->exception = new RuntimeException('SMS gateway down');

        $manager->expects('driver')->with('push')->andReturn($pushChannel);
        $manager->expects('driver')->with('sms')->andReturn($smsChannel);

        $sender = new NotificationSender($manager, $bus, $events);
        $sender->sendNow($notifiable, new DummyNotificationWithViaConnections, ['push']);

        // Check cleanup before another attempt can overwrite a leaked failure marker.
        $this->assertNull(CoroutineContext::get(NotificationSender::FAILED_EVENT_DISPATCHED_CONTEXT_KEY));
        $caught = null;

        try {
            $sender->sendNow($notifiable, new DummyNotificationWithViaConnections, ['sms']);
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        $this->assertSame($smsChannel->exception, $caught);
        $this->assertSame(['push', 'sms'], $failed);
    }

    public function testItPreservesNotificationStateMutatedInViaMethod(): void
    {
        $notifiable = new AnonymousNotifiable;
        $container = new Container;
        $container->instance('config', new Config(['app' => ['name' => 'Name', 'logo' => 'Logo']]));
        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);
        $driver = new ChannelSpy;
        $manager->expects('driver')->andReturn($driver);
        $bus = m::mock(BusDispatcherContract::class);

        $events = new EventFake(new EventDispatcher);

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->sendNow($notifiable, new DummyNotificationWithViaMutation);

        $this->assertSame('default', $driver->sent[0][1]->channelData);
        $events->assertDispatchedOnce(NotificationSending::class);
        $events->assertDispatchedOnce(NotificationDelivered::class);
        $events->assertDispatchedOnce(NotificationSent::class);
    }

    public function testItQueueOverridesQueueAttribute(): void
    {
        $notification = new #[Queue('attribute-queue')] class extends Notification implements ShouldQueue {
            use Queueable;

            /**
             * Get the notification channels.
             */
            public function via(mixed $notifiable): string
            {
                return 'mail';
            }
        };

        $notification->onQueue('manual-queue');

        $notifiable = new DummyNotifiable;
        $manager = $this->getManager();

        $events = m::mock(Dispatcher::class);

        $bus = new BusFake(new BusDispatcher(new Container));

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, $notification);

        $bus->assertDispatchedOnce(SendQueuedNotifications::class);
        $bus->assertDispatched(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->queue === 'manual-queue');
    }

    public function testItQueueAttributeIsUsedWhenOnQueueIsNotCalled(): void
    {
        $notification = new #[Queue('attribute-queue')] class extends Notification implements ShouldQueue {
            use Queueable;

            /**
             * Get the notification channels.
             */
            public function via(mixed $notifiable): string
            {
                return 'mail';
            }
        };

        $notifiable = new DummyNotifiable;
        $manager = $this->getManager();

        $events = m::mock(Dispatcher::class);

        $bus = new BusFake(new BusDispatcher(new Container));

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, $notification);

        $bus->assertDispatchedOnce(SendQueuedNotifications::class);
        $bus->assertDispatched(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->queue === 'attribute-queue');
    }

    public function testItConstructorOverrideTakesPrecedenceOverQueueAttribute(): void
    {
        $notification = new #[Queue('attribute-queue')] class extends Notification implements ShouldQueue {
            use Queueable;

            /**
             * Create a notification with an explicit queue.
             */
            public function __construct()
            {
                $this->queue = 'constructor-override-queue';
            }

            /**
             * Get the notification channels.
             */
            public function via(mixed $notifiable): string
            {
                return 'mail';
            }
        };

        $notifiable = new DummyNotifiable;
        $manager = $this->getManager();

        $events = m::mock(Dispatcher::class);

        $bus = new BusFake(new BusDispatcher(new Container));

        $sender = new NotificationSender($manager, $bus, $events);

        $sender->send($notifiable, $notification);

        $bus->assertDispatchedOnce(SendQueuedNotifications::class);
        $bus->assertDispatched(SendQueuedNotifications::class, fn (SendQueuedNotifications $job): bool => $job->queue === 'constructor-override-queue');
    }

    public function testNotificationEventsAreSkippedWhenNoListenersAreRegistered(): void
    {
        $notifiable = m::mock(Notifiable::class);
        $manager = m::mock(ChannelManager::class);
        $manager->expects('driver')
            ->andReturnSelf();
        $manager->expects('send');
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

    public function testNotificationFailedIsSkippedWithoutListeners(): void
    {
        $notifiable = new AnonymousNotifiable;
        $manager = m::mock(ChannelManager::class);
        $manager->shouldReceive('driver')->andReturn($driver = m::mock());
        $response = m::mock(ResponseInterface::class);
        $exception = new HttpTransportException('Transport error', $response);
        $driver->expects('send')->andThrow($exception);
        $bus = m::mock(BusDispatcherContract::class);

        $events = $this->mockEventDispatcher();
        $events->shouldReceive('hasListeners')->with(NotificationSending::class)->andReturn(false);
        $events->shouldReceive('hasListeners')->with(NotificationFailed::class)->andReturn(false);
        $events->shouldNotReceive('until');
        $events->shouldNotReceive('dispatch');

        $sender = new NotificationSender($manager, $bus, $events);

        try {
            $sender->sendNow($notifiable, new DummyNotificationWithViaConnections, ['mail']);

            $this->fail('Expected the transport exception to be rethrown.');
        } catch (TransportException $caught) {
            $this->assertSame($exception, $caught);
        }
    }

    /**
     * Create a channel manager with the given notification queue routes.
     *
     * @param array<class-string, array{?string, ?string}> $queueRoutes
     */
    protected function getManager(array $queueRoutes = []): ChannelManager
    {
        $container = new Container;
        $container->instance('config', new Config(['app' => ['name' => 'Name', 'logo' => 'Logo']]));

        if ($queueRoutes) {
            $routes = new QueueRoutes;

            foreach ($queueRoutes as $class => [$queue, $connection]) {
                $routes->set($class, $queue, $connection);
            }

            $container->instance('queue.routes', $routes);
        }

        Container::setInstance($container);

        return new ChannelManager($container);
    }

    /**
     * Create an event dispatcher that reports listeners by default.
     */
    private function mockEventDispatcher(): Dispatcher
    {
        $events = m::mock(Dispatcher::class);
        $events->shouldReceive('hasListeners')->byDefault()->andReturn(true);

        return $events;
    }
}

class DummyNotificationWithAfterSendingCallback extends Notification
{
    /**
     * Create a notification with an after-sending callback.
     */
    public function __construct(private Closure $callback)
    {
    }

    /**
     * Run the callback after delivery.
     */
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
     */
    public function via(mixed $notifiable): string
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
     */
    public function via(mixed $notifiable): string
    {
        return 'mail';
    }
}

class DummyNotificationWithEmptyStringVia extends Notification
{
    use Queueable;

    /**
     * Get the notification channels.
     */
    public function via(mixed $notifiable): string
    {
        return '';
    }
}

class DummyNotificationWithDatabaseVia extends Notification
{
    use Queueable;

    /**
     * Get the notification channels.
     */
    public function via(mixed $notifiable): string
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

    /**
     * Get the notification channels.
     */
    public function via(mixed $notifiable): string
    {
        return 'mail';
    }

    /**
     * Get the notification middleware.
     */
    public function middleware(): array
    {
        return [
            new TestNotificationMiddleware,
        ];
    }
}

class DummyMultiChannelNotificationWithConditionalMiddleware extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Get the notification channels.
     */
    public function via(mixed $notifiable): array
    {
        return [
            'mail',
            'database',
            'broadcast',
        ];
    }

    /**
     * Get the middleware for the notification channel.
     */
    public function middleware(mixed $notifiable, string $channel): array
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
    /**
     * Pass the notification to the next middleware.
     */
    public function handle(mixed $command, Closure $next): mixed
    {
        return $next($command);
    }
}

class TestMailNotificationMiddleware
{
    /**
     * Pass the notification to the next middleware.
     */
    public function handle(mixed $command, Closure $next): mixed
    {
        return $next($command);
    }
}

class TestDatabaseNotificationMiddleware
{
    /**
     * Pass the notification to the next middleware.
     */
    public function handle(mixed $command, Closure $next): mixed
    {
        return $next($command);
    }
}

class DummyNotificationWithViaMutation extends Notification
{
    public ?string $channelData = null;

    /**
     * Set channel data and get the notification channels.
     */
    public function via(mixed $notifiable): string
    {
        $this->channelData = $notifiable->routeConfig ?? 'default';

        return 'mail';
    }
}

class DummyNotifiable
{
    use Notifiable;
}
