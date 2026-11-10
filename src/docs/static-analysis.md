# Static Analysis

- [Introduction](#introduction)
- [Installation](#installation)
    - [Analyzing Packages](#analyzing-packages)
    - [Runtime Requirements](#runtime-requirements)
- [Configuration](#configuration)
- [What the Analysis Knows](#what-the-analysis-knows)
    - [Collection Types](#collection-types)
    - [Model Properties](#model-properties)
    - [Migrations and Schema Dumps](#migrations-and-schema-dumps)
    - [Accessor Types](#accessor-types)
    - [Cast Types](#cast-types)
    - [Model Serialization](#model-serialization)
    - [Relationships](#relationships)
    - [Custom Builders and Collections](#custom-builders-and-collections)
- [Custom PHPDoc Types](#custom-phpdoc-types)
    - [The `view-string` Type](#view-string)
    - [The `model-property` Type](#model-property)
    - [The `collection-of` Type](#collection-of)
    - [The `builder-of` Type](#builder-of)
    - [The `relation-of` Type](#relation-of)
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
    - [Cancellation](#cancellation)
    - [Coroutines in Transactions](#coroutines-in-transactions)
    - [Sleeping](#sleeping)
    - [Worker State](#worker-state)
    - [Runtime Configuration](#runtime-configuration)
    - [Model Relations](#model-relations)
    - [Model Columns](#model-columns)
    - [Model Scopes and Accessors](#model-scopes-and-accessors)
    - [Model Configuration](#model-configuration)
    - [Appended Attributes](#appended-attributes)
    - [Creating Models](#creating-models)
    - [Query Results](#query-results)
    - [Starting Queries](#starting-queries)
    - [Schema Dumps](#schema-dumps)
    - [Dispatching Jobs and Events](#dispatching-jobs-and-events)
    - [Dispatching Jobs in Transactions](#dispatching-jobs-in-transactions)
    - [Models in Queued Jobs](#models-in-queued-jobs)
    - [Batched Jobs](#batched-jobs)
    - [Unique Jobs](#unique-jobs)
    - [Queue Configuration](#queue-configuration)
    - [Validation Rules](#validation-rules)
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

<a name="configuration"></a>
## Configuration

The extension's checks are configured using parameters in your `phpstan.neon` file. For example, you may enable a check that is off by default, or disable one your project does not want:

```neon
parameters:
    checkUnusedViews: true
    noLegacyModelScopes: false
```

Parameters that accept directories take absolute paths, or paths relative to the directory you run PHPStan from. The following table lists each parameter, its default value and the section that describes it:

| Parameter | Default | Described In |
|---|---|---|
| `noEnvCallsOutsideOfConfig` | `true` | [Environment Variables](#environment-variables) |
| `checkConfigTypes` | `true` | [Configuration Values](#configuration-values) |
| `configDirectories` | `[]` | [Configuration Values](#configuration-values) |
| `noUnnecessaryEnumerableToArrayCalls` | `true` | [Converting Collections to Arrays](#converting-collections-to-arrays) |
| `checkMissingTranslations` | `true` | [Translations](#translations) |
| `translationDirectories` | `[]` | [Translations](#translations) |
| `checkUnusedViews` | `false` | [Views](#views) |
| `viewDirectories` | `[]` | [Views](#views) |
| `checkAuthCallsWhenInRequestScope` | `false` | [Authentication in Requests](#authentication-in-requests) |
| `checkRedisConnectionBoundMethods` | `true` | [Redis Connections](#redis-connections) |
| `checkCancellationHandling` | `true` | [Cancellation](#cancellation) |
| `checkCancellationPrecedence` | `true` | [Cancellation](#cancellation) |
| `noCoroutinesInTransactions` | `true` | [Coroutines in Transactions](#coroutines-in-transactions) |
| `noRawSleepCalls` | `true` | [Sleeping](#sleeping) |
| `checkWorkerSharedState` | `true` | [Worker State](#worker-state) |
| `checkStaticStateMutation` | `true` | [Worker State](#worker-state) |
| `checkUnboundedWorkerCaches` | `true` | [Worker State](#worker-state) |
| `noRuntimeConfigMutation` | `true` | [Runtime Configuration](#runtime-configuration) |
| `databaseMigrationsPath` | `[]` | [Migrations and Schema Dumps](#migrations-and-schema-dumps) |
| `squashedMigrationsPath` | `[]` | [Migrations and Schema Dumps](#migrations-and-schema-dumps) |
| `disableMigrationScan` | `false` | [Migrations and Schema Dumps](#migrations-and-schema-dumps) |
| `disableSchemaScan` | `false` | [Migrations and Schema Dumps](#migrations-and-schema-dumps) |
| `enableMigrationCache` | `true` | [Migrations and Schema Dumps](#migrations-and-schema-dumps) |
| `parseModelCastsMethod` | `true` | [Cast Types](#cast-types) |
| `checkModelProperties` | `true` | [Model Columns](#model-columns) |
| `eloquentBuilderMacros` | The `SoftDeletes` and Scout `Searchable` macros | [Custom Builders and Collections](#custom-builders-and-collections) |
| `noLegacyModelScopes` | `true` | [Model Scopes and Accessors](#model-scopes-and-accessors) |
| `checkModelMethodVisibility` | `true` | [Model Scopes and Accessors](#model-scopes-and-accessors) |
| `noLegacyModelProperties` | `true` | [Model Configuration](#model-configuration) |
| `checkModelAppends` | `true` | [Appended Attributes](#appended-attributes) |
| `noModelMake` | `true` | [Creating Models](#creating-models) |
| `noUnnecessaryCollectionCall` | `true` | [Query Results](#query-results) |
| `noUnnecessaryCollectionCallOnly` | `[]` | [Query Results](#query-results) |
| `noUnnecessaryCollectionCallExcept` | `[]` | [Query Results](#query-results) |
| `noImplicitQueryBuilderCall` | `false` | [Starting Queries](#starting-queries) |
| `checkDispatchInTransactionAfterCommit` | `true` | [Dispatching Jobs in Transactions](#dispatching-jobs-in-transactions) |
| `checkJobSerializesModels` | `true` | [Models in Queued Jobs](#models-in-queued-jobs) |
| `checkBatchedJobIsBatchable` | `true` | [Batched Jobs](#batched-jobs) |
| `noBatchedUniqueJob` | `true` | [Batched Jobs](#batched-jobs) |
| `checkBatchableJobChecksCancellation` | `false` | [Batched Jobs](#batched-jobs) |
| `checkUniqueJobUniqueId` | `true` | [Unique Jobs](#unique-jobs) |
| `checkUniqueJobUniqueFor` | `false` | [Unique Jobs](#unique-jobs) |
| `noLegacyQueueProperties` | `true` | [Queue Configuration](#queue-configuration) |
| `noPipeSeparatedValidationRules` | `true` | [Validation Rules](#validation-rules) |

PHPStan's own settings, such as its rule level, paths and baseline, keep working as usual. The extension changes one of them: it enables `reportIgnoresWithoutComments`, as described in [Fixing and Ignoring Diagnostics](#fixing-and-ignoring-diagnostics).

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

Filesystem disks work the same way. `Storage::disk('s3')` is typed from your `filesystems` configuration as the disk Hypervel builds for it, such as `ClientPooledFilesystem` for a pooled S3 disk or `LocalFilesystemAdapter` for a local one. Calls the `Storage` facade forwards to the default disk are typed from that disk. An unknown or non-constant disk name keeps the `Filesystem` contract type. Concurrency drivers are typed the same way from your `concurrency` configuration, so `Concurrency::driver('sync')` is a `SyncDriver`, while an unknown name or a driver registered using `extend()` keeps the `Driver` contract type.

Request input is typed as what the request can hold. A keyed `query()`, `post()` or `cookie()` value may be a string, number, boolean or array from the client, or a `Stringable` your code stored, so check or cast it before relying on one type. Whole input arrays may have integer keys, such as from a JSON list body. By default, typed helpers such as `$request->string()`, `integer()` and `boolean()` return a single type.

Dates are different: `now()`, `today()` and the `Date` facade return `CarbonInterface`, as they are declared, even when your application configures a specific date class. When your code needs a specific class, convert the value, such as with `toImmutable()`.

Model factories are typed as the factory the model's `factory` method selects, and the analysis follows whether the factory makes one model or a collection: `User::factory()->create()` is a `User`, while `User::factory(3)->create()` is a collection. The `createMany`, `createManyQuietly` and `makeMany` methods clear a factory's count. The analysis follows this when the call is a statement or an assigned value, but not when the call is passed straight to another call, so assign the result first if you use the same factory again afterwards. A factory class with its own `@template` types keeps its generic type instead, so its `create` and `make` methods return the model or a collection of it.

The `Arr::get` and `Arr::pull` methods return the type the array holds under the given key, including keys in "dot" notation. When the key may be missing, the type of the default is added, or `null` without one. `Arr::pull` also removes a top-level key from the type of the array it was given:

```php
/**
 * @param array{name: string, email?: string, address: array{city: string}} $user
 */
function example(array $user): void
{
    Arr::get($user, 'name'); // string
    Arr::get($user, 'email'); // string|null
    Arr::get($user, 'address.city'); // string

    Arr::pull($user, 'name'); // string, and $user no longer has a "name" key...
}
```

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

If you set the `parseModelCastsMethod` parameter to `false`, the method's body is not read, and its casts come only from the array shape its `@return` tag declares.

<a name="model-serialization"></a>
### Model Serialization

A model's `toArray` and `attributesToArray` methods return an array shape built from the model's columns, casts, accessors and appended attributes, without its hidden attributes. For example, a `posts` table with an integer `id`, a string `title` and a nullable `published_at` column cast to `datetime` gives:

```php
// array{id?: int, title?: string, published_at?: string|null, ...<string, mixed>}
$post->toArray();
```

Every key is optional, since a query may select only some columns, and the shape accepts other string keys for the aliases, aggregates and loaded relations a query can add. A model that customizes how it is serialized, or whose columns are not all known, keeps the declared `array` type.

<a name="relationships"></a>
### Relationships

The analysis reads a relationship's related model from the relationship method's return type, so declare the relation's generic types:

```php
/**
 * @return HasMany<Post, $this>
 */
public function posts(): HasMany
{
    return $this->hasMany(Post::class);
}
```

With this declaration, `$user->posts` is a collection of posts, `$user->posts_count` is an integer, and callbacks that constrain the relationship receive the right types without declaring them:

```php
User::whereHas('posts.comments', function ($query) {
    // $query is a Builder<Comment>...
});

User::with([
    'posts' => function ($query) {
        // $query is a HasMany<Post, User>...
    },
    'group' => function ($query) {
        // $query is a BelongsTo<Group, User>...
    },
]);
```

Each callback in an eager loading array receives its own key's relation, following nested paths such as `posts.comments`. Callbacks given to the `loadCount`, `loadSum` and other aggregate methods receive the related model's builder. A relationship whose name is not statically known, such as an arbitrary string, or a method without the generic types above, gives the callback an unknown relation or a builder of an unknown model.

<a name="custom-builders-and-collections"></a>
### Custom Builders and Collections

A model's queries are typed as its [custom Eloquent builder](/docs/{{version}}/eloquent#custom-eloquent-builders), so the builder's own methods may be chained from the model, its relationships and relationship callbacks. Declare the model as the builder's template type so that results keep the model's type:

```php
/**
 * @template TModel of User
 *
 * @extends Builder<TModel>
 */
class UserBuilder extends Builder
{
    /**
     * @return $this
     */
    public function active(): static
    {
        return $this->where('active', true);
    }
}

#[UseEloquentBuilder(UserBuilder::class)]
class User extends Model
{
    // ...
}

// UserBuilder<User>, then Collection<int, User>...
$users = User::query()->active()->get();
```

Likewise, query results are typed as the model's [custom collection](/docs/{{version}}/eloquent-collections#custom-collections), whether it comes from the `CollectedBy` attribute or a `newCollection` method. The same collection is returned by a paginator's `getCollection` method. Give a collection used by several models `TKey` and `TModel` template types, so each model's results keep their own type:

```php
/**
 * @template TKey of array-key
 * @template TModel of Model
 *
 * @extends Collection<TKey, TModel>
 */
class ActiveCollection extends Collection
{
    // ...
}
```

A trait's global scope may add macros to the builder of every model using the trait, as `SoftDeletes` adds `withTrashed`. Since those macros are registered when a query is built, a package describes them to the analysis using the `eloquentBuilderMacros` parameter, which maps the trait to an interface declaring the macros. The interface only supplies their signatures; the scope still registers the macros at runtime. A macro that returns `static` keeps the builder it is called on, and an interface with one template type receives the model:

```neon
parameters:
    eloquentBuilderMacros:
        App\Models\Concerns\Archivable: App\Analysis\ArchivableMacros
```

Your entries are added to the built-in mappings for `SoftDeletes` and Scout's `Searchable` trait.

<a name="custom-phpdoc-types"></a>
## Custom PHPDoc Types

The extension adds several PHPDoc types that you may use in your own code. Since only PHPStan with this extension understands them, write them in `@phpstan-param` and `@phpstan-return` tags beside an ordinary `@param` or `@return` tag when other tools read your docblocks.

<a name="view-string"></a>
### The `view-string` Type

A `view-string` is the name of a view the booted application can find. A statically known name passed to a `view-string` parameter is checked, as described in [Views](#views):

```php
/**
 * @phpstan-param view-string $view
 */
public function render(string $view): View
{
    return view($view);
}
```

From PHPStan's level 7, a string that is not known to be a view name is reported as well. A string checked using `view()->exists()` becomes a `view-string` inside the condition, so a name that is not known in advance may be passed on:

```php
if (view()->exists($name)) {
    return $this->render($name);
}
```

<a name="model-property"></a>
### The `model-property` Type

A `model-property<User>` is a string naming one of the model's attributes. A statically known name passed to such a parameter is checked against the model, as described in [Model Columns](#model-columns):

```php
/**
 * @phpstan-param model-property<User> $column
 */
function sortUsersBy(string $column): Collection
{
    return User::orderBy($column)->get();
}

sortUsersBy('emial'); // Reported...
```

<a name="collection-of"></a>
### The `collection-of` Type

A `collection-of<User>` is the collection class the model's queries return: the model's custom collection when it has one, otherwise `Hypervel\Database\Eloquent\Collection`. The model may also be a template type or a union of models:

```php
/**
 * @template TModel of Model
 *
 * @param class-string<TModel> $model
 *
 * @phpstan-return collection-of<TModel>
 */
function allModels(string $model): Collection
{
    return $model::all();
}
```

Like `array<TKey, TValue>`, a key type may be given first, such as `collection-of<string, User>`.

<a name="builder-of"></a>
### The `builder-of` Type

A `builder-of<User>` is the Eloquent builder the model's queries use: the model's custom builder when it has one, otherwise `Hypervel\Database\Eloquent\Builder<User>`. It accepts `static`, `$this`, template types and unions of models:

```php
/**
 * @phpstan-return builder-of<static>
 */
public function newActiveQuery(): Builder
{
    return $this->newQuery()->where('active', true);
}
```

A second argument names a relationship of the model, and gives the builder of the related model. Nested paths are followed, and a column list such as `posts:id,title` is ignored:

```php
/**
 * @phpstan-param builder-of<User, 'posts.comments'> $query
 */
function onlyApproved(Builder $query): void
{
    $query->where('approved', true);
}
```

A relationship the analysis cannot resolve, such as a name that is not statically known or a relationship method without generic types, gives a builder of an unknown model.

<a name="relation-of"></a>
### The `relation-of` Type

A `relation-of<User, 'posts'>` is the relation the model's relationship method declares, such as `HasMany<Post, User>`. Like `builder-of`, it accepts template types, nested paths and column lists. When the name may be one of several relationships, the type is a union of their relations, so code receiving it must work with each of them. A relationship the analysis cannot resolve gives `Relation<*, *, *>`.

<a name="fixing-and-ignoring-diagnostics"></a>
## Fixing and Ignoring Diagnostics

Whenever possible, fix the code a diagnostic points to. Ignore a diagnostic only for a legitimate exception or a limit of the analysis, and give the ignore the diagnostic's identifier and a reason:

```php
// @phpstan-ignore hypervel.noEnvCallsOutsideOfConfig (the test runner sets this token for each worker)
$token = Env::get('TEST_TOKEN');
```

The reason tells the next reader why the diagnostic does not apply, so the extension enables PHPStan's `reportIgnoresWithoutComments` setting: an ignore without a reason is reported, and so are `@phpstan-ignore-line` and `@phpstan-ignore-next-line`, which hide every error on the line. When one comment ignores several identifiers, each needs its own reason:

```php
// @phpstan-ignore property.notFound (Queueable property), method.notFound (Queueable method)
```

Never add a real defect to a baseline.

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
| [Cancellation](#cancellation) | Recognize cancellation with `Coroutine::causedByCancellation()` and keep it with `Coroutine::preferCancellation()`. | `hypervel.cancellation.exactType`, `hypervel.cancellation.precedence` |
| [Coroutines in Transactions](#coroutines-in-transactions) | Start coroutines once the transaction commits. | `hypervel.coroutineInTransaction` |
| [Sleeping](#sleeping) | Pause with the `Sleep` class. | `hypervel.rawSleep` |
| [Worker State](#worker-state) | Keep per-request values in method arguments or `CoroutineContext`, not on shared services or static properties. | `hypervel.workerSharedState`, `hypervel.staticStateMutation`, `hypervel.unboundedWorkerCache` |
| [Runtime Configuration](#runtime-configuration) | Set configuration while the application boots. | `hypervel.runtimeConfigMutation` |
| [Model Relations](#model-relations) | Load and query relations the model defines. | `hypervel.relationExistence` |
| [Model Columns](#model-columns) | Name columns the model's table has. | `argument.type` for column names, `method.notFound` and `staticMethod.notFound` for dynamic wheres |
| [Model Scopes and Accessors](#model-scopes-and-accessors) | Declare local scopes as protected `#[Scope]` methods and accessors as protected methods. | `hypervel.legacyModelScope`, `hypervel.noPublicModelScopeMethod`, `hypervel.noPublicModelAccessorMethod` |
| [Model Configuration](#model-configuration) | Configure models with attributes such as `Table` and `Fillable`. | `hypervel.legacyModelProperty` |
| [Appended Attributes](#appended-attributes) | Append attributes that a get accessor or class cast computes. | `hypervel.modelAppends` |
| [Creating Models](#creating-models) | Create models using `new` rather than `make`. | `hypervel.noModelMake` |
| [Query Results](#query-results) | Let the query count, test or pluck rows instead of fetching every model. | `hypervel.noUnnecessaryCollectionCall` |
| [Starting Queries](#starting-queries) | Start model queries with `query()`, when you enable the preference. | `hypervel.noImplicitQueryBuilderCall` |
| [Schema Dumps](#schema-dumps) | Keep schema dumps in the plain SQL `schema:dump` writes. | `hypervel.schemaDump` |
| [Dispatching Jobs and Events](#dispatching-jobs-and-events) | Dispatch with the arguments the constructor accepts. | `hypervel.jobs.noConstructor`, `hypervel.events.noConstructor`, `argument.type` and the other argument identifiers |
| [Dispatching Jobs in Transactions](#dispatching-jobs-in-transactions) | Queue jobs dispatched in a transaction once it commits. | `hypervel.dispatchInTransactionAfterCommit` |
| [Models in Queued Jobs](#models-in-queued-jobs) | Use `SerializesModels` in queued jobs that hold models. | `hypervel.jobSerializesModels` |
| [Batched Jobs](#batched-jobs) | Use `Batchable` in batched jobs, and dispatch unique jobs individually. | `hypervel.batchedJobIsBatchable`, `hypervel.noBatchedUniqueJob`, `hypervel.batchableJobChecksCancellation` |
| [Unique Jobs](#unique-jobs) | Declare what makes each unique job distinct. | `hypervel.uniqueJobUniqueId`, `hypervel.uniqueJobUniqueFor` |
| [Queue Configuration](#queue-configuration) | Configure queued classes with attributes such as `Tries` and `Backoff`. | `hypervel.legacyQueueProperty` |
| [Validation Rules](#validation-rules) | List validation rules in arrays. | `hypervel.pipeSeparatedValidationRules` |
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

Your application's configuration files are read from its configuration directory. If they live elsewhere, list the directories using the `configDirectories` parameter, which replaces the default directory and accepts glob patterns:

```neon
parameters:
    configDirectories:
        - config
        - modules/*/config
```

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

To fix the diagnostic, add the missing line or correct the key. Keys are checked against the translations your application loads. To check them against other directories instead, list the directories using the `translationDirectories` parameter. You may disable the rule using the `checkMissingTranslations` parameter.

<a name="views"></a>
### Views

Methods that accept a view name, such as `view()` and `View::make()`, require a view the booted application can find, so a misspelled view name is reported as an `argument.type` error. If a package only registers a view namespace when a command runs or a feature is enabled, the analysis cannot see those views; ignore those calls with a reason.

You may also enable the `checkUnusedViews` parameter to report views that no code refers to by a literal name. Views rendered by computed names or used by other packages may still be in use, so treat these diagnostics as a list to review rather than proof that a view can be deleted.

A view counts as used when its literal name is passed to the `view` helper, `View::make` or `Route::view`, to a mailable's or mail notification's `view`, `text` or `markdown` method, to a mail `Content` definition or `Mail::send`, or when another view refers to it using `@extends`, `@include`, `@includeIf`, `@includeWhen`, `@includeUnless`, `@includeFirst` or an anonymous component tag such as `<x-alert>`. By default, the views in your application's view paths are checked. To check other directories instead, list them using the `viewDirectories` parameter:

```neon
parameters:
    checkUnusedViews: true
    viewDirectories:
        - resources/views
        - modules/billing/resources/views
```

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

<a name="cancellation"></a>
### Cancellation

A failure thrown by a callback or a `finally` block can carry a [coroutine's cancellation](/docs/{{version}}/coroutines#coroutine-cancellation) as its previous exception. A `CanceledException` catch only matches cancellation thrown directly, so a later `Throwable` catch handles the canceled work as an ordinary failure:

```php
try {
    $order->sync();
} catch (CanceledException $exception) {
    // Catching CanceledException misses cancellation carried by another exception...
    throw $exception;
} catch (Throwable $exception) {
    report($exception);
}
```

Check `Coroutine::causedByCancellation` at the start of the `Throwable` catch instead:

```php
use Hypervel\Coroutine\Coroutine;

try {
    $order->sync();
} catch (Throwable $exception) {
    if (Coroutine::causedByCancellation($exception)) {
        throw $exception;
    }

    report($exception);
}
```

A `CanceledException` catch that keeps the exception in a variable for later is reported the same way. A `Throwable` catch that only rethrows is not reported, and neither is a `try` block that only waits on channels, wait groups, a `Waiter` or `Coroutine::join`, which throw cancellation directly. An `instanceof CanceledException` check is reported too, unless the value is already known to be a cancellation. Since `causedByCancellation` accepts only a `Throwable`, check `instanceof Throwable` first for a value that may be `null`, such as `getPrevious()`.

When you keep the first of several failures using `??=`, a later failure carrying cancellation is lost. Use `Coroutine::preferCancellation`, which keeps the first failure unless a later one carries this coroutine's cancellation:

```php
$failure = Coroutine::preferCancellation($failure, $exception);
```

A `??=` is not reported in a branch that only runs for ordinary failures, or when nothing can have been kept before it. You may disable these checks using the `checkCancellationHandling` and `checkCancellationPrecedence` parameters.

<a name="coroutines-in-transactions"></a>
### Coroutines in Transactions

A database transaction belongs to the coroutine that started it. A coroutine started inside the transaction's callback borrows its own [pooled connection](/docs/{{version}}/database#connection-pooling), so its queries run outside the transaction:

```php
DB::transaction(function () use ($order) {
    $order->save();

    // A coroutine started inside a database transaction runs outside it...
    Coroutine::create(fn () => $this->notifyWarehouse($order));
});
```

Start the coroutine once the transaction commits, such as from a `DB::afterCommit` callback, or do the work in the transaction's own coroutine. The analysis checks `go`, `co`, `parallel`, `wait`, `Coroutine::create` and `Coroutine::fork`, `Parallel`, `Concurrent`, `WaitConcurrent` and `Waiter`, as well as `Concurrency::run` when your configuration uses the `coroutine` driver, or when called on a driver known to be the `coroutine` driver, such as `Concurrency::driver('coroutine')`. Code inside a coroutine that is already reported is not checked again, and neither is a task list known to be empty. You may disable this check using the `noCoroutinesInTransactions` parameter.

<a name="sleeping"></a>
### Sleeping

Pause using the [`Sleep`](/docs/{{version}}/helpers#sleep) class instead of PHP's `sleep` and `usleep` functions, so tests can fake the delay:

```php
use Hypervel\Support\Sleep;

// Call Sleep::sleep() instead of sleep(), so tests can fake the delay.
sleep(1);

Sleep::sleep(1);
```

Keep the native function only where real time must pass, such as while polling an external process, and ignore the diagnostic with a reason. You may disable this check using the `noRawSleepCalls` parameter.

<a name="worker-state"></a>
### Worker State

A Hypervel worker handles many requests and jobs, often at the same time, and the container [shares most services](/docs/{{version}}/container#resolution-lifecycles) for the worker's whole life. Anything a shared service or a static property keeps is seen by every later request the worker handles, and by any request running alongside it:

```php
class InvoiceService
{
    protected ?Request $request = null;

    public function prepare(Request $request): void
    {
        // Every other request on this worker now sees this request...
        $this->request = $request;
    }
}
```

Pass request values to the methods that use them, or keep them in [CoroutineContext](/docs/{{version}}/coroutine-context). If the whole object belongs to one request, mark the class [`#[Scoped]`](/docs/{{version}}/container#scoped-attribute) so each request or job gets its own instance. The analysis reports:

- A property of a shared service that keeps the current request, a response, a user, an uploaded file or a model, whether a method assigns it or the constructor receives it. (`hypervel.workerSharedState`)
- A shared middleware, controller or job that writes to its own properties while handling a request or running a job. (`hypervel.workerSharedState`)
- A static property written while handling a request or running a job, or one that keeps any of the values above. (`hypervel.staticStateMutation`)
- An array on a static property or shared service whose keys come from the request or user, such as `$this->visits[$request->ip()]`, since it can grow for as long as the worker runs. Use a cache store with an expiry instead. Keys limited to a small set of values, such as booleans or constants, are not reported, and neither is an array the same method has just reset. When the array is bounded in a way the analysis cannot see, ignore the diagnostic with a reason. (`hypervel.unboundedWorkerCache`)

Code runs "while handling a request" in route middleware and global middleware, controller actions, closures and arrow functions passed as a route's action to a route method such as `Route::get`, and form request methods. Code runs "while running a job" in the `handle`, `__invoke` and `failed` methods of queued classes. Closures nested inside these are not checked, since they may run later.

To decide which classes are shared, the analysis follows the container's own rules using your booted application. It starts from your container bindings and instances, routes and middleware, event listeners, command bus handlers, `#[Singleton]` classes, classes resolved by name without parameters using `app`, `resolve` or the container's `make`, `get` or `factory` methods, and queued jobs, then follows each class's constructor dependencies. Registrations and `#[Singleton]` come first: a class registered with `singleton` or `instance`, or marked `#[Singleton]`, is shared even when it is [transient](/docs/{{version}}/container#transient-classes) or [self-building](/docs/{{version}}/container#self-building-classes). A class registered with `scoped` or marked `#[Scoped]` is not shared, and neither is one registered with `bind`, unless it is bound to a shared class. Other transient and self-building classes, and classes whose constructor takes an execution-scoped value such as `#[CurrentUser]`, are not shared. Classes created by a binding's closure are not followed, since the analysis does not run the closure. A constructor parameter the container always fills with a new object, such as a model a repository uses as a prototype, is not reported unless the constructor replaces it. The `handle` dependencies of a queued job are only followed when the job uses the `Dispatchable` trait.

Methods that only run while the application boots or in tests may say so with a docblock line starting with "Boot-only", "Tests only" or "Boot or tests only". Writes in these methods, and in `flushState` methods, are not reported:

```php
/**
 * Set the request used to build URLs.
 *
 * Tests only. The request stays on the shared service until a test replaces it.
 */
public function setRequestForTesting(Request $request): void
{
    $this->request = $request;
}
```

Other worker state, such as a lazily created connection or a registry filled while the application boots, is not reported. When a reported write is intended, ignore it with a reason. You may disable these checks using the `checkWorkerSharedState`, `checkStaticStateMutation` and `checkUnboundedWorkerCaches` parameters.

<a name="runtime-configuration"></a>
### Runtime Configuration

Configuration belongs to the whole worker, so changing it while handling a request changes it for every request the worker handles afterwards, and for any running at the same time:

```php
public function handle(Request $request, Closure $next): Response
{
    // Every request on this worker now uses this locale...
    config(['app.locale' => $request->user()->locale]);

    return $next($request);
}
```

Set configuration while your application boots, and keep per-request values in [CoroutineContext](/docs/{{version}}/coroutine-context). The analysis reports `config` calls with an array, `set`, `push`, `prepend`, `offsetSet` and `offsetUnset` calls on the configuration repository, and statements that set or unset one of its keys, such as `config()['app.locale'] = 'fr'`, while handling a request or running a job as described in [Worker State](#worker-state). The repository may come from the `config` helper, the `Config` facade, the container by any name that resolves to `config`, or a parameter the container injects into a controller action, a route closure, or a job's `handle` or `__invoke` method. A parameter with a default or a contextual attribute is not checked, and neither is a job parameter that a contextual binding gives another repository. Once a method gives a parameter a new value, later writes through it are not checked. Neither is a repository you create or keep in a property. You may disable this check using the `noRuntimeConfigMutation` parameter.

<a name="model-relations"></a>
### Model Relations

Eager loading, relationship queries and relationship aggregates report a relation the model does not define. Eager loading and the `has`, `whereHas` and `whereRelation` families also check each segment of a nested path:

```php
// Relation 'comment' is not found in App\Models\Post model.
$users = User::with('posts.comment')->get();
```

A relation registered using `resolveRelationUsing` while the application boots is found, though the segments after it are not checked. Relations on an abstract model or a union of models are not checked, since each model may define them differently.

A model's default `$with` and `$withCount` eager loads are checked the same way. Defaults a model inherits are checked for each model that inherits them, since the model may define the relation itself.

<a name="model-columns"></a>
### Model Columns

Eloquent methods that accept a column, such as `where`, `firstWhere`, `value`, `pluck`, `create`, `update` and `fill`, are checked against the model's [properties](#model-properties) when the name you pass is statically known. The same applies to queries made through a relationship and to the attributes given to a factory:

```php
// Parameter #1 $attributes of static method ...::create() expects array<model property of App\Models\User, mixed>...
User::create([
    'name' => 'Taylor',
    'emial' => 'taylor@example.com',
]);
```

A name qualified by a table, such as `users.email`, is checked against that table, and only the column of a `column as alias` name is checked. A dynamic where that names an unknown column, such as `whereEmial`, is reported as an undefined method. A name whose value is not statically known, such as an arbitrary string, is not checked, and neither are columns the analysis cannot know about, as described in [Model Properties](#model-properties).

You may check the column arguments of your own code using the [`model-property`](#model-property) type. These checks may be disabled using the `checkModelProperties` parameter.

<a name="model-scopes-and-accessors"></a>
### Model Scopes and Accessors

Eloquent calls local query scopes and accessors for you, so define scopes as protected methods marked with the `Scope` attribute, and accessors as protected methods:

```php
use Hypervel\Database\Eloquent\Attributes\Scope;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Casts\Attribute;

#[Scope]
protected function popular(Builder $query): void
{
    $query->where('votes', '>', 100);
}

protected function firstName(): Attribute
{
    return Attribute::get(fn (string $value) => ucfirst($value));
}
```

The analysis reports a legacy `scopePopular` method, naming the attributed scope that replaces it, and a scope or accessor that is not protected. Legacy scopes and public methods keep working, while a private scope or accessor is never called by Eloquent.

When the model already has a method with the scope's name, such as a notification's `read` method beside its `scopeRead` scope, the legacy scope is the only way to keep the scope's name, so it is not reported. A scope defined in a reusable trait should also keep its legacy name, since a model's own method with the same name would silently replace an attributed scope; ignore it with a reason. Likewise, ignore a public scope or accessor that other code calls directly as part of a public API. You may disable these checks using the `noLegacyModelScopes` and `checkModelMethodVisibility` parameters.

<a name="model-configuration"></a>
### Model Configuration

Configure a model with attributes rather than properties, such as its table, keys, timestamps and mass assignment:

```php
use Hypervel\Database\Eloquent\Attributes\Fillable;
use Hypervel\Database\Eloquent\Attributes\Hidden;
use Hypervel\Database\Eloquent\Attributes\Table;

#[Table('flights', key: 'flight_id')]
#[Fillable(['name', 'airline'])]
#[Hidden(['secret'])]
class Flight extends Model
{
}
```

The analysis reports a model's own `$table`, `$connection`, `$primaryKey`, `$keyType`, `$incrementing`, `$timestamps`, `$dateFormat`, `$fillable`, `$guarded`, `$hidden`, `$visible`, `$appends`, `$touches` and `$refreshes` declarations. It names the attribute that gives the model the same settings, or says the declaration can be removed when the model resolves the same value without it. A single `Table` attribute holds the table, key and key type. The properties keep working. A declaration the constructor assigns, or changes through a model setter such as `setTable` or `mergeFillable`, is not reported; changes made inside other methods the constructor calls, including trait initializers, are not detected.

Attributes and properties take precedence differently through inheritance, so a suggestion holds for the model itself, not necessarily its subclasses. A subclass's own attribute replaces an inherited attribute entirely: a subclass declaring `#[Hidden(['token'])]` adds to a parent's `$hidden = ['password']`, but replaces a parent's `#[Hidden(['password'])]`, so it must repeat `password`. An attribute can also win over a subclass's property, such as a parent's `#[WithoutTimestamps]` over a subclass's `$timestamps = true`. Review a model's subclasses before converting it, and keep the property when attributes cannot express the hierarchy, such as on a model that applications extend and customize through properties; ignore it with a reason. You may disable this check using the `noLegacyModelProperties` parameter.

<a name="appended-attributes"></a>
### Appended Attributes

A model serializes each attribute it appends, using the `Appends` attribute or the `$appends` property, through the attribute's get accessor or class cast. The analysis reports an appended attribute the model does not have, and a column with neither a get accessor nor a class cast:

```php
use Hypervel\Database\Eloquent\Attributes\Appends;
use Hypervel\Database\Eloquent\Casts\Attribute;

#[Appends(['is_admin'])]
class User extends Model
{
    protected function isAdmin(): Attribute
    {
        return Attribute::get(fn () => $this->role === 'admin');
    }
}
```

Attributes a model inherits are checked for each model that inherits them, since the model may define the accessor itself. Models that customize how attributes are serialized are not checked. You may disable this check using the `checkModelAppends` parameter.

<a name="creating-models"></a>
### Creating Models

A model's static `make` method builds a query for the model before creating it, so create the model using `new` instead:

```php
// Called 'Model::make()' which performs unnecessary work, use 'new Model()'.
$user = User::make(['name' => 'Taylor']);

$user = new User(['name' => 'Taylor']);
```

Since `make` creates the model through its query, a global scope can change the model it creates, and the model takes the query's connection. So the analysis only reports models with a fixed connection and no global scopes, boot methods or customized construction. Models using the default connection, or a trait such as `SoftDeletes` that adds a global scope, are not reported, and neither are calls through `static` in a class that may be extended. You may disable this check using the `noModelMake` parameter.

<a name="query-results"></a>
### Query Results

Fetching every row only to count, test or pluck the results is slower than letting the database do it. The analysis reports a collection method called on a query's results when the query itself can return the same result:

```php
// Called 'count' on Hypervel collection, but could have been retrieved as a query with 'count()'.
$active = Flight::where('active', 1)->get()->count();

$active = Flight::where('active', 1)->count();
```

The `first`, `count`, `isEmpty`, `isNotEmpty`, `take`, `pluck` and `modelKeys` methods are checked, with `isEmpty` and `isNotEmpty` replaced by the query's `doesntExist` and `exists` methods. A call is only reported when the whole query is visible: a model's static query methods or `DB::table`, followed by `where` clauses, ordering, eager loading, joins and limits, then `get` or `all`. Queries held in variables, relationship queries, and queries that select columns or call scopes or macros are not checked, and neither are models with global scopes, such as those using `SoftDeletes`, or with a custom collection that overrides the method or a method it calls. A call whose result a limit, join, accessor or class cast would change, such as plucking an attribute that has an accessor, is not reported, and neither is `pluck` or `modelKeys` on a model with an observer or `retrieved` listener, or one that customizes how it reads attributes.

You may choose the checked methods using the `noUnnecessaryCollectionCallOnly` and `noUnnecessaryCollectionCallExcept` parameters, or disable the rule using the `noUnnecessaryCollectionCall` parameter.

<a name="starting-queries"></a>
### Starting Queries

When the `noImplicitQueryBuilderCall` parameter is enabled, the analysis recommends starting a model query with `query()` instead of calling a builder method on the model directly:

```php
// Call to static method App\Models\User::where() is forwarded to the query builder.
$users = User::where('active', 1)->get();

$users = User::query()->where('active', 1)->get();
```

Calling builder methods on the model remains fully supported. Models that override `__call`, `__callStatic` or `query` to change how they forward these calls are not checked.

<a name="schema-dumps"></a>
### Schema Dumps

When a schema dump your models use cannot be read, such as a PostgreSQL dump in `pg_dump`'s custom archive format or a dump with a syntax error, the analysis reports it once, at the dump:

```text
database/schema/pgsql-schema.dump:1: Model property types cannot use this schema dump: PostgreSQL archives must be exported as plain SQL before parsing.
```

Until the dump is fixed, the tables it defines are unknown, so their attributes read as `mixed`. Regenerate the dump using the `schema:dump` Artisan command, which writes plain SQL. A dump is only reported when the analysed code reads a model attribute on its connection. You may stop reading schema dumps using the `disableSchemaScan` parameter.

<a name="dispatching-jobs-and-events"></a>
### Dispatching Jobs and Events

A job's `dispatch`, `dispatchIf`, `dispatchUnless`, `dispatchSync` and `dispatchAfterResponse` methods, and an event's `dispatch`, `dispatchIf`, `dispatchUnless` and `broadcast` methods, pass their arguments to the class's constructor, so the arguments are checked against it:

```php
// Parameter #1 $podcast of job class App\Jobs\ProcessPodcast constructor expects App\Models\Podcast...
ProcessPodcast::dispatch($podcast->id);
```

The first argument of `dispatchIf` and `dispatchUnless` is the condition, so only the arguments after it are checked. A class that defines its own dispatch method is checked against that method instead.

<a name="dispatching-jobs-in-transactions"></a>
### Dispatching Jobs in Transactions

A job dispatched inside a database transaction may be processed before the transaction commits, so it may not find the data the transaction writes, or it may run even though the transaction rolls back. The analysis reports a job dispatched inside a `DB::transaction` callback when its queue connection does not wait for the commit:

```php
DB::transaction(function () use ($order) {
    $order->save();

    // Job App\Jobs\ShipOrder is dispatched inside a transaction without deferring until commit.
    ShipOrder::dispatch($order);
});
```

Chain the `afterCommit` method onto the dispatch, implement the `ShouldQueueAfterCommit` interface on the job, or enable the [`after_commit` option](/docs/{{version}}/queues#jobs-and-database-transactions) on the queue connection. Jobs staged in the [transactional outbox](/docs/{{version}}/queues#transactional-outbox) are not reported, and neither are jobs queued by the database driver on the transaction's own connection, since their rows commit with the transaction. A dispatch that calls `beforeCommit` is a deliberate choice and is not reported either.

The analysis reads the queue connection from the dispatch, the job's `Connection` attribute or `$connection` property, and your configuration files. When it can't be known, such as a connection read from an environment variable or a job whose constructor changes its own settings, the dispatch is not reported. You may disable this check using the `checkDispatchInTransactionAfterCommit` parameter.

<a name="models-in-queued-jobs"></a>
### Models in Queued Jobs

A queued job that holds Eloquent models or Eloquent collections in its properties should use the `SerializesModels` trait, which the `Hypervel\Foundation\Queue\Queueable` trait includes. Without it, the whole model is serialized onto the queue and the job runs with the model as it was when the job was dispatched. With it, only the model's identifier is queued, and the model is retrieved fresh when the job runs.

Properties of any visibility are checked, including inherited ones. A job that defines its own `__serialize` method is not checked. You may disable this check using the `checkJobSerializesModels` parameter.

<a name="batched-jobs"></a>
### Batched Jobs

Every job you add to a batch must use the `Hypervel\Bus\Batchable` trait, or adding it fails. The analysis reports a job that does not use it when you pass it to `Bus::batch()` or to a batch's `add` method, including jobs held in variables and the jobs of a chain within the batch. You may disable this check using the `checkBatchedJobIsBatchable` parameter.

Jobs that implement `ShouldBeUnique` should be dispatched individually, since only an individual dispatch acquires the job's unique lock. `Bus::batch()`, `Bus::bulk()`, `Queue::bulk()` and a batch's `add` method queue jobs without it, so duplicates are queued. A later job of a chain within a batch acquires its lock when the chain reaches it, but when the lock is already held, the job is skipped and the batch never finishes. You may disable this check using the `noBatchedUniqueJob` parameter.

When the `checkBatchableJobChecksCancellation` parameter is enabled, the analysis also reports batchable jobs that do not check whether their batch has been cancelled. Cancelling a batch does not stop jobs that are already queued, so a job should return early when its batch has been cancelled, or use the `SkipIfBatchCancelled` middleware:

```php
public function handle(): void
{
    if ($this->batch()?->cancelled()) {
        return;
    }

    // ...
}
```

The analysis recognizes the batch's `cancelled` method, the job's `batching` method and the `SkipIfBatchCancelled` middleware in any of the job's methods, including inherited ones. It does not check that the job checks before doing any work.

<a name="unique-jobs"></a>
### Unique Jobs

A unique job's lock is identified by its class and its `uniqueId`. A job whose constructor accepts arguments, such as a job that updates one product, usually needs a separate lock for each set of arguments, so the analysis reports such a job when it declares no `uniqueId` method or property:

```php
/**
 * Get the unique ID for the job.
 */
public function uniqueId(): string
{
    return (string) $this->product->id;
}
```

When the job should be unique regardless of its arguments, return a constant from `uniqueId` to make that clear. You may disable this check using the `checkUniqueJobUniqueId` parameter.

When the `checkUniqueJobUniqueFor` parameter is enabled, the analysis also reports unique jobs that do not declare how long they stay unique, using the `UniqueFor` attribute, a `uniqueFor` method or a `$uniqueFor` property. Without one, the lock is acquired without a duration, whose meaning depends on your cache store, so a worker that stops while running the job may leave the lock held.

<a name="queue-configuration"></a>
### Queue Configuration

Configure queued jobs, listeners, mailables, notifications and broadcast events with attributes rather than properties:

```php
use Hypervel\Queue\Attributes\Backoff;
use Hypervel\Queue\Attributes\Queue;
use Hypervel\Queue\Attributes\Tries;

#[Queue('podcasts')]
#[Tries(5)]
#[Backoff([1, 5, 10])]
class ProcessPodcast implements ShouldQueue
{
}
```

The analysis reports public `$connection`, `$queue`, `$delay`, `$tries`, `$timeout`, `$maxExceptions`, `$backoff`, `$uniqueFor`, `$debounceFor`, `$failOnTimeout`, `$countCrashesAsExceptions` and `$deleteWhenMissingModels` declarations where the class's queue path reads the matching attribute. It names the attribute that keeps the same setting, or says the declaration can be removed. A declaration in a trait the class uses is reported with the attribute to place on the trait, unless its value depends on the class, such as `self::TRIES`. The properties keep working.

A setting computed by a method, such as `tries` or a listener's `viaQueue`, is not reported. Neither is a `false` flag, which can turn off an inherited attribute, nor a declaration the constructor assigns or changes through `onConnection`, `onQueue`, `delay` and the other `Queueable` setters, such as a wrapper job copying the settings of the object it wraps; changes made inside other methods the constructor calls are not detected. A subclass's non-null public property still wins over an inherited attribute, but a subclass's own attribute replaces it entirely: a subclass's `#[DebounceFor(10)]` drops the parent's `maxWait`. Keep a declared property that other code reads or assigns when no parent declares it, since an attribute does not provide that storage; ignore it with a reason. You may disable this check using the `noLegacyQueueProperties` parameter.

<a name="validation-rules"></a>
### Validation Rules

List validation rules in arrays rather than pipe-separated strings:

```php
$request->validate([
    'title' => ['required', 'string', 'max:255'],
    'code' => ['required', 'regex:/^(draft|published)$/'],
]);
```

The analysis reports the pipe-separated strings the validator splits where you write them: the rules you pass to `Validator::make`, `validator`, `$request->validate`, the controller `validate` methods, `Rule::when`, `Rule::anyOf` and a prompt's `validate` argument, the arrays a form request's or data class's `rules` method returns, and data `#[Rule]` attributes. Strings built with concatenation or interpolation are reported too. Running `phpstan analyse --fix` replaces a plain string whose split is unambiguous with the array of its rules; a string with an empty rule or with whitespace around a rule is reported without a fix.

A string holding a `regex` or `not_regex` rule is reported without a fix, because the validator also splits the pattern at its own `|`. Give the regex its own array element, where the validator keeps it whole. Data rules differ: a data rule string that starts with a regex rule is kept whole, so it is not reported.

The analysis reads the rules array where you write it. A constant or conditional value inside that array is checked, but a rules array built in a variable, helper or closure is not followed. You may disable this check using the `noPipeSeparatedValidationRules` parameter.

<a name="deprecations"></a>
### Deprecations

The bundled deprecation rules report uses of deprecated APIs from any of your dependencies, including PHP, Symfony and Carbon as well as Hypervel. Use the replacement the deprecation names. When you still need the deprecated API, such as to keep a public API compatible, ignore it with the reported identifier and a reason.

Deprecated methods and properties reached through a proxy, such as `$users->each->notify()`, `optional($user)->notify()` or `$user->when($active)->notify()`, are not reported, since PHPStan's deprecation checks only look at the proxy class itself.

To disable deprecation reporting, remove the `rules.neon` include. If you use the extension installer, add `phpstan/phpstan-deprecation-rules` to the `extra.phpstan/extension-installer.ignore` list in your `composer.json` file instead.
