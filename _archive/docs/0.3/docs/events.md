## Introduction

Hypervel's events provide a simple observer pattern implementation, allowing you to subscribe and listen for various events that occur within your application. Event classes are typically stored in the `app/Events` directory, while their listeners are stored in `app/Listeners`. Don't worry if you don't see these directories in your application as they will be created for you as you generate events and listeners using Artisan console commands.

Events serve as a great way to decouple various aspects of your application, since a single event can have multiple listeners that do not depend on each other. For example, you may wish to send a Slack notification to your user each time an order has shipped. Instead of coupling your order processing code to your Slack notification code, you can raise an `App\Events\OrderShipped` event which a listener can receive and use to dispatch a Slack notification.

::: note
Hypervel's events provide similar functionality to Laravel's events. To keep compatibility with Hyperf's events, events in Hypervel is built on Hyperf's event component.
:::

## Registering Events and Listeners

The `App\Providers\EventServiceProvider` included with your Hypervel application provides a convenient place to register all of your application's event listeners. The `listen` property contains an array of all events (keys) and their listeners (values). You may add as many events to this array as your application requires. For example, let's add an `OrderShipped` event:

```php
use App\Events\OrderShipped;
use App\Listeners\SendShipmentNotification;

/**
 * The event listener mappings for the application.
 *
 * @var array<class-string, array<int, class-string>>
 */
protected array $listen = [
    OrderShipped::class => [
        SendShipmentNotification::class,
    ],
];
```

::: note
The `event:list` command may be used to display a list of all events and listeners registered by your application.
:::

### Generating Events and Listeners

You may use the `make:event` and `make:listener` Artisan commands to generate individual events and listeners:

```shell
php artisan make:event PodcastProcessed

php artisan make:listener SendPodcastNotification
```

### Manually Registering Events

Typically, events should be registered via the `EventServiceProvider` `$listen` array; however, you may also register class or closure based event listeners manually in the `boot` method of your `EventServiceProvider`:

```php
use App\Events\PodcastProcessed;
use App\Listeners\SendPodcastNotification;
use Hypervel\Support\Facades\Event;

/**
 * Register any other events for your application.
 */
public function boot(): void
{
    Event::listen(
        PodcastProcessed::class,
        SendPodcastNotification::class,
    );

    Event::listen(function (PodcastProcessed $event) {
        // ...
    });
}
```

#### Queueable Anonymous Event Listeners

When registering closure based event listeners manually, you may wrap the listener closure within the `Hypervel\Event\queueable` function to instruct Hypervel to execute the listener using the [queue](/docs/queues):

```php
use App\Events\PodcastProcessed;
use function Hypervel\Events\queueable;
use Hypervel\Support\Facades\Event;

/**
 * Register any other events for your application.
 */
public function boot(): void
{
    Event::listen(queueable(function (PodcastProcessed $event) {
        // ...
    }));
}
```

Like queued jobs, you may use the `onConnection`, `onQueue`, and `delay` methods to customize the execution of the queued listener:

```php
Event::listen(queueable(function (PodcastProcessed $event) {
    // ...
})->onConnection('redis')->onQueue('podcasts')->delay(now()->addSeconds(10)));
```

If you would like to handle anonymous queued listener failures, you may provide a closure to the `catch` method while defining the `queueable` listener. This closure will receive the event instance and the `Throwable` instance that caused the listener's failure:

```php
use App\Events\PodcastProcessed;
use function Hypervel\Events\queueable;
use Hypervel\Support\Facades\Event;
use Throwable;

Event::listen(queueable(function (PodcastProcessed $event) {
    // ...
})->catch(function (PodcastProcessed $event, Throwable $e) {
    // The queued listener failed...
}));
```

#### Wildcard Event Listeners

You may even register listeners using the `*` as a wildcard parameter, allowing you to catch multiple events on the same listener. Wildcard listeners receive the event name as their first argument and the entire event data array as their second argument:

```php
Event::listen('event.*', function (string $eventName, array $data) {
    // ...
});
```

## Defining Events

