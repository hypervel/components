<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Testing\Fakes\EventFake;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @method static object|string dispatch(object|string $event, mixed $payload = [], bool $halt = false)
 * @method static void listen(\Closure|\Hypervel\Event\QueuedClosure|array|string $events, \Closure|\Hypervel\Event\QueuedClosure|array|string|int|null $listener = null, int $priority = 0)
 * @method static object|string until(object|string $event, mixed $payload = [])
 * @method static iterable getListeners(object|string $eventName)
 * @method static void push(string $event, mixed $payload = [])
 * @method static void flush(string $event)
 * @method static void forgetPushed()
 * @method static void forget(string $event)
 * @method static bool hasListeners(string $eventName)
 * @method static bool hasWildcardListeners(string $eventName)
 * @method static \Hypervel\Event\EventDispatcher setQueueResolver(callable $resolver)
 * @method static \Hypervel\Event\EventDispatcher setTransactionManagerResolver(callable $resolver)
 * @method static void subscribe(object|string $subscriber)
 * @method static array getRawListeners()
 * @method static mixed defer(callable $callback, array|null $events = null)
 * @method static \Hypervel\Support\Testing\Fakes\EventFake except(array|string $eventsToDispatch)
 * @method static void assertListening(string $expectedEvent, string $expectedListener)
 * @method static void assertDispatched(\Closure|string $event, callable|int|null $callback = null)
 * @method static void assertDispatchedTimes(string $event, int $times = 1)
 * @method static void assertNotDispatched(\Closure|string $event, callable|null $callback = null)
 * @method static void assertNothingDispatched()
 * @method static \Hypervel\Support\Collection dispatched(string $event, callable|null $callback = null)
 * @method static bool hasDispatched(string $event)
 * @method static array dispatchedEvents()
 *
 * @see \Hypervel\Event\EventDispatcher
 * @see \Hypervel\Support\Testing\Fakes\EventFake
 */
class Event extends Facade
{
    /**
     * Replace the bound instance with a fake.
     */
    public static function fake(array|string $eventsToFake = []): EventFake
    {
        static::swap($fake = new EventFake(static::getFacadeRoot(), $eventsToFake));

        Model::setEventDispatcher($fake);
        Cache::refreshEventDispatcher();

        return $fake;
    }

    /**
     * Replace the bound instance with a fake that fakes all events except the given events.
     */
    public static function fakeExcept(array|string $eventsToAllow): EventFake
    {
        return static::fake([
            function ($eventName) use ($eventsToAllow) {
                return ! in_array($eventName, (array) $eventsToAllow);
            },
        ]);
    }

    /**
     * Replace the bound instance with a fake during the given callable's execution.
     */
    public static function fakeFor(callable $callable, array $eventsToFake = []): mixed
    {
        $originalDispatcher = static::getFacadeRoot();

        static::fake($eventsToFake);

        return tap($callable(), function () use ($originalDispatcher) {
            static::swap($originalDispatcher);

            Model::setEventDispatcher($originalDispatcher);
            Cache::refreshEventDispatcher();
        });
    }

    /**
     * Replace the bound instance with a fake during the given callable's execution.
     */
    public static function fakeExceptFor(callable $callable, array $eventsToAllow = []): mixed
    {
        $originalDispatcher = static::getFacadeRoot();

        static::fakeExcept($eventsToAllow);

        return tap($callable(), function () use ($originalDispatcher) {
            static::swap($originalDispatcher);

            Model::setEventDispatcher($originalDispatcher);
            Cache::refreshEventDispatcher();
        });
    }

    protected static function getFacadeAccessor(): string
    {
        // Must use the canonical binding key (registered by ConfigProvider), not an alias.
        // Facade::swap() calls instance() with this key, and rebinding callbacks are
        // stored under the alias-resolved key. Using an alias here causes a mismatch.
        // @TODO Change to 'events' once we migrate to Laravel-style ServiceProviders.
        return EventDispatcherInterface::class;
    }
}
