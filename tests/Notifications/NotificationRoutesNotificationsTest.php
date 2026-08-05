<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications;

use Hypervel\Container\Container;
use Hypervel\Contracts\Notifications\Dispatcher;
use Hypervel\Notifications\AnonymousNotifiable;
use Hypervel\Notifications\RoutesNotifications;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use stdClass;

class NotificationRoutesNotificationsTest extends TestCase
{
    public function testNotificationCanBeDispatched(): void
    {
        $container = $this->getContainer();
        $factory = m::mock(Dispatcher::class);
        $container->instance(Dispatcher::class, $factory);
        $notifiable = new RoutesNotificationsTestInstance;
        $instance = new stdClass;
        $factory->shouldReceive('send')->with($notifiable, $instance);

        $notifiable->notify($instance);
    }

    public function testNotificationCanBeSentNow(): void
    {
        $container = $this->getContainer();
        $factory = m::mock(Dispatcher::class);
        $container->instance(Dispatcher::class, $factory);
        $notifiable = new RoutesNotificationsTestInstance;
        $instance = new stdClass;
        $factory->shouldReceive('sendNow')->with($notifiable, $instance, null);

        $notifiable->notifyNow($instance);
    }

    public function testNotificationOptionRouting(): void
    {
        $instance = new RoutesNotificationsTestInstance;
        $this->assertSame('bar', $instance->routeNotificationFor('foo'));
        $this->assertSame('taylor@laravel.com', $instance->routeNotificationFor('mail'));
    }

    public function testOnDemandNotificationsCannotUseDatabaseChannel(): void
    {
        $this->expectExceptionObject(
            new InvalidArgumentException('The database channel does not support on-demand notifications.')
        );

        (new AnonymousNotifiable)->route('database', 'foo');
    }

    public function testAnonymousNotifiableHasNoKey(): void
    {
        $this->assertNull((new AnonymousNotifiable)->getKey());
    }

    protected function getContainer(): Container
    {
        $container = new Container;

        Container::setInstance($container);

        return $container;
    }
}

class RoutesNotificationsTestInstance
{
    use RoutesNotifications;

    protected string $email = 'taylor@laravel.com';

    public function routeNotificationForFoo(): string
    {
        return 'bar';
    }
}
