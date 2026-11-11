# Static Analysis

- [Introduction](#introduction)
- [Installation](#installation)
    - [Analyzing Packages](#analyzing-packages)
    - [Runtime Requirements](#runtime-requirements)
- [Configuration](#configuration)
- [What the Analysis Knows](#what-the-analysis-knows)
    - [Configuration and Services](#configuration-and-services)
    - [Helpers and Factories](#helpers-and-factories)
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

Hypervel's `hypervel/phpstan` package extends [PHPStan](https://phpstan.org) with support for Eloquent models, container services, configuration and other framework features. It combines [Larastan](https://github.com/larastan/larastan)'s framework analysis with additional checks for common Hypervel mistakes, including coroutine and long-running worker issues.

<a name="installation"></a>
## Installation

New applications include the package already, so you may run `composer analyse` right away. To add it to an existing application or package, install it as a development dependency. The package requires PHPStan 2.3.5 or later:

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

You should load the extension whenever you analyze Hypervel code. Without it, PHPStan cannot resolve some framework PHPDoc types, such as eager loading arrays, and falls back to the native declarations.

<a name="analyzing-packages"></a>
### Analyzing Packages

To analyze a package, install `hypervel/testbench` as a development dependency. PHPStan uses the providers, configuration and package discovery settings in your `testbench.yaml` file, just like your package's tests. Authentication types come from the Testbench application, where `AUTH_MODEL` or the workbench `User` model selects the user model.

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

<a name="configuration-and-services"></a>
### Configuration and Services

Configuration types come from your configuration files themselves, never from the values on your machine. For example, the following `enabled` option is a `bool` no matter what your `.env` file contains:

```php
// config/feature.php
return ['enabled' => (bool) env('FEATURE_ENABLED', false)];

// Inferred as bool...
$enabled = config('feature.enabled');
```

Configuration types assume you are reading your application's configuration repository. If you build a separate repository, such as `new Repository($items)`, add a PHPDoc type to the values you read from it, or ignore a reported call with a reason when needed.

PHPStan boots your application to discover its services, authentication guards, commands, views and translations. Run analysis with the same environment locally and in CI, since settings such as `DB_CONNECTION` and `AUTH_MODEL` affect the inferred types. Package analysis uses your Testbench application's registrations.

A service resolved using `app(Repository::class)` has the type of its container binding. Helpers such as `response()` and `url()` also return their bound implementations, so those classes' methods and registered macros are available. A value typed as an interface exposes only that interface's methods, even when the bound class has additional methods or macros. Use the concrete type when your code requires them.

Declare parameter and return types on macro closures and register them during application boot so PHPStan can check their calls. A class's `@method` tag takes precedence over a macro's signature. Macros registered later, such as in a controller action, are not discovered.

Calls forwarded to a manager's configured default driver use the return type of its `create...Driver()` method or `extend()` callback, unless the manager declares the method or a `@mixin` for its drivers. Declare these return types so PHPStan can check the calls without creating the driver.

Filesystem calls use your configured disk types. With the built-in drivers, S3 disks return a `ClientPooledFilesystem` and local disks return a `LocalFilesystemAdapter`. Calls through the `Storage` facade use the default disk's methods. An unknown disk name keeps the `Filesystem` contract type.

Concurrency drivers use your `concurrency` configuration: `Concurrency::driver('sync')` returns a `SyncDriver`. Unknown names and custom drivers registered with `extend()` keep the `Driver` contract type.

PHPStan reuses cached results for unchanged code. Changes to the environment, service providers or other boot files also invalidate results that depend on the application's registrations.

<a name="helpers-and-factories"></a>
### Helpers and Factories

Use typed request helpers such as `$request->string()`, `integer()` and `boolean()` when you need a specific type. Values read by key using `query()`, `post()` or `cookie()` may be strings, numbers, booleans, arrays or `Stringable` objects. Input arrays may also have integer keys, such as those from a JSON list body.

The `now()` and `today()` helpers and the `Date` facade return `CarbonInterface`, even when you configure a specific date class. Use a conversion method such as `toImmutable()` when you need a more specific type.

Model factories retain their model types: `User::factory()->create()` returns a `User`, while `User::factory(3)->create()` returns a collection. The `createMany`, `createManyQuietly` and `makeMany` methods reset the factory's count. If you reuse that factory, assign these methods' results before passing them to another call so PHPStan can track the reset. Factories with their own `@template` declarations keep their generic types and may return either a model or a collection.

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

When a value cannot be inferred, such as configuration written by code the analysis cannot follow, PHPStan keeps the method's declared return type.

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

By default, PHPStan reads migrations registered with `loadMigrationsFrom` and those in `database/migrations`, using the same file selection and name order as `migrate`. Published migrations replace package migrations of the same name. [Schema dumps](/docs/{{version}}/migrations#squashing-migrations) are read from `database/schema`.

You may override these directories using `databaseMigrationsPath` and `squashedMigrationsPath`. Both accept glob patterns and replace their default paths. Unlike default migration discovery, custom migration directories are searched recursively for all `.php` files:

```neon
parameters:
    databaseMigrationsPath:
        - database/migrations
        - modules/*/migrations
    squashedMigrationsPath:
        - database/schema
```

A schema dump is only read when it has the name the `schema:dump` Artisan command gives it: `{connection}-schema.sql`, or `{connection}-schema.dump`, where `{connection}` is a connection in your `database` configuration. When both exist, the `.dump` file is read, as the `migrate` command does. The schema of a connection with no dump and no migrations is unknown.

PHPStan reads migrations without executing them. It follows schema builder calls, `if` and `switch` statements, `Schema::hasTable()` and `Schema::hasColumn()` checks, and literal SQL passed to `DB::statement()` or `DB::unprepared()`. Each call uses the migration's connection unless it selects another with `Schema::connection()` or `DB::connection()`, including through a builder stored in a variable:

```php
$schema = Schema::connection('analytics');

$schema->create('events', function (Blueprint $table) {
    // ...
});
```

Dynamic migration code can limit attribute typing. Blueprint macros, loops and helpers that receive a blueprint make its table unknown. A `switch` statement whose case can end early, such as through a `break` inside an `if`, makes the tables it changes unknown. When a migration assigns its `$connection` property outside its `down` method, such as in its constructor, the connection its calls use by default is unknown. Unknown column names leave room for additional columns; unknown table names make the connection's schema unknown, and unknown connection names make every connection's schema unknown. A column created in only some branches reads as `mixed`.

Use the `Schema` and `DB` facades for schema changes; connections obtained through properties or helpers such as `app('db')` are not followed. To target another database or schema, configure a connection and select it instead of relying on `USE` or `SET search_path` across separate SQL calls.

Migrations and schema dumps are cached in PHPStan's temporary directory and read again when they, or the classes and configuration they read, change. You may disable the cache using the `enableMigrationCache` parameter, or stop reading migrations or schema dumps using the `disableMigrationScan` or `disableSchemaScan` parameter.

<a name="accessor-types"></a>
### Accessor Types

Declare an accessor's read and write types using `Attribute<TGet, TSet>`. Use `never` for the write type when the accessor only has a getter:

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

Set the `get` and `set` callbacks when you create the `Attribute`. PHPStan treats them as read-only afterwards; this does not restrict reading or writing the model attribute itself. If you need to replace a callback, ignore `property.readOnlyByPhpDocAssignOutOfClass` with a reason.

<a name="cast-types"></a>
### Cast Types

PHPStan reads the array returned by your model's `casts` method. Casts may use strings, class constants, `[$cast, ...$arguments]`, or helpers such as `AsCollection::of` and `AsEnumCollection::of` with constant arguments.

An attribute with an unknown cast reads and writes as `mixed`. If the method builds its array dynamically or has multiple return statements, its casts cannot be inferred and attributes read as `mixed`. Declare an array shape when composing casts this way:

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

Include every returned cast, including inherited casts. An attribute omitted from a closed shape is treated as not cast by that method. If you cannot list them all, end the shape with `...` so unlisted attributes remain `mixed`.

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

If a model trait overrides `newEloquentBuilder`, as the [nested set](/docs/{{version}}/nested-set#model-setup) `HasNode` trait does, you may declare your builder type using `HasBuilder`. This declaration takes precedence during analysis; keep the runtime builder configuration shown above:

```php
/** @use HasBuilder<UserBuilder<static>> */
use HasBuilder;
```

If your Eloquent builder uses a custom query builder, declare that type on the builder's `getQuery` method, so methods forwarded to the query builder are typed from it.

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

To describe builder macros registered by a trait's global scope, map the trait to an interface using `eloquentBuilderMacros`. Declare the macro signatures on the interface and continue registering them in the scope at runtime. A `static` return type preserves the calling builder's type; an interface with one template type receives the model:

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

The extension enables `reportIgnoresWithoutComments`, so each ignored identifier needs a reason. Blanket `@phpstan-ignore-line` and `@phpstan-ignore-next-line` comments are also reported. To ignore several diagnostics on one line, give each its own reason:

```php
// @phpstan-ignore property.notFound (Queueable property), method.notFound (Queueable method)
```

Fix confirmed bugs rather than adding them to a baseline.

You may run `phpstan analyse --fix` to convert pipe-separated validation rules to arrays, remove unnecessary `value` and `with` calls, make private scopes and accessors protected, and start model queries with `query()` when that preference is enabled. Review the changes before committing them.

The following sections explain the diagnostics and their remedies. Use this table to find a diagnostic by its identifier:

| Topic | Identifiers |
|---|---|
| [Environment Variables](#environment-variables) | `hypervel.noEnvCallsOutsideOfConfig` |
| [Configuration Values](#configuration-values) | `hypervel.configType`, `hypervel.configCollection` |
| [Array Keys](#array-keys) | `offsetAccess.notFound` |
| [Value and With Helpers](#value-and-with-helpers) | `hypervel.uselessConstructs.value`, `hypervel.uselessConstructs.with` |
| [Converting Collections to Arrays](#converting-collections-to-arrays) | `hypervel.unnecessaryEnumerableToArrayCall` |
| [Translations](#translations) | `hypervel.missingTranslations` |
| [Views](#views) | `hypervel.unusedViews`, `argument.type` for view names |
| [Console Input](#console-input) | `hypervel.console.undefinedArgument`, `hypervel.console.undefinedOption` |
| [Authentication in Requests](#authentication-in-requests) | `hypervel.noAuthFacadeInRequestScope`, `hypervel.noAuthHelperInRequestScope` |
| [Redis Connections](#redis-connections) | `hypervel.redisConnectionBoundMethod` |
| [Cancellation](#cancellation) | `hypervel.cancellation.exactType`, `hypervel.cancellation.precedence` |
| [Coroutines in Transactions](#coroutines-in-transactions) | `hypervel.coroutineInTransaction` |
| [Sleeping](#sleeping) | `hypervel.rawSleep` |
| [Worker State](#worker-state) | `hypervel.workerSharedState`, `hypervel.staticStateMutation`, `hypervel.unboundedWorkerCache` |
| [Runtime Configuration](#runtime-configuration) | `hypervel.runtimeConfigMutation` |
| [Model Relations](#model-relations) | `hypervel.relationExistence` |
| [Model Columns](#model-columns) | `argument.type` for column names, `method.notFound` and `staticMethod.notFound` for dynamic wheres |
| [Model Scopes and Accessors](#model-scopes-and-accessors) | `hypervel.legacyModelScope`, `hypervel.noPublicModelScopeMethod`, `hypervel.noPublicModelAccessorMethod` |
| [Model Configuration](#model-configuration) | `hypervel.legacyModelProperty` |
| [Appended Attributes](#appended-attributes) | `hypervel.modelAppends` |
| [Creating Models](#creating-models) | `hypervel.noModelMake` |
| [Query Results](#query-results) | `hypervel.noUnnecessaryCollectionCall` |
| [Starting Queries](#starting-queries) | `hypervel.noImplicitQueryBuilderCall` |
| [Schema Dumps](#schema-dumps) | `hypervel.schemaDump` |
| [Dispatching Jobs and Events](#dispatching-jobs-and-events) | `hypervel.jobs.noConstructor`, `hypervel.events.noConstructor`, `argument.type` and the other argument identifiers |
| [Dispatching Jobs in Transactions](#dispatching-jobs-in-transactions) | `hypervel.dispatchInTransactionAfterCommit` |
| [Models in Queued Jobs](#models-in-queued-jobs) | `hypervel.jobSerializesModels` |
| [Batched Jobs](#batched-jobs) | `hypervel.batchedJobIsBatchable`, `hypervel.noBatchedUniqueJob`, `hypervel.batchableJobChecksCancellation` |
| [Unique Jobs](#unique-jobs) | `hypervel.uniqueJobUniqueId`, `hypervel.uniqueJobUniqueFor` |
| [Queue Configuration](#queue-configuration) | `hypervel.legacyQueueProperty` |
| [Validation Rules](#validation-rules) | `hypervel.pipeSeparatedValidationRules` |
| [Deprecations](#deprecations) | `method.deprecated` and the other deprecation identifiers |

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

The check covers direct calls to `env`, `env_array`, `env_or_fail`, `app_id`, `Env::get`, `Env::getArray` and `Env::getOrFail`. Configuration files, including those merged or published by packages, are allowed. Dynamic calls, `getenv()` and `$_ENV` are not checked.

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

Configuration types include the defaults of any package whose configuration your application has not published. When you analyze a package, values merged from package configuration files keep the getter's declared type instead, since the applications using the package replace them. The framework's defaults and your workbench configuration files are still typed. These checks may be disabled using the `checkConfigTypes` parameter.

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

PHPStan checks literal `group.item` and `namespace::group.item` translation keys used by the translation helpers, `Lang` facade and translator. It uses the requested locale and fallback, or your application's default locale. Checks require the group's translation file to exist; JSON translations, dynamic keys and custom translators or loaders are not checked.

To fix the diagnostic, add the missing line or correct the key. Keys are checked against the translations your application loads. To check them against other directories instead, list the directories using the `translationDirectories` parameter. You may disable the rule using the `checkMissingTranslations` parameter.

<a name="views"></a>
### Views

Methods that accept a view name, such as `view()` and `View::make()`, require a view the booted application can find, so a misspelled view name is reported as an `argument.type` error. If a package only registers a view namespace when a command runs or a feature is enabled, the analysis cannot see those views; ignore those calls with a reason.

You may also enable the `checkUnusedViews` parameter to report views that no code refers to by a literal name. Views rendered by computed names or used by other packages may still be in use, so treat these diagnostics as a list to review rather than proof that a view can be deleted.

View usage is recognized in view helpers, view routes, mail definitions, Blade layouts and includes, and anonymous component tags such as `<x-alert>`. By default, your application's view paths are checked. To check other directories instead, use the `viewDirectories` parameter:

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

Use the same approach instead of `instanceof CanceledException` or storing a directly caught cancellation for later. If the value may be `null`, such as `getPrevious()`, check `instanceof Throwable` first. A `Throwable` catch that only rethrows is not reported. Neither is a `try` block containing only waits that throw cancellation directly, such as channel waits or `Coroutine::join`.

When you keep the first of several failures using `??=`, a later failure carrying cancellation is lost. Use `Coroutine::preferCancellation`, which keeps the first failure unless a later one carries this coroutine's cancellation:

```php
$failure = Coroutine::preferCancellation($failure, $exception);
```

The check allows `??=` when no earlier failure can exist or the branch excludes cancellation. You may disable these checks using the `checkCancellationHandling` and `checkCancellationPrecedence` parameters.

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

Start the coroutine from a `DB::afterCommit` callback, or do the work in the transaction's own coroutine. The check covers `go`, `co`, `parallel`, `wait`, `Coroutine::create`, `Coroutine::createOwned`, `Coroutine::fork`, `Coroutine::forkOwned`, `Parallel`, `Concurrent`, `WaitConcurrent` and `Waiter`. It also covers `Concurrency::run` when the selected driver is known to be `coroutine`. You may disable it using the `noCoroutinesInTransactions` parameter.

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

Request and job checks cover middleware, controller actions, route closures, form requests, and queued classes' `handle`, `__invoke` and `failed` methods, along with the methods they call directly on `$this`. Nested closures are not checked because they may run later.

Shared services are identified from your application's container registrations, resolved classes, handlers and constructor dependencies, following the container's [resolution lifecycles](/docs/{{version}}/container#resolution-lifecycles). A constructor-injected model used as a fresh prototype is allowed. Objects created inside binding closures are not inspected, and job `handle` dependencies are followed only for jobs using `Dispatchable`.

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

Set configuration during application boot and keep per-request values in [CoroutineContext](/docs/{{version}}/coroutine-context). The check reports writes through the `config` helper, `Config` facade, container-resolved repository and supported method-injected parameters in the [request and job methods](#worker-state) described above.

Only writes known to target the shared configuration are reported. Repositories you construct yourself or store in properties are not checked. You may disable the rule using the `noRuntimeConfigMutation` parameter.

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

A scope declaring no return type, `void`, `null` or a builder remains chainable. Other return types are preserved; for example, `Builder|int` returns either the calling query or an integer.

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

The check recommends attributes for model settings such as the table, connection, keys, timestamps, mass assignment and serialization. It also identifies declarations that repeat an inherited or default value. Existing properties remain supported. Settings assigned directly by the constructor or through setters such as `setTable` are not reported; assignments inside helper methods or trait initializers are not detected.

Review subclasses before converting a model. Attributes and properties have different inheritance behavior: a child's `Hidden` attribute replaces the parent's `Hidden` attribute, but adds to a parent's `$hidden` property. An inherited `WithoutTimestamps` attribute also overrides a child's `$timestamps = true` property.

Keep properties when consumers rely on this behavior, especially on models that applications extend, and ignore the diagnostic with a reason. You may disable the check using `noLegacyModelProperties`.

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

This suggestion applies only when direct construction preserves the behavior of `make`. Models using the default connection, global scopes such as `SoftDeletes`, boot methods or custom construction are excluded, as are calls through `static` in extensible classes. You may disable the check using `noModelMake`.

<a name="query-results"></a>
### Query Results

Fetching every row only to count, test or pluck the results is slower than letting the database do it. The analysis reports a collection method called on a query's results when the query itself can return the same result:

```php
// Called 'count' on Hypervel collection, but could have been retrieved as a query with 'count()'.
$active = Flight::where('active', 1)->get()->count();

$active = Flight::where('active', 1)->count();
```

The check covers `first`, `count`, `isEmpty`, `isNotEmpty`, `take`, `pluck` and `modelKeys`. For `isEmpty` and `isNotEmpty`, the diagnostic suggests the query's `doesntExist` and `exists` methods.

Only direct query chains with equivalent results are reported. Queries stored in variables, relationship queries, selected columns, scopes, macros and custom collection behavior are excluded. Accessors, casts, observers and other model customizations can also prevent a suggestion. Keep the collection call when moving it to the database would change its behavior.

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

Until the dump is fixed, the tables it defines are unknown, so their attributes read as `mixed`. Regenerate the dump using the `schema:dump` Artisan command, which writes plain SQL. A dump is only reported when the analyzed code reads a model attribute on its connection. You may stop reading schema dumps using the `disableSchemaScan` parameter.

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

Every job you add to a batch must use the `Hypervel\Bus\Batchable` trait, or adding it fails. The analysis reports a job that does not use it when you pass it to `Bus::batch()` or to a batch's `add` method, including jobs held in variables and the jobs of a chain within the batch. A value that may hold different jobs, such as a job or a chain, or one of several lists of jobs, is reported only when every possibility includes a job without the trait. You may disable this check using the `checkBatchedJobIsBatchable` parameter.

Jobs that implement `ShouldBeUnique` should be dispatched individually, since only an individual dispatch acquires the job's unique lock. `Bus::batch()`, `Bus::bulk()`, `Queue::bulk()` and a batch's `add` method queue jobs without it, so duplicates are queued. A later job of a chain within a batch acquires its lock when the chain reaches it, but when the lock is already held, the job is skipped and the batch never finishes. A value that may hold different jobs is reported when any possibility includes a unique job. You may disable this check using the `noBatchedUniqueJob` parameter.

When the `checkBatchableJobChecksCancellation` parameter is enabled, the analysis also reports batchable jobs that do not check whether their batch has been canceled. Canceling a batch does not stop jobs that are already queued, so a job should return early when its batch has been canceled, or use the `SkipIfBatchCancelled` middleware:

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

A unique job's lock is identified by its class and its `uniqueId`. A job whose constructor accepts arguments, such as a job that updates one product, usually needs a separate lock for each set of arguments, so the analysis reports such a job unless it declares a public `uniqueId` method or property:

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

When the `checkUniqueJobUniqueFor` parameter is enabled, the analysis also reports unique jobs that do not declare how long they stay unique, using the `UniqueFor` attribute, a public `uniqueFor` method or a public `$uniqueFor` property. Without one, the lock is acquired without a duration, whose meaning depends on your cache store, so a worker that stops while running the job may leave the lock held.

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

The check recommends attributes for public queue settings, including the connection, queue, delay, retries and timeouts, wherever that class supports the attribute. It also identifies declarations that can be removed without changing the setting. Settings declared by a trait may be moved to an attribute on that trait unless their value depends on the consuming class. Existing properties remain supported.

Computed settings, `false` flags, and settings assigned directly by the constructor or through `Queueable` setters are not reported. A `false` flag can disable an inherited attribute. Assignments inside other constructor-called methods are not detected.

Review inheritance and other callers before converting a property. A subclass's non-null public property overrides an inherited attribute, while its own attribute replaces the parent's entire attribute; for example, `#[DebounceFor(10)]` does not inherit a parent's `maxWait`. Keep a property that other code reads or assigns if no parent provides it, and ignore the diagnostic with a reason. You may disable this check using `noLegacyQueueProperties`.

<a name="validation-rules"></a>
### Validation Rules

List validation rules in arrays rather than pipe-separated strings:

```php
$request->validate([
    'title' => ['required', 'string', 'max:255'],
    'code' => ['required', 'regex:/^(draft|published)$/'],
]);
```

The check covers rules passed to validation helpers and methods, form requests, data objects, conditional rules and prompts. Concatenated and interpolated strings are checked too. Run `phpstan analyse --fix` to convert unambiguous strings to arrays; rules with empty segments or surrounding whitespace require a manual change.

A string holding a `regex` or `not_regex` rule is reported without a fix, because the validator also splits the pattern at its own `|`. Give the regex its own array element, where the validator keeps it whole. Data rules differ: a data rule string that starts with a regex rule is kept whole, so it is not reported.

Rule arrays built in variables, helpers or closures are not followed. Constants and conditional values inside a directly supplied array are checked. You may disable the rule using `noPipeSeparatedValidationRules`.

<a name="deprecations"></a>
### Deprecations

The bundled deprecation rules report uses of deprecated APIs from any of your dependencies, including PHP, Symfony and Carbon as well as Hypervel. Use the replacement the deprecation names. When you still need the deprecated API, such as to keep a public API compatible, ignore it with the reported identifier and a reason.

Code inside a deprecated method, class or trait may use other deprecated APIs without being reported.

Deprecation checks do not cover methods and properties reached through proxies such as `$users->each`, `optional($user)` or `$user->when($active)`.

To disable deprecation reporting, remove the `rules.neon` include. If you use the extension installer, add `phpstan/phpstan-deprecation-rules` to the `extra.phpstan/extension-installer.ignore` list in your `composer.json` file instead.
