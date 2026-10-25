<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications;

use Closure;
use Exception;
use Hypervel\Bus\Dispatcher as BusDispatcher;
use Hypervel\Bus\Queueable;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Bus\Dispatcher as BusDispatcherContract;
use Hypervel\Contracts\Container\Container as ContainerContract;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Notifications\Dispatcher as NotificationDispatcherContract;
use Hypervel\Contracts\Notifications\Factory as NotificationFactoryContract;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Events\Dispatcher as EventsDispatcher;
use Hypervel\Foundation\Application;
use Hypervel\Notifications\ChannelManager;
use Hypervel\Notifications\Channels\MailChannel;
use Hypervel\Notifications\Events\NotificationDelivered;
use Hypervel\Notifications\Events\NotificationFailed;
use Hypervel\Notifications\Events\NotificationSending;
use Hypervel\Notifications\Events\NotificationSent;
use Hypervel\Notifications\Events\NotificationSkipped;
use Hypervel\Notifications\Notifiable;
use Hypervel\Notifications\Notification;
use Hypervel\Notifications\NotificationServiceProvider;
use Hypervel\Notifications\SendQueuedNotifications;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\QueueRoutes;
use Hypervel\Queue\SerializesModels;
use Hypervel\Support\Testing\Fakes\BusFake;
use Hypervel\Support\Testing\Fakes\EventFake;
use Hypervel\Support\Testing\Fakes\NotificationFake;
use Hypervel\Tests\Notifications\Fixtures\ChannelSpy;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Laravel\SerializableClosure\SerializableClosure;
use Mockery as m;
use Mockery\MockInterface;
use stdClass;

class NotificationChannelManagerTest extends TestCase
{
    public function testGetDefaultChannel(): void
    {
        $container = $this->getContainer();
        $container->instance(MailChannel::class, m::mock(MailChannel::class));

        $manager = new ChannelManager($container);

        $this->assertInstanceOf(MailChannel::class, $manager->channel());
    }

    public function testGetCustomChannelResolvesDirectly(): void
    {
        $container = $this->getContainer();
        $container->instance(MailChannel::class, m::mock(MailChannel::class));

        $manager = new ChannelManager($container);
        $channel = m::mock('customChannel');
        $manager->extend('test', fn (): MockInterface => $channel);

        $this->assertSame($channel, $manager->channel('test'));
    }

    public function testIntegerEnumChannelNamesAreNormalizedByTheManagerAndFake(): void
    {
        $manager = new ChannelManager($this->getContainer());
        $channel = m::mock('customChannel');
        $manager->extend('0', fn (): MockInterface => $channel);

        $this->assertSame($channel, $manager->channel(NotificationChannelManagerTestIntIdentifier::Zero));
        $this->assertNull((new NotificationFake)->channel(NotificationChannelManagerTestIntIdentifier::Zero));
    }

    public function testSlackRequiresItsPackageProvider(): void
    {
        $container = $this->getContainer();

        $manager = new ChannelManager($container);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIs('Driver [slack] not supported.');

        $manager->channel('slack');
    }

    public function testManagerAliasesResolveTheSameAutoSingleton(): void
    {
        $application = new Application;
        $application->instance('config', new ConfigRepository([]));
        Container::setInstance($application);
        (new NotificationServiceProvider($application))->register();

        $manager = $application->make(ChannelManager::class);

        $this->assertSame($manager, $application->make(NotificationDispatcherContract::class));
        $this->assertSame($manager, $application->make(NotificationFactoryContract::class));
    }

    public function testNotificationCanBeDispatchedToDriver(): void
    {
        $container = $this->getContainer();

        $events = new EventFake(new EventsDispatcher);
        $container->instance(Dispatcher::class, $events);

        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);
        $driver = new ChannelSpy;
        $manager->expects('driver')->andReturn($driver);

        $manager->send($notifiable = new NotificationChannelManagerTestNotifiable, new NotificationChannelManagerTestNotification);

