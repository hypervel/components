<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Search Engine
    |--------------------------------------------------------------------------
    |
    | This option controls the default search connection that gets used while
    | using Scout. This connection is used when syncing all models to the
    | search service. You should adjust this based on your needs.
    |
    | Supported: "meilisearch", "collection", "null"
    |
    */

    'driver' => env('SCOUT_DRIVER', 'collection'),

    /*
    |--------------------------------------------------------------------------
    | Index Prefix
    |--------------------------------------------------------------------------
    |
    | Here you may specify a prefix that will be applied to all search index
    | names used by Scout. This prefix may be useful if you have multiple
    | "tenants" or applications sharing the same search infrastructure.
    |
    */

    'prefix' => env('SCOUT_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Queue Configuration
    |--------------------------------------------------------------------------
    |
    | This option allows you to control if the operations that sync your data
    | with your search engines are queued. When enabled, all automatic data
    | syncing will get queued for better performance.
    |
    | By default, Hypervel Scout uses Coroutine::defer() which executes
    | indexing after the response is sent. Set 'enabled' to true to use
    | the queue system instead for durability and retries.
    |
    | The 'after_commit' option ensures that queued indexing jobs are only
    | dispatched after all database transactions have committed, preventing
    | indexing of data that might be rolled back.
    |
    */

    'queue' => [
        'enabled' => env('SCOUT_QUEUE', false),
        'connection' => env('SCOUT_QUEUE_CONNECTION'),
        'queue' => env('SCOUT_QUEUE_NAME'),
        'after_commit' => env('SCOUT_AFTER_COMMIT', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Chunk Sizes
    |--------------------------------------------------------------------------
    |
    | These options allow you to control the maximum chunk size when you are
    | mass importing data into the search engine. This allows you to fine
    | tune each of these chunk sizes based on the power of the servers.
    |
    */

    'chunk' => [
        'searchable' => 500,
        'unsearchable' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Concurrency
    |--------------------------------------------------------------------------
    |
    | This option controls the number of concurrent coroutines used when
    | running batch import operations. Higher values may speed up imports
    | but consume more resources.
    |
    */

    'concurrency' => env('SCOUT_CONCURRENCY', 100),

    /*
    |--------------------------------------------------------------------------
    | Soft Deletes
    |--------------------------------------------------------------------------
    |
    | This option allows you to control whether to keep soft deleted records
    | in the search indexes. Maintaining soft deleted records can be useful
    | if your application still needs to search for the records later.
    |
    */

    'soft_delete' => env('SCOUT_SOFT_DELETE', false),

    /*
    |--------------------------------------------------------------------------
    | Meilisearch Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your Meilisearch settings. Meilisearch is an open
    | source search engine with minimal configuration. Below, you can state
    | the host and key information for your own Meilisearch installation.
    |
    | See: https://www.meilisearch.com/docs/learn/configuration/instance_options
    |
    */

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'index-settings' => [
            // Per-index settings can be defined here:
            // 'users' => [
            //     'filterableAttributes' => ['id', 'name', 'email'],
            //     'sortableAttributes' => ['created_at'],
            // ],
        ],
    ],
];
