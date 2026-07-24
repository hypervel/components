# Stack Cache Tags and Tag Architecture Refactor

## Goal

Add cache tag support to the `stack` driver (any-mode only), rebuild the tagged-cache and tag-set class hierarchies so mode-specific behavior lives in mode-specific types, fix four live bugs found during design review, make the JWT blacklist work with both tag modes, and give the stack driver lock support by delegation.

Churn and backwards compatibility are not constraints. The final codebase must read as if it was designed this way from the start: no stale code, no stale comments, no inherit-then-neutralize hierarchies, no capability probes that lie.

This plan is the output of a five-round design review between Claude and Codex. Every factual claim below was verified against source. An implementer starting cold needs no context beyond this document and the repository.

## Background

The `stack` driver is Hypervel's multi-tier cache (microcaching): a fast node-local L1 (e.g. `swoole`, 3–5s TTL) over a shared L2 (e.g. `redis`). Reads hit L1 first and backfill on L2 hits. Writes go through all layers. `foundation/config/auth.php` already documents swoole+redis stacks as the recommended high-scale auth cache topology.

Hypervel's Redis cache supports two tag modes (`TagMode` enum, configured per store via `tag_mode`):

- **`all`** (default, Laravel classic): the tag set namespaces item keys. Reads/writes/deletes go through `tags([...])`. Tag ZSETs track entries with expiry-timestamp scores; flush deletes tracked entries; `FlushStale`/prune remove entries whose scores have passed.
- **`any`** (Redis 8.0+/Valkey 9.0+): items live under plain keys; tags are invalidation indexes only. Writes go through `tags([...])`; reads/deletes use plain keys; flushing any one tag deletes the actual keys. Tagged read-style calls throw `BadMethodCallException`.

Any mode is what makes stack tags possible: reads never consult tags, so the stack's plain-key read/backfill path is untouched, and tag flush deletes real L2 keys while L1 entries self-heal within their short TTL. All mode is incompatible: reads are tag-scoped and key-namespaced, which does not fit the stack's plain-key model. The auth package already encodes this rule (`EloquentUserProvider::ensureTaggableAnyModeStore()` requires `TagMode::Any`).

Bounded staleness after a tag flush (L1 serves stale for up to its TTL) is the standard microcache tradeoff (nginx `proxy_cache_use_stale`, Varnish grace, near-caches). `foundation/config/auth.php` already documents it for auth stacks.

## Verified Current State

All file references are relative to the components repo root. Line numbers are as of the commit this plan was written against — verify with grep before editing, do not trust them blindly after other changes land.

### Key formats (`src/cache/src/Redis/Support/TagKeyBuilder.php`, `StoreContext.php`)

- Cache key: `{prefix}{key}`
- Reverse index (any mode; tracks which tags a key belongs to): `{prefix}{key}:_any:tags` (a SET)
- Tag hash (any mode; tracks which keys a tag contains): `{prefix}_any:tag:{tag}:entries` (a HASH, fields expire via HSETEX)
- Tag registry (any mode): `{prefix}_any:tag:registry` (a ZSET, scores = expiry, updated with `ZADD GT`)
- All-mode tag entry set: `{prefix}_all:tag:{tag}:entries` (a ZSET, member = namespaced item key, score = expiry timestamp, `-1` for forever)
- None of these use Redis cluster hash tags (`{...}`), so they hash to different slots in cluster mode.

### Op invariants

- `AnyTag\Put` (`src/cache/src/Redis/Operations/AnyTag/Put.php`) writes value SETEX + reverse index (SET with TTL) + tag hash fields (HSETEX with TTL) + registry (`ZADD GT`) together — Lua single round trip on standard Redis, sequential commands on cluster (with a same-slot `multi()` batch for the reverse index and a single multi-member `ZADD` for the registry). It also removes the key from tags it no longer belongs to (reverse-index diff), so tagged writes maintain tag membership.
- `AllTag\Put` pipelines `ZADD {tagZset} {expiryTimestamp} {namespacedKey}` per tag + `SETEX`.
- `AllTag\FlushStale` uses `ZREMRANGEBYSCORE 0..now`; scores are the authoritative cleanup boundary; score `-1` (forever) is exempt.
- Plain `Operations/Put.php` is a bare `SETEX`; plain `Operations/Forget.php` is a bare `DEL`; `RedisStore::touch()` (`RedisStore.php:217`) is an inline raw `EXPIRE` with no operation class. All three are tag-metadata-blind.
- Operation classes are lazily instantiated singletons via private nullable properties + `get*Operation()` accessors on `RedisStore`, and grouped factories `AnyTagOperations` / `AllTagOperations` (constructor takes `StoreContext` + `Serialization`; ops that don't serialize take only `StoreContext`).
- Cluster branches never use pipeline (phpredis `RedisCluster` does not support it — documented in `AllTag\FlushStale`); the only batching is `multi()` for same-slot keys and multi-member single commands (`ZADD` with several members).

### Class hierarchy (current)

- `TagSet` (`src/cache/src/TagSet.php`): store + names + the classic random-identifier machinery — `resetTag()` stores a `uniqid()` under `tag:{name}:key` via `store->forever()`, `tagId()` reads it back (`store->get() ?: resetTag()`), `getNamespace()` implodes tag ids, `flush()`/`flushTag()` forget the tag keys. Only the generic path uses this machinery.
- `Redis\AllTagSet extends TagSet`: overrides `tagId()`/`tagKey()` to deterministic `_all:tag:{name}:entries` identifiers, `resetTag()` forgets the entry ZSET. Inherits `getNamespace()`/`tagIds()` walkers.
- `Redis\AnyTagSet extends TagSet`: neutralizes everything — `tagId()` returns the name, `getNamespace()` returns `''`, `reset()` delegates to `flush()` (which deletes actual keys via `anyTagOps()->flush()`). Adds `tagHashKey()`, `entries()`.
- `TaggedCache extends Repository` (`src/cache/src/TaggedCache.php`): implicitly all-mode — `itemKey()` → `taggedItemKey()` (xxh128 of `tags->getNamespace()` + `:` + key), `flush()` = `tags->reset()` + `CacheFlushing`/`CacheFlushed` events, `putMany()` loop, `increment()`/`decrement()` via `store` + `itemKey()`, tag-aware `event()` wrapper (sets tags on events with `setTags`), `getTags()`.
- `Redis\AllTaggedCache extends TaggedCache`: op-class overrides for add/put/putMany/increment/decrement/forever/flush/flushStale/remember/rememberForever; caches the tagged item key prefix (`taggedItemKeyPrefix()`, safe because all-mode identifiers are deterministic).
- `Redis\AnyTaggedCache extends TaggedCache`: throws `BadMethodCallException` on `get`/`getRaw`/`many`/`manyRaw`/`has`/`pull`/`forget`; passthrough `itemKey()`; op-class write overrides; `flush()` via `tags->flush()`; `items()`; `remember`/`rememberForever` via single-connection ops.
- `TaggableStore` (`src/cache/src/TaggableStore.php`): abstract, `tags()` returns `new TaggedCache($this, new TagSet(...))`, `getTagMode()` returns `TagMode::All`. Subclasses: `RedisStore` (mode-aware `tags()`), `FailoverStore`, `NullStore`, `AbstractArrayStore` (→ `ArrayStore`, `WorkerArrayStore`).
- `StackStore` (`src/cache/src/StackStore.php`): `implements Store` only. Wraps values in records `['value' => ..., 'ttl' => ...]` normalized to `['value' => ..., 'expiration' => ts]` by `putToStore()`; `getOrRestoreRecord()` reads down the layer chain and backfills upward; `callStores()`/`callStoresStacked()` implement write-through with rollback. No tags, no locks, no `RawReadable` (not needed — records carry sentinels verbatim and `Repository::getRaw()` falls back to `get()` for non-RawReadable stores).
- `StackStoreProxy` (`src/cache/src/StackStoreProxy.php`): per-layer wrapper created by `CacheManager::createStackDriver()`; clamps TTLs in `put()`/`forever()`/`touch()` when a layer `ttl` override is configured; implements `Store` only; wrapped store and ttl are `protected` with no accessors.

### Repository facts (`src/cache/src/Repository.php`)

- `supportsTags()` (line 827) is `method_exists($this->store, 'tags')`.
- `tags()` (line 803) carries `/* @phpstan-ignore-next-line */` because `tags()` is not on the `Store` contract.
- `clear()` (line 748) calls `$this->store->flush()` directly. **No tagged cache overrides `clear()`** — `Cache::tags([...])->clear()` flushes the entire underlying store in both modes. Live bug.
- `touch()` (line 669) calls `$this->get($key)` first; null TTL routes to `forever($key, $value)`, never `store->touch()`. Consequence: any-mode tagged `touch()` already throws, but via `get()`'s misleading message; all-mode tagged `touch(int)` reaches `store->touch(namespacedKey)` = raw `EXPIRE` that desyncs the ZSET score. Live bug (all mode) + accidental contract (any mode).
- `put()` with `seconds <= 0` calls `$this->forget($key)` (line ~297). `AnyTaggedCache::put()` with `seconds <= 0` returns `false` instead ("Can't forget via tags") — a divergence from repository semantics, fixable once plain forget cleans metadata.
- `remember()`/`rememberForever()` read via `getRaw()`, wrap in `withPinnedConnection` when the store supports it, and handle `NullSentinel` (nullable variants wrap the callback with `?? NullSentinel::VALUE`). All read-style methods route through the core set (`missing()`→`has()`, typed getters→`get()`, `sear`→`rememberForever`, `flexible`→`manyRaw`, `getMultiple`→`many`, `delete`/`deleteMultiple`→`forget`, `offsetGet/Exists/Unset/Set`→`get/has/forget/put`) — so any-mode throwing overrides propagate to the whole surface automatically.
- `supportsFlushingLocks()` (line ~835) is `$this->store instanceof CanFlushLocks`; `flushLocks()` (line 770) gates on it and carries a phpstan-ignore.
- `itemKey()` (line 987, protected) returns the key unchanged — the correct passthrough for any-mode tagged caches.

### Consumers (full trace results)

- `JWT` (`src/jwt/src/JwtServiceProvider.php:135`): gates blacklist storage on `$repository->supportsTags()` only. `src/jwt/src/Storage/TaggedCache.php` does tag-scoped `get()`/`forget()` — all-mode-only operations. **Live bug:** an any-mode Redis default store passes the gate, then every blacklist read throws at runtime.
- `Auth` (`src/auth/src/EloquentUserProvider.php`): `SUPPORTED_AUTH_CACHE_STORES` already contains `StackStore::class`; `ensureTaggableAnyModeStore()` (line 439) checks `instanceof TaggableStore` then `getTagMode() !== TagMode::Any`. `buildTaggedCache()` (line ~497) has a phpstan-ignore because `tags()` is not on the `Repository` contract — that contract follows Laravel and stays unchanged; the ignore is intentional and remains.
- `cache:doctor` / `cache:redis:benchmark`: guard `instanceof RedisStore` before every `getTagMode()` call, so a throwing `StackStore::getTagMode()` never reaches them.
- `ClearCommand.php:53` phpstan-ignore (flush via `__call`) — unaffected.
- `Cache` facade docblock: `@method static \Hypervel\Cache\TaggedCache tags(mixed $names)` — stays valid with `TaggedCache` abstract.
- `MemoizedStore` implements plain `Store` — correctly reports no tag support under the new probe.
- Nothing outside `TaggableStore::tags()` constructs the cache package's `TaggedCache` or `TagSet` (the `new TaggedCache` in JWT is JWT's own `Storage\TaggedCache`).
- `CanFlushLocks` implementers: `RedisStore`, `AbstractArrayStore`, `DatabaseStore`, `FileStore`.
- `LockProvider` contract: `lock(string $name, int $seconds = 0, ?string $owner = null): Lock`, `restoreLock(string $name, string $owner): Lock`.
- `FailoverStore` implements `LockProvider` — precedent for a composite store completing the lock contract.

### Bugs this plan fixes

1. **Tagged `clear()`** flushes the whole store (`Repository::clear()`, no tagged override).
2. **All-mode tagged `touch()`** raw-EXPIREs the namespaced key without updating tag ZSET scores; `FlushStale`/prune then removes the entry while the key lives, so a later tag flush misses it.
3. **Any-mode plain `touch()`** extends the key but not reverse index / tag hash fields / registry; tag flush misses the still-live key.
4. **Any-mode plain `forget()`** leaves tag membership behind; a later tag flush deletes a new, unrelated value written at the reused key; forever-tagged metadata orphans live indefinitely.
5. **JWT blacklist gate is mode-blind** (see consumers above).
6. **`AnyTaggedCache::put()` with `seconds <= 0`** returns `false` instead of deleting the key (diverges from `Repository::put()` semantics).