        $this->assertCount(1, $driver->sent);
        $this->assertSame($notifiable, $driver->sent[0][0]);
        $this->assertInstanceOf(NotificationChannelManagerTestNotification::class, $driver->sent[0][1]);
        $events->assertDispatchedOnce(NotificationSending::class);
        $events->assertDispatchedOnce(NotificationDelivered::class);
        $events->assertDispatchedOnce(NotificationSent::class);
    }

    public function testChannelCanBeResolvedUsingBackedEnum(): void
    {
        $container = $this->getContainer();

        $manager = new ChannelManager($container);
        $manager->extend('test', fn (): NotificationChannelManagerTestCustomChannel => new NotificationChannelManagerTestCustomChannel);

        $this->assertInstanceOf(NotificationChannelManagerTestCustomChannel::class, $manager->channel(NotificationChannelManagerTestChannelEnum::Test));
    }

    public function testDriverCanBeResolvedUsingBackedEnum(): void
    {
        $container = $this->getContainer();

        $manager = new ChannelManager($container);

        $this->assertInstanceOf(NotificationChannelManagerTestCustomChannel::class, $manager->driver(NotificationChannelManagerTestChannelEnum::Custom));
    }

    public function testNotificationNotSentOnHalt(): void
    {
        $container = $this->getContainer();

        $events = new EventsDispatcher;
        $container->instance(Dispatcher::class, $events);
        $calls = 0;
        $events->listen(NotificationSending::class, function () use (&$calls): bool {
            return $calls++ > 0;
        });
        $skipped = [];
        $events->listen(NotificationSkipped::class, function (NotificationSkipped $event) use (&$skipped): void {
            $skipped[] = $event;
        });
        $delivered = [];
        $events->listen(NotificationDelivered::class, function (NotificationDelivered $event) use (&$delivered): void {
            $delivered[] = $event;
        });
        $sent = [];
        $events->listen(NotificationSent::class, function (NotificationSent $event) use (&$sent): void {
            $sent[] = $event;
        });
        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);
        $driver = new ChannelSpy;
        $manager->expects('driver')->andReturn($driver);

        $manager->send([new NotificationChannelManagerTestNotifiable], new NotificationChannelManagerTestNotificationWithTwoChannels);

        $this->assertCount(1, $driver->sent);
        $this->assertCount(1, $skipped);
        $this->assertCount(1, $delivered);
        $this->assertCount(1, $sent);
    }

    public function testNotificationNotSentWhenCancelled(): void
    {
        $container = $this->getContainer();

        $events = new EventFake(new EventsDispatcher);
        $container->instance(Dispatcher::class, $events);
        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);
        $manager->shouldNotReceive('driver');

        $manager->send([new NotificationChannelManagerTestNotifiable], new NotificationChannelManagerTestCancelledNotification);

        $events->assertDispatchedOnce(NotificationSkipped::class);
        $events->assertNotDispatched(NotificationSent::class);
    }

    public function testNotificationSentWhenNotCancelled(): void
    {
        $container = $this->getContainer();

        $events = new EventFake(new EventsDispatcher);
        $container->instance(Dispatcher::class, $events);
        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);
        $driver = new ChannelSpy;
        $manager->expects('driver')->andReturn($driver);

        $manager->send([new NotificationChannelManagerTestNotifiable], new NotificationChannelManagerTestNotCancelledNotification);

        $this->assertCount(1, $driver->sent);
        $events->assertDispatchedOnce(NotificationSending::class);
        $events->assertDispatchedOnce(NotificationDelivered::class);
        $events->assertDispatchedOnce(NotificationSent::class);
    }

    public function testNotificationNotSentWhenFailed(): void
    {
        $container = $this->getContainer();

        $events = new EventFake(new EventsDispatcher);
        $container->instance(Dispatcher::class, $events);
        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);
        $driver = new ChannelSpy;
        $driver->exception = $exception = new Exception;
        $manager->expects('driver')->andReturn($driver);

        try {
            $manager->send(new NotificationChannelManagerTestNotifiable, new NotificationChannelManagerTestNotification);
            $this->fail('The channel exception was not thrown.');
        } catch (Exception $caught) {
            $this->assertSame($exception, $caught);
        }

        $events->assertDispatchedOnce(NotificationSending::class);
        $events->assertDispatchedOnce(NotificationFailed::class);
        $events->assertNotDispatched(NotificationDelivered::class);
        $events->assertNotDispatched(NotificationSent::class);
    }

    public function testNotificationFailedDispatchedOnlyOnceWhenMultipleFailed(): void
    {
        $container = $this->getContainer();
        $events = new EventsDispatcher;
        $container->instance(Dispatcher::class, $events);
        $manager = new ChannelManager($container);
        $manager->extend('test', function (): object {
            return new class {
                private int $count = 0;

                /**
                 * Fail after two successful sends.
                 */
                public function send(mixed $notifiable, Notification $notification): void
                {
                    if ($this->count > 1) {
                        throw new Exception('Channel failed.');
                    }

                    ++$this->count;
                }
            };
        });

        $failed = 0;
        $events->listen(NotificationFailed::class, function () use (&$failed): void {
            ++$failed;
        });
        $delivered = 0;
        $events->listen(NotificationDelivered::class, function () use (&$delivered): void {
            ++$delivered;
        });
        $sent = 0;
        $events->listen(NotificationSent::class, function () use (&$sent): void {
            ++$sent;
        });

        $this->expectExceptionObject(new Exception('Channel failed.'));

        try {
            $manager->send(new NotificationChannelManagerTestNotifiable, new NotificationChannelManagerTestNotification);
            $manager->send(new NotificationChannelManagerTestNotifiable, new NotificationChannelManagerTestNotification);
            $manager->send(new NotificationChannelManagerTestNotifiable, new NotificationChannelManagerTestNotification);
        } finally {
            $this->assertSame(2, $sent);
            $this->assertSame(2, $delivered);
            $this->assertSame(1, $failed);
            // The provider owns the failure listener; sending must not register additional listeners.
            // NotificationFailedEventTest covers channel-owned failure deduplication through the real provider.
            $this->assertCount(1, $events->getListeners(NotificationFailed::class));
        }
    }

    public function testNotificationCanBeQueued(): void
    {
        $container = $this->getContainer();
        $container->instance(QueueRoutes::class, $queueRoutes = new QueueRoutes);
        $container->instance('queue.routes', $queueRoutes);
        $container->instance(BusDispatcherContract::class, $bus = new BusFake(new BusDispatcher(new Container)));

        $manager = new ChannelManager($container);

        $manager->send([new NotificationChannelManagerTestNotifiable], new NotificationChannelManagerTestQueuedNotification);

        $bus->assertDispatched(SendQueuedNotifications::class);
    }

    public function testSendQueuedNotificationsCanBeOverrideViaContainer(): void
    {
        $container = $this->getContainer();
        $container->instance(QueueRoutes::class, $queueRoutes = new QueueRoutes);
        $container->instance('queue.routes', $queueRoutes);
        $container->instance(BusDispatcherContract::class, $bus = new BusFake(new BusDispatcher(new Container)));
        $container->bind(SendQueuedNotifications::class, TestSendQueuedNotifications::class);

        $manager = new ChannelManager($container);

        $manager->send([new NotificationChannelManagerTestNotifiable], new NotificationChannelManagerTestQueuedNotification);

        $bus->assertDispatched(TestSendQueuedNotifications::class);
    }

    public function testQueuedNotificationForwardsMessageGroupFromMethodToQueueJob(): void
    {
        $mockedMessageGroupId = 'group-1';

        $notification = $this->getMockBuilder(NotificationChannelManagerTestQueuedNotificationWithMessageGroupMethod::class)->onlyMethods(['messageGroup'])->getMock();
        $notification->expects($this->exactly(2))->method('messageGroup')->willReturn($mockedMessageGroupId);

        $container = $this->getContainer();
        $container->instance(QueueRoutes::class, $queueRoutes = new QueueRoutes);
        $container->instance('queue.routes', $queueRoutes);
        $container->instance(BusDispatcherContract::class, $bus = new BusFake(new BusDispatcher(new Container)));

        $manager = new ChannelManager($container);

        $manager->send([new NotificationChannelManagerTestNotifiable], $notification);

        $jobs = $bus->dispatched(SendQueuedNotifications::class);
        $this->assertCount(2, $jobs);
        $jobs->each(function (SendQueuedNotifications $job) use ($mockedMessageGroupId): void {
            $this->assertEquals($mockedMessageGroupId, $job->messageGroup);
        });
    }

    public function testQueuedNotificationForwardsMessageGroupFromPropertyOverridingMethodToQueueJob(): void
    {
        $mockedMessageGroupId = 'group-1';

        // Ensure the messageGroup method is not called when a messageGroup property is provided.
        $notification = $this->getMockBuilder(NotificationChannelManagerTestQueuedNotificationWithMessageGroupMethod::class)->onlyMethods(['messageGroup'])->getMock();
        $notification->expects($this->never())->method('messageGroup')->willReturn('this-should-not-be-used');
        $notification->onGroup($mockedMessageGroupId);

        $container = $this->getContainer();
        $container->instance(QueueRoutes::class, $queueRoutes = new QueueRoutes);
        $container->instance('queue.routes', $queueRoutes);
        $container->instance(BusDispatcherContract::class, $bus = new BusFake(new BusDispatcher(new Container)));

        $manager = new ChannelManager($container);

        $manager->send([new NotificationChannelManagerTestNotifiable], $notification);

        $jobs = $bus->dispatched(SendQueuedNotifications::class);
        $this->assertCount(2, $jobs);
        $jobs->each(function (SendQueuedNotifications $job) use ($mockedMessageGroupId): void {
            $this->assertEquals($mockedMessageGroupId, $job->messageGroup);
        });
    }

    public function testQueuedNotificationForwardsMessageGroupSetToQueueJob(): void
    {
        $mockedMessageGroupSet = [
            'test' => 'group-1',
            'test2' => 'group-2',
        ];

        $container = $this->getContainer();
        $container->instance(QueueRoutes::class, $queueRoutes = new QueueRoutes);
        $container->instance('queue.routes', $queueRoutes);
        $container->instance(BusDispatcherContract::class, $bus = new BusFake(new BusDispatcher(new Container)));

        $manager = new ChannelManager($container);

        $notification = (new NotificationChannelManagerTestQueuedNotificationWithTwoChannels)->onGroup($mockedMessageGroupSet);
        $manager->send([new NotificationChannelManagerTestNotifiable], $notification);

        $jobs = $bus->dispatched(SendQueuedNotifications::class);
        $this->assertCount(2, $jobs);
        $jobs->each(function (SendQueuedNotifications $job) use ($mockedMessageGroupSet): void {
            $this->assertEquals($mockedMessageGroupSet[$job->channels[0]], $job->messageGroup);
        });
    }

    public function testQueuedNotificationForwardsMessageGroupSetFromClassToQueueJob(): void
    {
        $mockedMessageGroupSet = [
            'test' => 'group-1',
            'test2' => 'group-2',
        ];

        $container = $this->getContainer();
        $container->instance('queue.routes', new QueueRoutes);
        $container->instance(BusDispatcherContract::class, $bus = new BusFake(new BusDispatcher(new Container)));

        $manager = new ChannelManager($container);

        $notification = new NotificationChannelManagerTestQueuedNotificationWithMessageGroups;
        $manager->send([new NotificationChannelManagerTestNotifiable], $notification);

        $jobs = $bus->dispatched(SendQueuedNotifications::class);
        $this->assertCount(2, $jobs);
        $jobs->each(function (SendQueuedNotifications $job) use ($mockedMessageGroupSet): void {
            $this->assertEquals($mockedMessageGroupSet[$job->channels[0]], $job->messageGroup);
        });
    }

    public function testQueuedNotificationForwardsDeduplicatorToQueueJob(): void
    {
        $mockedDeduplicator = fn (string $payload, string $queue): string => 'deduplication-id-1';

        $container = $this->getContainer();
        $container->instance('queue.routes', new QueueRoutes);
        $container->instance(BusDispatcherContract::class, $bus = new BusFake(new BusDispatcher(new Container)));

        $manager = new ChannelManager($container);

        $notification = (new NotificationChannelManagerTestQueuedNotification)->withDeduplicator($mockedDeduplicator);
        $manager->send([new NotificationChannelManagerTestNotifiable], $notification);

        $job = $bus->dispatched(SendQueuedNotifications::class)->sole();
        $this->assertInstanceOf(SerializableClosure::class, $job->deduplicator);
        $this->assertEquals($mockedDeduplicator, $job->deduplicator->getClosure());
    }

    public function testQueuedNotificationForwardsArrayCallableDeduplicatorToQueueJob(): void
    {
        $deduplicator = [NotificationChannelManagerTestDeduplicator::class, 'resolve'];

        $container = $this->getContainer();
        $container->instance('queue.routes', $queueRoutes = m::mock(QueueRoutes::class));
        $queueRoutes->expects('getQueue')->times(2)->andReturn(null);
        $queueRoutes->expects('getConnection')->times(2)->andReturn(null);
        $container->make(BusDispatcherContract::class)
            ->expects('dispatch')->times(2)->withArgs(function (SendQueuedNotifications $job) use ($deduplicator): bool {
                $this->assertSame($deduplicator, $job->deduplicator);

                $restored = unserialize(serialize($job));
                $this->assertSame('queue:payload', ($restored->deduplicator)('payload', 'queue'));

                return true;
            });

        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);

        $notification = (new NotificationChannelManagerTestQueuedNotificationWithTwoChannels)->withDeduplicator($deduplicator);
        $manager->send([new NotificationChannelManagerTestNotifiable], $notification);
    }

    public function testQueuedNotificationForwardsDeduplicatorSetToQueueJob(): void
    {
        $mockedDeduplicatorSet = [
            'test' => fn (string $payload, string $queue): string => 'deduplication-id-1',
            'test2' => fn (string $payload, string $queue): string => 'deduplication-id-2',
        ];

        $container = $this->getContainer();
        $container->instance('queue.routes', new QueueRoutes);
        $container->instance(BusDispatcherContract::class, $bus = new BusFake(new BusDispatcher(new Container)));

        $manager = new ChannelManager($container);

        $notification = (new NotificationChannelManagerTestQueuedNotificationWithTwoChannels)->withDeduplicator($mockedDeduplicatorSet);
        $manager->send([new NotificationChannelManagerTestNotifiable], $notification);

        $jobs = $bus->dispatched(SendQueuedNotifications::class);
        $this->assertCount(2, $jobs);
        $jobs->each(function (SendQueuedNotifications $job) use ($mockedDeduplicatorSet): void {
            $this->assertInstanceOf(SerializableClosure::class, $job->deduplicator);
            $this->assertEquals($mockedDeduplicatorSet[$job->channels[0]], $job->deduplicator->getClosure());

            $restored = unserialize(serialize($job));
            $this->assertSame(
                $mockedDeduplicatorSet[$job->channels[0]]('payload', 'queue'),
                ($restored->deduplicator)('payload', 'queue')
            );
        });
    }

    public function testQueuedNotificationForwardsDeduplicatorSetFromClassToQueueJob(): void
    {
        $container = $this->getContainer();
        $container->instance('queue.routes', new QueueRoutes);
        $container->instance(BusDispatcherContract::class, $bus = new BusFake(new BusDispatcher(new Container)));

        $manager = new ChannelManager($container);

        $notification = new NotificationChannelManagerTestQueuedNotificationWithDeduplicators;
        $manager->send([new NotificationChannelManagerTestNotifiable], $notification);

        $jobs = $bus->dispatched(SendQueuedNotifications::class);
        $this->assertCount(2, $jobs);
        $jobs->each(function (SendQueuedNotifications $job): void {
            $this->assertEquals(
                $job->notification->deduplicatorResults[$job->channels[0]],
                call_user_func($job->deduplicator, 'payload', 'queue')
            );
        });
    }

    public function testQueuedNotificationForwardsDeduplicationIdMethodToQueueJob(): void
    {
        $container = $this->getContainer();
        $container->instance('queue.routes', new QueueRoutes);
        $container->instance(BusDispatcherContract::class, $bus = new BusFake(new BusDispatcher(new Container)));

        $manager = new ChannelManager($container);

        $notification = new NotificationChannelManagerTestQueuedNotificationWithDeduplicationId;
        $manager->send([new NotificationChannelManagerTestNotifiable], $notification);

        $jobs = $bus->dispatched(SendQueuedNotifications::class);
        $this->assertCount(2, $jobs);
        $jobs->each(function (SendQueuedNotifications $job): void {
            $this->assertInstanceOf(SerializableClosure::class, $job->deduplicator);
            $this->assertSame(
                $job->notification->deduplicationId('payload', 'queue'),
                ($job->deduplicator->getClosure())('payload', 'queue')
            );
        });
    }

    public function testAfterSendingMethodAfterSendingNotification(): void
    {
        $container = $this->getContainer();

        $events = new EventFake(new EventsDispatcher);
        $container->instance(Dispatcher::class, $events);
        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);
        $driver = new ChannelSpy;
        $manager->expects('driver')->andReturn($driver);
        $driver->response = $response = new stdClass;

        $manager->send($notifiable = new NotificationChannelManagerTestNotifiable, new NotificationChannelManagerWithAfterSendingMethodNotification);

        $this->assertSame($notifiable, NotificationChannelManagerWithAfterSendingMethodNotification::$afterSendingNotifiable);
        $this->assertSame('test', NotificationChannelManagerWithAfterSendingMethodNotification::$afterSendingChannel);
        $this->assertSame($response, NotificationChannelManagerWithAfterSendingMethodNotification::$afterSendingResponse);

        $events->assertDispatchedOnce(NotificationSending::class);
        $events->assertDispatchedOnce(NotificationDelivered::class);
        $events->assertDispatchedOnce(NotificationSent::class);
    }

    /**
     * Create the notification container.
     */
    protected function getContainer(): Container
    {
        $container = new Container;
        $container->instance(ContainerContract::class, $container);
        $container->instance('config', new ConfigRepository([]));
        $container->instance(BusDispatcherContract::class, m::mock(BusDispatcherContract::class));
        $container->instance(Dispatcher::class, $this->mockEventDispatcher());

        Container::setInstance($container);

        return $container;
    }

    /**
     * Create an event dispatcher that reports listeners by default.
     */
    protected function mockEventDispatcher(): Dispatcher
    {
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->shouldReceive('hasListeners')->byDefault()->andReturn(true);

        return $dispatcher;
    }
}

