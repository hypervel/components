<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Notifications\AnonymousNotifiable;
use Hypervel\Contracts\Notifications\Dispatcher as NotificationDispatcher;
use Hypervel\Support\Testing\Fakes\NotificationFake;

/**
 * @method static void send(mixed $notifiables, mixed $notification)
 * @method static void sendNow(mixed $notifiables, mixed $notification, array|null $channels = null)
 * @method static mixed channel(string|null $name = null)
 * @method static \Hypervel\Notifications\ChannelManager extend(string $driver, \Closure $callback, bool $poolable = false)
 * @method static \Hypervel\Notifications\ChannelManager setPoolConfig(string $driver, array $config)
 * @method static array getPoolConfig(string $driver)
 * @method static string getDefaultDriver()
 * @method static string deliversVia()
 * @method static void deliverVia(string $channel)
 * @method static \Hypervel\Notifications\ChannelManager locale(string $locale)
 * @method static string|null getLocale()
 * @method static mixed driver(string|null $driver = null)
 * @method static array getDrivers()
 * @method static \Psr\Container\ContainerInterface getContainer()
 * @method static \Hypervel\Notifications\ChannelManager setContainer(\Psr\Container\ContainerInterface $container)
 * @method static \Hypervel\Notifications\ChannelManager forgetDrivers()
 * @method static \Hypervel\Notifications\ChannelManager setReleaseCallback(string $driver, \Closure $callback)
 * @method static \Closure|null getReleaseCallback(string $driver)
 * @method static \Hypervel\Notifications\ChannelManager addPoolable(string $driver)
 * @method static \Hypervel\Notifications\ChannelManager removePoolable(string $driver)
 * @method static array getPoolables()
 * @method static \Hypervel\Notifications\ChannelManager setPoolables(array $poolables)
 * @method static void assertSentOnDemand(\Closure|string $notification, callable|null $callback = null)
 * @method static void assertSentTo(mixed $notifiable, \Closure|string $notification, callable|string|int|null $callback = null)
 * @method static void assertSentOnDemandTimes(string $notification, int $times = 1)
 * @method static void assertSentToTimes(mixed $notifiable, string $notification, int $times = 1)
 * @method static void assertNotSentTo(mixed $notifiable, \Closure|string $notification, callable|null $callback = null)
 * @method static void assertNothingSent()
 * @method static void assertNothingSentTo(mixed $notifiable)
 * @method static void assertSentTimes(string $notification, int $expectedCount)
 * @method static void assertCount(int $expectedCount)
 * @method static \Hypervel\Support\Collection sent(mixed $notifiable, string $notification, callable|null $callback = null)
 * @method static bool hasSent(mixed $notifiable, string $notification)
 * @method static array sentNotifications()
 * @method static void macro(string $name, callable|object $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static bool hasMacro(string $name)
 *
 * @see \Hypervel\Notifications\ChannelManager
 * @see \Hypervel\Support\Testing\Fakes\NotificationFake
 */
class Notification extends Facade
{
    /**
     * Replace the bound instance with a fake.
     */
    public static function fake(): NotificationFake
    {
        return tap(new NotificationFake(), function ($fake) {
            static::swap($fake);
        });
    }

    /**
     * Begin sending a notification to an anonymous notifiable on the given channels.
     */
    public static function routes(array $channels): AnonymousNotifiable
    {
        $notifiable = new AnonymousNotifiable();

        foreach ($channels as $channel => $route) {
            $notifiable->route($channel, $route);
        }

        return $notifiable;
    }

    /**
     * Begin sending a notification to an anonymous notifiable.
     */
    public static function route(string $channel, mixed $route): AnonymousNotifiable
    {
        return (new AnonymousNotifiable())->route($channel, $route);
    }

    protected static function getFacadeAccessor()
    {
        return NotificationDispatcher::class;
    }
}
