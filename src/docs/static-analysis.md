# Static Analysis

- [Introduction](#introduction)
- [Installation](#installation)
    - [Analyzing Packages](#analyzing-packages)
    - [Runtime Requirements](#runtime-requirements)
- [What the Analysis Knows](#what-the-analysis-knows)
    - [Collection Types](#collection-types)
    - [Model Properties](#model-properties)
    - [Migrations and Schema Dumps](#migrations-and-schema-dumps)
    - [Accessor Types](#accessor-types)
    - [Cast Types](#cast-types)
- [Fixing and Ignoring Diagnostics](#fixing-and-ignoring-diagnostics)
    - [Environment Variables](#environment-variables)
    - [Configuration Values](#configuration-values)
    - [Array Keys](#array-keys)
    - [Value and With Helpers](#value-and-with-helpers)
    - [Converting Collections to Arrays](#converting-collections-to-arrays)
    - [Translations](#translations)
    - [Views](#views)
    - [Console Input](#console-input)
    - [Authentication in Requests](#authentication-in-requests)
    - [Redis Connections](#redis-connections)
    - [Model Relations](#model-relations)
    - [Schema Dumps](#schema-dumps)
    - [Deprecations](#deprecations)

<a name="introduction"></a>
## Introduction

Hypervel's `hypervel/phpstan` package is a [PHPStan](https://phpstan.org) extension that understands your Hypervel application. It is a port of [Larastan](https://github.com/larastan/larastan): it boots your application so PHPStan knows its container services, configuration, authentication guards, console commands, views and translations, and it adds rules that catch common mistakes in Hypervel code.

<a name="installation"></a>
## Installation

To get started, install the package as a development dependency:

```shell
composer require hypervel/phpstan --dev
```

Next, include the extension and the bundled deprecation rules in your `phpstan.neon` file:

```neon
includes:
    - vendor/hypervel/phpstan/extension.neon
    - vendor/phpstan/phpstan-deprecation-rules/rules.neon

parameters:
    level: 5
    paths:
        - app
```

If your project uses the [PHPStan extension installer](https://github.com/phpstan/extension-installer), it loads both extensions automatically, so you should omit the `includes` entries. Once configured, you may analyze your application:

```shell
./vendor/bin/phpstan analyse
```

<a name="analyzing-packages"></a>
### Analyzing Packages

When you analyze a package instead of an application, PHPStan boots the package's Testbench application, so the package should require `hypervel/testbench` as a development dependency. The analysis uses the providers, configuration and package discovery settings from your `testbench.yaml` file, just like your package's tests. Authentication types come from the Testbench application's configuration, where the `AUTH_MODEL` environment variable or the workbench `User` model selects the user model.

<a name="runtime-requirements"></a>
### Runtime Requirements

Since PHPStan boots your application, the PHP CLI that runs it needs the same Swoole setup as your application: Swoole (not OpenSwoole) built with OpenSSL and native cURL support, with `swoole.use_shortname` set to `Off`. If the application cannot boot, PHPStan stops and displays the exception that prevented it.

<a name="what-the-analysis-knows"></a>
## What the Analysis Knows

Configuration types come from your configuration files themselves, never from the values on your machine. For example, the following `enabled` option is a `bool` no matter what your `.env` file contains:

```php
// config/feature.php
return ['enabled' => (bool) env('FEATURE_ENABLED', false)];

// Inferred as bool...
$enabled = config('feature.enabled');
```

The facts your application registers come from the booted application: container bindings, authentication guards and user models, console commands, views and translations, including the default locale. Your environment can influence these facts. For instance, `app('db.connection')` is typed as the connection class your `DB_CONNECTION` variable selects, and the authentication user model may come from `AUTH_MODEL`. So, you should run the analysis with the same environment locally and in CI. When you analyze a package, the analysis sees the Testbench application's registrations, not those of every application that may install the package.

A value typed as an interface only has the interface's methods, even when the container binds a class with more of them or macros are registered on that class. When your code needs a specific implementation, type it as that implementation. Resolving a service from the container, such as `app(Repository::class)`, is typed as the class the booted application binds. So are the factories that helpers such as `response()`, `view()`, `validator()`, `url()` and `logger()` resolve, which is why `response()` has your response macros and `url()->full()` is available.

Calls a manager forwards to its default driver are typed from the return type declared by whatever creates the driver the booted application selects, such as its `create...Driver()` method or an `extend()` callback, unless the manager declares the method or a `@mixin` for its drivers. Analysis never builds the driver, so give `extend()` callbacks a return type. Like container bindings, the selected driver can depend on your environment.

Filesystem disks work the same way. `Storage::disk('s3')` is typed from your `filesystems` configuration as the disk Hypervel builds for it, such as `ClientPooledFilesystem` for a pooled S3 disk or `LocalFilesystemAdapter` for a local one. Calls the `Storage` facade forwards to the default disk are typed from that disk. An unknown or non-constant disk name keeps the `Filesystem` contract type.

Request input is typed as what the request can hold. A keyed `query()`, `post()` or `cookie()` value may be a string, number, boolean or array from the client, or a `Stringable` your code stored, so check or cast it before relying on one type. Whole input arrays may have integer keys, such as from a JSON list body. By default, typed helpers such as `$request->string()`, `integer()` and `boolean()` return a single type.

Dates are different: `now()`, `today()` and the `Date` facade return `CarbonInterface`, as they are declared, even when your application configures a specific date class. When your code needs a specific class, convert the value, such as with `toImmutable()`.

Some facts cannot be known statically. When the analysis cannot tell what a configuration key holds, such as a value that provider code writes in a way the analysis does not recognize and that did not run while your application booted, it uses the declared return type instead of guessing.

Calling `str()` without arguments returns an object that passes method calls to `Str`. The analysis does not check those calls.

<a name="collection-types"></a>
### Collection Types

Collections keep Laravel's declared types, so a collection of users may be passed where a collection of models is expected. Writes through the wider reference are checked against its model type, so the analysis can miss a value that violates the original collection's narrower user type.

If you enable PHPStan's [bleeding edge](https://phpstan.org/blog/what-is-bleeding-edge) mode, PHPStan keeps the value type it infers for a new collection, so adding or searching for a value of another type is reported:

```php
$ids = collect([1, 2, 3]);

$ids->push(4); // Reported with bleeding edge...
```

Declare the type the collection is meant to hold when you create it:

```php
/** @var Collection<int, int> $ids */
$ids = collect([1, 2, 3]);
```

A custom collection class that fixes its item type, such as a `UserCollection` that extends `Collection<int, User>`, keeps that type when methods such as `map()`, `mapWithKeys()`, `groupBy()` and `chunk()` return the same class holding other items. This is a PHPStan limitation. For example, `$users->groupBy('team_id')` is typed as a `UserCollection` of users, although it holds user collections. Declare the result's type where your code relies on it:

```php
use Hypervel\Support\Collection;

/** @var Collection<array-key, UserCollection> $teams */
$teams = $users->groupBy('team_id');
```

<a name="model-properties"></a>
### Model Properties

Model attributes are typed from the database schema your migrations and schema dumps describe, without connecting to a database. Reading an attribute the model's table does not have is reported:

```php
public function greeting(User $user): string
{
    return "Hello, {$user->nmae}"; // Access to an undefined property App\Models\User::$nmae...
}
```

Each model reads the schema of its own connection. A pooled connection, such as `pgsql-pooled`, reads the schema of the connection its migrations run on.

Attributes are typed as your database driver returns them. For example, a `decimal` column reads as a numeric string on MySQL, since decimals are fetched as strings to keep their precision. The connection's PDO options, such as `PDO::ATTR_STRINGIFY_FETCHES`, are taken into account. On SQLite, the types describe the values Hypervel writes, since SQLite only enforces column types on `STRICT` tables. [Casts](/docs/{{version}}/eloquent-mutators#attribute-casting), dates and accessors take precedence over the column's type, so cast an attribute when your code needs a specific type.

When the analysis cannot know whether a table has a column, such as after a migration it cannot follow, the attribute reads as `mixed` and is not reported as missing.

<a name="migrations-and-schema-dumps"></a>
### Migrations and Schema Dumps

The analysis finds migrations as the `migrate` command does: the `*_*.php` files directly in the directories, and the files, your service providers register with `loadMigrationsFrom`, then in your `database/migrations` directory. A migration with the same name as an earlier one replaces it, so a published migration takes the place of its package's, and migrations apply in name order. It also reads the [schema dumps](/docs/{{version}}/migrations#squashing-migrations) in your `database/schema` directory. To read them from other directories, list the directories using the `databaseMigrationsPath` or `squashedMigrationsPath` parameter. Each replaces its defaults and accepts glob patterns, and every `.php` file below a listed migrations directory is read, including those in subdirectories:

```neon
parameters:
    databaseMigrationsPath:
        - database/migrations
        - modules/*/migrations
    squashedMigrationsPath:
        - database/schema
```

A schema dump is only read when it has the name the `schema:dump` Artisan command gives it: `{connection}-schema.sql`, or `{connection}-schema.dump`, where `{connection}` is a connection in your `database` configuration. When both exist, the `.dump` file is read, as the `migrate` command does. The schema of a connection with no dump and no migrations is unknown.

Migrations are read as they are written, without running them. Each statement applies to the connection it runs on: the connection given to `Schema::connection()` or `DB::connection()`, otherwise the migration's connection. The analysis follows schema builder calls, `if` and `switch` statements, `Schema::hasTable()` and `Schema::hasColumn()` checks, and literal SQL passed to `DB::statement()` and `DB::unprepared()`. A column that only some branches create reads as `mixed`. Schema builders stored in local variables are followed too:

```php
$schema = Schema::connection('analytics');

$schema->create('events', function (Blueprint $table) {
    // ...
});
```

Code the analysis cannot follow makes what it could change unknown. Blueprint macros, loops and helpers that receive the `$table` blueprint make the table unknown, and a column name held in a variable means the table may have columns the analysis does not know. A table name the analysis cannot work out makes its connection's schema unknown, and a connection name it cannot work out, such as one built at runtime, makes the schema of every connection unknown.

Schema changes made through a connection the migration gets any other way, such as `$this->db` or `app('db')`, are not seen, so make them through the `Schema` and `DB` facades. Each `DB::statement()` call is also read on its own, so a session change that one call makes for later calls, such as `USE` or `SET search_path`, is not tracked. Instead, define a connection for the other database or schema in your `database` configuration and select it.

Migrations and schema dumps are cached in PHPStan's temporary directory and read again when they, or the classes and configuration they read, change. You may disable the cache using the `enableMigrationCache` parameter, or stop reading migrations or schema dumps using the `disableMigrationScan` or `disableSchemaScan` parameter.

<a name="accessor-types"></a>
### Accessor Types

An accessor's `Attribute` declares the type its getter returns and the type its setter accepts. The declared getter type may be wider than what the getter returns, and the declared setter type narrower than what the setter accepts:

```php
/**
 * @return Attribute<string, never>
 */
protected function fullName(): Attribute
{
    return Attribute::make(
        get: fn () => "{$this->first_name} {$this->last_name}",
    );
}
```

To keep these declarations sound, the analysis treats an attribute's `get` and `set` callbacks as read-only once the attribute is created, so assigning `$attribute->get` or `$attribute->set` afterwards is reported as `property.readOnlyByPhpDocAssignOutOfClass`. Reading and writing the model attribute itself is not affected. If your code must replace a callback, ignore the diagnostic with a reason.

<a name="cast-types"></a>
### Cast Types

The analysis reads a model's `casts` method without running it. It understands a single `return` of an array whose casts are strings, class constants, the `[$cast, ...$arguments]` array form, and helpers such as `AsCollection::of` and `AsEnumCollection::of` given constant arguments.

An attribute whose cast the analysis cannot work out, such as one built from a variable, reads and writes as `mixed`. When the method does not return such an array, for example because it merges the parent's casts or returns from several places, any attribute of the model may be cast, so all of them read as `mixed`. Declare the casts the method returns with an array shape to type them:

```php
/**
 * @return array{options: 'array', status: 'App\\Enums\\Status'}
 */
protected function casts(): array
{
    return array_merge(parent::casts(), [
        'options' => 'array',
        'status' => Status::class,
    ]);
}
```

The shape must list every cast the method can return, including inherited ones, since an attribute it leaves out is treated as not cast by the method. If you cannot list them all, end the shape with `...`, and the attributes it does not list keep reading as `mixed`.

<a name="fixing-and-ignoring-diagnostics"></a>
## Fixing and Ignoring Diagnostics

Whenever possible, fix the code a diagnostic points to. Ignore a diagnostic only for a legitimate exception or a limit of the analysis, and give the ignore the diagnostic's identifier and a reason:

```php
// @phpstan-ignore hypervel.noEnvCallsOutsideOfConfig (the test runner sets this token for each worker)
$token = Env::get('TEST_TOKEN');
```

Avoid `@phpstan-ignore-line` and `@phpstan-ignore-next-line`, which hide every error on the line, and always give a reason, so the next reader knows why the diagnostic does not apply. Never add a real defect to a baseline.

The following table summarizes the preferred pattern for each of Hypervel's diagnostics:

| Topic | Preferred Pattern | Identifiers |
|---|---|---|
| [Environment Variables](#environment-variables) | Read the environment in configuration files and use `config()` elsewhere. | `hypervel.noEnvCallsOutsideOfConfig` |
| [Configuration Values](#configuration-values) | Use the typed getter that matches the configuration value. | `hypervel.configType`, `hypervel.configCollection` |
| [Array Keys](#array-keys) | Read keys the array has, or pass a default for optional keys. | `offsetAccess.notFound` |
| [Value and With Helpers](#value-and-with-helpers) | Use the value directly when no closure or callback runs. | `hypervel.uselessConstructs.value`, `hypervel.uselessConstructs.with` |
| [Converting Collections to Arrays](#converting-collections-to-arrays) | Use `all()` when no items need converting. | `hypervel.unnecessaryEnumerableToArrayCall` |
| [Translations](#translations) | Use translation keys that exist. | `hypervel.missingTranslations` |
| [Views](#views) | Use view names the application can find. | `hypervel.unusedViews`, `argument.type` for view names |
| [Console Input](#console-input) | Read the arguments and options the command defines. | `hypervel.console.undefinedArgument`, `hypervel.console.undefinedOption` |
| [Authentication in Requests](#authentication-in-requests) | Use `$request->user()` where a request is available. | `hypervel.noAuthFacadeInRequestScope`, `hypervel.noAuthHelperInRequestScope` |
| [Redis Connections](#redis-connections) | Call connection-bound methods on a held connection. | `hypervel.redisConnectionBoundMethod` |
| [Model Relations](#model-relations) | Load and query relations the model defines. | `hypervel.relationExistence` |
| [Schema Dumps](#schema-dumps) | Keep schema dumps in the plain SQL `schema:dump` writes. | `hypervel.schemaDump` |
| [Deprecations](#deprecations) | Use the replacement the deprecation names. | `method.deprecated` and the other deprecation identifiers |

<a name="environment-variables"></a>
### Environment Variables

You should only read environment variables in your configuration files. Once your configuration is cached, the `.env` file is no longer loaded, so a read anywhere else only sees the process environment. Instead, read the variable in a configuration file and use `config()` elsewhere:

```php
// config/services.php
'billing' => [
    'key' => env('BILLING_KEY'),
],

// Elsewhere in your application...
$key = config('services.billing.key');
```

The rule checks calls to the `env`, `env_array`, `env_or_fail` and `app_id` helpers and to the `Env::get`, `Env::getArray` and `Env::getOrFail` methods. Calls within your configuration directories, the framework's configuration files and the configuration files that service providers merge or publish are allowed. Only direct calls are checked: callables, dynamic class names, `getenv()` and `$_ENV` are not.

Some infrastructure code genuinely needs the process environment, such as code that runs before configuration loads, values passed to child processes, or settings provided by a test runner. Ignore those calls individually, with a reason. You may disable the rule using the `noEnvCallsOutsideOfConfig` parameter.

<a name="configuration-values"></a>
### Configuration Values

The typed configuration getters, such as `Config::string()` and `config()->integer()`, throw an exception when the value has a different type. The analysis reports a typed getter, or a `collection()` call, whose key can never hold the required type:

```php
// config/queue.php
'retries' => '3',

// Config key 'queue.retries' is not an integer, so integer() throws...
$retries = Config::integer('queue.retries');
```

Correct the configuration value, casting environment variables within the configuration file, or use the getter that matches the value's type. Reserve `get()` for values that are intentionally nullable, a union of types, or unrestricted:

```php
'retries' => (int) env('QUEUE_RETRIES', 3),
```

Only values the analysis knows are checked. A key the analysis cannot follow keeps the getter's declared type.

Configuration types include the defaults of any package whose configuration your application has not published. If a package supports configuration that is wider than its shipped default, its code should declare the contract it accepts where it reads the value, rather than relying on the type of the default:

```php
/** @var list<string> $allowedOrigins */
$allowedOrigins = config()->array('mcp.allowed_origins');
```

Similarly, when a package ships a `null` default that applications may replace, a typed getter reached only after a `null` check can still be reported, since the analysis reads the configuration key rather than your local value. Ignore that call with a reason. These checks may be disabled using the `checkConfigTypes` parameter.

<a name="array-keys"></a>
### Array Keys

Calls to `Arr::get()` and `Arr::pull()` are checked against the keys the array is known to have, following keys in "dot" notation through nested arrays. A key the array never has is reported, since the call always returns `null`:

```php
/**
 * @param array{name: string, email: string} $user
 */
function notify(array $user): void
{
    $email = Arr::get($user, 'mail'); // Offset 'mail' does not exist...
}
```

Correct the key. When a key is optional by design, pass the default you expect, since calls with a default are not reported:

```php
$timeout = Arr::get($options, 'timeout', 30);
```

Arrays whose keys are not known, such as `array<string, mixed>`, are not checked.

<a name="value-and-with-helpers"></a>
### Value and With Helpers

The `value` helper only calls a closure, and the `with` helper only calls its callback, so a call that passes neither returns the value unchanged and is reported:

```php
$name = value($user->name); // Reported...
$name = with($user->name); // Reported...

$name = $user->name;
```

Use the value directly. When the value may be a closure, such as a default that callers may compute lazily, keep the `value` call; the analysis does not report it.

<a name="converting-collections-to-arrays"></a>
### Converting Collections to Arrays

A collection's `toArray()` method converts each `Arrayable` item to an array as well, while `all()` returns the items as they are. When no item is `Arrayable`, the two return the same array, so the analysis recommends `all()`:

```php
// Called [toArray()] on an Enumerable which does not contain any Arrayables...
$ids = collect([1, 2, 3])->toArray();

$ids = collect([1, 2, 3])->all();
```

Keep `toArray()` when the items are, or may be, `Arrayable`, such as models. Collections that override `toArray()`, `all()` or `map()` are not checked. You may disable the rule using the `noUnnecessaryEnumerableToArrayCalls` parameter.

<a name="translations"></a>
### Translations

The `__`, `trans` and `trans_choice` helpers, the `Lang` facade and the translator are checked for keys that are missing from a translation file the application can load. Only literal `group.item` and `namespace::group.item` keys are checked, and only when the group's file exists for the requested locale or its fallback. When a lookup does not specify a locale, the application's default locale is used. JSON translations, dynamic keys and custom translators or loaders are not checked.

To fix the diagnostic, add the missing line or correct the key. You may disable the rule using the `checkMissingTranslations` parameter.

<a name="views"></a>
### Views

Methods that accept a view name, such as `view()` and `View::make()`, require a view the booted application can find, so a misspelled view name is reported as an `argument.type` error. If a package only registers a view namespace when a command runs or a feature is enabled, the analysis cannot see those views; ignore those calls with a reason.

You may also enable the `checkUnusedViews` parameter to report views that no code refers to by a literal name. Views rendered by computed names or used by other packages may still be in use, so treat these diagnostics as a list to review rather than proof that a view can be deleted.

<a name="console-input"></a>
### Console Input

Calls to a command's `argument()` and `option()` methods are checked against the command's definition. Define the argument or option in the command's signature, or check `hasArgument()` or `hasOption()` before reading an input the command may not define:

```php
if ($this->hasOption('force') && $this->option('force')) {
    // ...
}
```

<a name="authentication-in-requests"></a>
### Authentication in Requests

When the `checkAuthCallsWhenInRequestScope` parameter is enabled, the analysis recommends `$request->user()` over `Auth::user()` and `auth()->user()` where a request is available. The facade and helper remain fully supported. Since a request's user resolver may return a different user than the `Auth` facade, review each change instead of replacing calls mechanically.

<a name="redis-connections"></a>
### Redis Connections

`Redis::connection()` returns a proxy that runs each command on a pooled connection, so methods that act on one connection, such as `client()`, `auth()` or `release()`, are reported. Call them on a held connection instead:

```php
use Hypervel\Redis\RedisConnection;
use Hypervel\Support\Facades\Redis;

Redis::withConnection(function (RedisConnection $connection) {
    $client = $connection->client();
});
```

These checks may be disabled using the `checkRedisConnectionBoundMethods` parameter.

<a name="model-relations"></a>
### Model Relations

Eager loading, relationship queries and relationship aggregates report a relation the model does not define. Eager loading and the `has`, `whereHas` and `whereRelation` families also check each segment of a nested path:

```php
// Relation 'comment' is not found in App\Models\Post model.
$users = User::with('posts.comment')->get();
```

A relation registered using `resolveRelationUsing` while the application boots is found, though the segments after it are not checked. Relations on an abstract model or a union of models are not checked, since each model may define them differently.

<a name="schema-dumps"></a>
### Schema Dumps

When a schema dump your models use cannot be read, such as a PostgreSQL dump in `pg_dump`'s custom archive format or a dump with a syntax error, the analysis reports it once, at the dump:

```text
database/schema/pgsql-schema.dump:1: Model property types cannot use this schema dump: PostgreSQL archives must be exported as plain SQL before parsing.
```

Until the dump is fixed, the tables it defines are unknown, so their attributes read as `mixed`. Regenerate the dump using the `schema:dump` Artisan command, which writes plain SQL. A dump is only reported when the analysed code reads a model attribute on its connection. You may stop reading schema dumps using the `disableSchemaScan` parameter.

<a name="deprecations"></a>
### Deprecations

The bundled deprecation rules report uses of deprecated APIs from any of your dependencies, including PHP, Symfony and Carbon as well as Hypervel. Use the replacement the deprecation names. When you still need the deprecated API, such as to keep a public API compatible, ignore it with the reported identifier and a reason.

Deprecated methods and properties reached through a proxy, such as `$users->each->notify()`, `optional($user)->notify()` or `$user->when($active)->notify()`, are not reported, since PHPStan's deprecation checks only look at the proxy class itself.

To disable deprecation reporting, remove the `rules.neon` include. If you use the extension installer, add `phpstan/phpstan-deprecation-rules` to the `extra.phpstan/extension-installer.ignore` list in your `composer.json` file instead.
