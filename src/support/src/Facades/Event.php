<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Testing\Fakes\EventFake;

/**
 * @method static \Closure createClassListener(array|string $listener, bool $wildcard = false)
 * @method static mixed defer(callable $callback, null|string[] $events = null)
 * @method static mixed dispatch(object|string $event, mixed $payload = [], bool $halt = false)
 * @method static void flush(string $event)
 * @method static void flushMacros()
 * @method static void flushState()
 * @method static void forget(string $event)
 * @method static void forgetPushed()
 * @method static array getListeners(string $eventName)
 * @method static array getObservers(string $eventName)
 * @method static array getRawListeners()
 * @method static bool hasListeners(string $eventName)
 * @method static bool hasMacro(string $name)
 * @method static bool hasWildcardListeners(string $eventName)
 * @method static void listen(\Closure|\Hypervel\Events\QueuedClosure|array|string $events, object|array|string|null $listener = null)
 * @method static void macro(string $name, callable|object $macro)
 * @method static \Closure makeListener(object|array|string $listener, bool $wildcard = false)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static void observe(array|string $events, object|array|string $observer)
 * @method static void push(string $event, mixed $payload = [])
 * @method static string|null resolveConnectionFromQueueRoute(object $queueable)
 * @method static string|null resolveQueueFromQueueRoute(object $queueable)
 * @method static \Hypervel\Events\Dispatcher setQueueResolver(callable $resolver)
 * @method static \Hypervel\Events\Dispatcher setTransactionManagerResolver(callable $resolver)
 * @method static void subscribe(object|string $subscriber)
 * @method static mixed until(object|string $event, mixed $payload = [])
 * @method static void assertDispatched(\Closure|string $event, callable|int|null $callback = null)
 * @method static void assertDispatchedOnce(string $event)
 * @method static void assertDispatchedTimes(string $event, int $times = 1)
 * @method static void assertListening(string $expectedEvent, array|string $expectedListener)
 * @method static void assertNotDispatched(\Closure|string $event, callable|null $callback = null)
 * @method static void assertNothingDispatched()
 * @method static \Hypervel\Support\Collection dispatched(string $event, callable|null $callback = null)
 * @method static array dispatchedEvents()
 * @method static \Hypervel\Support\Testing\Fakes\EventFake except(array|string $eventsToDispatch)
 * @method static bool hasDispatched(string $event)
 *
 * @see \Hypervel\Events\Dispatcher
 * @see \Hypervel\Support\Testing\Fakes\EventFake
 */
class Event extends Facade
{
    /**
     * Replace the bound instance with a fake.
     */
    public static function fake(array|string $eventsToFake = []): EventFake
    {
        $actualDispatcher = static::isFake()
            ? static::getFacadeRoot()->dispatcher
            : static::getFacadeRoot();

        return tap(new EventFake($actualDispatcher, $eventsToFake), function ($fake) {
            static::swap($fake);

            Model::setEventDispatcher($fake);
            Cache::refreshEventDispatcher();
        });
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

        try {
            return $callable();
        } finally {
            static::swap($originalDispatcher);

            Model::setEventDispatcher($originalDispatcher);
            Cache::refreshEventDispatcher();
        }
    }

    /**
     * Replace the bound instance with a fake during the given callable's execution.
     */
    public static function fakeExceptFor(callable $callable, array $eventsToAllow = []): mixed
    {
        $originalDispatcher = static::getFacadeRoot();

        static::fakeExcept($eventsToAllow);

        try {
            return $callable();
        } finally {
            static::swap($originalDispatcher);

            Model::setEventDispatcher($originalDispatcher);
            Cache::refreshEventDispatcher();
        }
    }

    protected static function getFacadeAccessor(): string
    {
        return 'events';
    }
}
