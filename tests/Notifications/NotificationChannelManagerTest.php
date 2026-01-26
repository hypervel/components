<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications;

use Hyperf\Config\Config;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSource;
use Hypervel\Bus\Queueable;
use Hypervel\Context\ApplicationContext;
use Hypervel\Contracts\Bus\Dispatcher as BusDispatcherContract;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Notifications\ChannelManager;
use Hypervel\Notifications\Channels\MailChannel;
use Hypervel\Notifications\Events\NotificationSending;
use Hypervel\Notifications\Events\NotificationSent;
use Hypervel\Notifications\Notifiable;
use Hypervel\Notifications\Notification;
use Hypervel\Notifications\NotificationPoolProxy;
use Hypervel\Notifications\SendQueuedNotifications;
use Hypervel\ObjectPool\Contracts\Factory as PoolFactory;
use Hypervel\ObjectPool\PoolManager;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @internal
 * @coversNothing
 */
class NotificationChannelManagerTest extends TestCase
{
    public function testGetDefaultChannel()
    {
        $container = $this->getContainer();
        $container->set(MailChannel::class, m::mock(MailChannel::class));

        $manager = new ChannelManager($container);

        $this->assertInstanceOf(MailChannel::class, $manager->channel());
    }

    public function testGetCustomChannelWithPool()
    {
        $container = $this->getContainer();
        $container->set(MailChannel::class, m::mock(MailChannel::class));

        $manager = new ChannelManager($container);
        $manager->extend('test', function () {
            return m::mock('customChannel');
        }, true);

        $this->assertInstanceOf(NotificationPoolProxy::class, $manager->channel('test'));
    }

    public function testNotificationCanBeDispatchedToDriver()
    {
        $container = $this->getContainer();

        $events = $container->get(EventDispatcherInterface::class);

        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);
        $manager->shouldReceive('driver')->andReturn($driver = m::mock());
        $driver->shouldReceive('send')->once();
        $events->shouldReceive('dispatch')->with(m::type(NotificationSending::class))->once();
        $events->shouldReceive('dispatch')->with(m::type(NotificationSent::class))->once();

        $manager->send(new NotificationChannelManagerTestNotifiable(), new NotificationChannelManagerTestNotification());
    }

    public function testNotificationNotSentOnHalt()
    {
        $container = $this->getContainer();

        $events = $container->get(EventDispatcherInterface::class);
        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);
        $events->shouldReceive('dispatch')->once()->with(m::type(NotificationSending::class));
        $manager->shouldReceive('driver')->once()->andReturn($driver = m::mock());
        $driver->shouldReceive('send')->once();
        $events->shouldReceive('dispatch')->once()->with(m::type(NotificationSent::class));

        $manager->send([new NotificationChannelManagerTestNotifiable()], new NotificationChannelManagerTestNotificationWithTwoChannels());
    }

    public function testNotificationNotSentWhenCancelled()
    {
        $container = $this->getContainer();

        $events = $container->get(EventDispatcherInterface::class);
        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);
        $events->shouldReceive('dispatch')->with(m::type(NotificationSending::class));
        $manager->shouldNotReceive('driver');
        $events->shouldNotReceive('dispatch');

        $manager->send([new NotificationChannelManagerTestNotifiable()], new NotificationChannelManagerTestCancelledNotification());
    }

    public function testNotificationSentWhenNotCancelled()
    {
        $container = $this->getContainer();

        $events = $container->get(EventDispatcherInterface::class);
        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);
        $events->shouldReceive('dispatch')->with(m::type(NotificationSending::class));
        $manager->shouldReceive('driver')->once()->andReturn($driver = m::mock());
        $driver->shouldReceive('send')->once();
        $events->shouldReceive('dispatch')->once()->with(m::type(NotificationSent::class));

        $manager->send([new NotificationChannelManagerTestNotifiable()], new NotificationChannelManagerTestNotCancelledNotification());
    }

    public function testNotificationCanBeQueued()
    {
        $container = $this->getContainer();
        $container->get(BusDispatcherContract::class)
            ->shouldReceive('dispatch')
            ->with(m::type(SendQueuedNotifications::class));

        $manager = m::mock(ChannelManager::class . '[driver]', [$container]);

        $manager->send([new NotificationChannelManagerTestNotifiable()], new NotificationChannelManagerTestQueuedNotification());
    }

    protected function getContainer(): Container
    {
        $container = new Container(
            new DefinitionSource([
                ConfigInterface::class => fn () => new Config([]),
                BusDispatcherContract::class => fn () => m::mock(BusDispatcherContract::class),
                EventDispatcherInterface::class => fn () => m::mock(EventDispatcherInterface::class),
                PoolFactory::class => PoolManager::class,
            ])
        );

        ApplicationContext::setContainer($container);

        return $container;
    }
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
    public static bool $shouldSend = true;

    public function via()
    {
        return ['test', 'test2'];
    }

    public function message()
    {
        return $this->line('test')->action('Text', 'url');
    }

    public function shouldSend($notifiable, $channel): bool
    {
        if (static::$shouldSend) {
            static::$shouldSend = false;
            return true;
        }

        return false;
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