An event class is essentially a data container which holds the information related to the event. For example, let's assume an `App\Events\OrderShipped` event receives an [Eloquent ORM](/docs/eloquent) object:

```php
<?php

namespace App\Events;

use App\Models\Order;
use Hypervel\Bus\Dispatchable;

class OrderShipped
{
    use Dispatchable;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Order $order,
    ) {}
}
```

As you can see, this event class contains no logic. It is a container for the `App\Models\Order` instance that was purchased.

## Defining Listeners

Next, let's take a look at the listener for our example event. Event listeners receive event instances in their `handle` method. The `event:generate` and `make:listener` Artisan commands will automatically import the proper event class and type-hint the event on the `handle` method. Within the `handle` method, you may perform any actions necessary to respond to the event:

```php
<?php

namespace App\Listeners;

use App\Events\OrderShipped;

class SendShipmentNotification
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        // ...
    }

    /**
     * Handle the event.
     */
    public function handle(OrderShipped $event): void
    {
        // Access the order using $event->order...
    }
}
```

::: note
Your event listeners may also type-hint any dependencies they need on their constructors. All event listeners are resolved via the Hypervel [service container](/docs/container), so dependencies will be injected automatically.
:::

#### Stopping The Propagation Of An Event

Sometimes, you may wish to stop the propagation of an event to other listeners. You may do so by returning `false` from your listener's `handle` method.

## Queued Event Listeners

Queueing listeners can be beneficial if your listener is going to perform a slow task such as sending an email or making an HTTP request. Before using queued listeners, make sure to [configure your queue](/docs/queues) and start a queue worker on your server or local development environment.

To specify that a listener should be queued, add the `ShouldQueue` interface to the listener class. Listeners generated by the `make:listener` Artisan command already have this interface imported into the current namespace so you can use it immediately:

```php
<?php

namespace App\Listeners;

use App\Events\OrderShipped;
use Hypervel\Queue\Contracts\ShouldQueue;

class SendShipmentNotification implements ShouldQueue
{
    // ...
}
```

That's it! Now, when an event handled by this listener is dispatched, the listener will automatically be queued by the event dispatcher using Hypervel's [queue system](/docs/queues). If no exceptions are thrown when the listener is executed by the queue, the queued job will automatically be deleted after it has finished processing.

#### Customizing The Queue Connection, Name, & Delay

If you would like to customize the queue connection, queue name, or queue delay time of an event listener, you may define the `$connection`, `$queue`, or `$delay` properties on your listener class:

```php
<?php

namespace App\Listeners;

use App\Events\OrderShipped;
use Hypervel\Queue\Contracts\ShouldQueue;

class SendShipmentNotification implements ShouldQueue
{
    /**
     * The name of the connection the job should be sent to.
     */
    public ?string $connection = 'redis';

    /**
     * The name of the queue the job should be sent to.
     */
    public ?string $queue = 'listeners';

    /**
     * The time (seconds) before the job should be processed.
     */
    public int $delay = 60;
}
```

If you would like to define the listener's queue connection, queue name, or delay at runtime, you may define `viaConnection`, `viaQueue`, or `withDelay` methods on the listener:

```php
/**
 * Get the name of the listener's queue connection.
 */
public function viaConnection(): string
{
    return 'redis';
}

/**
 * Get the name of the listener's queue.
 */
public function viaQueue(): string
{
    return 'listeners';
}

/**
 * Get the number of seconds before the job should be processed.
 */
public function withDelay(OrderShipped $event): int
{
    return $event->highPriority ? 0 : 60;
}
```

#### Conditionally Queueing Listeners

Sometimes, you may need to determine whether a listener should be queued based on some data that are only available at runtime. To accomplish this, a `shouldQueue` method may be added to a listener to determine whether the listener should be queued. If the `shouldQueue` method returns `false`, the listener will not be executed:

```php
<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use Hypervel\Queue\Contracts\ShouldQueue;

class RewardGiftCard implements ShouldQueue
{
    /**
     * Reward a gift card to the customer.
     */
    public function handle(OrderCreated $event): void
    {
        // ...
    }

    /**
     * Determine whether the listener should be queued.
     */
    public function shouldQueue(OrderCreated $event): bool
    {
        return $event->order->subtotal >= 5000;
    }
}
```

