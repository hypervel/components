<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications;

use Exception;
use Hypervel\Bus\Queueable;
use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Bus\Dispatcher as BusDispatcherContract;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Notifications\ChannelManager;
use Hypervel\Notifications\Channels\MailChannel;
use Hypervel\Notifications\Events\NotificationFailed;
use Hypervel\Notifications\Events\NotificationSending;
use Hypervel\Notifications\Events\NotificationSent;
use Hypervel\Notifications\Notifiable;
use Hypervel\Notifications\Notification;
use Hypervel\Notifications\NotificationPoolProxy;
use Hypervel\Notifications\NotificationSender;
use Hypervel\Notifications\SendQueuedNotifications;
use Hypervel\ObjectPool\Contracts\Factory as PoolFactory;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\Queue\InteractsWithQueue;
use Hypervel\Queue\QueueRoutes;
use Hypervel\Queue\SerializesModels;
use Laravel\SerializableClosure\SerializableClosure;
use Mockery as m;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class NotificationChannelManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::setInstance(null);

        parent::tearDown();
    }

    public function testGetDefaultChannel()
    {
        $container = $this->getContainer();
        $container->instance(MailChannel::class, m::mock(MailChannel::class));

        $manager = new ChannelManager($container);

        $this->assertInstanceOf(MailChannel::class, $manager->channel());
    }

    public function testGetCustomChannelWithPool()
    {
        $container = $this->getContainer();
        $container->instance(MailChannel::class, m::mock(MailChannel::class));

        $manager = new ChannelManager($container);
        $manager->extend('test', function () {
            return m::mock('customChannel');
        }, true);

        $this->assertInstanceOf(NotificationPoolProxy::class, $manager->channel('test'));
    }

    public function testNotificationCanBeDispatchedToDriver()
    {
        $container = $this->getContainer();

        $events = $container->make(Dispatcher::class);

        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);
        $manager->shouldReceive('driver')->andReturn($driver = m::mock());
        $events->shouldReceive('until')->with(m::type(NotificationSending::class))->andReturn(true);
        $driver->shouldReceive('send')->once();
        $events->shouldReceive('dispatch')->with(m::type(NotificationSent::class));

        $manager->send(new NotificationChannelManagerTestNotifiable(), new NotificationChannelManagerTestNotification());
    }

    public function testNotificationNotSentOnHalt()
    {
        $container = $this->getContainer();

        $events = $container->make(Dispatcher::class);
        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);
        $events->shouldReceive('until')->once()->with(m::type(NotificationSending::class))->andReturn(false);
        $events->shouldReceive('until')->with(m::type(NotificationSending::class))->andReturn(true);
        $manager->shouldReceive('driver')->once()->andReturn($driver = m::mock());
        $driver->shouldReceive('send')->once();
        $events->shouldReceive('dispatch')->with(m::type(NotificationSent::class));

        $manager->send([new NotificationChannelManagerTestNotifiable()], new NotificationChannelManagerTestNotificationWithTwoChannels());
    }

    public function testNotificationNotSentWhenCancelled()
    {
        $container = $this->getContainer();

        $events = $container->make(Dispatcher::class);
        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);
        $events->shouldReceive('until')->with(m::type(NotificationSending::class))->andReturn(true);
        $manager->shouldNotReceive('driver');
        $events->shouldNotReceive('dispatch');

        $manager->send([new NotificationChannelManagerTestNotifiable()], new NotificationChannelManagerTestCancelledNotification());
    }

    public function testNotificationSentWhenNotCancelled()
    {
        $container = $this->getContainer();

        $events = $container->make(Dispatcher::class);
        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);
        $events->shouldReceive('until')->with(m::type(NotificationSending::class))->andReturn(true);
        $manager->shouldReceive('driver')->once()->andReturn($driver = m::mock());
        $driver->shouldReceive('send')->once();
        $events->shouldReceive('dispatch')->once()->with(m::type(NotificationSent::class));

        $manager->send([new NotificationChannelManagerTestNotifiable()], new NotificationChannelManagerTestNotCancelledNotification());
    }

    public function testNotificationNotSentWhenFailed()
    {
        $this->expectException(Exception::class);

        $container = $this->getContainer();

        $events = $container->make(Dispatcher::class);
        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);
        $manager->shouldReceive('driver')->andReturn($driver = m::mock());
        $driver->shouldReceive('send')->andThrow(new Exception());
        $events->shouldReceive('until')->with(m::type(NotificationSending::class))->andReturn(true);
        $events->shouldReceive('dispatch')->once()->with(m::type(NotificationFailed::class));
        $events->shouldReceive('dispatch')->never()->with(m::type(NotificationSent::class));

        $manager->send(new NotificationChannelManagerTestNotifiable(), new NotificationChannelManagerTestNotification());
    }

    public function testNotificationFailedDispatchedOnlyOnceWhenFailed()
    {
        $this->expectException(Exception::class);

        $container = $this->getContainer();

        $events = $container->make(Dispatcher::class);
        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);
        $manager->shouldReceive('driver')->andReturn($driver = m::mock());
        $driver->shouldReceive('send')->andReturnUsing(function ($notifiable, $notification) use ($events) {
            $events->dispatch(new NotificationFailed($notifiable, $notification, 'test'));
            throw new Exception();
        });
        $events->shouldReceive('until')->with(m::type(NotificationSending::class))->andReturn(true);
        // Simulate boot-time listener: when NotificationFailed is dispatched, set Context flag
        $events->shouldReceive('dispatch')->once()->with(m::type(NotificationFailed::class))->andReturnUsing(function () {
            CoroutineContext::set(NotificationSender::FAILED_EVENT_DISPATCHED_CONTEXT_KEY, true);
        });
        $events->shouldReceive('dispatch')->never()->with(m::type(NotificationSent::class));

        $manager->send(new NotificationChannelManagerTestNotifiable(), new NotificationChannelManagerTestNotification());
    }

    public function testNotificationFailedDispatchedOnlyOnceWhenMultipleFailed()
    {
        $this->expectException(Exception::class);

        $container = $this->getContainer();

        $events = $container->make(Dispatcher::class);
        $manager = $container->make(ChannelManager::class, ['container' => $container]);
        $manager->extend('test', function () use ($events) {
            return new class($events) {
                private int $count = 0;

                public function __construct(private readonly Dispatcher $events)
                {
                }

                public function send(mixed $notifiable, Notification $notification): void
                {
                    if ($this->count > 1) {
                        throw new Exception();
                    }

                    ++$this->count;
                }
            };
        });
        $events->shouldReceive('until')->with(m::type(NotificationSending::class))->andReturn(true);
        // Simulate boot-time listener: when NotificationFailed is dispatched, set Context flag
        $events->shouldReceive('dispatch')->once()->with(m::type(NotificationFailed::class))->andReturnUsing(function () {
            CoroutineContext::set(NotificationSender::FAILED_EVENT_DISPATCHED_CONTEXT_KEY, true);
        });
        $events->shouldReceive('dispatch')->twice()->with(m::type(NotificationSent::class));

        $manager->send(new NotificationChannelManagerTestNotifiable(), new NotificationChannelManagerTestNotification());
        $manager->send(new NotificationChannelManagerTestNotifiable(), new NotificationChannelManagerTestNotification());
        $manager->send(new NotificationChannelManagerTestNotifiable(), new NotificationChannelManagerTestNotification());
    }

    public function testNotificationCanBeQueued()
    {
        $container = $this->getContainer();
        $container->instance(QueueRoutes::class, $queueRoutes = m::mock(QueueRoutes::class));
        $queueRoutes->shouldReceive('getQueue')->andReturn(null);
        $queueRoutes->shouldReceive('getConnection')->andReturn(null);
        $container->instance('queue.routes', $queueRoutes);
        $container->make(BusDispatcherContract::class)
            ->shouldReceive('dispatch')
            ->with(m::type(SendQueuedNotifications::class));

        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);

        $manager->send([new NotificationChannelManagerTestNotifiable()], new NotificationChannelManagerTestQueuedNotification());
    }

    public function testSendQueuedNotificationsCanBeOverrideViaContainer()
    {
        $container = $this->getContainer();
        $container->instance(QueueRoutes::class, $queueRoutes = m::mock(QueueRoutes::class));
        $queueRoutes->shouldReceive('getQueue')->andReturn(null);
        $queueRoutes->shouldReceive('getConnection')->andReturn(null);
        $container->instance('queue.routes', $queueRoutes);
        $container->make(BusDispatcherContract::class)
            ->shouldReceive('dispatch')
            ->with(m::type(TestSendQueuedNotifications::class));
        $container->bind(SendQueuedNotifications::class, TestSendQueuedNotifications::class);

        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);

        $manager->send([new NotificationChannelManagerTestNotifiable()], new NotificationChannelManagerTestQueuedNotification());
    }

    public function testQueuedNotificationForwardsMessageGroupFromMethodToQueueJob()
    {
        $mockedMessageGroupId = 'group-1';

        $notification = $this->getMockBuilder(NotificationChannelManagerTestQueuedNotificationWithMessageGroupMethod::class)->onlyMethods(['messageGroup'])->getMock();
        $notification->expects($this->exactly(2))->method('messageGroup')->willReturn($mockedMessageGroupId);

        $container = $this->getContainer();
        $container->instance(QueueRoutes::class, $queueRoutes = m::mock(QueueRoutes::class));
        $queueRoutes->shouldReceive('getQueue')->andReturn(null);
        $queueRoutes->shouldReceive('getConnection')->andReturn(null);
        $container->instance('queue.routes', $queueRoutes);
        $container->make(BusDispatcherContract::class)
            ->shouldReceive('dispatch')->twice()->withArgs(function ($job) use ($mockedMessageGroupId) {
                $this->assertInstanceOf(SendQueuedNotifications::class, $job);
                $this->assertEquals($mockedMessageGroupId, $job->messageGroup);

                return true;
            });

        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);

        $manager->send([new NotificationChannelManagerTestNotifiable()], $notification);
    }

    public function testQueuedNotificationForwardsMessageGroupFromPropertyOverridingMethodToQueueJob()
    {
        $mockedMessageGroupId = 'group-1';

        // Ensure the messageGroup method is not called when a messageGroup property is provided.
        $notification = $this->getMockBuilder(NotificationChannelManagerTestQueuedNotificationWithMessageGroupMethod::class)->onlyMethods(['messageGroup'])->getMock();
        $notification->expects($this->never())->method('messageGroup')->willReturn('this-should-not-be-used');
        $notification->onGroup($mockedMessageGroupId);

        $container = $this->getContainer();
        $container->instance(QueueRoutes::class, $queueRoutes = m::mock(QueueRoutes::class));
        $queueRoutes->shouldReceive('getQueue')->andReturn(null);
        $queueRoutes->shouldReceive('getConnection')->andReturn(null);
        $container->instance('queue.routes', $queueRoutes);
        $container->make(BusDispatcherContract::class)
            ->shouldReceive('dispatch')->twice()->withArgs(function ($job) use ($mockedMessageGroupId) {
                $this->assertInstanceOf(SendQueuedNotifications::class, $job);
                $this->assertEquals($mockedMessageGroupId, $job->messageGroup);

                return true;
            });

        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);

        $manager->send([new NotificationChannelManagerTestNotifiable()], $notification);
    }

    public function testQueuedNotificationForwardsMessageGroupSetToQueueJob()
    {
        $mockedMessageGroupSet = [
            'test' => 'group-1',
            'test2' => 'group-2',
        ];

        $container = $this->getContainer();
        $container->instance(QueueRoutes::class, $queueRoutes = m::mock(QueueRoutes::class));
        $queueRoutes->shouldReceive('getQueue')->andReturn(null);
        $queueRoutes->shouldReceive('getConnection')->andReturn(null);
        $container->instance('queue.routes', $queueRoutes);
        $container->make(BusDispatcherContract::class)
            ->shouldReceive('dispatch')->twice()->withArgs(function ($job) use ($mockedMessageGroupSet) {
                $this->assertInstanceOf(SendQueuedNotifications::class, $job);
                $this->assertEquals($mockedMessageGroupSet[$job->channels[0]], $job->messageGroup);

                return true;
            });

        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);

        $notification = (new NotificationChannelManagerTestQueuedNotificationWithTwoChannels())->onGroup($mockedMessageGroupSet);
        $manager->send([new NotificationChannelManagerTestNotifiable()], $notification);
    }

    public function testQueuedNotificationForwardsMessageGroupSetFromClassToQueueJob()
    {
        $mockedMessageGroupSet = [
            'test' => 'group-1',
            'test2' => 'group-2',
        ];

        $container = $this->getContainer();
        $container->instance('queue.routes', $queueRoutes = m::mock(QueueRoutes::class));
        $queueRoutes->shouldReceive('getQueue')->andReturn(null);
        $queueRoutes->shouldReceive('getConnection')->andReturn(null);
        $container->make(BusDispatcherContract::class)
            ->shouldReceive('dispatch')->twice()->withArgs(function ($job) use ($mockedMessageGroupSet) {
                $this->assertInstanceOf(SendQueuedNotifications::class, $job);
                $this->assertEquals($mockedMessageGroupSet[$job->channels[0]], $job->messageGroup);

                return true;
            });

        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);

        $notification = new NotificationChannelManagerTestQueuedNotificationWithMessageGroups();
        $manager->send([new NotificationChannelManagerTestNotifiable()], $notification);
    }

    public function testQueuedNotificationForwardsDeduplicatorToQueueJob()
    {
        $mockedDeduplicator = fn ($payload, $queue) => 'deduplication-id-1';

        $container = $this->getContainer();
        $container->instance('queue.routes', $queueRoutes = m::mock(QueueRoutes::class));
        $queueRoutes->shouldReceive('getQueue')->andReturn(null);
        $queueRoutes->shouldReceive('getConnection')->andReturn(null);
        $container->make(BusDispatcherContract::class)
            ->shouldReceive('dispatch')->once()->withArgs(function ($job) use ($mockedDeduplicator) {
                $this->assertInstanceOf(SendQueuedNotifications::class, $job);
                $this->assertInstanceOf(SerializableClosure::class, $job->deduplicator);
                $this->assertEquals($mockedDeduplicator, $job->deduplicator->getClosure());

                return true;
            });

        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);

        $notification = (new NotificationChannelManagerTestQueuedNotification())->withDeduplicator($mockedDeduplicator);
        $manager->send([new NotificationChannelManagerTestNotifiable()], $notification);
    }

    public function testQueuedNotificationForwardsDeduplicatorSetToQueueJob()
    {
        $mockedDeduplicatorSet = [
            'test' => fn ($payload, $queue) => 'deduplication-id-1',
            'test2' => fn ($payload, $queue) => 'deduplication-id-2',
        ];

        $container = $this->getContainer();
        $container->instance('queue.routes', $queueRoutes = m::mock(QueueRoutes::class));
        $queueRoutes->shouldReceive('getQueue')->andReturn(null);
        $queueRoutes->shouldReceive('getConnection')->andReturn(null);
        $container->make(BusDispatcherContract::class)
            ->shouldReceive('dispatch')->twice()->withArgs(function ($job) use ($mockedDeduplicatorSet) {
                $this->assertInstanceOf(SendQueuedNotifications::class, $job);
                $this->assertInstanceOf(SerializableClosure::class, $job->deduplicator);
                $this->assertEquals($mockedDeduplicatorSet[$job->channels[0]], $job->deduplicator->getClosure());

                return true;
            });

        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);

        $notification = (new NotificationChannelManagerTestQueuedNotificationWithTwoChannels())->withDeduplicator($mockedDeduplicatorSet);
        $manager->send([new NotificationChannelManagerTestNotifiable()], $notification);
    }

    public function testQueuedNotificationForwardsDeduplicatorSetFromClassToQueueJob()
    {
        $container = $this->getContainer();
        $container->instance('queue.routes', $queueRoutes = m::mock(QueueRoutes::class));
        $queueRoutes->shouldReceive('getQueue')->andReturn(null);
        $queueRoutes->shouldReceive('getConnection')->andReturn(null);
        $container->make(BusDispatcherContract::class)
            ->shouldReceive('dispatch')->twice()->withArgs(function ($job) {
                $this->assertInstanceOf(SendQueuedNotifications::class, $job);
                $this->assertEquals($job->notification->deduplicatorResults[$job->channels[0]], call_user_func($job->deduplicator, '', null));

                return true;
            });

        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);

        $notification = new NotificationChannelManagerTestQueuedNotificationWithDeduplicators();
        $manager->send([new NotificationChannelManagerTestNotifiable()], $notification);
    }

    public function testQueuedNotificationForwardsDeduplicationIdMethodToQueueJob()
    {
        $container = $this->getContainer();
        $container->instance('queue.routes', $queueRoutes = m::mock(QueueRoutes::class));
        $queueRoutes->shouldReceive('getQueue')->andReturn(null);
        $queueRoutes->shouldReceive('getConnection')->andReturn(null);
        $container->make(BusDispatcherContract::class)
            ->shouldReceive('dispatch')->twice()->withArgs(function ($job) {
                $this->assertInstanceOf(SendQueuedNotifications::class, $job);
                $this->assertInstanceOf(SerializableClosure::class, $job->deduplicator);
                $this->assertEquals($job->notification->deduplicationId(...), $job->deduplicator->getClosure());

                return true;
            });

        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);

        $notification = new NotificationChannelManagerTestQueuedNotificationWithDeduplicationId();
        $manager->send([new NotificationChannelManagerTestNotifiable()], $notification);
    }

    public function testAfterSendingMethodAfterSendingNotification()
    {
        $container = $this->getContainer();

        $events = $container->make(Dispatcher::class);
        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);
        $manager->shouldReceive('driver')->andReturn($driver = m::mock());
        $events->shouldReceive('until')->with(m::type(NotificationSending::class))->andReturn(true);
        $driver->shouldReceive('send')->once()->andReturn($response = m::mock());
        $events->shouldReceive('dispatch')->with(m::type(NotificationSent::class));

        $manager->send($notifiable = new NotificationChannelManagerTestNotifiable(), new NotificationChannelManagerWithAfterSendingMethodNotification());

        $this->assertSame($notifiable, NotificationChannelManagerWithAfterSendingMethodNotification::$afterSendingNotifiable);
        $this->assertSame('test', NotificationChannelManagerWithAfterSendingMethodNotification::$afterSendingChannel);
        $this->assertSame($response, NotificationChannelManagerWithAfterSendingMethodNotification::$afterSendingResponse);
    }

    protected function getContainer(): Container
    {
        $container = new Container();
        $container->instance(\Hypervel\Contracts\Container\Container::class, $container);
        $container->instance('config', new ConfigRepository([]));
        $container->instance(BusDispatcherContract::class, m::mock(BusDispatcherContract::class));
        $container->instance(Dispatcher::class, m::mock(Dispatcher::class));
        $container->singleton(PoolFactory::class, PoolManager::class);

        Container::setInstance($container);

        return $container;
    }
}

