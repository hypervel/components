Pool for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/pool)

Ported from: https://github.com/hyperf/hyperf/tree/master/src/pool

## Connection Ownership

Pools create connections lazily as callers need them, up to
`max_connections`. The `min_connections` option controls how far trimming
excess idle connections may reduce the total managed connection count. It is
not an idle-count invariant or a guaranteed total minimum, and it does not
prewarm or automatically replenish the pool. The caller that first needs each
new connection pays its connection-establishment cost, and the pool may have
zero idle connections under load. Lifecycle-expired or unhealthy connections
and explicit discards can reduce the managed count below `min_connections`;
failed connection creation can leave it below that value. None is
automatically replenished.

Every connection returned by `Pool::get()` is borrowed by the caller and must
be returned with `release()` or removed with `discard()`. `Pool::close()`
destroys idle connections immediately, while connections that were already
borrowed are destroyed when their owners release them.
