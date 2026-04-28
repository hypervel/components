# Hypervel Scout

- [Introduction](#introduction)
- [Installation](#installation)
    - [Indexing Mode](#queueing)
- [Driver Prerequisites](#driver-prerequisites)
- [Configuration](#configuration)
    - [Configuring Searchable Data](#configuring-searchable-data)
- [Database, Collection, and Null Engines](#database-and-collection-engines)
    - [Database Engine](#database-engine)
    - [Collection Engine](#collection-engine)
    - [Null Engine](#null-engine)
- [Third-Party Engine Configuration](#third-party-engine-configuration)
    - [Configuring Model Indexes](#configuring-model-indexes)
    - [Algolia](#algolia-configuration)
    - [Meilisearch](#meilisearch-configuration)
    - [Typesense](#typesense-configuration)
- [Third-Party Engine Indexing](#indexing)
    - [Batch Import](#batch-import)
    - [Adding Records](#adding-records)
    - [Updating Records](#updating-records)
    - [Removing Records](#removing-records)
    - [Pausing Indexing](#pausing-indexing)
    - [Conditionally Searchable Model Instances](#conditionally-searchable-model-instances)
- [Searching](#searching)
    - [Where Clauses](#where-clauses)
    - [Pagination](#pagination)
    - [Soft Deleting](#soft-deleting)
    - [Customizing Engine Searches](#customizing-engine-searches)
- [Custom Engines](#custom-engines)

<a name="introduction"></a>
## Introduction

[Hypervel Scout](https://github.com/hypervel/scout) provides a simple, driver-based solution for adding full-text search to your [Eloquent models](/docs/{{version}}/eloquent). Using model observers, Scout will automatically keep your search indexes in sync with your Eloquent records.

Scout ships with a built-in `database` engine that uses MySQL / PostgreSQL full-text indexes and `LIKE` clauses to search your existing database — no external service required. For most applications, this is all you need. For an overview of all search options available in Hypervel, consult the [search documentation](/docs/{{version}}/search).

Scout also includes drivers for [Algolia](https://www.algolia.com/), [Meilisearch](https://www.meilisearch.com), and [Typesense](https://typesense.org) when you need features like typo tolerance, faceted filtering, or geo-search at massive scale. A "collection" driver is also available for local development, and you are free to write [custom engines](#custom-engines) as well.

<a name="installation"></a>
## Installation

First, install Scout via the Composer package manager:

```shell
composer require hypervel/scout
```

After installing Scout, you should publish the Scout configuration file using the `vendor:publish` Artisan command. This command will publish the `scout.php` configuration file to your application's `config` directory:

```shell
php artisan vendor:publish --tag=scout-config
```

Finally, add the `Hypervel\Scout\Searchable` trait and the `Hypervel\Scout\Contracts\SearchableInterface` contract to the model you would like to make searchable. The trait will register a model observer that will automatically keep the model in sync with your search driver:

```php
<?php

namespace App\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Searchable;

class Post extends Model implements SearchableInterface
{
    use Searchable;
}
```

<a name="queueing"></a>
### Indexing Mode

By default, Hypervel Scout indexes models asynchronously (without blocking the worker), meaning the worker can continue to handle other requests while indexing completes. Additionally, indexing is scheduled via `Coroutine::defer` and runs after the HTTP response has been sent to the user, meaning there's no delay in returning the response. This default mode works well for most applications and requires no queue worker or other infrastructure.

If you need indexing failures to be persistently tracked and retried, or to wait for a database transaction to commit, you can switch Scout to queue-based indexing by enabling the `queue.enabled` option in your `config/scout.php` configuration file:

```php
'queue' => [
    'enabled' => true,
],
```

Once enabled, Scout dispatches indexing as [queued jobs](/docs/{{version}}/queues). The connection and queue those jobs run on are controlled by the `SCOUT_QUEUE_CONNECTION` and `SCOUT_QUEUE_NAME` environment variables, or by setting the `connection` and `queue` values directly under `queue` in `config/scout.php`.

Of course, if you customize the connection and queue that Scout jobs utilize, you should run a queue worker to process jobs on that connection and queue:

```shell
php artisan queue:work redis --queue=scout
```

Each queue option may also be set via the `SCOUT_QUEUE`, `SCOUT_QUEUE_CONNECTION`, `SCOUT_QUEUE_NAME`, and `SCOUT_QUEUE_AFTER_COMMIT` environment variables.

#### Transaction-Safe Dispatch

If your indexing happens inside a database transaction, set `after_commit` so the queued job is only dispatched once the transaction commits:

```php
'queue' => [
    'enabled' => true,
    'after_commit' => true,
],
```

This stops the queue worker from picking up an indexing job for a record that no longer exists because the transaction was rolled back.

#### When to Use Each Mode

The default coroutine-backed mode is the right choice for most applications. Stick with it when:

- Best-effort indexing is acceptable. Transient failures are retried automatically at the HTTP layer, but if those retries are exhausted, the failure is logged and the change is dropped from the index until something else triggers a re-index — typically `php artisan scout:import` or a manual `$model->searchable()` call.
- You're not indexing inside a database transaction that needs to commit first.

Switch to queue mode when:

- You need failed indexing operations to be persistently tracked. Failed jobs land in the `failed_jobs` table and can be inspected and retried via `php artisan queue:retry`.
- You need indexing to wait for a database transaction to commit (use `after_commit` for this).

Regardless of the chosen mode, some Scout drivers like Algolia and Meilisearch index records asynchronously on the engine side, so even though the indexing call has completed within your Hypervel application, the search engine itself may not reflect the new and updated records immediately.

> [!NOTE]
> This section is about how Scout indexes models when your application saves or deletes them. It does not affect the `scout:import` and `scout:queue-import` Artisan commands — see [Batch Import](#batch-import) for those.

<a name="driver-prerequisites"></a>
## Driver Prerequisites

<a name="algolia"></a>
### Algolia

When using the Algolia driver, you should configure your Algolia `id` and `secret` credentials in your `config/scout.php` configuration file. Once your credentials have been configured, you will also need to install the Algolia PHP SDK via the Composer package manager:

```shell
composer require algolia/algoliasearch-client-php
```

<a name="meilisearch"></a>
### Meilisearch

[Meilisearch](https://www.meilisearch.com) is a fast, open source search engine. If you aren't sure how to install Meilisearch on your local machine, you may use [Hypervel Sail](/docs/{{version}}/sail#meilisearch), Hypervel's officially supported Docker development environment.

When using the Meilisearch driver you will need to install the Meilisearch PHP SDK via the Composer package manager:

```shell
composer require meilisearch/meilisearch-php
```

Then, set the `SCOUT_DRIVER` environment variable as well as your Meilisearch `host` and `key` credentials within your application's `.env` file:

```ini
SCOUT_DRIVER=meilisearch
MEILISEARCH_HOST=http://127.0.0.1:7700
MEILISEARCH_KEY=masterKey
```

For more information regarding Meilisearch, please consult the [Meilisearch documentation](https://docs.meilisearch.com/learn/getting_started/quick_start.html).

In addition, you should ensure that you install a version of `meilisearch/meilisearch-php` that is compatible with your Meilisearch binary version by reviewing [Meilisearch's documentation regarding binary compatibility](https://github.com/meilisearch/meilisearch-php#-compatibility-with-meilisearch).

> [!WARNING]
> When upgrading Scout on an application that utilizes Meilisearch, you should always [review any additional breaking changes](https://github.com/meilisearch/Meilisearch/releases) to the Meilisearch service itself.

<a name="typesense"></a>
### Typesense

[Typesense](https://typesense.org) is a lightning-fast, open source search engine and supports keyword search, semantic search, geo search, and vector search.

You can [self-host](https://typesense.org/docs/guide/install-typesense.html#option-2-local-machine-self-hosting) Typesense or use [Typesense Cloud](https://cloud.typesense.org).

To get started using Typesense with Scout, install the Typesense PHP SDK via the Composer package manager:

```shell
composer require typesense/typesense-php
```

Then, set the `SCOUT_DRIVER` environment variable as well as your Typesense host and API key credentials within your application's .env file:

```ini
SCOUT_DRIVER=typesense
TYPESENSE_API_KEY=masterKey
TYPESENSE_HOST=localhost
```

If you are using [Hypervel Sail](/docs/{{version}}/sail), you may need to adjust the `TYPESENSE_HOST` environment variable to match the Docker container name. You may also optionally specify your installation's port, path, and protocol:

```ini
TYPESENSE_PORT=8108
TYPESENSE_PATH=
TYPESENSE_PROTOCOL=http
```

Additional settings and schema definitions for your Typesense collections can be found within your application's `config/scout.php` configuration file. For more information regarding Typesense, please consult the [Typesense documentation](https://typesense.org/docs/guide/#quick-start).

<a name="configuration"></a>
## Configuration

<a name="configuring-searchable-data"></a>
### Configuring Searchable Data

By default, the entire `toArray` form of a given model will be persisted to its search index. If you would like to customize the data that is synchronized to the search index, you may override the `toSearchableArray` method on the model:

```php
<?php

namespace App\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Searchable;

class Post extends Model implements SearchableInterface
{
    use Searchable;

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        $array = $this->toArray();

        // Customize the data array...

        return $array;
    }
}
```

<a name="configuring-search-engines-per-model"></a>
#### Configuring Model Engines

When searching, Scout will typically use the default search engine specified in your application's `scout` configuration file. However, the search engine for a particular model can be changed by overriding the `searchableUsing` method on the model:

```php
<?php

namespace App\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Engines\Engine;
use Hypervel\Scout\Scout;
use Hypervel\Scout\Searchable;

class User extends Model implements SearchableInterface
{
    use Searchable;

    /**
     * Get the engine used to index the model.
     */
    public function searchableUsing(): Engine
    {
        return Scout::engine('meilisearch');
    }
}
```

<a name="database-and-collection-engines"></a>
## Database, Collection, and Null Engines

<a name="database-engine"></a>
### Database Engine

> [!WARNING]
> The database engine currently supports MySQL and PostgreSQL, both of which provide support for fast, full-text column indexing.

The `database` engine uses MySQL / PostgreSQL full-text indexes and `LIKE` clauses to search your existing database directly. For many applications, this is the simplest and most practical way to add search — no external service or additional infrastructure required.

To use the database engine, set the `SCOUT_DRIVER` environment variable to `database`:

```ini
SCOUT_DRIVER=database
```

Once configured, you may [define your searchable data](#configuring-searchable-data) and start [executing search queries](#searching) against your models. Unlike third-party engines, the database engine requires no separate indexing step — it searches your database tables directly.

#### Customizing Database Searching Strategies

By default, the database engine will execute a `LIKE` query against every model attribute that you have [configured as searchable](#configuring-searchable-data). However, you can assign more efficient search strategies to specific columns. The `SearchUsingFullText` attribute will use your database's full-text index for that column, while `SearchUsingPrefix` will only match the beginning of strings (`example%`) instead of searching within the entire string (`%example%`).

To define this behavior, assign PHP attributes to your model's `toSearchableArray` method. Any columns without an attribute will continue to use the default `LIKE` strategy:

```php
use Hypervel\Scout\Attributes\SearchUsingFullText;
use Hypervel\Scout\Attributes\SearchUsingPrefix;

/**
 * Get the indexable data array for the model.
 *
 * @return array<string, mixed>
 */
#[SearchUsingPrefix(['id', 'email'])]
#[SearchUsingFullText(['bio'])]
public function toSearchableArray(): array
{
    return [
        'id' => $this->id,
        'name' => $this->name,
        'email' => $this->email,
        'bio' => $this->bio,
    ];
}
```

> [!WARNING]
> Before specifying that a column should use full text query constraints, ensure that the column has been assigned a [full text index](/docs/{{version}}/migrations#available-index-types).

<a name="collection-engine"></a>
### Collection Engine

The "collection" engine is intended for quick prototypes, extremely small datasets (a few hundred records), or running tests. It retrieves all possible records from your database and uses Hypervel's `Str::is` helper to filter them in PHP, so it does not require any indexing or database-specific features. For anything beyond trivial use cases, you should use the [database engine](#database-engine) instead.

To use the collection engine, you may simply set the value of the `SCOUT_DRIVER` environment variable to `collection`, or specify the `collection` driver directly in your application's `scout` configuration file:

```ini
SCOUT_DRIVER=collection
```

Once you have specified the collection driver as your preferred driver, you may start [executing search queries](#searching) against your models. Search engine indexing, such as the indexing needed to seed Algolia, Meilisearch, or Typesense indexes, is unnecessary when using the collection engine.

#### Differences From Database Engine

While the database engine uses full-text indexes and `LIKE` clauses to find matching records efficiently, the collection engine pulls all records and filters them in PHP. The collection engine is the most portable option as it works across all relational databases supported by Hypervel (including SQLite); however, it is significantly less efficient than the database engine and should not be used with large datasets.

<a name="null-engine"></a>
### Null Engine

The "null" engine is a no-op driver useful for tests or any scenario where you want to disable search entirely without removing the `Searchable` trait from your models. All indexing and search calls return immediately without dispatching any work.

To use the null engine, set the `driver` value to `null` in your `config/scout.php` file:

```php
'driver' => 'null',
```

<a name="third-party-engine-configuration"></a>
## Third-Party Engine Configuration

The following configuration options are only relevant when using a third-party search engine such as Algolia, Meilisearch, or Typesense. If you are using the [database engine](#database-engine), you may skip this section.

<a name="configuring-model-indexes"></a>
### Configuring Model Indexes

When using a third-party engine, each Eloquent model is synced with a given search "index", which contains all of the searchable records for that model. By default, each model will be persisted to an index matching the model's typical "table" name. Typically, this is the plural form of the model name; however, you are free to customize the model's index by overriding the `searchableAs` method on the model:

```php
<?php

namespace App\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Searchable;

class Post extends Model implements SearchableInterface
{
    use Searchable;

    /**
     * Get the name of the index associated with the model.
     */
    public function searchableAs(): string
    {
        return 'posts_index';
    }
}
```

> [!NOTE]
> The `searchableAs` method has no effect when using the database engine, which always searches the model's database table directly.

For more advanced use cases — such as zero-downtime index rebuilds via aliases — you may also override the `indexableAs` method to write to a different index name than the one used for searching. By default, `indexableAs` returns the same value as `searchableAs`:

```php
public function indexableAs(): string
{
    return 'posts_v2';
}
```

<a name="configuring-the-model-id"></a>
#### Configuring the Model ID

By default, Scout will use the primary key of the model as the model's unique ID / key that is stored in the search index. If you need to customize this behavior when using a third-party engine, you may override the `getScoutKey` and the `getScoutKeyName` methods on the model:

```php
<?php

namespace App\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Contracts\SearchableInterface;
use Hypervel\Scout\Searchable;

class User extends Model implements SearchableInterface
{
    use Searchable;

    /**
     * Get the value used to index the model.
     */
    public function getScoutKey(): mixed
    {
        return $this->email;
    }

    /**
     * Get the key name used to index the model.
     */
    public function getScoutKeyName(): string
    {
        return 'email';
    }
}
```

> [!NOTE]
> The `getScoutKey` and `getScoutKeyName` methods have no effect when using the database engine, which always uses the model's primary key.

<a name="algolia-configuration"></a>
### Algolia

<a name="algolia-index-settings"></a>
#### Index Settings

Sometimes you may want to configure additional settings on your Algolia indexes. While you can manage these settings via the Algolia UI, it is sometimes more efficient to manage the desired state of your index configuration directly from your application's `config/scout.php` configuration file.

This approach allows you to deploy these settings through your application's automated deployment pipeline, avoiding manual configuration and ensuring consistency across multiple environments. You may configure filterable attributes, ranking, faceting, or [any other supported settings](https://www.algolia.com/doc/rest-api/search/#tag/Indices/operation/setSettings).

To get started, add settings for each index in your application's `config/scout.php` configuration file:

```php
use App\Models\User;
use App\Models\Flight;

'algolia' => [
    'id' => env('ALGOLIA_APP_ID', ''),
    'secret' => env('ALGOLIA_SECRET', ''),
    'index-settings' => [
        User::class => [
            'searchableAttributes' => ['id', 'name', 'email'],
            'attributesForFaceting'=> ['filterOnly(email)'],
            // Other settings fields...
        ],
        Flight::class => [
            'searchableAttributes'=> ['id', 'destination'],
        ],
    ],
],
```

Entries may also be keyed by a literal index name string instead of a model class, which is useful when an index is not tied to a single model:

```php
'index-settings' => [
    'flights_v2' => [
        'searchableAttributes' => ['id', 'destination'],
    ],
],
```

If the model underlying a given index is soft deletable and is included in the `index-settings` array, Scout will automatically include support for faceting on soft deleted models on that index. If you have no other faceting attributes to define for a soft deletable model index, you may simply add an empty entry to the `index-settings` array for that model:

```php
'index-settings' => [
    Flight::class => []
],
```

After configuring your application's index settings, you must invoke the `scout:sync-index-settings` Artisan command. This command will inform Algolia of your currently configured index settings. For convenience, you may wish to make this command part of your deployment process:

```shell
php artisan scout:sync-index-settings
```

<a name="algolia-identifying-users"></a>
#### Identifying Users

Scout allows you to auto identify users when using Algolia. Associating the authenticated user with search operations may be helpful when viewing your search analytics within Algolia's dashboard. You can enable user identification by defining a `SCOUT_IDENTIFY` environment variable as `true` in your application's `.env` file:

```ini
SCOUT_IDENTIFY=true
```

Enabling this feature will also pass the request's IP address and your authenticated user's primary identifier to Algolia so this data is associated with any search request that is made by the user.

<a name="meilisearch-configuration"></a>
### Meilisearch

<a name="meilisearch-index-settings"></a>
#### Index Settings

Meilisearch requires you to pre-define index search settings such as filterable attributes, sortable attributes, and [other supported settings fields](https://docs.meilisearch.com/reference/api/settings.html).

Filterable attributes are any attributes you plan to filter on when invoking Scout's `where` method, while sortable attributes are any attributes you plan to sort by when invoking Scout's `orderBy` method. To define your index settings, adjust the `index-settings` portion of your `meilisearch` configuration entry in your application's `scout` configuration file:

```php
use App\Models\User;
use App\Models\Flight;

'meilisearch' => [
    'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
    'key' => env('MEILISEARCH_KEY', null),
    'index-settings' => [
        User::class => [
            'filterableAttributes'=> ['id', 'name', 'email'],
            'sortableAttributes' => ['created_at'],
            // Other settings fields...
        ],
        Flight::class => [
            'filterableAttributes'=> ['id', 'destination'],
            'sortableAttributes' => ['updated_at'],
        ],
    ],
],
```

Entries may also be keyed by a literal index name string instead of a model class, which is useful when an index is not tied to a single model:

```php
'index-settings' => [
    'flights_v2' => [
        'filterableAttributes' => ['id', 'destination'],
        'sortableAttributes' => ['updated_at'],
    ],
],
```

If the model underlying a given index is soft deletable and is included in the `index-settings` array, Scout will automatically include support for filtering on soft deleted models on that index. If you have no other filterable or sortable attributes to define for a soft deletable model index, you may simply add an empty entry to the `index-settings` array for that model:

```php
'index-settings' => [
    Flight::class => []
],
```

After configuring your application's index settings, you must invoke the `scout:sync-index-settings` Artisan command. This command will inform Meilisearch of your currently configured index settings. For convenience, you may wish to make this command part of your deployment process:

```shell
php artisan scout:sync-index-settings
```

<a name="meilisearch-data-types"></a>
#### Searchable Data Types

Meilisearch will only perform filter operations (`>`, `<`, etc.) on data of the correct type. When customizing your searchable data, you should ensure that numeric values are cast to their correct type:

```php
public function toSearchableArray()
{
    return [
        'id' => (int) $this->id,
        'name' => $this->name,
        'price' => (float) $this->price,
    ];
}
```

<a name="typesense-configuration"></a>
### Typesense

<a name="typesense-searchable-data"></a>
#### Preparing Searchable Data

When utilizing Typesense, your searchable models must define a `toSearchableArray` method that casts your model's primary key to a string and creation date to a UNIX timestamp:

```php
/**
 * Get the indexable data array for the model.
 *
 * @return array<string, mixed>
 */
public function toSearchableArray(): array
{
    return array_merge($this->toArray(),[
        'id' => (string) $this->id,
        'created_at' => $this->created_at->timestamp,
    ]);
}
```

You may define your Typesense collection schema in your application's `config/scout.php` file under `typesense.model-settings`, or directly on the model by defining a `typesenseCollectionSchema` method. A collection schema describes the data types of each field that is searchable via Typesense. For more information on all available schema options, please consult the [Typesense documentation](https://typesense.org/docs/latest/api/collections.html#schema-parameters).

If you need to change your Typesense collection's schema after it has been defined, you may either run `scout:flush` and `scout:import`, which will delete all existing indexed data and recreate the schema. Or, you may use Typesense's API to modify the collection's schema without removing any indexed data.

If your searchable model is soft deletable, you should define a `__soft_deleted` field in the model's corresponding Typesense schema within your application's `config/scout.php` configuration file:

```php
User::class => [
    'collection-schema' => [
        'fields' => [
            // ...
            [
                'name' => '__soft_deleted',
                'type' => 'int32',
                'optional' => true,
            ],
        ],
    ],
],
```

<a name="typesense-dynamic-search-parameters"></a>
#### Dynamic Search Parameters

Default Typesense search parameters may be defined in `config/scout.php` under `typesense.model-settings`, or directly on the model by defining a `typesenseSearchParameters` method. In addition, Typesense allows you to modify your [search parameters](https://typesense.org/docs/latest/api/search.html#search-parameters) dynamically when performing a search operation via the `options` method:

```php
use App\Models\Todo;

Todo::search('Groceries')->options([
    'query_by' => 'title, description'
])->get();
```

<a name="indexing"></a>
## Third-Party Engine Indexing

> [!NOTE]
> The indexing features described in this section are primarily relevant when using a third-party engine (Algolia, Meilisearch, or Typesense). The database engine searches your database tables directly, so it does not require manual index management.

<a name="batch-import"></a>
### Batch Import

If you are installing Scout into an existing project, you may already have database records you need to import into your indexes. Scout provides a `scout:import` Artisan command that you may use to import all of your existing records into your search indexes:

```shell
php artisan scout:import "App\Models\Post"
```

You may pass the `--fresh` option to flush the index before importing. This is useful when re-importing a model whose `toSearchableArray` shape has changed, or when you simply want to rebuild the index from scratch:

```shell
php artisan scout:import "App\Models\Post" --fresh
```

Alternatively, the `scout:queue-import` command imports your existing records via [queued jobs](/docs/{{version}}/queues). The work is processed in parallel by your queue workers:

```shell
php artisan scout:queue-import "App\Models\Post"
```

You may optionally control the chunk size, the ID range, and the destination queue:

```shell
php artisan scout:queue-import "App\Models\Post" --chunk=500 --min=1000 --max=50000 --queue=imports
```

The `--min` and `--max` options are useful for resuming a partial import, or for running several imports in parallel against different ranges. There is no `--fresh` option — to rebuild the index from scratch, run `scout:flush` first.

The `flush` command may be used to remove all of a model's records from your search indexes:

```shell
php artisan scout:flush "App\Models\Post"
```

By default, `scout:import` processes chunks in parallel using up to 50 concurrent coroutines. You may tune this by setting `command_concurrency` in your `config/scout.php` file or via the `SCOUT_COMMAND_CONCURRENCY` environment variable.

<a name="modifying-the-import-query"></a>
#### Modifying the Import Query

If you would like to modify the query that is used to retrieve all of your models for batch importing, you may define a `makeAllSearchableUsing` method on your model. This is a great place to add any eager relationship loading that may be necessary before importing your models:

```php
use Hypervel\Database\Eloquent\Builder;

/**
 * Modify the query used to retrieve models when making all of the models searchable.
 */
protected function makeAllSearchableUsing(Builder $query): Builder
{
    return $query->with('author');
}
```

> [!WARNING]
> The `makeAllSearchableUsing` method may not be applicable when using a queue to batch import models. Relationships are [not restored](/docs/{{version}}/queues#handling-relationships) when model collections are processed by jobs.

<a name="adding-records"></a>
### Adding Records

Once you have added the `Hypervel\Scout\Searchable` trait to a model, all you need to do is `save` or `create` a model instance and it will automatically be added to your search index. If you have configured Scout to [use queues](#queueing) this operation will be performed in the background by your queue worker:

```php
use App\Models\Order;

$order = new Order;

// ...

$order->save();
```

<a name="adding-records-via-query"></a>
#### Adding Records via Query

If you would like to add a collection of models to your search index via an Eloquent query, you may chain the `searchable` method onto the Eloquent query. The `searchable` method will [chunk the results](/docs/{{version}}/eloquent#chunking-results) of the query and add the records to your search index. Again, if you have configured Scout to use queues, all of the chunks will be imported in the background by your queue workers:

```php
use App\Models\Order;

Order::where('price', '>', 100)->searchable();
```

You may also call the `searchable` method on an Eloquent relationship instance:

```php
$user->orders()->searchable();
```

Or, if you already have a collection of Eloquent models in memory, you may call the `searchable` method on the collection instance to add the model instances to their corresponding index:

```php
$orders->searchable();
```

> [!NOTE]
> The `searchable` method can be considered an "upsert" operation. In other words, if the model record is already in your index, it will be updated. If it does not exist in the search index, it will be added to the index.

<a name="updating-records"></a>
### Updating Records

To update a searchable model, you only need to update the model instance's properties and `save` the model to your database. Scout will automatically persist the changes to your search index:

```php
use App\Models\Order;

$order = Order::find(1);

// Update the order...

$order->save();
```

You may also invoke the `searchable` method on an Eloquent query instance to update a collection of models. If the models do not exist in your search index, they will be created:

```php
Order::where('price', '>', 100)->searchable();
```

If you would like to update the search index records for all of the models in a relationship, you may invoke the `searchable` on the relationship instance:

```php
$user->orders()->searchable();
```

Or, if you already have a collection of Eloquent models in memory, you may call the `searchable` method on the collection instance to update the model instances in their corresponding index:

```php
$orders->searchable();
```

<a name="modifying-records-before-importing"></a>
#### Modifying Records Before Importing

Sometimes you may need to prepare the collection of models before they are made searchable. For instance, you may want to eager load a relationship so that the relationship data can be efficiently added to your search index. To accomplish this, define a `makeSearchableUsing` method on the corresponding model:

```php
use Hypervel\Database\Eloquent\Collection;

/**
 * Modify the collection of models being made searchable.
 */
public function makeSearchableUsing(Collection $models): Collection
{
    return $models->load('author');
}
```

<a name="conditionally-updating-the-search-index"></a>
#### Conditionally Updating the Search Index

By default, Scout will reindex an updated model regardless of which attributes were modified. If you would like to customize this behavior, you may define a `searchIndexShouldBeUpdated` method on your model:

```php
/**
 * Determine if the search index should be updated.
 */
public function searchIndexShouldBeUpdated(): bool
{
    return $this->wasRecentlyCreated || $this->wasChanged(['title', 'body']);
}
```

<a name="removing-records"></a>
### Removing Records

To remove a record from your index you may simply `delete` the model from the database. This may be done even if you are using [soft deleted](/docs/{{version}}/eloquent#soft-deleting) models:

```php
use App\Models\Order;

$order = Order::find(1);

$order->delete();
```

If you do not want to retrieve the model before deleting the record, you may use the `unsearchable` method on an Eloquent query instance:

```php
Order::where('price', '>', 100)->unsearchable();
```

If you would like to remove the search index records for all of the models in a relationship, you may invoke the `unsearchable` on the relationship instance:

```php
$user->orders()->unsearchable();
```

Or, if you already have a collection of Eloquent models in memory, you may call the `unsearchable` method on the collection instance to remove the model instances from their corresponding index:

```php
$orders->unsearchable();
```

To remove all of the model records from their corresponding index, you may invoke the `removeAllFromSearch` method:

```php
Order::removeAllFromSearch();
```

<a name="pausing-indexing"></a>
### Pausing Indexing

Sometimes you may need to perform a batch of Eloquent operations on a model without syncing the model data to your search index. You may do this using the `withoutSyncingToSearch` method. This method accepts a single closure which will be immediately executed. Any model operations that occur within the closure will not be synced to the model's index:

```php
use App\Models\Order;

Order::withoutSyncingToSearch(function () {
    // Perform model actions...
});
```

The sync-disable state is scoped to the current coroutine, so disabling sync in one request or coroutine does not affect other concurrent coroutines.

If you need to disable and re-enable syncing without wrapping operations in a closure, Scout also exposes lower-level helpers:

```php
Order::disableSearchSyncing();

// Perform model actions without triggering search indexing...

Order::enableSearchSyncing();
```

You may also check the current state via `Order::isSearchSyncingEnabled()`.

<a name="conditionally-searchable-model-instances"></a>
### Conditionally Searchable Model Instances

Sometimes you may need to only make a model searchable under certain conditions. For example, imagine you have `App\Models\Post` model that may be in one of two states: "draft" and "published". You may only want to allow "published" posts to be searchable. To accomplish this, you may define a `shouldBeSearchable` method on your model:

```php
/**
 * Determine if the model should be searchable.
 */
public function shouldBeSearchable(): bool
{
    return $this->isPublished();
}
```

The `shouldBeSearchable` method is only applied when manipulating models through the `save` and `create` methods, queries, or relationships. Directly making models or collections searchable using the `searchable` method will override the result of the `shouldBeSearchable` method.

> [!WARNING]
> The `shouldBeSearchable` method is not applicable when using Scout's "database" engine, as all searchable data is always stored in the database. To achieve similar behavior when using the database engine, you should use [where clauses](#where-clauses) instead.

<a name="customizing-scout-jobs"></a>
### Customizing Scout Jobs

When [queue mode](#queueing) is enabled, Scout dispatches `Hypervel\Scout\Jobs\MakeSearchable` and `Hypervel\Scout\Jobs\RemoveFromSearch` jobs to perform the actual indexing. If you need to override either job class — for example, to add custom logging or middleware — you may register your own job classes from your application's `App\Providers\AppServiceProvider`:

```php
use App\Jobs\MyMakeSearchable;
use App\Jobs\MyRemoveFromSearch;
use Hypervel\Scout\Scout;

/**
 * Bootstrap any application services.
 */
public function boot(): void
{
    Scout::makeSearchableUsing(MyMakeSearchable::class);
    Scout::removeFromSearchUsing(MyRemoveFromSearch::class);
}
```

Custom job classes should extend the corresponding default job and override only the methods you need to change. These overrides only affect queue-mode indexing — in the default mode, indexing runs inline via `Coroutine::defer` and does not pass through a job class.

<a name="searching"></a>
## Searching

You may begin searching a model using the `search` method. The search method accepts a single string that will be used to search your models. You should then chain the `get` method onto the search query to retrieve the Eloquent models that match the given search query:

```php
use App\Models\Order;

$orders = Order::search('Star Trek')->get();
```

Since Scout searches return a collection of Eloquent models, you may even return the results directly from a route or controller and they will automatically be converted to JSON:

```php
use App\Models\Order;
use Hypervel\Http\Request;

Route::get('/search', function (Request $request) {
    return Order::search($request->search)->get();
});
```

If you would like to get the raw search results before they are converted to Eloquent models, you may use the `raw` method:

```php
$orders = Order::search('Star Trek')->raw();
```

<a name="custom-indexes"></a>
#### Custom Indexes

When searching using third-party engines, search queries will typically be performed on the index specified by the model's [searchableAs](#configuring-model-indexes) method. However, you may use the `within` method to specify a custom index that should be searched instead:

```php
$orders = Order::search('Star Trek')
    ->within('tv_shows_popularity_desc')
    ->get();
```

<a name="where-clauses"></a>
### Where Clauses

Scout allows you to add "where" clauses to your search queries. For example, basic equality checks are useful for scoping search queries by an owner ID:

```php
use App\Models\Order;

$orders = Order::search('Star Trek')->where('user_id', 1)->get();
```

You may also use the `=`, `!=`, `<`, `>`, `>=`, `<=` comparsion operators to build more advanced queries:

```php
Order::search('Star Trek')
  ->where('status', '=', 'completed')
  ->where('is_refunded', '!=', true)
  ->where('total_price', '>', 100)
  ->where('shipping_cost', '<', 20)
  ->where('discount_percent', '>=', 10)
  ->where('item_count', '<=', 5)
  ->get();
```

In addition, the `whereIn` method may be used to verify that a given column's value is contained within the given array:

```php
$orders = Order::search('Star Trek')->whereIn(
    'status', ['open', 'paid']
)->get();
```

The `whereNotIn` method verifies that the given column's value is not contained in the given array:

```php
$orders = Order::search('Star Trek')->whereNotIn(
    'status', ['closed']
)->get();
```

> [!WARNING]
> If your application is using Meilisearch, you must configure your application's [filterable attributes](#meilisearch-index-settings) before utilizing Scout's "where" clauses.

<a name="customizing-the-eloquent-results-query"></a>
#### Customizing the Eloquent Results Query

After Scout retrieves a list of matching Eloquent models from your application's search engine, Eloquent is used to retrieve all of the matching models by their primary keys. You may customize this query by invoking the `query` method. The `query` method accepts a closure that will receive the Eloquent query builder instance as an argument:

```php
use App\Models\Order;
use Hypervel\Database\Eloquent\Builder;

$orders = Order::search('Star Trek')
    ->query(fn (Builder $query) => $query->with('invoices'))
    ->get();
```

When using a third-party engine, this callback is invoked after the relevant models have already been retrieved from the search engine, so it should not be used for "filtering" results — use [Scout where clauses](#where-clauses) instead. However, when using the database engine, the `query` method's constraints are applied directly to the database query, so you may use it for filtering as well.

<a name="pagination"></a>
### Pagination

In addition to retrieving a collection of models, you may paginate your search results using the `paginate` method. This method will return an `Hypervel\Pagination\LengthAwarePaginator` instance just as if you had [paginated a traditional Eloquent query](/docs/{{version}}/pagination):

```php
use App\Models\Order;

$orders = Order::search('Star Trek')->paginate();
```

You may specify how many models to retrieve per page by passing the amount as the first argument to the `paginate` method:

```php
$orders = Order::search('Star Trek')->paginate(15);
```

When using the database engine, you may also use the `simplePaginate` method. Unlike `paginate`, which retrieves the total number of matching records so it can display page numbers, `simplePaginate` only determines whether there are more results beyond the current page — making it more efficient for large datasets where you only need "previous" and "next" links:

```php
$orders = Order::search('Star Trek')->simplePaginate(15);
```

Once you have retrieved the results, you may display the results and render the page links using [Blade](/docs/{{version}}/blade) just as if you had paginated a traditional Eloquent query:

```html
<div class="container">
    @foreach ($orders as $order)
        {{ $order->price }}
    @endforeach
</div>

{{ $orders->links() }}
```

Of course, if you would like to retrieve the pagination results as JSON, you may return the paginator instance directly from a route or controller:

```php
use App\Models\Order;
use Hypervel\Http\Request;

Route::get('/orders', function (Request $request) {
    return Order::search($request->input('query'))->paginate(15);
});
```

> [!WARNING]
> Since search engines are not aware of your Eloquent model's global scope definitions, you should not utilize global scopes in applications that utilize Scout pagination. Or, you should recreate the global scope's constraints when searching via Scout.

<a name="soft-deleting"></a>
### Soft Deleting

If your indexed models are [soft deleting](/docs/{{version}}/eloquent#soft-deleting) and you need to search your soft deleted models, set the `soft_delete` option of the `config/scout.php` configuration file to `true`:

```php
'soft_delete' => true,
```

When this configuration option is `true`, Scout will not remove soft deleted models from the search index. Instead, it will set a hidden `__soft_deleted` attribute on the indexed record. Then, you may use the `withTrashed` or `onlyTrashed` methods to retrieve the soft deleted records when searching:

```php
use App\Models\Order;

// Include trashed records when retrieving results...
$orders = Order::search('Star Trek')->withTrashed()->get();

// Only include trashed records when retrieving results...
$orders = Order::search('Star Trek')->onlyTrashed()->get();
```

> [!NOTE]
> When a soft deleted model is permanently deleted using `forceDelete`, Scout will remove it from the search index automatically.

<a name="customizing-engine-searches"></a>
### Customizing Engine Searches

If you need to perform advanced customization of the search behavior of an engine you may pass a closure as the second argument to the `search` method. For example, you could use this callback to add geo-location data before the search query is sent to Algolia:

```php
use Algolia\AlgoliaSearch\Api\SearchClient;
use App\Models\Order;

Order::search(
    'Star Trek',
    function (SearchClient $algolia, string $query, array $options) {
        $options['aroundLatLng'] = '36,111';
        $options['aroundRadius'] = 1000000;

        return $algolia->searchSingleIndex(
            'orders',
            array_merge(['query' => $query], $options),
        );
    }
)->get();
```

<a name="custom-engines"></a>
## Custom Engines

<a name="writing-the-engine"></a>
#### Writing the Engine

If one of the built-in Scout search engines doesn't fit your needs, you may write your own custom engine and register it with Scout. Your engine should extend the `Hypervel\Scout\Engines\Engine` abstract class. This abstract class contains eleven methods your custom engine must implement:

```php
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Builder;
use Hypervel\Support\Collection;
use Hypervel\Support\LazyCollection;

abstract public function update(EloquentCollection $models): void;
abstract public function delete(EloquentCollection $models): void;
abstract public function search(Builder $builder): mixed;
abstract public function paginate(Builder $builder, int $perPage, int $page): mixed;
abstract public function mapIds(mixed $results): Collection;
abstract public function map(Builder $builder, mixed $results, Model $model): EloquentCollection;
abstract public function lazyMap(Builder $builder, mixed $results, Model $model): LazyCollection;
abstract public function getTotalCount(mixed $results): int;
abstract public function flush(Model $model): void;
abstract public function createIndex(string $name, array $options = []): mixed;
abstract public function deleteIndex(string $name): mixed;
```

You may find it helpful to review the implementations of these methods on the `Hypervel\Scout\Engines\AlgoliaEngine` class. This class will provide you with a good starting point for learning how to implement each of these methods in your own engine.

<a name="registering-the-engine"></a>
#### Registering the Engine

Once you have written your custom engine, you may register it with Scout using the `extend` method of the Scout engine manager. Scout's engine manager may be resolved from the Hypervel service container. You should call the `extend` method from the `boot` method of your `App\Providers\AppServiceProvider` class or any other service provider used by your application:

```php
use App\ScoutExtensions\MySqlSearchEngine;
use Hypervel\Scout\EngineManager;

/**
 * Bootstrap any application services.
 */
public function boot(): void
{
    resolve(EngineManager::class)->extend('mysql', function () {
        return new MySqlSearchEngine;
    });
}
```

Once your engine has been registered, you may specify it as your default Scout `driver` in your application's `config/scout.php` configuration file:

```php
'driver' => 'mysql',
```