class TestSendQueuedNotifications implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
}

class NotificationChannelManagerTestNotifiable
{
    use Notifiable;
}

class NotificationChannelManagerTestNotification extends Notification
{
    public function via()
    {
        return ['test'];
    }

    public function message()
    {
        return $this->line('test')->action('Text', 'url');
    }
}

class NotificationChannelManagerTestNotificationWithTwoChannels extends Notification
{
    public function via()
    {
        return ['test', 'test2'];
    }

    public function message()
    {
        return $this->line('test')->action('Text', 'url');
    }
}

class NotificationChannelManagerTestCancelledNotification extends Notification
{
    public function via()
    {
        return ['test'];
    }

    public function message()
    {
        return $this->line('test')->action('Text', 'url');
    }

    public function shouldSend($notifiable, $channel)
    {
        return false;
    }
}

class NotificationChannelManagerTestNotCancelledNotification extends Notification
{
    public function via()
    {
        return ['test'];
    }

    public function message()
    {
        return $this->line('test')->action('Text', 'url');
    }

    public function shouldSend($notifiable, $channel)
    {
        return true;
    }
}

class NotificationChannelManagerTestQueuedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via()
    {
        return ['test'];
    }

    public function message()
    {
        return $this->line('test')->action('Text', 'url');
    }
}