class TestSendQueuedNotifications implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
}

enum NotificationChannelManagerTestIntIdentifier: int
{
    case Zero = 0;
}

class NotificationChannelManagerTestNotifiable
{
    use Notifiable;
}

class NotificationChannelManagerTestNotification extends Notification
{
    /**
     * Get the notification's delivery channels.
     *
     * @return list<string>
     */
    public function via(): array
    {
        return ['test'];
    }
}

class NotificationChannelManagerTestNotificationWithTwoChannels extends Notification
{
    /**
     * Get the notification's delivery channels.
     *
     * @return list<string>
     */
    public function via(): array
    {
        return ['test', 'test2'];
    }
}

class NotificationChannelManagerTestCancelledNotification extends Notification
{
    /**
     * Get the notification's delivery channels.
     *
     * @return list<string>
     */
    public function via(): array
    {
        return ['test'];
    }

    /**
     * Determine if the notification should be sent.
     */
    public function shouldSend(mixed $notifiable, string $channel): bool
    {
        return false;
    }
}

class NotificationChannelManagerTestNotCancelledNotification extends Notification
{
    /**
     * Get the notification's delivery channels.
     *
     * @return list<string>
     */
    public function via(): array
    {
        return ['test'];
    }

    /**
     * Determine if the notification should be sent.
     */
    public function shouldSend(mixed $notifiable, string $channel): bool
    {
        return true;
    }
}

class NotificationChannelManagerTestQueuedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Get the notification's delivery channels.
     *
     * @return list<string>
     */
    public function via(): array
    {
        return ['test'];
    }
}

class NotificationChannelManagerTestQueuedNotificationWithTwoChannels extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Get the notification's delivery channels.
     *
     * @return list<string>
     */
    public function via(): array
    {
        return ['test', 'test2'];
    }
}

class NotificationChannelManagerTestQueuedNotificationWithMessageGroupMethod extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Get the notification's delivery channels.
     *
     * @return list<string>
     */
    public function via(): array
    {
        return ['test', 'test2'];
    }

    /**
     * Get the notification's message group.
     */
    public function messageGroup(): string
    {
        return 'group-1';
    }
}

class NotificationChannelManagerTestQueuedNotificationWithMessageGroups extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Get the notification's delivery channels.
     *
     * @return list<string>
     */
    public function via(): array
    {
        return ['test', 'test2'];
    }

    /**
     * Get the message group for the notification's channel.
     */
    public function withMessageGroups(mixed $notifiable, string $channel): ?string
    {
        return match ($channel) {
            'test' => 'group-1',
            'test2' => 'group-2',
            default => null,
        };
    }
}

class NotificationChannelManagerTestDeduplicator
{
    /**
     * Get a deduplication ID from the queue and payload.
     */
    public static function resolve(string $payload, string $queue): string
    {
        return $queue . ':' . $payload;
    }
}

