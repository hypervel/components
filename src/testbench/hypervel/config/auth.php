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
            | Supported stores: 'redis', 'database', 'file', 'swoole', 'stack'.
            | Any other driver ('array', 'null', 'session', 'failover') is
            | rejected.
            |
            | Cross-node behaviour:
            |   - 'redis' / 'database': fully shared — invalidation is global.
            |   - 'file' / 'swoole': node-local, no cross-node invalidation
            |     (single-instance deployments only).
            |   - 'stack' with a node-local upper tier (e.g. [swoole, redis]):
            |     eventually consistent — the shared lower tier clears
            |     globally, but each node's L1 serves its stale entry until
            |     the L1 TTL expires. This is the microcaching trade-off.
            |
            | High-scale: the recommended topology is a 'stack' cache with
            | 'swoole' as L1 (3–5s) and 'redis' as L2 — the microcaching
            | pattern eliminates the majority of Redis round-trips for
            | authed requests at high concurrency. See the auth caching
            | documentation for the full explanation.
            |
            | Caveat: only the outer store is validated. A stack with an
            | unsupported inner tier (e.g. [array, redis]) won't be caught.
            |
            | Cache tags (optional):
            |   Set 'tags' to an array of tag names (e.g. ['auth_users'])
            |   to add those cache tags to every cached user. This is useful
            |   for bulk cache invalidation using Cache::store(...)->tags([...])->flush().
            |   Requires a TaggableStore configured in TagMode::Any
            |   (e.g. a Redis store with tag_mode=any).
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