class NotificationChannelManagerTestQueuedNotificationWithTwoChannels extends Notification implements ShouldQueue
{
    use Queueable;

    public function via()
    {
        return ['test', 'test2'];
    }

    public function message()
    {
        return $this->line('test')->action('Text', 'url');
    }
}

class NotificationChannelManagerTestQueuedNotificationWithMessageGroupMethod extends Notification implements ShouldQueue
{
    use Queueable;

    public function via()
    {
        return ['test', 'test2'];
    }

    public function message()
    {
        return $this->line('test')->action('Text', 'url');
    }

    public function messageGroup()
    {
        return 'group-1';
    }
}

class NotificationChannelManagerTestQueuedNotificationWithMessageGroups extends Notification implements ShouldQueue
{
    use Queueable;

    public function via()
    {
        return ['test', 'test2'];
    }

    public function message()
    {
        return $this->line('test')->action('Text', 'url');
    }

    public function withMessageGroups($notifiable, $channel)
    {
        return match ($channel) {
            'test' => 'group-1',
            'test2' => 'group-2',
            default => null,
        };
    }
}

class NotificationChannelManagerTestQueuedNotificationWithDeduplicators extends Notification implements ShouldQueue
{
    use Queueable;

    public array $deduplicatorResults = [
        'test' => 'deduplication-id-1',
        'test2' => 'deduplication-id-2',
    ];

