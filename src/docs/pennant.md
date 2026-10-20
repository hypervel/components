# Hypervel Pennant

- [Introduction](#introduction)
- [Installation](#installation)
- [Configuration](#configuration)
- [Defining Features](#defining-features)
    - [Class Based Features](#class-based-features)
- [Checking Features](#checking-features)
    - [Conditional Execution](#conditional-execution)
    - [The `HasFeatures` Trait](#the-has-features-trait)
    - [Blade Directive](#blade-directive)
    - [Middleware](#middleware)
    - [Intercepting Feature Checks](#intercepting-feature-checks)
    - [In-Memory Cache](#in-memory-cache)
- [Scope](#scope)
    - [Specifying the Scope](#specifying-the-scope)
    - [Global Scope](#global-scope)
    - [Default Scope](#default-scope)
    - [Nullable Scope](#nullable-scope)
    - [Identifying Scope](#identifying-scope)
    - [Serializing Scope](#serializing-scope)
- [Rich Feature Values](#rich-feature-values)
- [Retrieving Multiple Features](#retrieving-multiple-features)
- [Eager Loading](#eager-loading)
- [Updating Values](#updating-values)
    - [Bulk Updates](#bulk-updates)
    - [Purging Features](#purging-features)
- [Partitioning](#partitioning)
    - [Registering a Partition](#registering-a-partition)
    - [Partitioned Schema](#partitioned-schema)
- [Shared Cache](#shared-cache)
- [Testing](#testing)
- [Adding Custom Pennant Drivers](#adding-custom-pennant-drivers)
    - [Implementing the Driver](#implementing-the-driver)
    - [Registering the Driver](#registering-the-driver)
    - [Defining Features Externally](#defining-features-externally)
- [Events](#events)
- [Credits](#credits)

<a name="introduction"></a>
## Introduction

[Hypervel Pennant](https://github.com/hypervel/pennant) is a simple and light-weight feature flag package - without the cruft. Feature flags enable you to incrementally roll out new application features with confidence, A/B test new interface designs, complement a trunk-based development strategy, and much more.

<a name="installation"></a>
## Installation

First, install Pennant into your project using the Composer package manager:

```shell
composer require hypervel/pennant
```

Next, you should publish the Pennant configuration and migration files using the `vendor:publish` Artisan command:

```shell
php artisan vendor:publish --provider="Hypervel\Pennant\PennantServiceProvider"
```

Finally, you should run your application's database migrations. This will create a `features` table that Pennant uses to power its `database` driver:

```shell
php artisan migrate
```

On MySQL and MariaDB, the migration stores feature names and scopes in binary columns, so names and scopes that differ only by case, accents, or trailing spaces are stored separately.

<a name="configuration"></a>
## Configuration

After publishing Pennant's assets, its configuration file will be located at `config/pennant.php`. This configuration file allows you to specify the default storage mechanism that will be used by Pennant to store resolved feature flag values.

Pennant includes support for storing resolved feature flag values in an in-memory array via the `array` driver. Or, Pennant can store resolved feature flag values persistently in a relational database via the `database` driver, which is the default storage mechanism used by Pennant.

A `database` store uses the default database connection when its `connection` option is `null`, including a connection selected with `DB::usingConnection()`. Since Pennant stores the first value it resolves for a scope, a store can't use a [`::read` connection](/docs/{{version}}/database#read-and-write-connections).

Each store reads its configuration once, when it is first used, and keeps it for the life of the worker. Configure your stores before defining features, and don't change `pennant.stores` while handling requests: [configuration](/docs/{{version}}/configuration) is shared by every request a worker handles, so stores that have already been built won't see the change. To store each request's values on a different connection, leave the store's `connection` set to `null` and select the connection with `DB::usingConnection()`. To keep each tenant's values apart in one table, use [partitioning](#partitioning).

If you reconfigure stores while your application boots or within a test, call `Feature::forgetDrivers()` and then define your features again, since rebuilt stores start without feature definitions. Forget every store rather than just the one you changed, because database stores reading the same table also use each other's [shared cache](#shared-cache) settings.

<a name="defining-features"></a>
## Defining Features

To define a feature, you may use the `define` method offered by the `Feature` facade. You will need to provide a name for the feature, as well as a closure that will be invoked to resolve the feature's initial value.

Typically, features are defined in a service provider using the `Feature` facade. The closure will receive the "scope" for the feature check. Most commonly, the scope is the currently authenticated user. In this example, we will define a feature for incrementally rolling out a new API to our application's users:

```php
<?php

namespace App\Providers;

use App\Models\User;
use Hypervel\Pennant\Feature;
use Hypervel\Support\Lottery;
use Hypervel\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Feature::define('new-api', fn (User $user) => match (true) {
            $user->isInternalTeamMember() => true,
            $user->isHighTrafficCustomer() => false,
            default => Lottery::odds(1 / 100),
        });
    }
}
```

As you can see, we have the following rules for our feature:

- All internal team members should be using the new API.
- Any high traffic customers should not be using the new API.
- Otherwise, the feature should be randomly assigned to users with a 1 in 100 chance of being active.

The first time the `new-api` feature is checked for a given user, the result of the closure will be stored by the storage driver. The next time the feature is checked against the same user, the value will be retrieved from storage and the closure will not be invoked.

For convenience, if a feature definition only returns a lottery, you may omit the closure completely:

    Feature::define('site-redesign', Lottery::odds(1, 1000));

<a name="class-based-features"></a>
### Class Based Features

Pennant also allows you to define class-based features. Unlike closure-based feature definitions, there is no need to register a class-based feature in a service provider. To create a class-based feature, you may invoke the `pennant:feature` Artisan command. By default, the feature class will be placed in your application's `app/Features` directory:

```shell
php artisan pennant:feature NewApi
```

When writing a feature class, you only need to define a `resolve` method, which will be invoked to resolve the feature's initial value for a given scope. Again, the scope will typically be the currently authenticated user:

```php
<?php

namespace App\Features;

use App\Models\User;
use Hypervel\Support\Lottery;

class NewApi
{
    /**
     * Resolve the feature's initial value.
     */
    public function resolve(User $user): mixed
    {
        return match (true) {
            $user->isInternalTeamMember() => true,
            $user->isHighTrafficCustomer() => false,
            default => Lottery::odds(1 / 100),
        };
    }
}
```

If you would like to manually resolve an instance of a class-based feature, you may invoke the `instance` method on the `Feature` facade:

```php
use App\Features\NewApi;
use Hypervel\Pennant\Feature;

$instance = Feature::instance(NewApi::class);
```

> [!NOTE]
> Feature classes are resolved via the [container](/docs/{{version}}/container), so you may inject dependencies into the feature class's constructor when needed. By default, each feature class is resolved once and shared by every request a worker handles, so don't store request-specific state in its properties.

#### Customizing the Stored Feature Name

By default, Pennant will store the feature class's fully qualified class name. If you would like to decouple the stored feature name from the application's internal structure, you may add the `Name` attribute on the feature class. The value of this attribute will be stored in place of the class name:

```php
<?php

namespace App\Features;

use Hypervel\Pennant\Attributes\Name;

#[Name('new-api')]
class NewApi
{
    // ...
}
```

<a name="checking-features"></a>
## Checking Features

To determine if a feature is active, you may use the `active` method on the `Feature` facade. By default, features are checked against the currently authenticated user:

```php
<?php

namespace App\Http\Controllers;

use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Pennant\Feature;

class PodcastController
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        return Feature::active('new-api')
            ? $this->resolveNewApiResponse($request)
            : $this->resolveLegacyApiResponse($request);
    }

    // ...
}
```

Although features are checked against the currently authenticated user by default, you may easily check the feature against another user or [scope](#scope). To accomplish this, use the `for` method offered by the `Feature` facade:

```php
return Feature::for($user)->active('new-api')
    ? $this->resolveNewApiResponse($request)
    : $this->resolveLegacyApiResponse($request);
```

Pennant also offers some additional convenience methods that may prove useful when determining if a feature is active or not:

```php
// Determine if all of the given features are active...
Feature::allAreActive(['new-api', 'site-redesign']);

// Determine if any of the given features are active...
Feature::someAreActive(['new-api', 'site-redesign']);

// Determine if a feature is inactive...
Feature::inactive('new-api');

// Determine if all of the given features are inactive...
Feature::allAreInactive(['new-api', 'site-redesign']);

// Determine if any of the given features are inactive...
Feature::someAreInactive(['new-api', 'site-redesign']);
```

> [!NOTE]
> When using Pennant outside of an HTTP context, such as in an Artisan command or a queued job, you should typically [explicitly specify the feature's scope](#specifying-the-scope). Alternatively, you may define a [default scope](#default-scope) that accounts for both authenticated HTTP contexts and unauthenticated contexts.

<a name="checking-class-based-features"></a>
#### Checking Class Based Features

For class-based features, you should provide the class name when checking the feature:

```php
<?php

namespace App\Http\Controllers;

use App\Features\NewApi;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Pennant\Feature;

class PodcastController
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        return Feature::active(NewApi::class)
            ? $this->resolveNewApiResponse($request)
            : $this->resolveLegacyApiResponse($request);
    }

    // ...
}
```

<a name="conditional-execution"></a>
### Conditional Execution

The `when` method may be used to fluently execute a given closure if a feature is active. Additionally, a second closure may be provided and will be executed if the feature is inactive:

```php
<?php

namespace App\Http\Controllers;

use App\Features\NewApi;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Pennant\Feature;

class PodcastController
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): Response
    {
        return Feature::when(NewApi::class,
            fn () => $this->resolveNewApiResponse($request),
            fn () => $this->resolveLegacyApiResponse($request),
        );
    }

    // ...
}
```

The `unless` method serves as the inverse of the `when` method, executing the first closure if the feature is inactive:

```php
return Feature::unless(NewApi::class,
    fn () => $this->resolveLegacyApiResponse($request),
    fn () => $this->resolveNewApiResponse($request),
);
```

<a name="the-has-features-trait"></a>
### The `HasFeatures` Trait

Pennant's `HasFeatures` trait may be added to your application's `User` model (or any other model that has features) to provide a fluent, convenient way to check features directly from the model:

```php
<?php

namespace App\Models;

use Hypervel\Foundation\Auth\User as Authenticatable;
use Hypervel\Pennant\Concerns\HasFeatures;

class User extends Authenticatable
{
    use HasFeatures;

    // ...
}
```

Once the trait has been added to your model, you may easily check features by invoking the `features` method:

```php
if ($user->features()->active('new-api')) {
    // ...
}
```

Of course, the `features` method provides access to many other convenient methods for interacting with features:

```php
// Values...
$value = $user->features()->value('purchase-button');
$values = $user->features()->values(['new-api', 'purchase-button']);

// State...
$user->features()->active('new-api');
$user->features()->allAreActive(['new-api', 'server-api']);
$user->features()->someAreActive(['new-api', 'server-api']);

$user->features()->inactive('new-api');
$user->features()->allAreInactive(['new-api', 'server-api']);
$user->features()->someAreInactive(['new-api', 'server-api']);

// Conditional execution...
$user->features()->when('new-api',
    fn () => /* ... */,
    fn () => /* ... */,
);

$user->features()->unless('new-api',
    fn () => /* ... */,
    fn () => /* ... */,
);
```

<a name="blade-directive"></a>
### Blade Directive

To make checking features in Blade a seamless experience, Pennant offers the `@feature` and `@featureany` directive:

```blade
@feature('site-redesign')
    <!-- 'site-redesign' is active -->
@else
    <!-- 'site-redesign' is inactive -->
@endfeature

@featureany(['site-redesign', 'beta'])
    <!-- 'site-redesign' or `beta` is active -->
@endfeatureany
```

<a name="middleware"></a>
### Middleware

Pennant also includes a [middleware](/docs/{{version}}/middleware) that may be used to verify the currently authenticated user has access to a feature before a route is even invoked. You may assign the middleware to a route and specify the features that are required to access the route. If any of the specified features are inactive for the currently authenticated user, a `400 Bad Request` HTTP response will be returned by the route. Multiple features may be passed to the static `using` method.

```php
use Hypervel\Pennant\Middleware\EnsureFeaturesAreActive;
use Hypervel\Support\Facades\Route;

Route::get('/api/servers', function () {
    // ...
})->middleware(EnsureFeaturesAreActive::using('new-api', 'servers-api'));
```

<a name="customizing-the-response"></a>
#### Customizing the Response

If you would like to customize the response that is returned by the middleware when one of the listed features is inactive, you may use the `whenInactive` method provided by the `EnsureFeaturesAreActive` middleware. Typically, this method should be invoked within the `boot` method of one of your application's service providers:

```php
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\Pennant\Middleware\EnsureFeaturesAreActive;

/**
 * Bootstrap any application services.
 */
public function boot(): void
{
    EnsureFeaturesAreActive::whenInactive(
        function (Request $request, array $features) {
            return new Response(status: 403);
        }
    );

    // ...
}
```

<a name="intercepting-feature-checks"></a>
### Intercepting Feature Checks

Sometimes it can be useful to perform some in-memory checks before retrieving the stored value of a given feature. Imagine you are developing a new API behind a feature flag and want the ability to disable the new API without losing any of the resolved feature values in storage. If you notice a bug in the new API, you could easily disable it for everyone except internal team members, fix the bug, and then re-enable the new API for the users that previously had access to the feature.

You can achieve this with a [class-based feature's](#class-based-features) `before` method. When present, the `before` method is always run in-memory before retrieving the value from storage. If a non-`null` value is returned from the method, it will be used in place of the feature's stored value for the duration of the request:

```php
<?php

namespace App\Features;

use App\Models\User;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Lottery;

class NewApi
{
    /**
     * Run an always-in-memory check before the stored value is retrieved.
     */
    public function before(User $user): mixed
    {
        if (Config::boolean('features.new-api.disabled')) {
            return $user->isInternalTeamMember();
        }

        return null;
    }

    /**
     * Resolve the feature's initial value.
     */
    public function resolve(User $user): mixed
    {
        return match (true) {
            $user->isInternalTeamMember() => true,
            $user->isHighTrafficCustomer() => false,
            default => Lottery::odds(1 / 100),
        };
    }
}
```

You could also use this feature to schedule the global rollout of a feature that was previously behind a feature flag:

```php
<?php

namespace App\Features;

use App\Models\User;
use Hypervel\Support\Facades\Config;
use Hypervel\Support\Facades\Date;

class NewApi
{
    /**
     * Run an always-in-memory check before the stored value is retrieved.
     */
    public function before(User $user): mixed
    {
        if (Config::boolean('features.new-api.disabled')) {
            return $user->isInternalTeamMember();
        }

        if (Date::parse(Config::string('features.new-api.rollout-date'))->isPast()) {
            return true;
        }

        return null;
    }

    // ...
}
```

Since the `before` method's result is never stored, it is also a good place to defer to another source of truth, such as your billing or entitlement service, when your application has one. Pennant doesn't provide an entitlement system of its own.

<a name="in-memory-cache"></a>
### In-Memory Cache

When checking a feature, Pennant will create an in-memory cache of the result. If you are using the `database` driver, this means that re-checking the same feature flag within a single request will not trigger additional database queries. This also ensures that the feature has a consistent result for the duration of the request.

The in-memory cache belongs to the current coroutine. Since Hypervel handles each request and each job processed by a queue worker in its own coroutine, concurrent requests handled by the same worker never see each other's values. A child coroutine starts with an empty cache, unless it copies its parent's context, in which case it starts with a copy of the parent's cache. The `array` driver's stored values follow the same rules, so they only last for the current request or job.

For `database` stores, values cached while a database transaction is open are forgotten if the transaction rolls back, and they aren't copied to child coroutines until it commits.

If you need to manually flush the in-memory cache, you may use the `flushCache` method offered by the `Feature` facade. Only the current coroutine's cache is flushed:

```php
Feature::flushCache();
```

<a name="scope"></a>
## Scope

<a name="specifying-the-scope"></a>
### Specifying the Scope

As discussed, features are typically checked against the currently authenticated user. However, this may not always suit your needs. Therefore, it is possible to specify the scope you would like to check a given feature against via the `Feature` facade's `for` method:

```php
return Feature::for($user)->active('new-api')
    ? $this->resolveNewApiResponse($request)
    : $this->resolveLegacyApiResponse($request);
```

Of course, feature scopes are not limited to "users". Imagine you have built a new billing experience that you are rolling out to entire teams rather than individual users. Perhaps you would like the oldest teams to have a slower rollout than the newer teams. Your feature resolution closure might look something like the following:

```php
use App\Models\Team;
use Hypervel\Pennant\Feature;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Lottery;

Feature::define('billing-v2', function (Team $team) {
    if ($team->created_at->isAfter(Date::parse('1st Jan, 2023'))) {
        return true;
    }

    if ($team->created_at->isAfter(Date::parse('1st Jan, 2019'))) {
        return Lottery::odds(1 / 100);
    }

    return Lottery::odds(1 / 1000);
});
```

You will notice that the closure we have defined is not expecting a `User`, but is instead expecting a `Team` model. To determine if this feature is active for a user's team, you should pass the team to the `for` method offered by the `Feature` facade:

```php
if (Feature::for($user->team)->active('billing-v2')) {
    return redirect('/billing/v2');
}

// ...
```

<a name="global-scope"></a>
### Global Scope

To check or interact with a feature using a global scope, regardless of the configured default scope resolver, use the `globally` method. This is useful for application-wide feature flags, such as temporarily enabling maintenance behavior or rolling out a feature to every user:

```php
Feature::globally()->active('new-api');

Feature::globally()->activate('new-api');
```

<a name="default-scope"></a>
### Default Scope

It is also possible to customize the default scope Pennant uses to check features. For example, maybe all of your features are checked against the currently authenticated user's team instead of the user. Instead of having to call `Feature::for($user->team)` every time you check a feature, you may instead specify the team as the default scope. Typically, this should be done in one of your application's service providers:

```php
<?php

namespace App\Providers;

use Hypervel\Pennant\Feature;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Feature::resolveScopeUsing(fn ($driver) => Auth::user()?->team);

        // ...
    }
}
```

If no scope is explicitly provided via the `for` method, the feature check will now use the currently authenticated user's team as the default scope:

```php
Feature::active('billing-v2');

// Is now equivalent to...

Feature::for($user->team)->active('billing-v2');
```

<a name="nullable-scope"></a>
### Nullable Scope

If the scope you provide when checking a feature is `null` and the feature's definition does not support `null` via a nullable type or by including `null` in a union type, Pennant will automatically return `false` as the feature's result value. This `false` result is stored like any other value, so if you later change the definition to support `null`, [purge](#purging-features) the feature or `forget` its stored value for the `null` scope.

So, if the scope you are passing to a feature is potentially `null` and you want the feature's value resolver to be invoked, you should account for that in your feature's definition. A `null` scope may occur if you check a feature within an Artisan command, queued job, or unauthenticated route. Since there is usually not an authenticated user in these contexts, the default scope will be `null`.

If you do not always [explicitly specify your feature scope](#specifying-the-scope) then you should ensure the scope's type is "nullable" and handle the `null` scope value within your feature definition logic:

```php
use App\Models\User;
use Hypervel\Pennant\Feature;
use Hypervel\Support\Lottery;

Feature::define('new-api', fn (User $user) => match (true) {// [tl! remove]
Feature::define('new-api', fn (User|null $user) => match (true) {// [tl! add]
    $user === null => true,// [tl! add]
    $user->isInternalTeamMember() => true,
    $user->isHighTrafficCustomer() => false,
    default => Lottery::odds(1 / 100),
});
```

<a name="identifying-scope"></a>
### Identifying Scope

Pennant identifies each scope by a string. `null`, strings, numbers, and saved Eloquent models are converted automatically, and any other object used as a scope must implement the `Hypervel\Pennant\Contracts\FeatureScopeSerializeable` contract, whose `featureScopeSerialize` method returns a string that uniquely identifies it. Other values, such as arrays and booleans, throw an exception. However, if your application utilizes a third-party Pennant driver, that driver may not know how to properly store an identifier for an Eloquent model or other custom types in your application.

In light of this, Pennant allows you to format scope values for storage by implementing the `FeatureScopeable` contract on the objects in your application that are used as Pennant scopes.

For example, imagine you are using two different feature drivers in a single application: the built-in `database` driver and a third-party "Flag Rocket" driver. The "Flag Rocket" driver does not know how to properly store an Eloquent model. Instead, it requires a `FlagRocketUser` instance. By implementing the `toFeatureIdentifier` method defined by the `FeatureScopeable` contract, we can customize the storable scope value provided to each store used by our application. The method receives the name of the store being used:

```php
<?php

namespace App\Models;

use FlagRocket\FlagRocketUser;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Pennant\Contracts\FeatureScopeable;

class User extends Model implements FeatureScopeable
{
    /**
     * Cast the object to a feature scope identifier for the given store.
     */
    public function toFeatureIdentifier(string $driver): mixed
    {
        return match($driver) {
            'database' => $this,
            'flag-rocket' => FlagRocketUser::fromId($this->flag_rocket_id),
        };
    }
}
```

Since Pennant's in-memory cache also identifies scopes by a string, the `FlagRocketUser` instance must implement the `FeatureScopeSerializeable` contract.

<a name="serializing-scope"></a>
### Serializing Scope

Pennant identifies an Eloquent model scope by its class and key, so a model must have a key before you use it as a scope. Checking a feature for an unsaved model without a key throws an exception, since every unsaved model would otherwise share the same stored value. Save the model first, or pass a scope that identifies it.

By default, Pennant will use a fully qualified class name when storing a feature associated with an Eloquent model. If you are already using an [Eloquent morph map](/docs/{{version}}/eloquent-relationships#custom-polymorphic-types), you may choose to have Pennant also use the morph map to decouple the stored feature from your application structure.

To achieve this, after defining your Eloquent morph map in a service provider, you may invoke the `Feature` facade's `useMorphMap` method:

```php
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Pennant\Feature;

Relation::enforceMorphMap([
    'post' => 'App\Models\Post',
    'video' => 'App\Models\Video',
]);

Feature::useMorphMap();
```

<a name="rich-feature-values"></a>
## Rich Feature Values

Until now, we have primarily shown features as being in a binary state, meaning they are either "active" or "inactive", but Pennant also allows you to store rich values as well.

For example, imagine you are testing three new colors for the "Buy now" button of your application. Instead of returning `true` or `false` from the feature definition, you may instead return a string:

```php
use App\Models\User;
use Hypervel\Pennant\Feature;
use Hypervel\Support\Arr;

Feature::define('purchase-button', fn (User $user) => Arr::random([
    'blue-sapphire',
    'seafoam-green',
    'tart-orange',
]));
```

You may retrieve the value of the `purchase-button` feature using the `value` method:

```php
$color = Feature::value('purchase-button');
```

Pennant's included Blade directive also makes it easy to conditionally render content based on the current value of the feature:

```blade
@feature('purchase-button', 'blue-sapphire')
    <!-- 'blue-sapphire' is active -->
@elsefeature('purchase-button', 'seafoam-green')
    <!-- 'seafoam-green' is active -->
@elsefeature('purchase-button', 'tart-orange')
    <!-- 'tart-orange' is active -->
@endfeature
```

> [!NOTE]
> When using rich values, it is important to know that a feature is considered "active" when it has any value other than `false`.

When calling the [conditional `when`](#conditional-execution) method, the feature's rich value will be provided to the first closure:

```php
Feature::when('purchase-button',
    fn ($color) => /* ... */,
    fn () => /* ... */,
);
```

Likewise, when calling the conditional `unless` method, the feature's rich value will be provided to the optional second closure:

```php
Feature::unless('purchase-button',
    fn () => /* ... */,
    fn ($color) => /* ... */,
);
```

<a name="retrieving-multiple-features"></a>
## Retrieving Multiple Features

The `values` method allows the retrieval of multiple features for a given scope:

```php
Feature::values(['billing-v2', 'purchase-button']);

// [
//     'billing-v2' => false,
//     'purchase-button' => 'blue-sapphire',
// ]
```

Or, you may use the `all` method to retrieve the values of all defined features for a given scope:

```php
Feature::all();

// [
//     'billing-v2' => false,
//     'purchase-button' => 'blue-sapphire',
//     'site-redesign' => true,
// ]
```

However, class-based features are dynamically registered and are not known by Pennant until they are explicitly checked. Since a registered class stays registered for the life of the worker, the results returned by the `all` method may include class-based features first checked by an earlier request on the same worker, while leaving out those that haven't been checked yet.

If you would like the `all` method to always include the same feature classes, you may use Pennant's feature discovery capabilities. To get started, invoke the `discover` method in one of your application's service providers:

```php
<?php

namespace App\Providers;

use Hypervel\Pennant\Feature;
use Hypervel\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Feature::discover();

        // ...
    }
}
```

The `discover` method will register all of the feature classes in your application's `app/Features` directory. The `all` method will now include these classes in its results, regardless of whether they have been checked:

```php
Feature::all();

// [
//     'App\Features\NewApi' => true,
//     'billing-v2' => false,
//     'purchase-button' => 'blue-sapphire',
//     'site-redesign' => true,
// ]
```

<a name="eager-loading"></a>
## Eager Loading

Although Pennant keeps an in-memory cache of all resolved features for a single request, it is still possible to encounter performance issues. To alleviate this, Pennant offers the ability to eager load feature values.

To illustrate this, imagine that we are checking if a feature is active within a loop:

```php
use Hypervel\Pennant\Feature;

foreach ($users as $user) {
    if (Feature::for($user)->active('notifications-beta')) {
        $user->notify(new RegistrationSuccess);
    }
}
```

Assuming we are using the database driver, this code will execute a database query for every user in the loop - executing potentially hundreds of queries. However, using Pennant's `load` method, we can remove this potential performance bottleneck by eager loading the feature values for a collection of users or scopes:

```php
Feature::for($users)->load(['notifications-beta']);

foreach ($users as $user) {
    if (Feature::for($user)->active('notifications-beta')) {
        $user->notify(new RegistrationSuccess);
    }
}
```

To load feature values only when they have not already been loaded, you may use the `loadMissing` method:

```php
Feature::for($users)->loadMissing([
    'new-api',
    'purchase-button',
    'notifications-beta',
]);
```

You may load all defined features using the `loadAll` method:

```php
Feature::for($users)->loadAll();
```

<a name="updating-values"></a>
## Updating Values

When a feature's value is resolved for the first time, the underlying driver will store the result in storage. This is often necessary to ensure a consistent experience for your users across requests. However, at times, you may want to manually update the feature's stored value.

To accomplish this, you may use the `activate` and `deactivate` methods to toggle a feature "on" or "off":

```php
use Hypervel\Pennant\Feature;

// Activate the feature for the default scope...
Feature::activate('new-api');

// Deactivate the feature for the given scope...
Feature::for($user->team)->deactivate('billing-v2');
```

It is also possible to manually set a rich value for a feature by providing a second argument to the `activate` method:

```php
Feature::activate('purchase-button', 'seafoam-green');
```

To instruct Pennant to forget the stored value for a feature, you may use the `forget` method. When the feature is checked again, Pennant will resolve the feature's value from its feature definition:

```php
Feature::forget('purchase-button');
```

<a name="bulk-updates"></a>
### Bulk Updates

To update stored feature values in bulk, you may use the `activateForEveryone` and `deactivateForEveryone` methods.

For example, imagine you are now confident in the `new-api` feature's stability and have landed on the best `'purchase-button'` color for your checkout flow - you can update the stored value for all users accordingly:

```php
use Hypervel\Pennant\Feature;

Feature::activateForEveryone('new-api');

Feature::activateForEveryone('purchase-button', 'seafoam-green');
```

Alternatively, you may deactivate the feature for all users:

```php
Feature::deactivateForEveryone('new-api');
```

> [!NOTE]
> This will only update the resolved feature values that have been stored by Pennant's storage driver. You will also need to update the feature definition in your application.

<a name="purging-features"></a>
### Purging Features

Sometimes, it can be useful to purge an entire feature from storage. This is typically necessary if you have removed the feature from your application or you have made adjustments to the feature's definition that you would like to rollout to all users.

You may remove all stored values for a feature using the `purge` method:

```php
// Purging a single feature...
Feature::purge('new-api');

// Purging multiple features...
Feature::purge(['new-api', 'purchase-button']);
```

If you would like to purge _all_ features from storage, you may invoke the `purge` method without any arguments:

```php
Feature::purge();
```

As it can be useful to purge features as part of your application's deployment pipeline, Pennant includes a `pennant:purge` Artisan command which will purge the provided features from storage:

```shell
php artisan pennant:purge new-api

php artisan pennant:purge new-api purchase-button
```

It is also possible to purge all features _except_ those in a given feature list. For example, imagine you wanted to purge all features but keep the values for the "new-api" and "purchase-button" features in storage. To accomplish this, you can pass those feature names to the `--except` option:

```shell
php artisan pennant:purge --except=new-api --except=purchase-button
```

For convenience, the `pennant:purge` command also supports an `--except-registered` flag. This flag indicates that all features except those explicitly registered in a service provider should be purged:

```shell
php artisan pennant:purge --except-registered
```

<a name="partitioning"></a>
## Partitioning

Partitioning keeps the feature values of separate tenants, workspaces, or teams apart while storing them in the same table. Once you register a partition, Pennant applies the current partition value to every read and write, including first resolutions, updates, bulk updates, purges, the in-memory cache, the `array` driver, and the [shared cache](#shared-cache). [Global scope](#global-scope) values belong to a partition too, so `Feature::globally()` reads and writes the current partition's value.

<a name="registering-a-partition"></a>
### Registering a Partition

You may register a partition using the `Feature` facade's `resolvePartitionUsing` method. Pennant must know about the partition before any store is used, so register it in the `register` method of one of your application's service providers. Registering it after a store has been resolved throws an exception:

```php
use Hypervel\Pennant\Feature;
use Hypervel\Support\Facades\Context;

/**
 * Register any application services.
 */
public function register(): void
{
    Feature::resolvePartitionUsing('team_id', fn (string $store) => Context::get('team_id'));
}
```

The first argument is the partition column, which must be a simple SQL identifier. The closure receives the store name and returns the current partition value. Since it runs every time a feature is checked, it should read a value your application has already placed in the current request's, job's, or command's [context](/docs/{{version}}/context) rather than query the database. It may return an integer or a non-empty string. When it returns `null` or an empty string, Pennant throws a `Hypervel\Pennant\Exceptions\FeaturePartitionNotResolved` exception instead of reading or writing values outside a partition.

Purging features, including with the `pennant:purge` command, only affects the current partition, so a partition value must be available when the command runs. To purge a feature from every partition, run the purge once within each partition.

If some flags should be shared by every partition, such as flags for your application's central administration area, you may give them their own store with `partitioned` set to `false`. Since that store doesn't write a partition value, it needs a table of its own:

```php
'stores' => [
    'central' => [
        'driver' => 'database',
        'table' => 'central_features',
        'partitioned' => false,
    ],

    // ...
],
```

Partitioning applies to the built-in `array` and `database` drivers. A [custom driver](#adding-custom-pennant-drivers) is responsible for keeping each partition's values apart in its own storage.

<a name="partitioned-schema"></a>
### Partitioned Schema

Pennant's migration is not partitioned. When you use partitioning, add a non-null partition column to the published migration and include it in the table's unique index in place of the `name` and `scope` index:

```php
Schema::create('features', function (Blueprint $table) {
    $table->id();
    $table->foreignId('team_id')->constrained()->cascadeOnDelete();

    // ...

    $table->unique(['team_id', 'name', 'scope']);
});
```

Use a column type that matches your partition values, such as `foreignUuid` for UUIDs.

<a name="shared-cache"></a>
## Shared Cache

The `database` driver can share stored values between your servers through a [cache](/docs/{{version}}/cache) store, so checking a feature that isn't already in the [in-memory cache](#in-memory-cache) doesn't need a database query. A cache hit runs no query and doesn't take a connection from the database pool. To enable the shared cache, set the store's `cache` option to an array containing the name of a cache `store` and, optionally, a `ttl` in seconds:

```php
'database' => [
    'driver' => 'database',
    'connection' => null,
    'table' => 'features',
    'cache' => [
        'store' => 'redis',
        'ttl' => 3600,
    ],
],
```

The `ttl` defaults to one hour and must be a positive integer.

The database remains the source of truth. Only values stored in the database are cached: values returned by [`before` methods](#intercepting-feature-checks) and the `false` result of unknown features are never cached. When a cached value expires or is evicted, Pennant reads it from the database again rather than resolving it again, so the cache never changes a feature's stored value.

Updating values through any `database` store invalidates the cached values of every store that reads the same table on the same connection, even when the store making the change has no cache of its own. Invalidation happens after the surrounding database transaction commits. Changes made to the table without Pennant, such as manual queries, aren't seen until the cached values expire. While a transaction is open, Pennant reads from the database and doesn't use the shared cache, so it never caches values that might be rolled back.

A store whose `connection` is `null` shares invalidation with stores on every connection, since it may use any connection selected with `DB::usingConnection()`. A connection's `::write` side shares cached values with the connection itself. If several connection names point to the same database, use the same name for all of your Pennant stores, since Pennant can't tell that they share data.

If invalidating the cache fails after a change has been committed, Pennant throws an exception, and other servers may keep reading the old value until it expires. The same applies if a worker stops between committing a change and invalidating the cache. The `ttl` limits how long such a value may be served. When you use a [cache stack](/docs/{{version}}/cache#building-cache-stacks) with a [Swoole table](/docs/{{version}}/cache#swoole-table-cache) layer, each server's layer may also keep serving a value until that layer's TTL expires, so use a Redis store when changes must reach every server promptly.

<a name="testing"></a>
## Testing

When testing code that interacts with feature flags, the easiest way to control the feature flag's returned value in your tests is to simply re-define the feature. For example, imagine you have the following feature defined in one of your application's service provider:

```php
use Hypervel\Pennant\Feature;
use Hypervel\Support\Arr;

Feature::define('purchase-button', fn () => Arr::random([
    'blue-sapphire',
    'seafoam-green',
    'tart-orange',
]));
```

To modify the feature's returned value in your tests, you may re-define the feature at the beginning of the test. The following test will always pass, even though the `Arr::random()` implementation is still present in the service provider:

```php tab=Pest
use Hypervel\Pennant\Feature;

test('it can control feature values', function () {
    Feature::define('purchase-button', 'seafoam-green');

    expect(Feature::value('purchase-button'))->toBe('seafoam-green');
});
```

```php tab=PHPUnit
use Hypervel\Pennant\Feature;

public function test_it_can_control_feature_values()
{
    Feature::define('purchase-button', 'seafoam-green');

    $this->assertSame('seafoam-green', Feature::value('purchase-button'));
}
```

The same approach may be used for class-based features:

```php tab=Pest
use App\Features\NewApi;
use Hypervel\Pennant\Feature;

test('it can control feature values', function () {
    Feature::define(NewApi::class, true);

    expect(Feature::value(NewApi::class))->toBeTrue();
});
```

```php tab=PHPUnit
use App\Features\NewApi;
use Hypervel\Pennant\Feature;

public function test_it_can_control_feature_values()
{
    Feature::define(NewApi::class, true);

    $this->assertTrue(Feature::value(NewApi::class));
}
```

If your feature is returning a `Lottery` instance, there are a handful of useful [testing helpers available](/docs/{{version}}/helpers#testing-lotteries).

<a name="store-configuration"></a>
#### Store Configuration

You may configure the store that Pennant will use during testing by defining the `PENNANT_STORE` environment variable in your application's `phpunit.xml` file:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit colors="true">
    <!-- ... -->
    <php>
        <env name="PENNANT_STORE" value="array"/>
        <!-- ... -->
    </php>
</phpunit>
```

<a name="adding-custom-pennant-drivers"></a>
## Adding Custom Pennant Drivers

<a name="implementing-the-driver"></a>
#### Implementing the Driver

If none of Pennant's existing storage drivers fit your application's needs, you may write your own storage driver. Your custom driver should implement the `Hypervel\Pennant\Contracts\Driver` interface:

```php
<?php

namespace App\Extensions;

use Hypervel\Pennant\Contracts\Driver;

class RedisFeatureDriver implements Driver
{
    public function define(string $feature, callable $resolver): void {}
    public function defined(): array {}
    public function getAll(array $features): array {}
    public function get(string $feature, mixed $scope): mixed {}
    public function set(string $feature, mixed $scope, mixed $value): void {}
    public function setForAllScopes(string $feature, mixed $value): void {}
    public function delete(string $feature, mixed $scope): void {}
    public function purge(array|null $features): void {}
}
```

Now, we just need to implement each of these methods using a Redis connection. For an example of how to implement each of these methods, take a look at the `Hypervel\Pennant\Drivers\DatabaseDriver` in the [Pennant source code](https://github.com/hypervel/pennant/blob/{{version}}/src/Drivers/DatabaseDriver.php).

Pennant creates each store once and shares it with every request a worker handles, so keep request-specific state out of your driver's properties. Pennant's [in-memory cache](#in-memory-cache) already avoids repeated lookups within a request. If you use [partitioning](#partitioning), your driver is responsible for keeping each partition's values apart.

> [!NOTE]
> Hypervel does not ship with a directory to contain your extensions. You are free to place them anywhere you like. In this example, we have created an `Extensions` directory to house the `RedisFeatureDriver`.

<a name="registering-the-driver"></a>
#### Registering the Driver

Once your driver has been implemented, you are ready to register it with Hypervel. To add additional drivers to Pennant, you may use the `extend` method provided by the `Feature` facade. You should call the `extend` method from the `boot` method of one of your application's [service provider](/docs/{{version}}/providers):

```php
<?php

namespace App\Providers;

use App\Extensions\RedisFeatureDriver;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Pennant\Feature;
use Hypervel\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // ...
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Feature::extend('redis', function (Application $app) {
            return new RedisFeatureDriver($app->make('redis'), $app->make('events'), []);
        });
    }
}
```

Once the driver has been registered, you may use the `redis` driver in your application's `config/pennant.php` configuration file:

```php
'stores' => [

    'redis' => [
        'driver' => 'redis',
        'connection' => null,
    ],

    // ...

],
```

<a name="defining-features-externally"></a>
### Defining Features Externally

If your driver is a wrapper around a third-party feature flag platform, you will likely define features on the platform rather than using Pennant's `Feature::define` method. If that is the case, your custom driver should also implement the `Hypervel\Pennant\Contracts\DefinesFeaturesExternally` interface:

```php
<?php

namespace App\Extensions;

use Hypervel\Pennant\Contracts\DefinesFeaturesExternally;
use Hypervel\Pennant\Contracts\Driver;

class FeatureFlagServiceDriver implements Driver, DefinesFeaturesExternally
{
    /**
     * Get the features defined for the given scope.
     */
    public function definedFeaturesForScope(mixed $scope): array {}

    /* ... */
}
```

The `definedFeaturesForScope` method should return a list of feature names defined for the provided scope.

<a name="events"></a>
## Events

Pennant dispatches a variety of events that can be useful when tracking feature flags throughout your application.

### `Hypervel\Pennant\Events\FeatureRetrieved`

This event is dispatched whenever a [feature is checked](#checking-features). This event may be useful for creating and tracking metrics against a feature flag's usage throughout your application.

### `Hypervel\Pennant\Events\FeatureResolved`

This event is dispatched the first time a feature's value is resolved for a specific scope.

### `Hypervel\Pennant\Events\UnknownFeatureResolved`

This event is dispatched the first time an unknown feature is resolved for a specific scope. Listening to this event may be useful if you have intended to remove a feature flag but have accidentally left stray references to it throughout your application:

```php
<?php

namespace App\Providers;

use Hypervel\Pennant\Events\UnknownFeatureResolved;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Log;
use Hypervel\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(function (UnknownFeatureResolved $event) {
            Log::error("Resolving unknown feature [{$event->feature}].");
        });
    }
}
```

### `Hypervel\Pennant\Events\DynamicallyRegisteringFeatureClass`

This event is dispatched when a [class-based feature](#class-based-features) is dynamically checked for the first time through a store. Since stores are shared by every request a worker handles, it is dispatched once per worker rather than once per request.

### `Hypervel\Pennant\Events\UnexpectedNullScopeEncountered`

This event is dispatched when a `null` scope is passed to a feature definition that [doesn't support null](#nullable-scope).

This situation is handled gracefully and the feature will return `false`. However, if you would like to opt out of this feature's default graceful behavior, you may register a listener for this event in the `boot` method of your application's `AppServiceProvider`:

```php
use Hypervel\Pennant\Events\UnexpectedNullScopeEncountered;
use Hypervel\Support\Facades\Event;

/**
 * Bootstrap any application services.
 */
public function boot(): void
{
    Event::listen(UnexpectedNullScopeEncountered::class, fn () => abort(500));
}
```

### `Hypervel\Pennant\Events\FeatureUpdated`

This event is dispatched when updating a feature for a scope, usually by calling `activate` or `deactivate`.

### `Hypervel\Pennant\Events\FeatureUpdatedForAllScopes`

This event is dispatched when updating a feature for all scopes, usually by calling `activateForEveryone` or `deactivateForEveryone`.

### `Hypervel\Pennant\Events\FeatureDeleted`

This event is dispatched when deleting a feature for a scope, usually by calling `forget`.

### `Hypervel\Pennant\Events\FeaturesPurged`

This event is dispatched when purging specific features.

### `Hypervel\Pennant\Events\AllFeaturesPurged`

This event is dispatched when purging all features.

<a name="credits"></a>
## Credits

Hypervel Pennant began as a port of [Laravel Pennant](https://github.com/laravel/pennant) and has been adapted for Hypervel's framework architecture and coroutine runtime.
