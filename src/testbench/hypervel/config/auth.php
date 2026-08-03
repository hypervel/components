<?php

declare(strict_types=1);

return [
    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => Hypervel\Foundation\Auth\User::class,

            /*
            |------------------------------------------------------------------
            | User Lookup Cache (opt-in, Eloquent provider only)
            |------------------------------------------------------------------
            |
            | Caches retrieveById() lookups across requests. Disabled by
            | default. Credential and token lookups are never cached
            | (security).
            |
            | Supported stores: 'redis', 'database', 'file', 'storage',
            | 'swoole', and stacks containing only supported stores. Array,
            | worker-array, null, session, and failover stores are rejected.
            | Stack layers are validated recursively.
            |
            | Cross-node behavior:
            |   - 'redis' / 'database': fully shared — invalidation is global.
            |   - 'storage': shared only when its configured disk is shared.
            |   - 'file' / 'swoole' used as the only store: node-local, with no
            |     cross-node invalidation (single-instance deployments only).
            |   - 'stack' with a node-local upper tier (e.g. [swoole, redis]):
            |     eventually consistent — the shared lower tier clears
            |     globally, but each node's L1 serves its stale entry until
            |     the L1 TTL expires. This is the microcaching trade-off.
            |
            | A short-lived node-local L1 over a shared L2 can reduce shared
            | cache traffic, with bounded L1 staleness as the trade-off. Cache
            | configuration is read during process startup and must not change
            | while a worker is serving requests.
            |
            | Cache tags (optional):
            |   Set 'tags' to an array of tag names (e.g. ['auth_users'])
            |   to add those cache tags to every cached user. This is useful
            |   for bulk cache invalidation using Cache::store(...)->tags([...])->flush().
            |   Requires a store with any-mode tag support (e.g. a Redis
            |   store with tag_mode=any, or a stack over any-mode taggable layers).
            |
            */
            'cache' => [
                'enabled' => env('AUTH_USERS_CACHE_ENABLED', false),
                'store' => env('AUTH_USERS_CACHE_STORE'),
                'ttl' => env('AUTH_USERS_CACHE_TTL', 300),
                'prefix' => env('AUTH_USERS_CACHE_PREFIX', 'auth_users'),
                'tags' => null,
            ],
        ],
    ],
];