    public function via()
    {
        return ['test', 'test2'];
    }

    public function message()
    {
        return $this->line('test')->action('Text', 'url');
    }

    public function withDeduplicators($notifiable, $channel)
    {
        return match ($channel) {
            'test' => fn ($payload, $queue) => $this->deduplicatorResults['test'],
            'test2' => fn ($payload, $queue) => $this->deduplicatorResults['test2'],
            default => null,
        };
    }
}

class NotificationChannelManagerTestQueuedNotificationWithDeduplicationId extends Notification implements ShouldQueue
{
    use Queueable;

    public function via()
    {
        return ['test', 'test2'];
    }

    public function message()
    {
        return $this->line('test')->action('Text', 'url');
    }

    public function deduplicationId($payload, $queue)
    {
        return 'deduplication-id-1';
    }
}

class NotificationChannelManagerWithAfterSendingMethodNotification extends Notification
{
    public static mixed $afterSendingNotifiable = null;

    public static ?string $afterSendingChannel = null;

    public static mixed $afterSendingResponse = null;

    public function via()
    {
        return ['test'];
    }

    public function afterSending($notifiable, $channel, $response)
    {
        static::$afterSendingNotifiable = $notifiable;
        static::$afterSendingChannel = $channel;
        static::$afterSendingResponse = $response;
    }
}
