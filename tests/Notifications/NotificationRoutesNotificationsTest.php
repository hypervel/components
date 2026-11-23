<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications;

use Hypervel\Container\Container;
use Hypervel\Contracts\Notifications\Dispatcher;
use Hypervel\Notifications\AnonymousNotifiable;
use Hypervel\Notifications\RoutesNotifications;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use stdClass;

class NotificationRoutesNotificationsTest extends TestCase
{
    use VerifiesDoubles;

    public function testNotificationCanBeDispatched(): void
    {
        $container = $this->getContainer();
        $factory = Double::for(Dispatcher::class)->strict();
        $container->instance(Dispatcher::class, $factory);
        $notifiable = new RoutesNotificationsTestInstance;
        $instance = new stdClass;
        $factory->expects('send')->with(Argument::same($notifiable), Argument::same($instance));

        $notifiable->notify($instance);
    }

    public function testNotificationCanBeSentNow(): void
    {
        $container = $this->getContainer();
        $factory = Double::for(Dispatcher::class)->strict();
        $container->instance(Dispatcher::class, $factory);
        $notifiable = new RoutesNotificationsTestInstance;
        $instance = new stdClass;
        $factory->expects('sendNow')->with(Argument::same($notifiable), Argument::same($instance), null);

        $notifiable->notifyNow($instance);
    }

    public function testNotificationOptionRouting(): void
    {
        $instance = new RoutesNotificationsTestInstance;
        $this->assertSame('bar', $instance->routeNotificationFor('foo'));
        $this->assertSame('taylor@hypervel.com', $instance->routeNotificationFor('mail'));
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

    /**
     * Create the notification container.
     */
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

    protected string $email = 'taylor@hypervel.com';

    /**
     * Route notifications for the foo channel.
     */
    public function routeNotificationForFoo(): string
    {
        return 'bar';
    }
}