## Dispatching Events

To dispatch an event, you may call the static `dispatch` method on the event. This method is made available on the event by the `Hypervel\Bus\Dispatchable` trait. Any arguments passed to the `dispatch` method will be passed to the event's constructor:

```php
<?php

namespace App\Http\Controllers;

use App\Events\OrderShipped;
use App\Http\Controllers\Controller;
use App\Models\Order;
use Psr\Http\Message\ResponseInterface;
use Hypervel\Http\Request;

class OrderShipmentController extends Controller
{
    /**
     * Ship the given order.
     */
    public function store(Request $request): ResponseInterface
    {
        $order = Order::findOrFail($request->order_id);

        // Order shipment logic...

        OrderShipped::dispatch($order);

        return redirect('/orders');
    }
}
```

 If you would like to conditionally dispatch an event, you may use the `dispatchIf` and `dispatchUnless` methods:

```php
OrderShipped::dispatchIf($condition, $order);

OrderShipped::dispatchUnless($condition, $order);
```

::: note
When testing, it can be helpful to assert that certain events were dispatched without actually triggering their listeners. Hypervel's [built-in testing helpers](#testing) make it a cinch.
:::

### Dispatching Events After Database Transactions

Sometimes, you may want to instruct Hypervel to only dispatch an event after the active database transaction has committed. To do so, you may implement the `ShouldDispatchAfterCommit` interface on the event class.

This interface instructs Hypervel to not dispatch the event until the current database transaction is committed. If the transaction fails, the event will be discarded. If no database transaction is in progress when the event is dispatched, the event will be dispatched immediately:

```php
<?php

namespace App\Events;

use App\Models\Order;
use Hypervel\Broadcasting\InteractsWithSockets;
use Hypervel\Events\Contracts\ShouldDispatchAfterCommit;
use Hypervel\Bus\Dispatchable;
use Hypervel\Queue\SerializesModels;

class OrderShipped implements ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Order $order,
    ) {}
}
```

### Deferring Events

Deferred events allow you to delay the dispatching of model events and execution of event listeners until after a specific block of code has completed. This is particularly useful when you need to ensure that all related records are created before event listeners are triggered.

To defer events, provide a closure to the `Event::defer()` method:

```php
use App\Models\User;
use Hypervel\Support\Facades\Event;

Event::defer(function () {
    $user = User::create(['name' => 'Victoria Otwell']);

    $user->posts()->create(['title' => 'My first post!']);
});
```

All events triggered within the closure will be dispatched after the closure is executed. This ensures that event listeners have access to all related records that were created during the deferred execution. If an exception occurs within the closure, the deferred events will not be dispatched.

## Event Subscribers

### Writing Event Subscribers

Event subscribers are classes that may subscribe to multiple events from within the subscriber class itself, allowing you to define several event handlers within a single class. Subscribers should define a `subscribe` method, which will be passed an event dispatcher instance. You may call the `listen` method on the given dispatcher to register event listeners:

```php
<?php

namespace App\Listeners;

use App\Events\Auth\Login;
use App\Events\Auth\Logout;
use Hypervel\Events\EventDispatcher;

class UserEventSubscriber
{
    /**
     * Handle user login events.
     */
    public function handleUserLogin(Login $event): void {}

    /**
     * Handle user logout events.
     */
    public function handleUserLogout(Logout $event): void {}

    /**
     * Register the listeners for the subscriber.
     */
    public function subscribe(Dispatcher $events): void
    {
        $events->listen(
            Login::class,
            [UserEventSubscriber::class, 'handleUserLogin']
        );

        $events->listen(
            Logout::class,
            [UserEventSubscriber::class, 'handleUserLogout']
        );
    }
}
```

If your event listener methods are defined within the subscriber itself, you may find it more convenient to return an array of events and method names from the subscriber's `subscribe` method. Hypervel will automatically determine the subscriber's class name when registering the event listeners:

```php
<?php

namespace App\Listeners;

use App\Events\Auth\Login;
use App\Events\Auth\Logout;
use Hypervel\Events\EventDispatcher;

class UserEventSubscriber
{
    /**
     * Handle user login events.
     */
    public function handleUserLogin(Login $event): void {}

    /**
     * Handle user logout events.
     */
    public function handleUserLogout(Logout $event): void {}

    /**
     * Register the listeners for the subscriber.
     *
     * @return array<string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'handleUserLogin',
            Logout::class => 'handleUserLogout',
        ];
    }
}
```

### Registering Event Subscribers

After writing the subscriber, you are ready to register it with the event dispatcher. You may register subscribers using the `$subscribe` property on the `EventServiceProvider`. For example, let's add the `UserEventSubscriber` to the list:

```php
<?php

namespace App\Providers;

use App\Listeners\UserEventSubscriber;
use Hypervel\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     */
    protected array $listen = [
        // ...
    ];

    /**
     * The subscriber classes to register.
     */
    protected array $subscribe = [
        UserEventSubscriber::class,
    ];
}
```

## Testing

When testing code that dispatches events, you may wish to instruct Hypervel to not actually execute the event's listeners, since the listener's code can be tested directly and separately of the code that dispatches the corresponding event. Of course, to test the listener itself, you may instantiate a listener instance and invoke the `handle` method directly in your test.

Using the `Event` facade's `fake` method, you may prevent listeners from executing, execute the code under test, and then assert which events were dispatched by your application using the `assertDispatched`, `assertNotDispatched`, and `assertNothingDispatched` methods:

```php
<?php

namespace Tests\Feature;

use App\Events\OrderFailedToShip;
use App\Events\OrderShipped;
use Hypervel\Support\Facades\Event;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Test order shipping.
     */
    public function test_orders_can_be_shipped(): void
    {
        Event::fake();

        // Perform order shipping...

        // Assert that an event was dispatched...
        Event::assertDispatched(OrderShipped::class);

        // Assert an event was dispatched twice...
        Event::assertDispatched(OrderShipped::class, 2);

        // Assert an event was not dispatched...
        Event::assertNotDispatched(OrderFailedToShip::class);

        // Assert that no events were dispatched...
        Event::assertNothingDispatched();
    }
}
```

You may pass a closure to the `assertDispatched` or `assertNotDispatched` methods in order to assert that an event was dispatched that passes a given "truth test". If at least one event was dispatched that passes the given truth test then the assertion will be successful:

```php
Event::assertDispatched(function (OrderShipped $event) use ($order) {
    return $event->order->id === $order->id;
});
```

If you would simply like to assert that an event listener is listening to a given event, you may use the `assertListening` method:

```php
Event::assertListening(
    OrderShipped::class,
    SendShipmentNotification::class
);
```

::: warning
After calling `Event::fake()`, no event listeners will be executed. So, if your tests use model factories that rely on events, such as creating a UUID during a model's `creating` event, you should call `Event::fake()` **after** using your factories.
:::

### Faking a Subset of Events

If you only want to fake event listeners for a specific set of events, you may pass them to the `fake` or `fakeFor` method:

```php
/**
 * Test order process.
 */
public function test_orders_can_be_processed(): void
{
    Event::fake([
        OrderCreated::class,
    ]);

    $order = Order::factory()->create();

    Event::assertDispatched(OrderCreated::class);

    // Other events are dispatched as normal...
    $order->update([...]);
}
```

You may fake all events except for a set of specified events using the `except` method:

```php
Event::fake()->except([
    OrderCreated::class,
]);
```

### Scoped Event Fakes

If you only want to fake event listeners for a portion of your test, you may use the `fakeFor` method:

```php
<?php

namespace Tests\Feature;

use App\Events\OrderCreated;
use App\Models\Order;
use Hypervel\Support\Facades\Event;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Test order process.
     */
    public function test_orders_can_be_processed(): void
    {
        $order = Event::fakeFor(function () {
            $order = Order::factory()->create();

            Event::assertDispatched(OrderCreated::class);

            return $order;
        });

        // Events are dispatched as normal and observers will run ...
        $order->update([...]);
    }
}
```