## Design Decisions

Each decision below is final. Rejected alternatives are recorded so the review doesn't re-litigate them.

### D1. Stack tags are any-mode only, write/index/flush only

Tagged reads/lookups/deletes throw exactly as any-mode Redis does today. Reads use plain keys through the stack. Rejected: all-mode stack tags — all-mode reads are tag-scoped and namespaced, incompatible with plain-key read/backfill; the generic `TagSet` version keys would themselves get cached into L1 and reconstruct stale namespaces.

### D2. Composition rule (structural validation, computed once)

A stack supports tags iff: at least one layer's underlying store is a `TaggableStore`, and **every layer from the first taggable layer to the bottom** is a `TaggableStore` whose `supportsTags()` is true and whose `getTagMode()` is `TagMode::Any`. Non-taggable layers are allowed only above the first taggable layer.

Why: a non-taggable (or all-mode) layer at or below the taggable region breaks invalidation. `[swoole, redis-any, database]` — tag flush clears Redis; the next read misses swoole and Redis, hits the database layer, and backfills the flushed value everywhere. That is permanent resurrection, not bounded staleness, so it is rejected structurally. Note this also correctly rejects all-mode-taggable upper layers (e.g. `[array, redis-any]`: `ArrayStore` is taggable all-mode, becomes "first taggable layer", fails the any-mode requirement).

Rejected: enforcing finite TTL overrides on the non-taggable microcache layers (`[swoole no-ttl, redis-any]`). Missing TTL bounds staleness at the item TTL — a tuning footgun, not corruption. Documented prominently instead.

Rejected: skipping tagged writes to lower non-taggable layers instead of validating. Asymmetric layer contents and murky rollback semantics; a config error at `tags()` time is strictly better.

### D3. Tagged-cache hierarchy: abstract base + mode siblings

`TaggedCache` becomes the abstract mode-neutral base; `NamespacedTaggedCache` (all-mode key namespacing, generic path) and `AnyModeTaggedCache` (throw-on-read contract) are siblings under it. `AllTaggedCache extends NamespacedTaggedCache`; `AnyTaggedCache` and the new `StackTaggedCache` extend `AnyModeTaggedCache`.

Why: today `AnyTaggedCache` inherits the all-mode implementation and disables half of it with throwing overrides. Adding `StackTaggedCache` would duplicate the throw-on-read dance — the any-mode contract existing twice as convention. After the split, the contract is a type. `Repository::tags(): TaggedCache` stays meaningful; `assertInstanceOf(TaggedCache::class)` tests stay valid; nothing else constructs the base (verified).

### D4. TagSet hierarchy: slim base + namespaced branch

`TagSet` slims to store + names + `getNames()` + abstract `reset()`/`flush()`. `NamespacedTagSet extends TagSet` adds the namespace surface (`getNamespace()`, `tagIds()`, abstract `tagId()`/`tagKey()`). `VersionedTagSet extends NamespacedTagSet` holds the current random-identifier machinery and serves the generic path. `AllTagSet extends NamespacedTagSet` (deterministic ids). `AnyTagSet` and the new `StackTagSet` extend the slim `TagSet` — any-mode code structurally cannot reach namespace machinery. `AnyTagSet` drops its neutralization overrides (`tagId()`, `tagIds()`, `tagKey()`, `getNamespace()`, `resetTag()`) — they existed only to fight the old base.

### D5. Capability probes are first-class and never lie

- `TaggableStore::supportsTags(): bool` (base returns `true`); `StackStore` overrides with composition validity.
- `Repository::supportsTags()` becomes `$store instanceof TaggableStore && $store->supportsTags()` — removing the `method_exists` probe and the phpstan-ignore at `Repository::tags()` (inline instanceof narrows the store).
- `StackStore::getTagMode()` returns `TagMode::Any` when the composition is valid and **throws** `NotSupportedException` when it is not. `getTagMode()` is a statement about a valid tag-capable store, never a probe — probes use `supportsTags()`. Verified safe: all `getTagMode()` call sites are either behind `instanceof RedisStore` guards (doctor/benchmark) or behind `supportsTags()` in the new gate orderings.
- Same pattern for locks: `CanFlushLocks` gains `supportsFlushingLocks(): bool`; `Repository::supportsFlushingLocks()` becomes `instanceof && probe`. Existing implementers (`RedisStore`, `AbstractArrayStore`, `DatabaseStore`, `FileStore`) return `true`.

Why mode is a probe at store level but a type at cache level: `RedisStore` is dual-mode by config (`tag_mode`), so store-level mode cannot be a static type — per-mode marker interfaces were rejected because a config-dual store would implement both and gates would still need runtime probes. Tagged-cache objects are constructed after mode resolution, so there mode *is* static and is encoded as a type (D3).

### D6. Touch becomes metadata-aware where drift is real; plain put/forever stays blind by contract