class NotificationChannelManagerTestQueuedNotificationWithDeduplicators extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @var array<string, string>
     */
    public array $deduplicatorResults = [
        'test' => 'deduplication-id-1',
        'test2' => 'deduplication-id-2',
    ];

    /**
     * Get the notification's delivery channels.
     *
     * @return list<string>
     */
    public function via(): array
    {
        return ['test', 'test2'];
    }

    /**
     * Get the deduplicator for the notification's channel.
     *
     * @return null|Closure(string, string): string
     */
    public function withDeduplicators(mixed $notifiable, string $channel): ?Closure
    {
        return match ($channel) {
            'test' => fn (string $payload, string $queue): string => $this->deduplicatorResults['test'],
            'test2' => fn (string $payload, string $queue): string => $this->deduplicatorResults['test2'],
            default => null,
        };
    }
}

class NotificationChannelManagerTestQueuedNotificationWithDeduplicationId extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Get the notification's delivery channels.
     *
     * @return list<string>
     */
    public function via(): array
    {
        return ['test', 'test2'];
    }

    /**
     * Get the notification's deduplication ID.
     */
    public function deduplicationId(string $payload, string $queue): string
    {
        return 'deduplication-id-1';
    }
}

class NotificationChannelManagerWithAfterSendingMethodNotification extends Notification
{
    public static mixed $afterSendingNotifiable = null;

    public static ?string $afterSendingChannel = null;

    public static mixed $afterSendingResponse = null;

    /**
     * Get the notification's delivery channels.
     *
     * @return list<string>
     */
    public function via(): array
    {
        return ['test'];
    }

    /**
     * Handle the notification after it has been sent.
     */
    public function afterSending(mixed $notifiable, string $channel, mixed $response): void
    {
        static::$afterSendingNotifiable = $notifiable;
        static::$afterSendingChannel = $channel;
        static::$afterSendingResponse = $response;
    }
}

enum NotificationChannelManagerTestChannelEnum: string
{
    case Test = 'test';
    case Custom = NotificationChannelManagerTestCustomChannel::class;
}

class NotificationChannelManagerTestCustomChannel
{
    /**
     * Send the given notification.
     */
    public function send(mixed $notifiable, Notification $notification): mixed
    {
        return null;
    }
}
