# Hypervel Telescope

- [Introduction](#introduction)
- [Installation](#installation)
    - [Local Only Installation](#local-only-installation)
    - [Configuration](#configuration)
    - [Data Pruning](#data-pruning)
    - [Dashboard Authorization](#dashboard-authorization)
- [Upgrading Telescope](#upgrading-telescope)
- [Managing Telescope](#managing-telescope)
- [Filtering](#filtering)
    - [Entries](#filtering-entries)
    - [Batches](#filtering-batches)
- [Tagging](#tagging)
- [Available Watchers](#available-watchers)
    - [Batch Watcher](#batch-watcher)
    - [Cache Watcher](#cache-watcher)
    - [Command Watcher](#command-watcher)
    - [Dump Watcher](#dump-watcher)
    - [Event Watcher](#event-watcher)
    - [Exception Watcher](#exception-watcher)
    - [Gate Watcher](#gate-watcher)
    - [HTTP Client Watcher](#http-client-watcher)
    - [Job Watcher](#job-watcher)
    - [Log Watcher](#log-watcher)
    - [Mail Watcher](#mail-watcher)
    - [Model Watcher](#model-watcher)
    - [Notification Watcher](#notification-watcher)
    - [Query Watcher](#query-watcher)
    - [Redis Watcher](#redis-watcher)
    - [Reverb Watcher](#reverb-watcher)
    - [Request Watcher](#request-watcher)
    - [Schedule Watcher](#schedule-watcher)
    - [View Watcher](#view-watcher)
- [Displaying User Avatars](#displaying-user-avatars)

<a name="introduction"></a>
## Introduction

[Hypervel Telescope](https://github.com/hypervel/telescope) makes a wonderful companion to your local Hypervel development environment. Telescope provides insight into the requests coming into your application, exceptions, log entries, database queries, queued jobs, mail, notifications, cache operations, scheduled tasks, variable dumps, and more.

<a name="installation"></a>
## Installation

You may use the Composer package manager to install Telescope into your Hypervel project:

```shell
composer require hypervel/telescope
```

After installing Telescope, publish its configuration, migrations, and service provider using the `telescope:install` Artisan command. After installing Telescope, you should also run the `migrate` command in order to create the tables needed to store Telescope's data:

```shell
php artisan telescope:install

php artisan migrate
```

Finally, you may access the Telescope dashboard via the `/telescope` route.

<a name="local-only-installation"></a>
### Local Only Installation

If you plan to only use Telescope to assist your local development, you may install Telescope using the `--dev` flag:

```shell
composer require hypervel/telescope --dev

php artisan telescope:install

php artisan migrate
```

After running `telescope:install`, you should remove the `TelescopeServiceProvider` service provider registration from your application's `bootstrap/providers.php` configuration file. Instead, manually register Telescope's service providers in the `register` method of your `App\Providers\AppServiceProvider` class. We will ensure the current environment is `local` before registering the providers:

```php
/**
 * Register any application services.
 */
public function register(): void
{
    if ($this->app->environment('local') && class_exists(\Hypervel\Telescope\TelescopeServiceProvider::class)) {
        $this->app->register(\Hypervel\Telescope\TelescopeServiceProvider::class);
        $this->app->register(TelescopeServiceProvider::class);
    }
}
```

Finally, you should also prevent the Telescope package from being [auto-discovered](/docs/{{version}}/packages#package-discovery) by adding the following to your `composer.json` file:

```json
"extra": {
    "hypervel": {
        "dont-discover": [
            "hypervel/telescope"
        ]
    }
},
```

<a name="configuration"></a>
### Configuration

After publishing Telescope's configuration, its primary configuration file will be located at `config/telescope.php`. This configuration file allows you to configure your [watcher options](#available-watchers). Each configuration option includes a description of its purpose, so be sure to thoroughly explore this file.

If desired, you may disable Telescope's data collection entirely using the `enabled` configuration option:

```php
'enabled' => env('TELESCOPE_ENABLED', true),
```

<a name="data-pruning"></a>
### Data Pruning

Without pruning, the `telescope_entries` table can accumulate records very quickly. To mitigate this, you should [schedule](/docs/{{version}}/scheduling) the `telescope:prune` Artisan command to run daily:

```php
use Hypervel\Support\Facades\Schedule;

Schedule::command('telescope:prune')->daily();
```

By default, all entries older than 24 hours will be pruned. You may use the `hours` option when calling the command to determine how long to retain Telescope data. For example, the following command will delete all records created over 48 hours ago:

```php
use Hypervel\Support\Facades\Schedule;

Schedule::command('telescope:prune --hours=48')->daily();
```

You may also use the `keep-exceptions` option to retain exception entries while pruning other stale entries:

```php
use Hypervel\Support\Facades\Schedule;

Schedule::command('telescope:prune --keep-exceptions')->daily();
```

<a name="dashboard-authorization"></a>
### Dashboard Authorization

The Telescope dashboard may be accessed via the `/telescope` route. By default, you will only be able to access this dashboard in the `local` environment. Within your `app/Providers/TelescopeServiceProvider.php` file, there is an [authorization gate](/docs/{{version}}/authorization#gates) definition. This authorization gate controls access to Telescope in **non-local** environments. You are free to modify this gate as needed to restrict access to your Telescope installation:

```php
use App\Models\User;

/**
 * Register the Telescope gate.
 *
 * This gate determines who can access Telescope in non-local environments.
 */
protected function gate(): void
{
    Gate::define('viewTelescope', function (User $user) {
        return in_array($user->email, [
            'albert@hypervel.org',
        ]);
    });
}
```

> [!WARNING]
> You should ensure you change your `APP_ENV` environment variable to `production` in your production environment. Otherwise, your Telescope installation will be publicly available.

<a name="upgrading-telescope"></a>
## Upgrading Telescope

When upgrading to a new major version of Telescope, it's important that you carefully review [the release notes](https://github.com/hypervel/telescope/releases).

In addition, when upgrading to any new Telescope version, you may re-publish Telescope's configuration file to review any newly added options:

```shell
php artisan telescope:publish --force
```

<a name="managing-telescope"></a>
## Managing Telescope

You may pause Telescope recording without disabling the package entirely using the `telescope:pause` command:

```shell
php artisan telescope:pause
```

To resume recording, use the `telescope:resume` command:

```shell
php artisan telescope:resume
```

You may delete all Telescope entries and monitored tags using the `telescope:clear` command:

```shell
php artisan telescope:clear
```

<a name="filtering"></a>
## Filtering

<a name="filtering-entries"></a>
### Entries

You may filter the data that is recorded by Telescope via the `filter` closure that is defined in your `App\Providers\TelescopeServiceProvider` class. By default, this closure records all data in the `local` environment and exceptions, failed jobs, scheduled tasks, and data with monitored tags in all other environments:

```php
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;

/**
 * Register any application services.
 */
public function register(): void
{
    $this->hideSensitiveRequestDetails();

    Telescope::filter(function (IncomingEntry $entry) {
        if ($this->app->environment('local')) {
            return true;
        }

        return $entry->isReportableException() ||
            $entry->isFailedJob() ||
            $entry->isScheduledTask() ||
            $entry->isSlowQuery() ||
            $entry->hasMonitoredTag();
    });
}
```

<a name="filtering-batches"></a>
### Batches

While the `filter` closure filters data for individual entries, you may use the `filterBatch` method to register a closure that filters all data for a given request or console command. If the closure returns `true`, all of the entries are recorded by Telescope:

```php
use Hypervel\Support\Collection;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;

/**
 * Register any application services.
 */
public function register(): void
{
    $this->hideSensitiveRequestDetails();

    Telescope::filterBatch(function (Collection $entries) {
        if ($this->app->environment('local')) {
            return true;
        }

        return $entries->contains(function (IncomingEntry $entry) {
            return $entry->isReportableException() ||
                $entry->isFailedJob() ||
                $entry->isScheduledTask() ||
                $entry->isSlowQuery() ||
                $entry->hasMonitoredTag();
            });
    });
}
```

<a name="tagging"></a>
## Tagging

Telescope allows you to search entries by "tag". Often, tags are Eloquent model class names or authenticated user IDs which Telescope automatically adds to entries. Occasionally, you may want to attach your own custom tags to entries. To accomplish this, you may use the `Telescope::tag` method. The `tag` method accepts a closure which should return an array of tags. The tags returned by the closure will be merged with any tags Telescope would automatically attach to the entry. Typically, you should call the `tag` method within the `register` method of your `App\Providers\TelescopeServiceProvider` class:

```php
use Hypervel\Telescope\EntryType;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;

/**
 * Register any application services.
 */
public function register(): void
{
    $this->hideSensitiveRequestDetails();

    Telescope::tag(function (IncomingEntry $entry) {
        return $entry->type === EntryType::REQUEST
            ? ['status:'.$entry->content['response_status']]
            : [];
    });
}
```

<a name="available-watchers"></a>
## Available Watchers

Telescope "watchers" gather application data when a request or console command is executed. You may customize the list of watchers that you would like to enable within your `config/telescope.php` configuration file:

```php
'watchers' => [
    Watchers\CacheWatcher::class => true,
    Watchers\CommandWatcher::class => true,
    // ...
],
```

Some watchers also allow you to provide additional customization options:

```php
'watchers' => [
    Watchers\QueryWatcher::class => [
        'enabled' => env('TELESCOPE_QUERY_WATCHER', true),
        'slow' => 100,
    ],
    // ...
],
```

<a name="batch-watcher"></a>
### Batch Watcher

The batch watcher records information about queued [batches](/docs/{{version}}/queues#job-batching), including the job and connection information.

<a name="cache-watcher"></a>
### Cache Watcher

The cache watcher records data when a cache key is hit, missed, updated and forgotten.

<a name="command-watcher"></a>
### Command Watcher

The command watcher records the arguments, options, exit code, and output whenever an Artisan command is executed. If you would like to exclude certain commands from being recorded by the watcher, you may specify the command in the `ignore` option within your `config/telescope.php` file:

```php
'watchers' => [
    Watchers\CommandWatcher::class => [
        'enabled' => env('TELESCOPE_COMMAND_WATCHER', true),
        'ignore' => ['key:generate'],
    ],
    // ...
],
```

<a name="dump-watcher"></a>
### Dump Watcher

The dump watcher records and displays your variable dumps in Telescope. When using Hypervel, variables may be dumped using the global `dump` function. The dump watcher tab must be open in a browser for the dump to be recorded, otherwise, the dumps will be ignored by the watcher.

<a name="event-watcher"></a>
### Event Watcher

The event watcher records the payload, listeners, and broadcast data for any [events](/docs/{{version}}/events) dispatched by your application. The Hypervel framework's internal events are ignored by the Event watcher.

<a name="exception-watcher"></a>
### Exception Watcher

The exception watcher records the data and stack trace for any reportable exceptions that are thrown by your application.

<a name="gate-watcher"></a>
### Gate Watcher

The gate watcher records the data and result of [gate and policy](/docs/{{version}}/authorization) checks by your application. If you would like to exclude certain abilities from being recorded by the watcher, you may specify those in the `ignore_abilities` option in your `config/telescope.php` file:

```php
'watchers' => [
    Watchers\GateWatcher::class => [
        'enabled' => env('TELESCOPE_GATE_WATCHER', true),
        'ignore_abilities' => ['viewNova'],
    ],
    // ...
],
```

<a name="http-client-watcher"></a>
### HTTP Client Watcher

The HTTP client watcher records outgoing [HTTP client requests](/docs/{{version}}/http-client) made by your application.

You may exclude individual outgoing requests from being recorded or attach custom Telescope tags using the HTTP client's [Telescope recording methods](/docs/{{version}}/http-client#telescope-recording).

You may ignore specific hosts or limit the recorded request and response payload sizes using the watcher's configuration options:

```php
'watchers' => [
    Watchers\ClientRequestWatcher::class => [
        'enabled' => env('TELESCOPE_CLIENT_REQUEST_WATCHER', true),
        'ignore_hosts' => [],
        'request_size_limit' => env('TELESCOPE_HTTP_CLIENT_REQUEST_SIZE_LIMIT', 64),
        'response_size_limit' => env('TELESCOPE_HTTP_CLIENT_RESPONSE_SIZE_LIMIT', 64),
        'truncate_oversized' => env('TELESCOPE_HTTP_CLIENT_TRUNCATE_OVERSIZED', false),
    ],

    // ...
],
```

By default, oversized payloads are replaced with `Purged By Telescope` without reading or processing the body. If `truncate_oversized` is enabled, Telescope will read, mask, and truncate the payload to the configured size limit.

<a name="job-watcher"></a>
### Job Watcher

The job watcher records the data and status of any [jobs](/docs/{{version}}/queues) dispatched by your application. When the [Context](/docs/{{version}}/context) facade contains data, Telescope stores that context with the job entry so queued work can be traced back to the request or command that dispatched it.

<a name="log-watcher"></a>
### Log Watcher

The log watcher records the [log data](/docs/{{version}}/logging) for any logs written by your application. Context shared through Hypervel's [Context](/docs/{{version}}/context) facade is displayed with the log entry metadata.

By default, Telescope will only record logs at the `error` level and above. However, you can modify the `level` option in your application's `config/telescope.php` configuration file to modify this behavior:

```php
'watchers' => [
    Watchers\LogWatcher::class => [
        'enabled' => env('TELESCOPE_LOG_WATCHER', true),
        'level' => 'debug',
    ],

    // ...
],
```

<a name="mail-watcher"></a>
### Mail Watcher

The mail watcher allows you to view an in-browser preview of [emails](/docs/{{version}}/mail) sent by your application along with their associated data. You may also download the email as an `.eml` file.

<a name="model-watcher"></a>
### Model Watcher

The model watcher records model changes whenever an Eloquent [model event](/docs/{{version}}/eloquent#events) is dispatched. You may specify which model events should be recorded via the watcher's `events` option:

```php
'watchers' => [
    Watchers\ModelWatcher::class => [
        'enabled' => env('TELESCOPE_MODEL_WATCHER', true),
        'events' => ['eloquent.created*', 'eloquent.updated*'],
    ],
    // ...
],
```

If you would like to record the number of models hydrated during a given request, enable the `hydrations` option:

```php
'watchers' => [
    Watchers\ModelWatcher::class => [
        'enabled' => env('TELESCOPE_MODEL_WATCHER', true),
        'events' => ['eloquent.created*', 'eloquent.updated*'],
        'hydrations' => true,
    ],
    // ...
],
```

<a name="notification-watcher"></a>
### Notification Watcher

The notification watcher records all [notifications](/docs/{{version}}/notifications) sent by your application. If the notification triggers an email and you have the mail watcher enabled, the email will also be available for preview on the mail watcher screen.

<a name="query-watcher"></a>
### Query Watcher

The query watcher records the raw SQL, bindings, and execution time for all queries that are executed by your application. The watcher also tags any queries slower than 100 milliseconds as `slow`. You may customize the slow query threshold using the watcher's `slow` option:

```php
'watchers' => [
    Watchers\QueryWatcher::class => [
        'enabled' => env('TELESCOPE_QUERY_WATCHER', true),
        'slow' => 50,
    ],
    // ...
],
```

<a name="redis-watcher"></a>
### Redis Watcher

The Redis watcher records all [Redis](/docs/{{version}}/redis) commands executed by your application. If you are using Redis for caching, cache commands will also be recorded by the Redis watcher.

<a name="reverb-watcher"></a>
### Reverb Watcher

The Reverb watcher records [Reverb](/docs/{{version}}/reverb) WebSocket events such as established and closed connections, created and removed channels, and pruned connections:

```php
'watchers' => [
    Watchers\ReverbWatcher::class => [
        'enabled' => env('TELESCOPE_REVERB_WATCHER', true),
        'events' => [
            'connection_established',
            'connection_closed',
            'channel_created',
            'channel_removed',
            'connection_pruned',
        ],
        'message_size_limit' => env('TELESCOPE_REVERB_MESSAGE_SIZE_LIMIT', 64),
    ],

    // ...
],
```

You may also add `message_received` or `message_sent` to the `events` array to record WebSocket message payloads. These events can create a large number of Telescope entries, so they should only be enabled for targeted debugging.

<a name="request-watcher"></a>
### Request Watcher

The request watcher records the request, headers, session, and response data associated with any requests handled by the application. Hypervel also records the current [Context](/docs/{{version}}/context) data and low-level [coroutine context](/docs/{{version}}/coroutine-context) for the request, which are shown on the request detail view. You may limit your recorded response data via the `size_limit` (in kilobytes) option:

```php
'watchers' => [
    Watchers\RequestWatcher::class => [
        'enabled' => env('TELESCOPE_REQUEST_WATCHER', true),
        'size_limit' => env('TELESCOPE_RESPONSE_SIZE_LIMIT', 64),
        'ignore_http_methods' => [],
        'ignore_status_codes' => [],
    ],
    // ...
],
```

You may also use the `ignore_http_methods` and `ignore_status_codes` options to skip recording requests by HTTP method or response status code.

<a name="schedule-watcher"></a>
### Schedule Watcher

The schedule watcher records the command and output of any [scheduled tasks](/docs/{{version}}/scheduling) run by your application.

<a name="view-watcher"></a>
### View Watcher

The view watcher records the [view](/docs/{{version}}/views) name, path, data, and "composers" used when rendering views.

<a name="displaying-user-avatars"></a>
## Displaying User Avatars

The Telescope dashboard displays the user avatar for the user that was authenticated when a given entry was saved. By default, Telescope will retrieve avatars using the Gravatar web service. However, you may customize the avatar URL by registering a callback in your `App\Providers\TelescopeServiceProvider` class. The callback will receive the user's ID and email address and should return the user's avatar image URL:

```php
use App\Models\User;
use Hypervel\Telescope\Telescope;

/**
 * Register any application services.
 */
public function register(): void
{
    // ...

    Telescope::avatar(function (?string $id, ?string $email) {
        return ! is_null($id)
            ? '/avatars/'.User::find($id)->avatar_path
            : '/generic-avatar.jpg';
    });
}
```