- All-mode tagged `touch(int)` → new `AllTag\Touch` op (EXPIRE + plain `ZADD` new score per tag ZSET — not `GT`, because touch may shorten TTL and the score must track the real expiry). `touch(null)` already routes through tagged `forever()` (metadata synced, score `-1`); the override only intercepts the integer branch.
- Any-mode tagged `touch()` → explicit throw in `AnyModeTaggedCache` with an accurate message (the current throw is an accident of `Repository::touch()` calling `get()` first).
- Any-mode plain `touch()` → new mode-aware path: `RedisStore::touch()` routes to `AnyTag\Touch` in any mode (EXPIRE key + if reverse index exists: EXPIRE reverse index, `HEXPIRE` each tag hash field, `ZADD GT` registry) and to a new plain `Operations/Touch.php` op in all mode (bare EXPIRE — plain keys are never in all-mode ZSETs, verified; the op class exists for consistency: every other store method delegates to an op class).
- Any-mode plain `forget()` → new `AnyTag\Forget` op (read reverse index; HDEL the key's field from each tag hash; DEL reverse index; DEL key). **No registry ops** — removing one key doesn't empty a tag; registry hygiene belongs to the prune command. All-mode plain forget stays a bare DEL (plain keys carry no all-mode metadata).
- Plain `put()`/`forever()` on tagged keys stays metadata-blind. Making the hottest cache operation do a reverse-index check to cover value-replacement-on-tagged-keys is the wrong trade. Documented contract: tag membership persists until the key is deleted or re-tagged; tagged writes sync metadata; plain deletes remove membership; TTL changes to tagged items go through `tags()`. A `docs/todo.md` entry records the possible future opt-in.

Cluster branches follow the established patterns: no pipeline; `multi()` only for same-slot batches; multi-member single commands where possible; sequential otherwise. Standard-mode branches are single Lua round trips (dynamic tag-hash key construction inside Lua is established practice — `StoreContext::tagHashSuffix()` exists for exactly this).

### D7. Stack tagged-write mechanics live in StackStore

`StackTaggedCache` stays thin (like `AnyTaggedCache` delegating to `anyTagOps()`): record building, layer iteration, TTL clamping, and rollback stay in `StackStore` (`putRecordTagged()`, `incrementTagged()`), which is the single owner of record semantics. Tagged writes route through each taggable layer's own `tags($names)` path (so tag indexes are recorded) with the layer's proxy TTL clamp replicated exactly; non-taggable upper layers get plain proxied writes; rollback mirrors `callStores()` (plain `forget`, which now cleans any-mode metadata).

### D8. Stack locks: bottom-layer delegation

`StackStore` implements `LockProvider` + `CanFlushLocks`. `lock()`/`restoreLock()` delegate to the bottom layer's underlying store when it is a `LockProvider`, else throw `NotSupportedException` naming the layer. Locks never touch upper tiers (a microcached lock is broken mutual exclusion; delegation makes the lock exactly as correct as using the bottom store directly). `supportsFlushingLocks()`/`flushLocks()`/`hasSeparateLockStore()` delegate the same way with the honest probe. Motivation: stack-as-default-store currently breaks `Cache::lock()`, `withoutOverlapping()`, `funnel()`, and lock-backed `flexible()`. Precedent: `FailoverStore` (its attempt-all semantics fit failover; bottom-delegation fits stacking).

### D9. JWT blacklist: dual-mode storage

`JWT\Storage\TaggedCache` becomes mode-aware via `getTagMode()->supportsDirectGet()`: all mode keeps current tag-scoped behavior; any mode prefixes logical keys with a private constant (`jwt_blacklist:` — replaces the collision isolation the all-mode namespace provided), writes through `tags()`, reads/deletes plain (plain forget now cleans metadata), flushes via tag. The provider gate becomes just `supportsTags()` (now composition-aware). No `instanceof RedisStore` anywhere — the probe surface is `TaggableStore`. Same pattern as auth user caching.

### D10. Non-goals

- No metadata-aware plain `put()`/`forever()` (D6; todo.md entry).
- No TTL-override enforcement on microcache layers (D2; docs).
- No registry ops in any-mode forget (prune owns registry hygiene).
- No `Repository::tagMode()` convenience method — `getStore()->getTagMode()` behind the `TaggableStore` instanceof is the established consumer pattern (auth).
- No changes to the `Repository`/`CacheContract` contracts — they follow Laravel; the auth phpstan-ignore at the `tags()` call stays, with its existing justification comment.
- No `RawReadable` on `StackStore` — verified unnecessary (records carry sentinels; `getRaw()` falls back to `get()`).
- No changes to `flexible()` through tagged caches — it throws via `manyRaw()` in any mode, which is existing parity.

## Target Hierarchies

```text
TagSet (abstract: store, names, getNames, abstract reset/flush)
├── NamespacedTagSet (abstract: getNamespace, tagIds; abstract tagId, tagKey)
│   ├── VersionedTagSet (generic path: random ids stored in cache, rotated on reset)
│   └── Redis\AllTagSet (deterministic "_all:tag:{name}:entries" ids)
├── Redis\AnyTagSet (tagHashKey, entries; flush deletes real keys)
└── StackTagSet (delegates flush to taggable layers)

TaggedCache (abstract: tags property, getTags, event wrapper, putMany,
│            increment/decrement via itemKey, flush = tags->reset + events,
│            clear = $this->flush)
├── NamespacedTaggedCache (itemKey → taggedItemKey namespace hashing; generic path)
│   └── Redis\AllTaggedCache (op-class overrides, cached key prefix, touch override)
└── AnyModeTaggedCache (abstract: throwing get/getRaw/many/manyRaw/has/pull/forget/touch)
    ├── Redis\AnyTaggedCache (op-class write overrides, items(), remember ops)
    └── StackTaggedCache (delegates record writes to StackStore)

TaggableStore (abstract: tags → NamespacedTaggedCache+VersionedTagSet,
│              getTagMode → All, supportsTags → true)
├── RedisStore (mode-aware tags(), mode-aware touch()/forget() routing)
├── FailoverStore, NullStore, AbstractArrayStore (unchanged behavior)
└── StackStore (composition-validated tags(), LockProvider, CanFlushLocks)
```

## Implementation Phases

Work through phases in order. Each phase ends with its test files green (`./vendor/bin/phpunit --no-progress <file>`), per the repo rule: run tests per file, immediately. Run all commands from the components repo root.

---

### Phase 1 — TagSet hierarchy

**1.1 Slim the base `TagSet`** (`src/cache/src/TagSet.php`):

```php
<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Contracts\Cache\Store;

abstract class TagSet
{
    /**
     * The cache store implementation.
     */
    protected Store $store;

    /**
     * The tag names.
     */
    protected array $names = [];

    /**
     * Create a new TagSet instance.
     */
    public function __construct(Store $store, array $names = [])
    {
        $this->store = $store;
        $this->names = $names;
    }

    /**
     * Reset all tags in the set.
     */
    abstract public function reset(): void;

    /**
     * Flush all the tags in the set.
     */
    abstract public function flush(): void;

    /**
     * Get all of the tag names in the set.
     */
    public function getNames(): array
    {
        return $this->names;
    }
}
```

**1.2 New `NamespacedTagSet`** (`src/cache/src/NamespacedTagSet.php`):

```php
<?php

declare(strict_types=1);

namespace Hypervel\Cache;

/**
 * Base for tag sets whose tags namespace the item keyspace (all-mode
 * semantics): items are stored under keys derived from the tag set and
 * must be read back through the same tags.
 */
abstract class NamespacedTagSet extends TagSet
{
    /**
     * Get a unique namespace that changes when any of the tags are flushed.
     */
    public function getNamespace(): string
    {
        return implode('|', $this->tagIds());
    }

    /**
     * Get an array of tag identifiers for all of the tags in the set.
     *
     * @return array<string>
     */
    public function tagIds(): array
    {
        return array_map([$this, 'tagId'], $this->names);
    }

    /**
     * Get the unique tag identifier for a given tag.
     */
    abstract public function tagId(string $name): string;

    /**
     * Get the tag identifier key for a given tag.
     */
    abstract public function tagKey(string $name): string;
}
```

**1.3 New `VersionedTagSet`** (`src/cache/src/VersionedTagSet.php`) — receives the machinery removed from the old base, unchanged in behavior:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Cache;

/**
 * Generic namespaced tag set for stores without a native tag index.
 *
 * Each tag's identifier is a random value stored in the cache itself;
 * resetting a tag rotates the identifier, which changes the namespace and
 * orphans previously tagged entries to expire naturally.
 */
class VersionedTagSet extends NamespacedTagSet
{
    /**
     * Reset all tags in the set.
     */
    public function reset(): void
    {
        array_walk($this->names, [$this, 'resetTag']);
    }

    /**
     * Reset the tag and return the new tag identifier.
     */
    public function resetTag(string $name): string
    {
        $this->store->forever($this->tagKey($name), $id = str_replace('.', '', uniqid('', true)));

        return $id;
    }

    /**
     * Flush all the tags in the set.
     */
    public function flush(): void
    {
        array_walk($this->names, [$this, 'flushTag']);
    }

    /**
     * Flush the tag from the cache.
     */
    public function flushTag(string $name): string
    {
        $this->store->forget($key = $this->tagKey($name));

        return $key;
    }

    /**
     * Get the unique tag identifier for a given tag.
     */
    public function tagId(string $name): string
    {
        return $this->store->get($this->tagKey($name)) ?: $this->resetTag($name);
    }

    /**
     * Get the tag identifier key for a given tag.
     */
    public function tagKey(string $name): string
    {
        return 'tag:' . $name . ':key';
    }
}
```

**1.4 `Redis\AllTagSet`**: change `extends TagSet` → `extends NamespacedTagSet` (import `Hypervel\Cache\NamespacedTagSet`). It must now define `reset()` and `flush()` concretely (previously inherited walkers):

```php
    /**
     * Reset all tags in the set.
     */
    public function reset(): void
    {
        array_walk($this->names, [$this, 'resetTag']);
    }

    /**
     * Flush all the tags in the set.
     */
    public function flush(): void
    {
        array_walk($this->names, [$this, 'flushTag']);
    }
```

Place them in the source order matching `VersionedTagSet` (reset, resetTag, flush, flushTag, then the id methods). Its existing `tagId()`/`tagKey()`/`resetTag()`/`flushTag()`/`addEntry()`/`entries()` are unchanged.

**1.5 `Redis\AnyTagSet`**: stays `extends TagSet`. Delete the neutralization overrides — `tagId()`, `tagIds()`, `tagKey()`, `getNamespace()`, `resetTag()` — and their docblocks. Keep: constructor, `tagHashKey()`, `entries()`, `reset()` (add `: void` — it already delegates to `flush()`), `flush()`, `flushTag()`. Update the class docblock: remove the "Key differences from AllTagSet" comparisons that referenced the deleted methods; state what it is (any-mode tag set: names are the identifiers, flush deletes the actual cache keys, hashes track membership with HSETEX field expiry).

**1.6 `TaggableStore::tags()`**: construct the generic pair explicitly:

```php
    /**
     * Begin executing a new tags operation.
     */
    public function tags(mixed $names): TaggedCache
    {
        return new NamespacedTaggedCache($this, new VersionedTagSet($this, is_array($names) ? $names : func_get_args()));
    }
```

(Applied in Phase 2 together with the cache split — the two phases must land together for the suite to be green; treat Phases 1+2 as one commit-sized unit, testing after both.)

**Consumers to update in this phase**: grep `tests/` for `new TagSet(`, `TagSet::class`, `getNamespace`, `tagId`, `resetTag` usages against `AnyTagSet` and fix accordingly (`tests/Cache/Redis/AnyTagSetTest.php` will lose the tests for deleted methods — deleting a test here is correct only for methods that no longer exist; port any behavioral assertions to the surviving methods). `tests/Cache/CacheTaggedCacheTest.php` references the generic set — update instantiations to `VersionedTagSet`.

---

### Phase 2 — TaggedCache hierarchy + `clear()` fix

**2.1 `TaggedCache`** (`src/cache/src/TaggedCache.php`) becomes abstract and mode-neutral. Full target content of the class body (imports unchanged plus none removed):

```php
abstract class TaggedCache extends Repository
{
    /**
     * The tag set instance.
     */
    protected TagSet $tags;

    /**
     * Create a new tagged cache instance.
     */
    public function __construct(Store $store, TagSet $tags)
    {
        parent::__construct($store);

        $this->tags = $tags;
    }

    /**
     * Store multiple items in the cache for a given number of seconds.
     */
    public function putMany(array $values, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        // unchanged body
    }

    /**
     * Increment the value of an item in the cache.
     */
    public function increment(UnitEnum|string $key, int $value = 1): bool|int
    {
        // unchanged body (store->increment via itemKey)
    }

    /**
     * Decrement the value of an item in the cache.
     */
    public function decrement(UnitEnum|string $key, int $value = 1): bool|int
    {
        // unchanged body
    }

    /**
     * Remove all items from the cache.
     */
    public function flush(): bool
    {
        // unchanged body (tags->reset() + CacheFlushing/CacheFlushed events)
    }

    /**
     * Remove all items from the cache.
     *
     * A tagged cache's PSR clear() scope is the tag set, not the whole store.
     */
    public function clear(): bool
    {
        return $this->flush();
    }

    /**
     * Get the tag set instance.
     */
    public function getTags(): TagSet
    {
        return $this->tags;
    }

    /**
     * Fire an event for this cache instance.
     */
    protected function event(string $eventClass, Closure $event): void
    {
        // unchanged body (setTags wrapper)
    }
}
```

Removed from `TaggedCache`: `taggedItemKey()` and the `itemKey()` override (they move to `NamespacedTaggedCache`). `Repository::itemKey()` passthrough becomes the inherited default — correct for any-mode.

**2.2 New `NamespacedTaggedCache`** (`src/cache/src/NamespacedTaggedCache.php`):

```php
<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Contracts\Cache\Store;

/**
 * Tagged cache for namespaced (all-mode) tag semantics.
 *
 * Item keys are namespaced by the tag set: values must be read, written,
 * and deleted through the same ordered tag set used to store them.
 */
class NamespacedTaggedCache extends TaggedCache
{
    /**
     * The tag set instance.
     *
     * @var NamespacedTagSet
     */
    protected TagSet $tags;

    /**
     * Create a new tagged cache instance.
     */
    public function __construct(Store $store, NamespacedTagSet $tags)
    {
        parent::__construct($store, $tags);
    }

    /**
     * Get a fully qualified key for a tagged item.
     */
    public function taggedItemKey(string $key): string
    {
        return hash('xxh128', $this->tags->getNamespace()) . ':' . $key;
    }

    /**
     * Format the key for a cache item.
     */
    protected function itemKey(string $key): string
    {
        return $this->taggedItemKey($key);
    }

    /**
     * Get the tag set instance (covariant return type).
     */
    public function getTags(): NamespacedTagSet
    {
        return $this->tags;
    }
}
```

(Import `Hypervel\Contracts\Cache\Store`.)

**2.3 New `AnyModeTaggedCache`** (`src/cache/src/AnyModeTaggedCache.php`):

```php
<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use BadMethodCallException;
use DateInterval;
use DateTimeInterface;
use UnitEnum;

/**
 * Tagged cache for any-mode tag semantics.
 *
 * Tags are invalidation indexes only: items live under their plain cache
 * keys, tags are recorded on writes, and flushing any one tag removes every
 * item written with it. Reads, existence checks, per-key deletes, and TTL
 * adjustments are not tag operations in this mode — they throw here and
 * must be performed directly on the repository with the full key.
 */
abstract class AnyModeTaggedCache extends TaggedCache
{
    /**
     * Retrieve an item from the cache by key.
     *
     * @throws BadMethodCallException always — tags are for writing and flushing only
     */
    public function get(array|UnitEnum|string $key, mixed $default = null): mixed
    {
        throw new BadMethodCallException(
            'Cannot get items via tags in any mode. Tags are for writing and flushing only. '
            . 'Use Cache::get() directly with the full key.'
        );
    }

    /**
     * @throws BadMethodCallException always — tags are for writing and flushing only
     */
    public function getRaw(UnitEnum|string $key): mixed
    {
        throw new BadMethodCallException(
            'Cannot get items via tags in any mode. Tags are for writing and flushing only. '
            . 'Use Cache::get() directly with the full key.'
        );
    }

    /**
     * Retrieve multiple items from the cache by key.
     *
     * @throws BadMethodCallException always — tags are for writing and flushing only
     */
    public function many(array $keys): array
    {
        throw new BadMethodCallException(
            'Cannot get items via tags in any mode. Tags are for writing and flushing only. '
            . 'Use Cache::many() directly with the full keys.'
        );
    }

    /**
     * @throws BadMethodCallException always — tags are for writing and flushing only
     */
    public function manyRaw(array $keys): array
    {
        throw new BadMethodCallException(
            'Cannot get items via tags in any mode. Tags are for writing and flushing only. '
            . 'Use Cache::many() directly with the full keys.'
        );
    }

    /**
     * Determine if an item exists in the cache.
     *
     * @throws BadMethodCallException always — tags are for writing and flushing only
     */
    public function has(array|UnitEnum|string $key): bool
    {
        throw new BadMethodCallException(
            'Cannot check existence via tags in any mode. Tags are for writing and flushing only. '
            . 'Use Cache::has() directly with the full key.'
        );
    }

    /**
     * Retrieve an item from the cache and delete it.
     *
     * @throws BadMethodCallException always — tags are for writing and flushing only
     */
    public function pull(UnitEnum|string $key, mixed $default = null): mixed
    {
        throw new BadMethodCallException(
            'Cannot pull items via tags in any mode. Tags are for writing and flushing only. '
            . 'Use Cache::pull() directly with the full key.'
        );
    }

    /**
     * Remove an item from the cache.
     *
     * @throws BadMethodCallException always — tags are for writing and flushing only
     */
    public function forget(UnitEnum|string $key): bool
    {
        throw new BadMethodCallException(
            'Cannot forget items via tags in any mode. Tags are for writing and flushing only. '
            . 'Use Cache::forget() directly with the full key, or flush() to remove all tagged items.'
        );
    }

    /**
     * Set the expiration of a cached item.
     *
     * @throws BadMethodCallException always — tags are for writing and flushing only
     */
    public function touch(UnitEnum|string $key, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        throw new BadMethodCallException(
            'Cannot touch items via tags in any mode. Re-put the item through tags() to change '
            . 'its TTL; a direct Cache::touch() uses the store\'s plain-key semantics.'
        );
    }
}
```

Signatures must match `Repository`'s exactly (copy them from `Repository`, they are listed in Verified Current State). The throwing method bodies are moved verbatim from `AnyTaggedCache` where they exist there today; `touch()` is new.

**2.4 `Redis\AllTaggedCache`**: `extends TaggedCache` → `extends NamespacedTaggedCache` (namespace import `Hypervel\Cache\NamespacedTaggedCache`). Delete nothing else in this phase (its `touch()` override is Phase 4). Its constructor property docblocks (`@var RedisStore`, `@var AllTagSet`) and typed constructor stay; the `@var AllTagSet` docblock on `$tags` now narrows `NamespacedTagSet`.

**2.5 `Redis\AnyTaggedCache`**: `extends TaggedCache` → `extends AnyModeTaggedCache` (import it). Delete the now-inherited throwing methods: `get()`, `getRaw()`, `many()`, `manyRaw()`, `has()`, `pull()`, `forget()`. Delete its `itemKey()` override (the inherited `Repository::itemKey()` passthrough is identical) and the "Key differences" class docblock lines that describe the moved contract — replace the class docblock with the Redis-specifics only (HSETEX usage, single-connection remember ops). Everything else (put/putMany/add/forever/increment/decrement/flush/items/remember/rememberForever/getTags/putManyForever) stays.

**2.6 Fix `AnyTaggedCache::put()` zero-TTL divergence** (bug 6). Replace:

```php
        if ($seconds <= 0) {
            // Can't forget via tags, just return false
            return false;
        }
```

with:

```php
        if ($seconds <= 0) {
            return $this->store->forget($key);
        }
```

This matches `Repository::put()` semantics (expired TTL deletes the key). It is correct in any mode because the item key is the plain key, and after Phase 5 the store-level forget also cleans tag metadata.

**Fix the same divergence in both Redis `putMany()` overrides.** `Repository::putMany()` with `$seconds <= 0` routes to `deleteMultiple()` (verified — `Repository.php:340`); both Redis tagged overrides return `false` instead:

- `AnyTaggedCache::putMany()`: tagged `deleteMultiple()` would throw (it routes through the throwing `forget()`), so delete plain:

```php
        if ($seconds <= 0) {
            $result = true;

            foreach (array_keys($values) as $key) {
                if (! $this->store->forget((string) $key)) {
                    $result = false;
                }
            }

            return $result;
        }
```

- `AllTaggedCache::putMany()`: tagged `forget()` is supported and namespaced in all mode, so mirror the repository directly:

```php
        if ($seconds <= 0) {
            return $this->deleteMultiple(array_map(static fn ($key) => (string) $key, array_keys($values)));
        }
```

`StackTaggedCache` needs no change: it inherits the base `TaggedCache::putMany()` loop, and each `put()` handles `<= 0` by plain-forgetting (6.4). The generic `NamespacedTaggedCache` path is likewise already correct through the base loop. Tests: zero/negative TTL `putMany()` for Redis any-mode (plain deletes, metadata cleaned), Redis all-mode (namespaced deletes), and the stack (per-key plain forget through the loop).

**2.7 `TaggableStore::tags()`** — apply the Phase 1.6 change now (returns `NamespacedTaggedCache`). Add imports.

**Tests for Phases 1+2** (write/update, then run each file):
- `tests/Cache/CacheTaggedCacheTest.php` — update generic instantiations (`new NamespacedTaggedCache(..., new VersionedTagSet(...))`); all existing behavioral assertions must pass unchanged (the generic path is behavior-identical). Add: `clear()` on a generic tagged cache flushes only the tag namespace (put a tagged and an untagged value on an `ArrayStore` repository, `tags(...)->clear()`, assert untagged survives and `assertInstanceOf(TaggedCache::class, ...)` still holds).
- `tests/Cache/Redis/AnyTaggedCacheTest.php` — assertions for throwing methods now exercise the inherited base; add `touch()` throw + message; add PSR/ArrayAccess alias coverage: `getMultiple()`, `delete()`, `deleteMultiple()`, `offsetGet`, `offsetExists`, `offsetUnset` throw; `setMultiple()` and `offsetSet()` succeed (mock the write path); `clear()` calls tag flush, not store flush. Add zero-TTL `put()` deletes via store `forget`.
- `tests/Cache/Redis/AllTaggedCacheTest.php` — `clear()` uses tagged flush (`allTagOps` flush), not `store->flush()`.
- `tests/Cache/Redis/AnyTagSetTest.php` / `AllTagSetTest.php` — reflect the hierarchy (deleted methods gone; `AllTagSet` reset/flush walkers still behave as before).

---

### Phase 3 — First-class `supportsTags()`

**3.1 `TaggableStore`**: add below `tags()`:

```php
    /**
     * Determine if this store currently supports tags.
     *
     * Stores whose tag support depends on configuration or composition
     * (e.g. the stack store) override this; for everything else extending
     * TaggableStore, tag support is unconditional.
     */
    public function supportsTags(): bool
    {
        return true;
    }
```

**3.2 `Repository::supportsTags()`**:

```php
    /**
     * Determine if the current store supports tags.
     */
    public function supportsTags(): bool
    {
        return $this->store instanceof TaggableStore && $this->store->supportsTags();
    }
```

**3.3 `Repository::tags()`** — restructure so phpstan narrows the store and the ignore comes out:

```php
    /**
     * Begin executing a new tags operation if the store supports it.
     *
     * @throws BadMethodCallException
     */
    public function tags(mixed $names): TaggedCache
    {
        $store = $this->store;

        if (! $store instanceof TaggableStore || ! $store->supportsTags()) {
            throw new BadMethodCallException('This cache store does not support tagging.');
        }

        $names = is_array($names) ? $names : func_get_args();
        $names = array_map(fn ($name) => enum_value($name), $names);

        $cache = $store->tags($names);

        $cache->config = $this->config;

        if (! is_null($this->events)) {
            $cache->setEventDispatcher($this->events);
        }

        return $cache->setDefaultCacheTime($this->default);
    }
```

Delete the `/* @phpstan-ignore-next-line */`. Add the `TaggableStore` import if missing. Run `./vendor/bin/phpstan` on the cache package after this phase to confirm the ignore is no longer needed (and that `$cache->config` public-property access still passes — it did before, unchanged).

**Tests**: `tests/Cache/CacheRepositoryTest.php` — `supportsTags()` true for a `TaggableStore` mock with `supportsTags() => true`, false for a plain `Store` mock, false for a `TaggableStore` mock with `supportsTags() => false`; `tags()` throws for the latter two.

---

### Phase 4 — Touch operations

**4.1 New plain `Operations/Touch.php`** (`src/cache/src/Redis/Operations/Touch.php`):

```php
<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Adjust the expiration time of a cached item.
 */
class Touch
{
    /**
     * Create a new touch operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
    ) {
    }

    /**
     * Execute the touch (expire) operation.
     */
    public function execute(string $key, int $seconds): bool
    {
        return $this->context->withConnection(
            fn (RedisConnection $connection) => (bool) $connection->expire(
                $this->context->prefix() . $key,
                max(1, $seconds)
            )
        );
    }
}
```

Note the current inline code casts `(int) max(1, $seconds)` — `max(1, int)` is already `int`; drop the redundant cast.

**4.2 New `Operations/AnyTag/Touch.php`** — mirrors the `AnyTag\Put` structure (Lua standard / sequential cluster). Behavior: extend the key; if the key has a reverse index, extend the reverse index, each tag hash field (`HEXPIRE`), and the registry scores (`ZADD GT` — a shortened key TTL must not shrink registry scores below other keys' needs; the registry is a per-tag upper bound, unlike the all-mode per-entry scores):

```php
<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AnyTag;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Adjust the expiration time of a cached item and its tag metadata.
 *
 * Any-mode tag hash fields and reverse indexes carry their own TTLs, and
 * flush treats them as authoritative membership. A bare EXPIRE on the key
 * would let the key outlive its tag metadata, making a later tag flush
 * miss a still-live key — so touch extends key and metadata together.
 */
class Touch
{
    /**
     * Create a new touch operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
    ) {
    }

    /**
     * Execute the touch operation.
     */
    public function execute(string $key, int $seconds): bool
    {
        if ($this->context->isCluster()) {
            return $this->executeCluster($key, $seconds);
        }

        return $this->executeUsingLua($key, $seconds);
    }

    /**
     * Execute for cluster using sequential commands.
     */
    private function executeCluster(string $key, int $seconds): bool
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($key, $seconds) {
            $seconds = max(1, $seconds);

            if (! $connection->expire($this->context->prefix() . $key, $seconds)) {
                return false;
            }

            $tagsKey = $this->context->reverseIndexKey($key);
            $tags = $connection->smembers($tagsKey);

            if (empty($tags)) {
                return true;
            }

            $connection->expire($tagsKey, $seconds);

            foreach ($tags as $tag) {
                $connection->hexpire($this->context->tagHashKey((string) $tag), $seconds, [$key]);
            }

            $expiry = time() + $seconds;
            $zaddArgs = [];

            foreach ($tags as $tag) {
                $zaddArgs[] = $expiry;
                $zaddArgs[] = (string) $tag;
            }

            $connection->zadd($this->context->registryKey(), ['GT'], ...$zaddArgs);

            return true;
        });
    }

    /**
     * Execute using Lua script for performance.
     */
    private function executeUsingLua(string $key, int $seconds): bool
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($key, $seconds) {
            $keys = [
                $this->context->prefix() . $key,       // KEYS[1]
                $this->context->reverseIndexKey($key), // KEYS[2]
            ];

            $args = [
                max(1, $seconds),                  // ARGV[1]
                $this->context->fullTagPrefix(),   // ARGV[2]
                $this->context->fullRegistryKey(), // ARGV[3]
                time(),                            // ARGV[4]
                $key,                              // ARGV[5]
                $this->context->tagHashSuffix(),   // ARGV[6]
            ];

            return (bool) $connection->evalWithShaCache($this->touchWithTagsScript(), $keys, $args);
        });
    }

    /**
     * Get the Lua script for touching a value and its tag metadata.
     *
     * KEYS[1] - The cache key (prefixed)
     * KEYS[2] - The reverse index key
     * ARGV[1] - TTL in seconds
     * ARGV[2] - Tag prefix for building tag hash keys
     * ARGV[3] - Tag registry key
     * ARGV[4] - Current timestamp
     * ARGV[5] - Raw key (without prefix, for hash field name)
     * ARGV[6] - Tag hash suffix (":entries")
     */
    protected function touchWithTagsScript(): string
    {
        return <<<'LUA'
            local key = KEYS[1]
            local tagsKey = KEYS[2]
            local ttl = tonumber(ARGV[1])
            local tagPrefix = ARGV[2]
            local registryKey = ARGV[3]
            local now = tonumber(ARGV[4])
            local rawKey = ARGV[5]
            local tagHashSuffix = ARGV[6]

            if redis.call('EXPIRE', key, ttl) == 0 then
                return false
            end

            local tags = redis.call('SMEMBERS', tagsKey)

            if #tags == 0 then
                return true
            end

            redis.call('EXPIRE', tagsKey, ttl)

            local expiry = now + ttl

            for _, tag in ipairs(tags) do
                local tagHash = tagPrefix .. tag .. tagHashSuffix
                redis.call('HEXPIRE', tagHash, ttl, 'FIELDS', 1, rawKey)
                redis.call('ZADD', registryKey, 'GT', expiry, tag)
            end

            return true
            LUA;
    }
}
```

Before writing this file, read `Operations/AnyTag/Put.php`, `Forever.php`, and `Increment.php` in full and match their exact conventions (docblock style, `withConnection` usage, `evalWithShaCache`, cluster batching, `hexpire` client method availability — check `RedisConnection` / how `hsetex` is invoked in `Put.php` and mirror the calling convention for `hexpire`; if the phpredis client exposes hash-field expiry via a different method signature, follow whatever `Put.php`'s HSETEX handling establishes).

**4.3 New `Operations/AllTag/Touch.php`** — extends the namespaced key and re-scores each tag ZSET entry (plain `ZADD`, not `GT`; per-entry scores must track the entry's real expiry in both directions):

```php
<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AllTag;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Adjust the expiration time of a tagged cache item and its tag entries.
 *
 * All-mode tag ZSET scores are the authoritative expiry used by stale
 * pruning. A bare EXPIRE on the item key would desynchronize the scores:
 * pruning would drop the entry while the key lives, and a later tag flush
 * would miss it — so touch updates key TTL and scores together.
 */
