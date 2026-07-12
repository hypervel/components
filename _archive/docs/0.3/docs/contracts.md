# Contracts
[[toc]]

## Introduction

Hypervel's "contracts" are a set of interfaces that define the core services provided by the framework. For example, an `Hypervel\Auth\Contracts\Gate` contract defines the methods needed for authorizing a resource, while the `Hypervel\Hashing\Contracts\Hasher` contract defines the methods needed for generating a secure hash.

All of the contracts live separately in the `Contracts` directory in their belonging packages. This provides a quick reference point for all available contracts, as well as a single, decoupled package that may be utilized when building packages that interact with Hypervel services.

### Contracts vs. Facades

Hypervel's [facades](/docs/facades) and helper functions provide a simple way of utilizing Hypervel's services without needing to type-hint and resolve contracts out of the service container. In most cases, each facade has an equivalent contract.

Unlike facades, which do not require you to require them in your class' constructor, contracts allow you to define explicit dependencies for your classes. Some developers prefer to explicitly define their dependencies in this way and therefore prefer to use contracts, while other developers enjoy the convenience of facades. **In general, most applications can use facades without issue during development.**

## When to Use Contracts

The decision to use contracts or facades will come down to personal taste and the tastes of your development team. Both contracts and facades can be used to create robust, well-tested Hypervel applications. Contracts and facades are not mutually exclusive. Some parts of your applications may use facades while others depend on contracts. As long as you are keeping your class' responsibilities focused, you will notice very few practical differences between using contracts and facades.

In general, most applications can use facades without issue during development. If you are building a package that integrates with multiple PHP frameworks you may wish to use the corresponding contracts to define your integration with Hypervel's services without the need to require Hypervel's concrete implementations in your package's `composer.json` file.

## How to Use Contracts

So, how do you get an implementation of a contract? It's actually quite simple.

Many types of classes in Hypervel are resolved through the [service container](/docs/container), including controllers, event listeners, middleware and even route closures. So, to get an implementation of a contract, you can just "type-hint" the interface in the constructor of the class being resolved.

For example, take a look at this event listener:

```php
<?php

namespace App\Listeners;

use App\Events\OrderWasPlaced;
use App\Models\User;
use Hypervel\Cache\Contracts\Factory;

class CacheOrderInformation
{
    /**
     * Create a new event handler instance.
     */
    public function __construct(
        protected Factory $cache,
    ) {}

    /**
     * Handle the event.
     */
    public function handle(OrderWasPlaced $event): void
    {
        // ...
    }
}
```

When the event listener is resolved, the service container will read the type-hints on the constructor of the class, and inject the appropriate value. To learn more about registering things in the service container, check out [its documentation](/docs/container).

<a name="contract-reference"></a>
## Contract Reference

This table provides a quick reference to all of the Hypervel contracts and their equivalent facades:

| Contract                                                                                                                                               | References Facade         |
|--------------------------------------------------------------------------------------------------------------------------------------------------------|---------------------------|
| [Hypervel\Auth\Contracts\Authorizable](https://github.com/hypervel/components/blob/main/src/auth/src/Contracts/Authorizable.php)                 |  &nbsp;                   |
| [Hypervel\Auth\Contracts\Gate](https://github.com/hypervel/components/blob/main/src/auth/src/Contracts/Gate.php)                                 | `Gate`                    |
| [Hypervel\Auth\Contracts\Authenticatable](https://github.com/hypervel/components/blob/main/src/auth/src/Contracts/Authenticatable.php)                         |  &nbsp;                   |
| [Hypervel\Auth\Contracts\Factory](https://github.com/hypervel/components/blob/main/src/auth/src/Contracts/FactoryContract.php)                                         | `Auth`                    |
| [Hypervel\Auth\Contracts\Guard](https://github.com/hypervel/components/blob/main/src/auth/src/Contracts/Guard.php)                                             | `Auth::guard()`         |
| [Hypervel\Auth\Contracts\StatefulGuard](https://github.com/hypervel/components/blob/main/src/auth/src/Contracts/StatefulGuard.php)                             | &nbsp;                    |
| [Hypervel\Auth\Contracts\SupportsBasicAuth](https://github.com/hypervel/components/blob/main/src/auth/src/Contracts/SupportsBasicAuth.php)                     | &nbsp;                    |
| [Hypervel\Auth\Contracts\UserProvider](https://github.com/hypervel/components/blob/main/src/auth/src/Contracts/UserProvider.php)                               | &nbsp;                    |
| [Hypervel\Bus\Contracts\Dispatcher](https://github.com/hypervel/components/blob/main/src/bus/src/Contracts/Dispatcher.php)                               | `Bus`                    |
| [Hypervel\Bus\Contracts\QueuingDispatcher](https://github.com/hypervel/components/blob/main/src/bus/src/Contracts/QueuingDispatcher.php)                               | `Bus::dispatchToQueue()`        |
| [Hypervel\Broadcasting\Contracts\Factory](https://github.com/hypervel/components/blob/main/src/broadcasting/src/Contracts/Factory.php)                               | `Broadcast`                  |
| [Hypervel\Broadcasting\Contracts\Broadcaster](https://github.com/hypervel/components/blob/main/src/broadcasting/src/Contracts/Broadcaster.php)                               | `Broadcast::connection()`           |
| [Hypervel\Broadcasting\Contracts\ShouldBroadcast](https://github.com/hypervel/components/blob/main/src/broadcasting/src/Contracts/ShouldBroadcast.php)                               | &nbsp;                    |
| [Hypervel\Broadcasting\Contracts\ShouldBroadcastNow](https://github.com/hypervel/components/blob/main/src/broadcasting/src/Contracts/ShouldBroadcastNow.php)                               | &nbsp;                    |
| [Hypervel\Cache\Contracts\Factory](https://github.com/hypervel/components/blob/main/src/cache/src/Contracts/Factory.php)                                       | `Cache`                   |
| [Hypervel\Cache\Contracts\Lock](https://github.com/hypervel/components/blob/main/src/cache/src/Contracts/Lock.php)                                             | &nbsp;                    |
| [Hypervel\Cache\Contracts\LockProvider](https://github.com/hypervel/components/blob/main/src/cache/src/Contracts/LockProvider.php)                             | &nbsp;                    |
| [Hypervel\Cache\Contracts\Repository](https://github.com/hypervel/components/blob/main/src/cache/src/Contracts/Repository.php)                                 | `Cache::driver()`         |
| [Hypervel\Cache\Contracts\Store](https://github.com/hypervel/components/blob/main/src/cache/src/Contracts/Store.php)                                           | &nbsp;                    |
| [Hypervel\Config\Contracts\Repository](https://github.com/hypervel/components/blob/main/src/config/src/Contracts/Repository.php)                               | `Config`                  |
| [Hypervel\Container\Contracts\Container](https://github.com/hypervel/components/blob/main/src/container/src/Contracts/Container.php)                               | `App`                  |
| [Hypervel\Foundation\Exceptions\Contracts\ExceptionHandler](https://github.com/hypervel/components/blob/main/src/foundation/src/Exceptions/Contracts/ExceptionHandler.php)                               | &nbsp;                  |
| [Hypervel\Encryption\Contracts\Encrypter](https://github.com/hypervel/components/blob/main/src/encryption/src/Contracts/ReposEncrypteritory.php)                               | `Crypt`                  |
| [Hypervel\Event\Contracts\Dispatcher](https://github.com/hypervel/components/blob/main/src/event/src/Contracts/Dispatcher.php)                               | `Event`                  |
| [Hypervel\Filesystem\Contracts\Cloud](https://github.com/hypervel/components/blob/main/src/filesystem/src/Contracts/Cloud.php)                           | `Storage::cloud()`                    |
| [Hypervel\Filesystem\Contracts\Factory](https://github.com/hypervel/components/blob/main/src/filesystem/src/Contracts/Factory.php)                           | `Storage`                    |
| [Hypervel\Filesystem\Contracts\Filesystem](https://github.com/hypervel/components/blob/main/src/filesystem/src/Contracts/Filesystem.php)                           | `Storage::disk()`                    |
| [Hypervel\Foundation\Contracts\Application](https://github.com/hypervel/components/blob/main/src/foundation/src/Contracts/Application.php)                           | `App`                    |
| [Hypervel\Console\Contracts\Application](https://github.com/hypervel/components/blob/main/src/foundation/src/Console/Contracts/Application.php)                           | &nbsp;                    |
| [Hypervel\Foundation\Console\Contracts\Kernel](https://github.com/hypervel/components/blob/main/src/foundation/src/Console/Contracts/Kernel.php)                           | `Artisan`                    |
| [Hypervel\Hashing\Contracts\Hasher](https://github.com/hypervel/components/blob/main/src/hashing/src/Contracts/Hasher.php)                           | `Hash`                    |
| [Hypervel\Mail\Contracts\MailQueue](https://github.com/hypervel/components/blob/main/src/mail/src/Contracts/MailQueue.php)                           | `Mail::queue()`                    |
| [Hypervel\Mail\Contracts\Mailable](https://github.com/hypervel/components/blob/main/src/mail/src/Contracts/Mailable.php)                           | &nbsp;                    |
| [Hypervel\Mail\Contracts\Mailer](https://github.com/hypervel/components/blob/main/src/mail/src/Contracts/Mailer.php)                           | `Mail`                    |
| [Hypervel\Notifications\Contracts\Dispatcher](https://github.com/hypervel/components/blob/main/src/notifications/src/Contracts/Dispatcher.php)                           | `Notification`                    |
| [Hypervel\Notifications\Contracts\Factory](https://github.com/hypervel/components/blob/main/src/notifications/src/Contracts/Factory.php)                           | `Notification`                    |
| [Hypervel\ObjectPool\Contracts\Factory](https://github.com/hypervel/components/blob/main/src/object-pool/src/Contracts/Factory.php)                           | &nbsp;                    |
| [Hypervel\ObjectPool\Contracts\ObjectPool](https://github.com/hypervel/components/blob/main/src/object-pool/src/Contracts/ObjectPool.php)                           | &nbsp;                    |
| [Hypervel\ObjectPool\Contracts\Recycler](https://github.com/hypervel/components/blob/main/src/object-pool/src/Contracts/Recycler.php)                           | &nbsp;                    |
| [Hypervel\Queue\Contracts\EntityResolver](https://github.com/hypervel/components/blob/main/src/queue/src/Contracts/EntityResolver.php)                           | &nbsp;                    |
| [Hypervel\Queue\Contracts\Factory](https://github.com/hypervel/components/blob/main/src/queue/src/Contracts/Factory.php)                           | `Queue`                    |
| [Hypervel\Queue\Contracts\Job](https://github.com/hypervel/components/blob/main/src/queue/src/Contracts/Job.php)                           | &nbsp;                    |
| [Hypervel\Queue\Contracts\Monitor](https://github.com/hypervel/components/blob/main/src/queue/src/Contracts/Factory.php)                           | `Queue`                    |
| [Hypervel\Queue\Contracts\Queue](https://github.com/hypervel/components/blob/main/src/queue/src/Contracts/Queue.php)                           | `Queue::connection()`                    |
| [Hypervel\Queue\Contracts\QueueableCollection](https://github.com/hypervel/components/blob/main/src/queue/src/Contracts/QueueableCollection.php)                           | &nbsp;                    |
| [Hypervel\Queue\Contracts\QueueableEntity](https://github.com/hypervel/components/blob/main/src/queue/src/Contracts/QueueableEntity.php)                           | &nbsp;                    |
| [Hypervel\Queue\Contracts\ShouldQueue](https://github.com/hypervel/components/blob/main/src/queue/src/Contracts/ShouldQueue.php)                           | &nbsp;                    |
| [Hypervel\Router\Contracts\UrlGenerator](https://github.com/hypervel/components/blob/main/src/router/contracts/UrlGenerator.php)                           | &nbsp;                    |
| [Hypervel\Router\Contracts\UrlRoutable](https://github.com/hypervel/components/blob/main/src/router/contracts/UrlRoutable.php)                           | &nbsp;                    |
| [Hypervel\Session\Contracts\Factory](https://github.com/hypervel/components/blob/main/src/session/contracts/Factory.php)                           | &nbsp;                    |
| [Hypervel\Session\Contracts\Session](https://github.com/hypervel/components/blob/main/src/session/contracts/Session.php)                           | `Session::store()`                    |
| [Hypervel\Support\Contracts\Arrayable](https://github.com/hypervel/components/blob/main/src/support/contracts/Arrayable.php)                           | &nbsp;                    |
| [Hypervel\Support\Contracts\Htmlable](https://github.com/hypervel/components/blob/main/src/support/contracts/Htmlable.php)                           | &nbsp;                    |
| [Hypervel\Support\Contracts\Jsonable](https://github.com/hypervel/components/blob/main/src/support/contracts/Jsonable.php)                           | &nbsp;                    |
| [Hypervel\Support\Contracts\MessageBag](https://github.com/hypervel/components/blob/main/src/support/contracts/MessageBag.php)                           | &nbsp;                    |
| [Hypervel\Support\Contracts\MessageProvider](https://github.com/hypervel/components/blob/main/src/support/contracts/MessageProvider.php)                           | &nbsp;                    |
| [Hypervel\Support\Contracts\Renderable](https://github.com/hypervel/components/blob/main/src/support/contracts/Renderable.php)                           | &nbsp;                    |
| [Hypervel\Support\Contracts\Reasonable](https://github.com/hypervel/components/blob/main/src/support/contracts/Reasonable.php)                           | &nbsp;                    |
| [Hypervel\Translation\Contracts\Loader](https://github.com/hypervel/components/blob/main/src/translation/contracts/Loader.php)                           | &nbsp;                    |
| [Hypervel\Translation\Contracts\Translator](https://github.com/hypervel/components/blob/main/src/translation/contracts/Translator.php)                           | `Lang`                    |
| [Hypervel\Foundation\Exceptions\Contracts\ExceptionHandler](https://github.com/hypervel/components/blob/main/src/foundation/src/Exceptions/Contracts/ExceptionHandler.php)                           | &nbsp;                    |
| [Hypervel\Foundation\Exceptions\Contracts\ExceptionRenderer](https://github.com/hypervel/components/blob/main/src/foundation/src/Exceptions/Contracts/ExceptionRenderer.php)                           | &nbsp;                    |
| [Hypervel\Foundation\Http\Contracts\ExceptionRenderer](https://github.com/hypervel/components/blob/main/src/foundation/src/Http/Contracts/MiddlewareContract.php)                           | &nbsp;                    |
| [Hypervel\Cookie\Contracts\Cookie](https://github.com/hypervel/components/blob/main/src/cookie/src/Contracts/Cookie.php)                                     | `Cookie`                  |
| [Hypervel\Http\Contracts\RequestContract](https://github.com/hypervel/components/blob/main/src/http/src/Contracts/RequestContract.php)                                           | `Request`                    |
| [Hypervel\Http\Contracts\ResponseContract](https://github.com/hypervel/components/blob/main/src/http/src/Contracts/ResponseContract.php)                                           | `Response`                    |
| [Hypervel\Router\Contracts\UrlGenerator](https://github.com/hypervel/components/blob/main/src/router/src/Contracts/UrlGenerator.php)                         | `URL`                     |

::: info
The contracts in Hyperf can refer to [hyperf/contract](https://github.com/hyperf/hyperf/tree/master/src/contract/src) package
:::