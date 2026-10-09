Broadcasting for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/broadcasting)

Documentation: https://hypervel.org/docs/broadcasting

## Differences From Laravel

The outgoing channel formatter and incoming channel authorizer are also worker-wide and should be configured during worker boot.

Custom drivers may opt into Hypervel's connection pooling through the broadcast manager.

The default Pusher/Reverb client rejects Guzzle's `max_host_connections` and `max_total_connections` options because enforcing them across coroutines requires an unsafe shared multi-handler. Bound broadcast concurrency or use rate limiting instead.

Mercure uses a standalone HTTP hub. FrankenPHP's in-process `mercure_publish()` integration is not available under Swoole.

The broadcast service provider does not implement Laravel's `DeferrableProvider` marker because Hypervel has no deferred service provider mechanism.

Ported from: https://github.com/laravel/framework/tree/13.x/src/Illuminate/Broadcasting