class Touch
{
    /**
     * Create a new touch operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
    ) {
    }

    /**
     * Execute the touch operation.
     *
     * The key is expired first and metadata is only written when the key
     * proved live — scoring the ZSETs for a missing (or concurrently
     * deleted) key would create exactly the stale entries this operation
     * exists to prevent.
     *
     * @param array<string> $tagIds Array of tag identifiers (e.g., "_all:tag:users:entries")
     */
    public function execute(string $key, int $seconds, array $tagIds): bool
    {
        if ($this->context->isCluster()) {
            return $this->executeCluster($key, $seconds, $tagIds);
        }

        return $this->executeUsingLua($key, $seconds, $tagIds);
    }

    /**
     * Execute for cluster using sequential commands.
     */
    private function executeCluster(string $key, int $seconds, array $tagIds): bool
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($key, $seconds, $tagIds) {
            $prefix = $this->context->prefix();
            $seconds = max(1, $seconds);

            if (! $connection->expire($prefix . $key, $seconds)) {
                return false;
            }

            $score = now()->addSeconds($seconds)->getTimestamp();

            foreach ($tagIds as $tagId) {
                $connection->zadd($prefix . $tagId, $score, $key);
            }

            return true;
        });
    }

    /**
     * Execute using Lua script for performance.
     */
    private function executeUsingLua(string $key, int $seconds, array $tagIds): bool
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($key, $seconds, $tagIds) {
            $seconds = max(1, $seconds);

            // Tag ZSET keys are statically known, so they are declared in KEYS
            // (phpredis applies OPT_PREFIX to KEYS only) — the opt-prefixed
            // fullTagPrefix()/fullRegistryKey() ARGV convention is reserved for
            // keys built dynamically inside Lua.
            $keys = [
                $this->context->prefix() . $key, // KEYS[1]
                ...array_map(fn (string $tagId) => $this->context->prefix() . $tagId, $tagIds), // KEYS[2...]
            ];

            $args = [
                $seconds,                                    // ARGV[1]
                now()->addSeconds($seconds)->getTimestamp(), // ARGV[2]
                $key,                                        // ARGV[3]
            ];

            return (bool) $connection->evalWithShaCache($this->touchTaggedScript(), $keys, $args);
        });
    }

    /**
     * Get the Lua script for touching a tagged value and its ZSET scores.
     *
     * KEYS[1] - The cache key (prefixed, namespaced)
     * KEYS[2...] - Prefixed tag ZSET keys
     * ARGV[1] - TTL in seconds
     * ARGV[2] - New expiry score
     * ARGV[3] - Raw namespaced key (ZSET member)
     */
    protected function touchTaggedScript(): string
    {
        return <<<'LUA'
            local key = KEYS[1]
            local ttl = tonumber(ARGV[1])
            local score = tonumber(ARGV[2])
            local member = ARGV[3]

            if redis.call('EXPIRE', key, ttl) == 0 then
                return false
            end

            for i = 2, #KEYS do
                redis.call('ZADD', KEYS[i], score, member)
            end

            return true
            LUA;
    }
}
```

Score convention matches `AllTag\Put` (`now()->addSeconds(...)->getTimestamp()`); the any-mode family uses `time()` (matching `AnyTag\Put`). Both branches expire-then-score so a missing or concurrently deleted key never gains ZSET entries — the same prove-liveness-first shape as `AnyTag\Touch`.

**4.4 Register the ops.** `AllTagOperations`: add `private ?Touch $touch = null;` + accessor `touch(): Touch` (`new Touch($this->context)`) in the position matching the class's existing ordering. `AnyTagOperations`: same. `RedisStore`: add `private ?Touch $touchOperation = null;` + `getTouchOperation()` following the exact pattern of `getForgetOperation()`.

**4.5 `RedisStore::touch()`** — route by mode, delete the inline `expire`:

```php
    /**
     * Adjust the expiration time of a cached item.
     */
    public function touch(string $key, int $seconds): bool
    {
        if ($this->tagMode === TagMode::Any) {
            return $this->anyTagOps()->touch()->execute($key, $seconds);
        }

        return $this->getTouchOperation()->execute($key, $seconds);
    }
