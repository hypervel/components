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
    | Supported: "meilisearch", "typesense", "database", "collection", "null"
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
    | indexing at coroutine exit (in HTTP requests, typically after the
    | response is emitted). Set 'enabled' to true to use
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
    | Command Concurrency
    |--------------------------------------------------------------------------
    |
    | This option controls the maximum number of concurrent coroutines used
    | when running bulk import/flush operations via Scout commands. Higher
    | values speed up imports but consume more resources. This only affects
    | console commands, not HTTP request indexing.
    |
    */

    'command_concurrency' => env('SCOUT_COMMAND_CONCURRENCY', 50),

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

    /*
    |--------------------------------------------------------------------------
    | Typesense Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your Typesense settings. Typesense is a fast,
    | typo-tolerant search engine optimized for instant search experiences.
    |
    | See: https://typesense.org/docs/
    |
    */

    'typesense' => [
        'client-settings' => [
            'api_key' => env('TYPESENSE_API_KEY', ''),
            'nodes' => [
                [
                    'host' => env('TYPESENSE_HOST', 'localhost'),
                    'port' => env('TYPESENSE_PORT', '8108'),
                    'protocol' => env('TYPESENSE_PROTOCOL', 'http'),
                ],
            ],
            'connection_timeout_seconds' => 2,
        ],
        'max_total_results' => env('TYPESENSE_MAX_TOTAL_RESULTS', 1000),
        'import_action' => 'upsert',
        'model-settings' => [
            // Per-model settings can be defined here:
            // App\Models\User::class => [
            //     'collection-schema' => [
            //         'fields' => [
            //             ['name' => 'id', 'type' => 'string'],
            //             ['name' => 'name', 'type' => 'string'],
            //             ['name' => 'created_at', 'type' => 'int64'],
            //         ],
            //         'default_sorting_field' => 'created_at',
            //     ],
            //     'search-parameters' => [
            //         'query_by' => 'name',
            //     ],
            // ],
        ],
    ],
];