```

**4.6 `AllTaggedCache::touch()`** override (place it near the other write overrides, matching upstream ordering conventions — after `putMany()`, before `increment()`, mirroring `Repository`'s method grouping):

```php
    /**
     * Set the expiration of a cached item; null TTL will retain the item forever.
     */
    public function touch(UnitEnum|string $key, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        $key = enum_value($key);
        $value = $this->get($key);

        if (is_null($value)) {
            return false;
        }

        if (is_null($ttl)) {
            return $this->forever($key, $value);
        }

        return $this->store->allTagOps()->touch()->execute(
            $this->itemKey($key),
            $this->getSeconds($ttl),
            $this->tags->tagIds()
        );
    }
```

This mirrors `Repository::touch()` exactly: get-check, null-TTL routes to (tagged) `forever()`, integer TTL goes to the store path. `Repository::touch()` has no `<= 0` special case (the op clamps with `max(1, ...)` like the plain path), so this override adds none.

**4.7 `AnyModeTaggedCache::touch()`** — already added in Phase 2.3.

**Tests**:
- `tests/Cache/Redis/AllTaggedCacheTest.php`: `touch(int)` routes to `allTagOps()->touch()` with namespaced key + tagIds; `touch(null)` routes to tagged `forever()`; `touch()` on missing key returns false.
- `tests/Cache/Redis/AnyTaggedCacheTest.php`: `touch()` throws with the touch-specific message.
- `tests/Cache/Redis/RedisStoreTest.php`: `touch()` routes by mode — assert at the connection level per the file's existing style (op instances are private lazy singletons, not mockable directly): in all mode a bare `expire` is issued; in any mode the touch script/metadata commands run. Read the file's existing mode-routing tests first and mirror their approach.
- Integration (`tests/Integration/Cache/Redis/TaggedOperationsIntegrationTest.php` or a new `TouchOperationsIntegrationTest.php` following that file's structure): all-mode — put tagged, touch to extend, run `flushStale`, assert entry survives and tag flush still deletes the key; touch to shorten, assert score followed; **tagged touch of a missing key returns false and writes no ZSET entries** (inspect the tag ZSETs via raw connection). Any-mode — put tagged, plain-touch via `Cache::touch()`, assert reverse index TTL / hash field TTL / registry extended (inspect via raw connection), tag flush still deletes; touch of an untagged key is a plain expire; touch of a missing key returns false and writes no metadata.

---

### Phase 5 — Any-mode metadata-aware forget

**5.1 New `Operations/AnyTag/Forget.php`**:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Operations\AnyTag;

use Hypervel\Cache\Redis\Support\StoreContext;
use Hypervel\Redis\RedisConnection;

/**
 * Remove an item from the cache along with its tag membership.
 *
 * A bare DEL would leave the key listed in its tag hashes; a later tag
 * flush would then delete an unrelated new value written at the reused
 * key. Deleting membership with the key keeps flush scoped to values
 * that were actually written through the tags. The registry is not
 * touched — removing one key does not empty a tag, and registry hygiene
 * belongs to pruning.
 */
class Forget
{
    /**
     * Create a new forget operation instance.
     */
    public function __construct(
        private readonly StoreContext $context,
    ) {
    }

    /**
     * Execute the forget (delete) operation.
     */
    public function execute(string $key): bool
    {
        if ($this->context->isCluster()) {
            return $this->executeCluster($key);
        }

        return $this->executeUsingLua($key);
    }

    /**
     * Execute for cluster using sequential commands.
     */
    private function executeCluster(string $key): bool
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($key) {
            $tagsKey = $this->context->reverseIndexKey($key);
            $tags = $connection->smembers($tagsKey);

            foreach ($tags as $tag) {
                $connection->hdel($this->context->tagHashKey((string) $tag), $key);
            }

            if (! empty($tags)) {
                $connection->del($tagsKey);
            }

            return (bool) $connection->del($this->context->prefix() . $key);
        });
    }

    /**
     * Execute using Lua script for performance.
     */
    private function executeUsingLua(string $key): bool
    {
        return $this->context->withConnection(function (RedisConnection $connection) use ($key) {
            $keys = [
                $this->context->prefix() . $key,       // KEYS[1]
                $this->context->reverseIndexKey($key), // KEYS[2]
            ];

            $args = [
                $this->context->fullTagPrefix(),  // ARGV[1]
                $key,                             // ARGV[2]
                $this->context->tagHashSuffix(),  // ARGV[3]
            ];

            return (bool) $connection->evalWithShaCache($this->forgetWithTagsScript(), $keys, $args);
        });
    }

    /**
     * Get the Lua script for deleting a value and its tag membership.
     *
     * KEYS[1] - The cache key (prefixed)
     * KEYS[2] - The reverse index key
     * ARGV[1] - Tag prefix for building tag hash keys
     * ARGV[2] - Raw key (without prefix, for hash field name)
     * ARGV[3] - Tag hash suffix (":entries")
     */
    protected function forgetWithTagsScript(): string
    {
        return <<<'LUA'
            local key = KEYS[1]
            local tagsKey = KEYS[2]
            local tagPrefix = ARGV[1]
            local rawKey = ARGV[2]
            local tagHashSuffix = ARGV[3]

            local tags = redis.call('SMEMBERS', tagsKey)

            for _, tag in ipairs(tags) do
                local tagHash = tagPrefix .. tag .. tagHashSuffix
                redis.call('HDEL', tagHash, rawKey)
            end

            if #tags > 0 then
                redis.call('DEL', tagsKey)
            end

            return redis.call('DEL', key)
            LUA;
    }
}
```

**5.2 Register** in `AnyTagOperations` (property + `forget(): Forget` accessor).

**5.3 `RedisStore::forget()`** — route by mode:

```php
    /**
     * Remove an item from the cache.
     */
    public function forget(string $key): bool
    {
        if ($this->tagMode === TagMode::Any) {
            return $this->anyTagOps()->forget()->execute($key);
        }

        return $this->getForgetOperation()->execute($key);
    }
```

The plain `Operations/Forget.php` stays as the all-mode path, unchanged.

**Tests**:
- `tests/Cache/Redis/RedisStoreTest.php`: `forget()` routes by mode.
- Integration: any-mode — write key through tags, plain `Cache::forget()`, assert key + reverse index + tag hash field gone; **key-reuse proof**: write through tags, plain forget, write same key plain (untagged), tag flush, assert the new value survives; forget of an untagged key still deletes and returns true; forget of a missing key returns false (match plain `DEL` return semantics — assert against the current plain-op behavior).

---

### Phase 6 — Stack tags

**6.1 `StackStoreProxy`** — add accessors (place after the constructor):

```php
    /**
     * Get the wrapped store.
     */
    public function getStore(): Store
    {
        return $this->store;
    }

    /**
     * Get the layer TTL override in seconds, if configured.
     */
    public function getTtl(): ?int
    {
        return $this->ttl;
    }
```

**6.2 New `StackTagSet`** (`src/cache/src/StackTagSet.php`):

```php
<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Hypervel\Contracts\Cache\Store;

/**
 * Any-mode tag set for the stack store.
 *
 * Delegates tag flushing to every taggable layer. Non-taggable layers
 * above the taggable region are not flushed — their entries expire within
 * their configured layer TTL (the microcache staleness tradeoff).
 */
class StackTagSet extends TagSet
{
    /**
     * The cache store implementation.
     *
     * @var StackStore
     */
    protected Store $store;

    /**
     * Create a new StackTagSet instance.
     */
    public function __construct(StackStore $store, array $names = [])
    {
        parent::__construct($store, $names);
    }

    /**
     * Reset all tags in the set.
     */
    public function reset(): void
    {
        $this->flush();
    }

    /**
     * Flush all the tags in the set.
     */
    public function flush(): void
    {
        foreach ($this->store->taggableLayers() as $layer) {
            $layer->tags($this->names)->getTags()->flush();
        }
    }
}
```

(Flushing via each layer's tag set — not its tagged cache `flush()` — avoids duplicate flush events; the stack-level `TaggedCache::flush()` fires the events once.)

**6.3 `StackStore`** — the full set of changes:

Class declaration: `class StackStore extends TaggableStore` (drop `implements Store`; `TaggableStore` implements it). Add `implements LockProvider, CanFlushLocks` in Phase 7 — for this phase just `extends TaggableStore`.

Constructor normalization — internal code (TTL clamps, tagged writes, validation unwrapping) needs the uniform `StackStoreProxy` type, and a no-TTL proxy is behaviorally identical to the raw store, so the constructor normalizes instead of pushing wrapping onto every caller (`CacheManager` already wraps; direct construction in tests currently passes raw stores):

```php
    /**
     * @var StackStoreProxy[]
     */
    protected array $stores;

    /**
     * @param array<int, StackStoreProxy|Store> $stores
     *
     * @throws InvalidArgumentException when no layers are given
     */
    public function __construct(array $stores)
    {
        if ($stores === []) {
            throw new InvalidArgumentException('A cache stack requires at least one store layer.');
        }

        $this->stores = array_map(
            static fn (Store $store) => $store instanceof StackStoreProxy ? $store : new StackStoreProxy($store),
            $stores,
        );
    }
```

The empty-array rejection is a constructor invariant (fail-fast): an empty stack is a pure misconfiguration, and Phase 7's bottom-layer delegation must always have a bottom layer. Verified: nothing in `src/` or `tests/` constructs an empty `StackStore`. Import `InvalidArgumentException`.

New composition validation state and API (place after the constructor):

```php
    /**
     * Memoized tag-composition validation error.
     *
     * Contains false before validation has run, null for a valid
     * composition, and the error message for an invalid one. The layer
     * array is immutable after construction, so this is computed once.
     */
    protected false|null|string $tagCompositionError = false;
```

```php
    /**
     * Begin executing a new tags operation.
     *
     * @throws NotSupportedException when the layer composition cannot support tags
     */
    public function tags(mixed $names): TaggedCache
    {
        if (! is_null($error = $this->tagCompositionError())) {
            throw new NotSupportedException($error);
        }

        return new StackTaggedCache($this, new StackTagSet($this, is_array($names) ? $names : func_get_args()));
    }

    /**
     * Determine if this store currently supports tags.
     */
    public function supportsTags(): bool
    {
        return is_null($this->tagCompositionError());
    }

    /**
     * Get the tag mode this store operates under.
     *
     * Only meaningful for a tag-capable composition — use supportsTags()
     * to probe. Stack tags are always any-mode (tags index the shared
     * layers; plain-key reads and backfill are untouched).
     *
     * @throws NotSupportedException when the layer composition cannot support tags
     */
    public function getTagMode(): TagMode
    {
        if (! is_null($error = $this->tagCompositionError())) {
            throw new NotSupportedException($error);
        }

        return TagMode::Any;
    }

    /**
     * Get the memoized tag-composition validation error, if any.
     */
    protected function tagCompositionError(): ?string
    {
        if ($this->tagCompositionError === false) {
            $this->tagCompositionError = $this->validateTagComposition();
        }

        return $this->tagCompositionError;
    }

    /**
     * Validate the layer composition for tag support.
     *
     * Returns an error message, or null when the composition is valid:
     * at least one taggable layer, and every layer from the first taggable
     * layer down must be an any-mode taggable store. A non-taggable or
     * all-mode layer below the taggable region would resurrect flushed
     * values on read-through, silently undoing tag invalidation.
     */
    protected function validateTagComposition(): ?string
    {
        $firstTaggable = null;

        foreach ($this->stores as $index => $proxy) {
            $store = $proxy->getStore();

            if ($firstTaggable === null) {
                if ($store instanceof TaggableStore) {
                    $firstTaggable = $index;
                } else {
                    continue;
                }
            }

            if (! $store instanceof TaggableStore) {
                return sprintf(
                    'Stack layer %d [%s] does not support tags. Layers below the first taggable layer must all be any-mode taggable stores.',
                    $index,
                    $store::class,
                );
            }

            if (! $store->supportsTags() || $store->getTagMode() !== TagMode::Any) {
                return sprintf(
                    'Stack layer %d [%s] must be a taggable store in any mode to participate in stack tags.',
                    $index,
                    $store::class,
                );
            }
        }

        if ($firstTaggable === null) {
            return 'The stack has no taggable layer; stack tags require at least one any-mode taggable store.';
        }

        return null;
    }

    /**
     * Get the underlying taggable layer stores, top to bottom.
     *
     * @return array<int, TaggableStore>
     *
     * @throws NotSupportedException when the layer composition cannot support tags
     */
    public function taggableLayers(): array
    {
        if (! is_null($error = $this->tagCompositionError())) {
            throw new NotSupportedException($error);
        }

        $layers = [];

        foreach ($this->stores as $proxy) {
            $store = $proxy->getStore();

            if ($store instanceof TaggableStore) {
                $layers[] = $store;
            }
        }

        return $layers;
    }
```

Careful detail: `validateTagComposition()` calls `$store->supportsTags()` **before** `$store->getTagMode()`, so a nested invalid stack layer reports unsupported instead of throwing.

Tagged record writes (place next to `putRecord()`/`putToStore()` so record logic reads as one unit):

```php
    /**
     * Store a record in all layers, indexing it under the given tags in
     * the taggable layers.
     *
     * @param array<string> $tags
     */
    public function putRecordTagged(array $tags, string $key, array $record): bool
    {
        return $this->callStores(
            fn (StackStoreProxy $proxy) => $this->putToStoreTagged($proxy, $tags, $key, $record),
            fn (StackStoreProxy $proxy) => $proxy->forget($key),
        );
    }

    /**
     * Store a record in a single layer, via the layer's tagged write path
     * when the layer is taggable.
     *
     * Mirrors putToStore(), including the layer TTL clamp that
     * StackStoreProxy::put() applies on the plain path — tagged writes
     * bypass the proxy, so the clamp is replicated here.
     */
    protected function putToStoreTagged(StackStoreProxy $proxy, array $tags, string $key, array $record): bool
    {
        $store = $proxy->getStore();

        if (! $store instanceof TaggableStore) {
            return $this->putToStore($proxy, $key, $record);
        }

        if (! array_key_exists('value', $record)) {
            return false;
        }

        if (! array_key_exists('expiration', $record) && ! array_key_exists('ttl', $record)) {
            if (is_null($proxyTtl = $proxy->getTtl())) {
                return $store->tags($tags)->forever($key, $record);
            }

            return $store->tags($tags)->put($key, $record, $proxyTtl);
        }

        $currentTimestamp = Carbon::now()->getTimestamp();
        $value = $record['value'];
        $expiration = $record['expiration'] ?? $currentTimestamp + $record['ttl'];
        $ttl = $record['ttl'] ?? $record['expiration'] - $currentTimestamp;
        $normalizedRecord = compact('value', 'expiration');

        $proxyTtl = $proxy->getTtl();
        $effectiveTtl = is_null($proxyTtl) || $ttl < $proxyTtl ? $ttl : $proxyTtl;

        return $store->tags($tags)->put($key, $normalizedRecord, $effectiveTtl);
    }

    /**
     * Increment a record's value, indexing the write under the given tags.
     *
     * @param array<string> $tags
     */
    public function incrementTagged(array $tags, string $key, int $value = 1): bool|int
    {
        $record = $this->getOrRestoreRecord($key);

        if (is_null($record)) {
            return tap($value, fn ($value) => $this->putRecordTagged($tags, $key, ['value' => $value]));
        }

        $newValue = $record['value'] + $value;
        $newRecord = ['value' => $newValue] + $record;

        if ($this->putRecordTagged($tags, $key, $newRecord)) {
            return $newValue;
        }

        return false;
    }
```

Type note: with constructor normalization in place, update every internal closure and helper signature from `Store` to `StackStoreProxy` (`putToStore()`, the closures inside `get`/`forever`/`forget`/`flush`/`putRecord`/`getOrRestoreRecord`) — the narrow type is now guaranteed and `putToStoreTagged` needs the proxy accessors. `CacheManager::createStackDriver()` already passes proxies (verified); direct constructions with raw stores keep working through normalization, so `tests/Cache/CacheStackStoreTest.php` (which constructs `new StackStore([$array, $swoole, $redis])` with raw stores in several tests and proxies in others — verified) passes unchanged.

Add imports: `Hypervel\Cache\Exceptions\NotSupportedException`, `Hypervel\Cache\TagMode` (same namespace — no import needed for `TagMode`, `TaggableStore`, `TaggedCache`, `StackTagSet`, `StackTaggedCache`; only the exception import from the sub-namespace).

**6.4 New `StackTaggedCache`** (`src/cache/src/StackTaggedCache.php`):

```php
<?php

declare(strict_types=1);

namespace Hypervel\Cache;

use Closure;
use DateInterval;
use DateTimeInterface;
use Hypervel\Cache\Events\CacheHit;
use Hypervel\Cache\Events\CacheMissed;
use Hypervel\Cache\Events\KeyWritten;
use Hypervel\Contracts\Cache\Store;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * Any-mode tagged cache for the stack store.
 *
 * Writes push stack records through every layer — tagged writes on the
 * taggable layers so tag indexes are recorded, plain writes above. Reads
 * are not tag operations (see AnyModeTaggedCache): use the plain stack
 * repository, which serves L1 hits and backfills on L2 hits as usual.
 * After a tag flush, non-taggable upper layers serve their entries until
 * the layer TTL expires (the microcache staleness tradeoff).
 */
class StackTaggedCache extends AnyModeTaggedCache
{
    /**
     * The cache store implementation.
     *
     * @var StackStore
     */
    protected Store $store;

    /**
     * The tag set instance.
     *
     * @var StackTagSet
     */
    protected TagSet $tags;

    /**
     * Create a new tagged cache instance.
     */
    public function __construct(StackStore $store, StackTagSet $tags)
    {
        parent::__construct($store, $tags);
    }

    /**
     * Store an item in the cache.
     */
    public function put(array|UnitEnum|string $key, mixed $value, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        if (is_array($key)) {
            return $this->putMany($key, $value);
        }

        $key = enum_value($key);

        if ($ttl === null) {
            return $this->forever($key, $value);
        }

        $seconds = $this->getSeconds($ttl);

        if ($seconds <= 0) {
            return $this->store->forget($key);
        }

        $result = $this->store->putRecordTagged($this->tags->getNames(), $key, [
            'value' => $value,
            'ttl' => $seconds,
        ]);

        if ($result) {
            $this->event(KeyWritten::class, fn (): KeyWritten => new KeyWritten(null, $key, NullSentinel::unwrap($value), $seconds));
        }

        return $result;
    }

    /**
     * Store an item in the cache if the key does not exist.
     */
    public function add(UnitEnum|string $key, mixed $value, DateInterval|DateTimeInterface|int|null $ttl = null): bool
    {
        $key = enum_value($key);

        if (! is_null($this->store->get($key))) {
            return false;
        }

        return $this->put($key, $value, $ttl);
    }

    /**
     * Store an item in the cache indefinitely.
     */
    public function forever(UnitEnum|string $key, mixed $value): bool
    {
        $key = enum_value($key);

        $result = $this->store->putRecordTagged($this->tags->getNames(), $key, ['value' => $value]);

        if ($result) {
            $this->event(KeyWritten::class, fn (): KeyWritten => new KeyWritten(null, $key, NullSentinel::unwrap($value)));
        }

        return $result;
    }

    /**
     * Increment the value of an item in the cache.
     */
    public function increment(UnitEnum|string $key, int $value = 1): bool|int
    {
        return $this->store->incrementTagged($this->tags->getNames(), enum_value($key), $value);
    }

    /**
     * Decrement the value of an item in the cache.
     */
    public function decrement(UnitEnum|string $key, int $value = 1): bool|int
    {
        return $this->increment($key, $value * -1);
    }

    /**
     * Get an item from the cache, or execute the given Closure and store the result.
     *
     * Reads plain through the stack (L1 hit, else L2 with L1 backfill);
     * writes through the tagged path on a miss.
     *
     * @template TCacheValue
     *
     * @param Closure(): TCacheValue $callback
     * @return TCacheValue
     */
    public function remember(UnitEnum|string $key, DateInterval|DateTimeInterface|int|null $ttl, Closure $callback): mixed
    {
        if ($ttl === null) {
            return $this->rememberForever($key, $callback);
        }

        $key = enum_value($key);
        $seconds = $this->getSeconds($ttl);

        if ($seconds <= 0) {
            return $callback();
        }

        $value = $this->store->get($key);

        if (! is_null($value)) {
            $this->event(CacheHit::class, fn (): CacheHit => new CacheHit(null, $key, NullSentinel::unwrap($value)));

            return NullSentinel::unwrap($value);
        }

        $this->event(CacheMissed::class, fn (): CacheMissed => new CacheMissed(null, $key));

        $value = $callback();

        $this->put($key, $value, $seconds);

        return NullSentinel::unwrap($value);
    }

    /**
     * Get an item from the cache, or execute the given Closure and store the result forever.
     *
     * @template TCacheValue
     *
     * @param Closure(): TCacheValue $callback
     * @return TCacheValue
     */
    public function rememberForever(UnitEnum|string $key, Closure $callback): mixed
    {
        $key = enum_value($key);
        $value = $this->store->get($key);

        if (! is_null($value)) {
            $this->event(CacheHit::class, fn (): CacheHit => new CacheHit(null, $key, NullSentinel::unwrap($value)));

            return NullSentinel::unwrap($value);
        }

        $this->event(CacheMissed::class, fn (): CacheMissed => new CacheMissed(null, $key));

        $value = $callback();

        $this->forever($key, $value);

        return NullSentinel::unwrap($value);
    }

    /**
     * Get the tag set instance (covariant return type).
     */
    public function getTags(): StackTagSet
    {
        return $this->tags;
    }

    /**
     * Store multiple items in the cache indefinitely.
     */
    protected function putManyForever(array $values): bool
    {
        $result = true;

        foreach ($values as $key => $value) {
            if (! $this->forever((string) $key, $value)) {
                $result = false;
            }
        }

        return $result;
    }
}
```

Notes: `putMany()` is inherited from the base `TaggedCache` (loops `put()` — correct here; the stack has no batched op). `flush()` is inherited (`tags->reset()` → `StackTagSet::flush()` + events — correct). `remember()`'s nullable variants ride on it via `Repository::rememberNullable()` wrapping the callback with the sentinel. Event `name` argument is `null`, matching `AnyTaggedCache`/`AllTaggedCache` (tagged caches don't carry a store name).

**6.5 `Cache` facade docblock**: unchanged (`tags()` returns `TaggedCache` — still true). Verify with the facade-documenter if the repo workflow regenerates docblocks; do not hand-edit beyond need.

**Tests** (new `tests/Cache/CacheStackStoreTagsTest.php`, matching the `Cache*Test` naming of `CacheStackStoreTest`/`CacheTaggedCacheTest`, plus updates):

Unit (Mockery, no Redis):
- Composition validation: `[nonTaggable, anyModeTaggable]` valid; `[anyModeTaggable]` valid; `[nonTaggable, anyModeTaggable, anyModeTaggable]` valid; `[nonTaggable]` invalid (no taggable layer); `[anyModeTaggable, nonTaggable]` invalid (non-taggable below); `[nonTaggable, allModeTaggable]` invalid (mode); `[allModeTaggable, anyModeTaggable]` invalid (first taggable is all-mode); nested-stack layer probes `supportsTags()` without tripping `getTagMode()`. Build layers as `new StackStoreProxy(m::mock(TaggableStore::class), ttl)` with `getTagMode`/`supportsTags` expectations, plus `m::mock(Store::class)` for non-taggable.
- `supportsTags()` true/false; `getTagMode()` returns `Any` when valid, throws `NotSupportedException` when not; `tags()` throws with the layer-naming message; validation runs once (mock expectations `once()`).
- Tagged write mechanics: mock taggable layer expects `tags(['t'])` returning a mock tagged cache expecting `put($key, recordWithExpiration, clampedTtl)`; non-taggable upper layer expects plain `put($key, record, ttl)`; proxy TTL clamp applied to the tagged path (layer ttl 3, item ttl 300 → tagged put receives 3); forever records route to tagged `forever()` (no proxy ttl) / tagged `put(ttl)` (with proxy ttl); rollback: lower-layer failure triggers `forget` on written layers.
- `increment`/`decrement` read-modify-write through `putRecordTagged`.
- `remember` plain-read hit skips writes; miss writes tagged.
- Inherited contract: all `AnyModeTaggedCache` throwing methods + aliases (`getMultiple`, `delete`, `deleteMultiple`, `offsetGet/Exists/Unset`) throw; `setMultiple`/`offsetSet` write; `clear()` flushes tags not store.

Integration (`tests/Integration/Cache/Redis/StackTaggedCacheIntegrationTest.php`, guarded by `InteractsWithRedis`, any-mode Redis store config + a `file` (non-taggable) L1 layer with a short ttl override — file is the simplest real non-taggable layer; add one swoole-L1 test if an existing swoole-store test demonstrates the table setup pattern, otherwise file-only is sufficient because layer interaction is identical through the proxy):
- Tagged write through the stack → value readable plain via the stack; Redis holds the record and the tag indexes; L1 holds the record.
- Tag flush → Redis keys gone; stack read within L1 TTL serves stale; after L1 TTL expiry, read misses (no resurrection).
- Resurrection guard: composition `[file, redis-any]` valid; assert `[redis-any, file]` throws.
- `remember` backfills L1 from L2.
- Key-reuse with plain forget (ties Phase 5 together end-to-end through the stack).

---

### Phase 7 — Stack locks + honest `CanFlushLocks`

**7.1 `CanFlushLocks`** (`src/contracts/src/Cache/CanFlushLocks.php`) — add:

```php
    /**
     * Determine if the store can currently flush locks.
     *
     * Composite stores may implement the interface while delegating to a
     * layer that cannot flush locks; this probe reports the real
     * capability so supportsFlushingLocks() checks never lie.
     */
    public function supportsFlushingLocks(): bool;
```

**7.2 Implementers** — `RedisStore`, `AbstractArrayStore`, `DatabaseStore`, `FileStore` each add:

```php
    /**
     * Determine if the store can currently flush locks.
     */
    public function supportsFlushingLocks(): bool
    {
        return true;
    }
```

Place next to each class's existing `flushLocks()`/`hasSeparateLockStore()` methods.

**7.3 `Repository::supportsFlushingLocks()`**:

```php
    /**
     * Determine if the current store supports flushing locks.
     */
    public function supportsFlushingLocks(): bool
    {
        return $this->store instanceof CanFlushLocks && $this->store->supportsFlushingLocks();
    }
```

`Repository::flushLocks()` keeps its structure; with the instanceof inline in the probe, check whether the phpstan-ignore on `$store->flushLocks()` can now be removed by assigning the narrowed store (`$store = $this->getStore(); if (! $store instanceof CanFlushLocks || ! $store->supportsFlushingLocks()) { throw ... }`) — restructure exactly like `tags()` so the ignore comes out. Same event flow otherwise.

**7.4 `StackStore`** — add `implements LockProvider, CanFlushLocks` to the class declaration and:

```php
    /**
     * Get a lock instance.
     *
     * Locks are delegated to the bottom layer's store and never touch the
     * caching tiers — a lock cached in a microcache layer would not be a
     * lock. The delegated lock has exactly the semantics of using the
     * bottom store directly.
     *
     * @throws NotSupportedException when the bottom layer is not a lock provider
     */
    public function lock(string $name, int $seconds = 0, ?string $owner = null): Lock
    {
        return $this->bottomLockProvider()->lock($name, $seconds, $owner);
    }

    /**
     * Restore a lock instance using the owner identifier.
     *
     * @throws NotSupportedException when the bottom layer is not a lock provider
     */
    public function restoreLock(string $name, string $owner): Lock
    {
        return $this->bottomLockProvider()->restoreLock($name, $owner);
    }

    /**
     * Determine if the store can currently flush locks.
     */
    public function supportsFlushingLocks(): bool
    {
        $store = $this->bottomStore();

        return $store instanceof CanFlushLocks && $store->supportsFlushingLocks();
    }

    /**
     * Flush all locks managed by the store.
     *
     * @throws NotSupportedException when the bottom layer cannot flush locks
     */
    public function flushLocks(): bool
    {
        $store = $this->bottomStore();

        if (! $store instanceof CanFlushLocks || ! $store->supportsFlushingLocks()) {
            throw new NotSupportedException(sprintf(
                'The stack\'s bottom layer [%s] does not support flushing locks.',
                $store::class,
            ));
        }

        return $store->flushLocks();
    }

    /**
     * Determine if the lock store is separate from the cache store.
     */
    public function hasSeparateLockStore(): bool
    {
        $store = $this->bottomStore();

        return $store instanceof CanFlushLocks && $store->hasSeparateLockStore();
    }

    /**
     * Get the bottom layer's underlying store.
     */
    protected function bottomStore(): Store
    {
        // array_key_last() instead of end() — end() mutates the array's internal pointer.
        return $this->stores[array_key_last($this->stores)]->getStore();
    }

    /**
     * Get the bottom layer's store as a lock provider.
     *
     * @throws NotSupportedException when the bottom layer is not a lock provider
     */
    protected function bottomLockProvider(): LockProvider
    {
        $store = $this->bottomStore();

        if (! $store instanceof LockProvider) {
            throw new NotSupportedException(sprintf(
                'The stack\'s bottom layer [%s] does not support locks. Use a lock-capable store (e.g. redis) as the bottom layer.',
                $store::class,
            ));
        }

        return $store;
    }
```

Imports: `Hypervel\Contracts\Cache\CanFlushLocks`, `Hypervel\Contracts\Cache\Lock`, `Hypervel\Contracts\Cache\LockProvider`. Group lock methods together after the flush/tag region, mirroring how `RedisStore` orders its lock section. The Phase 6 constructor invariant guarantees at least one layer, so `array_key_last()` in `bottomStore()` is always defined.

**Tests**: new `tests/Cache/CacheStackStoreLocksTest.php` — `lock()`/`restoreLock()` delegate to a bottom `LockProvider` mock with args passed through; non-provider bottom throws `NotSupportedException` naming the class; `supportsFlushingLocks()` reflects the bottom's probe (true/false/non-CanFlushLocks); `flushLocks()` delegates or throws; `hasSeparateLockStore()` delegates; empty-layer construction throws `InvalidArgumentException` (add to `CacheStackStoreTest`, since the invariant belongs to Phase 6's constructor). `tests/Cache/CacheRepositoryTest.php` — `supportsFlushingLocks()` uses the store probe.

`tests/Cache/FunnelUnsupportedStoresTest.php` contains `testStackStoreDoesNotImplementLockProvider` (line 20), which asserts `StackStore` is *not* a `LockProvider` — it documents exactly the gap this phase closes, so the design inverts it. Remove that test method and cover the inverse in `CacheStackStoreLocksTest` (delegation when the bottom supports locks, `NotSupportedException` when it doesn't — which is the funnel-relevant failure mode that test guarded). Read the rest of `FunnelUnsupportedStoresTest` for other stack-related assumptions and update them to the delegation behavior. This is a deliberate, plan-approved test removal, not a workaround.

Verify all four `CanFlushLocks` implementers' existing lock tests still pass (they gain a trivial probe method).

---

### Phase 8 — JWT dual-mode blacklist storage

**8.1 `src/jwt/src/Storage/TaggedCache.php`** — full replacement of the class body:

```php
<?php

declare(strict_types=1);

namespace Hypervel\Jwt\Storage;

use Hypervel\Cache\TaggableStore;
use Hypervel\Contracts\Cache\Repository as CacheContract;
use Hypervel\Jwt\Contracts\StorageContract;

class TaggedCache implements StorageContract
{
    /**
     * Key prefix applied in direct-key (any-mode) storage.
     *
     * In all mode the tag namespace isolates blacklist keys from the rest
     * of the cache; in any mode keys are plain, so the prefix provides
     * that isolation instead.
     */
    private const DIRECT_KEY_PREFIX = 'jwt_blacklist:';

    protected string $tag = 'jwt_blacklist';

    /**
     * Whether the store uses direct plain-key reads (any mode).
     *
     * Any-mode tags are write/index/flush only: writes go through tags()
     * to record the invalidation index, while reads and per-key deletes
     * use the plain key.
     */
    protected bool $directKeyMode;

    /**
     * Constructor.
     */
    public function __construct(
        protected CacheContract $cache
    ) {
        $store = $cache->getStore();

        $this->directKeyMode = $store instanceof TaggableStore
            && $store->supportsTags()
            && $store->getTagMode()->supportsDirectGet();
    }

    /**
     * Add a new item into storage.
     */
    public function add(string $key, mixed $value, int $minutes): void
    {
        /* @phpstan-ignore-next-line */
        $this->cache->tags([$this->tag])->put($this->storageKey($key), $value, $minutes * 60);
    }

    /**
     * Add a new item into storage forever.
     */
    public function forever(string $key, mixed $value): void
    {
        /* @phpstan-ignore-next-line */
        $this->cache->tags([$this->tag])->forever($this->storageKey($key), $value);
    }

    /**
     * Get an item from storage.
     */
    public function get(string $key): mixed
    {
        if ($this->directKeyMode) {
            return $this->cache->get($this->storageKey($key));
        }

        /* @phpstan-ignore-next-line */
        return $this->cache->tags([$this->tag])->get($key);
    }

    /**
     * Remove an item from storage.
     */
    public function destroy(string $key): bool
    {
        if ($this->directKeyMode) {
            return $this->cache->forget($this->storageKey($key));
        }

        /* @phpstan-ignore-next-line */
        return $this->cache->tags([$this->tag])->forget($key);
    }

    /**
     * Remove all items associated with the tag.
     */
    public function flush(): void
    {
        /* @phpstan-ignore-next-line */
        $this->cache->tags([$this->tag])->flush();
    }

    /**
     * Get the storage key for a logical blacklist key.
     */
    protected function storageKey(string $key): string
    {
        return $this->directKeyMode ? self::DIRECT_KEY_PREFIX . $key : $key;
    }
}
```

Constructor probe note: the probe follows the honest ordering (type → capability → mode) because the provider gate (below) only enforces `supportsTags()` when the blacklist is enabled — with the blacklist disabled, the storage can be constructed over any store, including a `TaggableStore` whose `getTagMode()` throws (an invalid stack composition). `getTagMode()` is never a probe (D5); `supportsTags()` guards it here exactly as in the auth gate and `Repository::tags()`. `storageKey()` applies the prefix only in direct-key mode; the tag name is never prefixed.

**8.2 `JwtServiceProvider::cacheStoreForJwtBlacklist()`** — the `supportsTags()` gate is now composition-aware; only the message changes:

```php
        if ($blacklistEnabled && ! $repository->supportsTags()) {
            throw new RuntimeException(
                'The JWT blacklist requires a taggable cache store (all-mode or any-mode). '
                . 'Use a taggable store or set a custom jwt.providers.storage.'
            );
        }
```

**Tests**: `tests/Jwt/Storage/TaggedCacheTest.php` — split into all-mode and any-mode cases (store mock returning `TagMode::All` / `TagMode::Any`): all mode preserves current expectations verbatim; any mode expects tagged writes with prefixed keys, plain `get`/`forget` with prefixed keys, tagged flush, and asserts the tag name is unprefixed. Collision isolation: any-mode `get('abc')` reads `jwt_blacklist:abc`, never `abc`. `tests/Jwt/JwtServiceProviderTest.php` — gate accepts a taggable any-mode store and a valid composition; rejects non-taggable; message updated.

---

### Phase 9 — Auth gate ordering

`EloquentUserProvider::ensureTaggableAnyModeStore()` — probe order: type → capability → mode:

```php
    protected function ensureTaggableAnyModeStore(CacheRepository $cache): void
    {
        $store = $cache->getStore();

        if (! $store instanceof TaggableStore || ! $store->supportsTags()) {
            throw new InvalidArgumentException(sprintf(
                'Auth user caching tags require a store that supports tags; got [%s]. See the auth cache documentation for supported stores.',
                $store::class,
            ));
        }

        if ($store->getTagMode() !== TagMode::Any) {
            throw new InvalidArgumentException(sprintf(
                'Auth user caching tags require a store configured in TagMode::Any; got [%s] in mode [%s]. Configure a Redis store with tag_mode=any (or a stack over one) for auth caching.',
                $store::class,
                $store->getTagMode()->value,
            ));
        }
    }
```

Update the method docblock's rationale bullets to stay accurate. No whitelist change (`StackStore::class` already present). Keep the phpstan-ignore at the `tags()` call site with its existing comment.

**Tests**: extend the existing auth cache tests: a valid mock any-mode stack composition passes the gate; an invalid stack (supportsTags false) is rejected by the capability check without `getTagMode()` being called (expectation ordering proves it).

---

### Phase 10 — Documentation

All edits with `Edit` (never rewrite files). Locations verified; re-grep before editing.

1. **`src/boost/docs/cache.md` tags support note (line ~531)**: replace the note with: tags supported by `redis`, `array`, `failover`, `null`, and `stack` (any-mode compositions only — see Tagged Cache Stacks); not supported by `file`, `database`, `swoole`, `session`, or `memo`.
2. **`src/boost/docs/cache.md` — new "Tagged Cache Stacks" section** (anchor `tagged-cache-stacks`, placed after "Any Tag Mode", added to the TOC): covers — any-mode-only semantics (writes/flush through `tags()`, reads plain); the composition rule with a valid and an invalid example config and the resurrection rationale; the bounded-staleness tradeoff after tag flush (per-node L1 serves stale up to its TTL); **strong guidance** that non-taggable microcache layers should carry a short `ttl` override, and what happens without one (staleness bounded only by item TTL); `supportsTags()` as the probe.
3. **`src/boost/docs/cache.md` — Any Tag Mode section**: add the plain-write contract paragraph, precise about which paths sync metadata: tag membership persists until the key is deleted or re-tagged; plain `forget()` removes membership; a finite-TTL `Cache::touch($key, $ttl)` on an any-mode Redis store keeps tag metadata in sync; `touch($key, null)`, plain `put()`, and plain `forever()` are plain rewrites that do not re-tag or re-sync tag TTL metadata — change a tagged item's TTL or tags by writing through `tags()`. Add: tagged `touch()` throws. The Tagged Cache Stacks section states the stack-specific boundary: direct stack `touch()` rewrites records through plain layer writes, so for tagged stack items use a tagged re-put when tag metadata must stay authoritative.
4. **`src/boost/docs/cache.md` flushLocks note (line ~768)**: add `stack` (delegates to its bottom layer; supported when the bottom layer supports flushing locks). Mention stack lock delegation in the Building Cache Stacks section (one paragraph: locks and lock helpers work on stacks by bottom-layer delegation; bottom layer must be lock-capable).
5. **`src/boost/docs/authentication.md` (line ~300)**: update "Redis is the stock store that supports configurable tag modes" guidance to include any-mode stacks as valid auth-tag stores.
6. **`src/foundation/config/auth.php` + `src/testbench/hypervel/config/auth.php`**: update the cache tags comment (currently "Requires a TaggableStore configured in TagMode::Any (e.g. a Redis store with tag_mode=any)") to mention stack compositions; revisit the "only the outer store is validated" caveat — it remains true for the non-tags store whitelist, but tags now validate the composition; reword so both facts are stated accurately.
7. **JWT docs**: `grep -rn 'blacklist' src/boost/docs/` — update any JWT blacklist storage docs to describe both modes and add the explicit security caveat: on a stack (or any store with a node-local tier), a revoked token may still validate for up to the L1 TTL on other nodes; choose blacklist stores accordingly. If no doc file covers JWT blacklist storage, add the caveat to the jwt config comment above `blacklist_enabled` instead.
8. **`docs/todo.md`**: add under a Cache heading (create it in alphabetical position if absent): an entry for opt-in metadata-aware plain `put()`/`forever()` on any-mode Redis stores — plain value-rewrites deliberately don't re-sync tag metadata TTLs (hot-path cost; the contract is documented in cache.md), and a future opt-in could close that for stores willing to pay it. Write the entry in the file's existing style.

### Phase 11 — Full verification

1. `composer test:parallel` from the repo root — full suite green. Investigate all failures per AGENTS.md rules (straightforward fixes proceed; anything behavioral stops for approval).
2. `./vendor/bin/phpstan` — zero new errors; confirm the two removed ignores stay removed.
3. `./vendor/bin/php-cs-fixer fix` — run without flags; commit-ready formatting.
4. Grep sweeps (must all come back clean):
   - `grep -rn 'method_exists.*tags' src/` — no capability probing by method_exists remains.
   - `grep -rn 'uniqid' src/cache/src/` — only `VersionedTagSet`.
   - `grep -rn 'Key differences from AllTagSet' src/` — gone.
   - `grep -rn 'new TaggedCache(' src/cache tests/Cache` — none (abstract). (`src/jwt` legitimately matches on its own `JWT\Storage\TaggedCache` — different class.)
   - `grep -rn 'instanceof RedisStore' src/jwt src/auth` — none.

## Comment Guidance

Required comments (non-obvious WHY only — all already embedded in the snippets above):

- `AnyModeTaggedCache` class docblock: the any-mode contract (single source of truth for it).
- `AnyTag\Forget` / `AnyTag\Touch` / `AllTag\Touch` class docblocks: why metadata participates (drift/reuse failure modes) and, for `Forget`, why the registry is untouched.
- `StackStore::validateTagComposition()`: the resurrection rationale.
- `StackStore` lock methods: why locks never touch cache tiers.
- `StackStore::putToStoreTagged()`: why the proxy clamp is replicated.
- `StackTagSet::flush()`: why upper layers are not flushed.
- `StackTaggedCache` class docblock + `remember()`: plain-read/tagged-write shape.
- `TaggedCache::clear()`: one line — PSR clear scope is the tag set.
- `JWT\Storage\TaggedCache`: prefix rationale and direct-key mode explanation.
- Cluster branches: keep the existing style of slot commentary (mirror `AnyTag\Put`).

Forbidden: comments narrating the refactor ("moved from TaggedCache", "was previously..."), annotations on routine type modernizations, restating method names, framework-divergence notes. Docblocks are Laravel-style title-first imperative; no `@param`/`@return` where the signature already says it. No `Boot-only.`/`Tests only.` warnings are needed on any method in this plan (none of the new methods mutate worker-lifetime state outside construction; `RedisStore::setTagMode()` already carries its warning).

## Test Plan Summary

Ordered by phase; every file run individually right after it's written or updated (`./vendor/bin/phpunit --no-progress <path>`).

| Phase | File | Proves |
|---|---|---|
| 1+2 | `tests/Cache/CacheTaggedCacheTest.php` (update) | Generic path behavior identical; `clear()` scoped to tags; instanceof `TaggedCache` holds |
| 1+2 | `tests/Cache/Redis/AnyTagSetTest.php`, `AllTagSetTest.php` (update) | Hierarchy split; walkers; deleted neutralizers gone |
| 1+2 | `tests/Cache/Redis/AnyTaggedCacheTest.php` (update) | Inherited throw contract incl. `touch()` + PSR/ArrayAccess aliases; `clear()`; zero-TTL put deletes |
| 1+2 | `tests/Cache/Redis/AllTaggedCacheTest.php` (update) | `clear()` via tagged flush |
| 3 | `tests/Cache/CacheRepositoryTest.php` (update) | `supportsTags()` probe truth table; `tags()` throws |
| 4 | `tests/Cache/Redis/RedisStoreTest.php` (update) | `touch()`/`forget()` mode routing |
| 4 | `tests/Cache/Redis/AllTaggedCacheTest.php` (update) | touch override routing incl. `touch(null)` → forever |
| 4 | Integration: touch cases in the tagged-operations suite | Score sync both directions; any-mode metadata extension; prune survival |
| 5 | Integration: forget cases | Metadata cleanup; key-reuse proof; untagged forget unchanged |
| 6 | `tests/Cache/CacheStackStoreTagsTest.php` (new) | Composition truth table; probe honesty; write mechanics with clamps; rollback; increment; remember; inherited contract |
| 6 | `tests/Cache/CacheStackStoreTest.php` (verify) | Raw-store constructions still pass via proxy normalization |
| 6 | `tests/Integration/Cache/Redis/StackTaggedCacheIntegrationTest.php` (new) | End-to-end write/read/backfill/flush/staleness/no-resurrection |
| 7 | `tests/Cache/CacheStackStoreLocksTest.php` (new) | Delegation, throws, probe honesty |
| 7 | `tests/Cache/FunnelUnsupportedStoresTest.php` (update) | Stack lock-gap assertion replaced by delegation coverage |
| 8 | `tests/Jwt/Storage/TaggedCacheTest.php` (update) | Both modes; prefix isolation |
| 8 | `tests/Jwt/JwtServiceProviderTest.php` (update) | Gate behavior + message |
| 9 | Auth cache tests (update) | Gate ordering; stack acceptance |
| 11 | `composer test:parallel` | Whole suite |

Unit tests use Mockery (`use Mockery as m;`) per repo convention; integration tests use `InteractsWithRedis` and live in `tests/Integration/Cache/Redis/` matching the existing suites' structure (read `TaggedOperationsIntegrationTest.php` before writing new integration files and mirror its setup/teardown and store-config helpers). All test classes extend `Hypervel\Tests\TestCase` or the appropriate integration base already used by sibling files — never raw PHPUnit.

## Execution Rules

- Work from the components repo root. One file at a time. Edit, never rewrite existing files.
- Before creating each new operation class, read its op-family siblings in full (`AnyTag/Put.php`, `AnyTag/Increment.php`, `AllTag/Put.php`, `AllTag/FlushStale.php`, plain `Put.php`/`Forget.php`) and match conventions exactly — including how the client exposes `HSETEX`/`HEXPIRE` (verify the `hexpire` call signature against how `Put.php` invokes `hsetex` and against `RedisConnection`; if it differs from this plan's snippet, follow the client's actual API and note it).
- Before editing each existing class, read it in full.
- Line numbers in this plan are anchors, not gospel — grep before editing.
- Any test failure that isn't a straightforward mechanical fix: stop and report root cause with a recommended fix.
- If any verified fact in this plan turns out to be wrong at implementation time, stop and report before proceeding — do not silently adapt.
