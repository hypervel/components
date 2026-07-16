# Normalize Framework Enum Identifier Contracts

## Scope

Correct the framework-wide mismatch between Laravel-style `UnitEnum|string` public APIs and Hypervel's strict internal string identifiers. A backed enum may legitimately expose an integer value, including `0`; `enum_value()` preserves that integer by design. Several managers, cache operations, queue carriers, and other string-only consumers currently pass that integer into a strict `string` parameter or property, producing a delayed `TypeError`, retaining an invalid value until later execution, or treating `0` as a request for the default configuration.

This is one cross-package work unit owned by the Support identifier contract (`support-02`). It updates every affected owning boundary and consumer together. It does not mark any package's full audit complete.

Affected or revalidated packages are:

- `auth`, `broadcasting`, `bus`, `cache`, `concurrency`, `console`, `container`, `contracts`, `cookie`, `database`, `events`, `filesystem`, `foundation`, `hashing`, `horizon`, `inertia`, `jwt`, `log`, `mail`, `notifications`, `permission`, `pipeline`, `queue`, `redis`, `reverb`, `routing`, `sanctum`, `scout`, `session`, `socialite`, `support`, `telescope`, `testbench`, and `translation`;
- already-completed `events`, plus its completed `bus` and `broadcasting` consumer work, are explicitly reopened for enum-carrier revalidation;
- already-correct enum handling in Auth, CacheManager, LogManager, RateLimiter, and other matching APIs is retained as the reference shape; RedisManager and FilesystemManager already stringify enums but are corrected to preserve Laravel's empty-string fallback.

## Goal

Finish with one unambiguous rule:

> A public enum accepted as the name of a driver, connection, queue, cache key, cookie, route target, or other string identifier is normalized once to its string value or case name at the owning boundary. A public enum used as data keeps its native backed value.

The resulting code must:

- make every declared `UnitEnum|string` identifier API truthful for unit, string-backed, and integer-backed enums;
- preserve the string identifier `"0"` instead of falling back to a default;
- update contracts, facades, fakes, carriers, and concrete managers together;
- retain Laravel-facing method names, behavior, and configuration;
- add no global registry, enum wrapper, cache, context slot, or shared normalization abstraction;
- add no coroutine state or worker-lifetime state;
- leave value-oriented enum APIs unchanged;
- remove tests and comments that currently bless a `TypeError` or delayed invalid state.

## Backing research

### `enum_value()` preserves value-domain semantics

The Support helper intentionally returns a backed enum's native `int|string` value and a unit enum's name. Changing it to always return a string would silently alter validation values, query bindings, model attributes, schema definitions, session/context keys, message groups, JSON output, and other value domains.

Therefore, the helper is not defective and must not be changed. String conversion belongs at the receiver that owns the string-only contract:

```php
if ($name instanceof UnitEnum) {
    $name = (string) enum_value($name);
}
```

For a non-nullable enum-or-string value, the equivalent expression is acceptable:

```php
$key = $key instanceof UnitEnum
    ? (string) enum_value($key)
    : $key;
```

This keeps the common string path to one predictable `instanceof` check and avoids a new helper call or abstraction.

### Default selection must preserve upstream empty-string semantics

Laravel's current enum additions often use `enum_value($name) ?: $default`. Under an integer-backed enum, `0` is a valid identifier but is falsey. Hypervel must normalize it to `"0"` before selecting the default, while retaining the existing behavior for an explicit empty string. At a boundary where Laravel uses `?:`, use an exact null-or-empty comparison:

```php
if ($name instanceof UnitEnum) {
    $name = (string) enum_value($name);
}

$name = $name === null || $name === ''
    ? $this->getDefaultDriver()
    : $name;
```

Enum normalization and default selection are separate decisions. Replace only `?:` default-selection boundaries with exact null-or-empty checks because `?:` drops the normalized identifier `"0"`. Existing `??` boundaries already preserve `"0"` and remain null-only. CacheManager is the sole `??` resolution boundary in the reviewed manager family: `store('')` remains an explicit empty store name and fails normally, while Redis, Filesystem, Support Manager, and the other manager resolution paths retain Laravel's empty-string fallback. MultipleInstanceManager's `purge()` and `forgetInstance()` plus Filesystem's `purge()` remain string-only null-coalescing maintenance boundaries. Broadcast's enum-aware `purge()` likewise retains Laravel's null-only fallback after normalization. Match each owning boundary's upstream operator; do not infer the rule from current Hypervel code because an existing divergence may be the defect.

Do not use `?:`, `empty()`, or another truthiness test for identifier selection. Exact comparisons preserve both identifier `"0"` and the upstream empty-string contract.

### Current upstream surface

The originating Laravel commits identify the intended public surface:

- `9152f353b7` — base Manager driver enums;
- `a5c4aac517` — broadcasting manager enums;
- `69a4acb877` — mail manager enums;
- `45154000d2` — queue connection enums;
- `95891fa4a7` — notification channel/driver enums;
- `7b943ac7eb` — concurrency driver enums;
- `58f015a8fc` — queue route enums;
- `a2a9be1a62` — queue, log, and session default-driver enums;
- `9bfbcee398` — mail default-driver enums.

Those commits are discovery pointers only. The checked-out current Laravel framework and Socialite sources under `examples/laravel/` are the implementation reference, including all later corrections. Current upstream documentation contains no enum-specific guidance for these manager arguments, so there is no prose section to port. Hypervel contracts and facade metadata are the public type documentation that must be kept accurate.

Laravel's current `Cache\Repository::getMultiple()` contains the same numeric-enum key defect described below. Record an upstream-ready report in the final owner handoff; opening an external issue or pull request remains an owner-coordinated action rather than a dependency of the Hypervel fix.

### Complete mechanical audit

The baseline audit inspected every one of the 191 `enum_value()` calls under `src/`, then searched all 438 `UnitEnum|string`-shaped source, contract, and facade surfaces for paths that delegate without calling the helper directly.

The calls divide into these classes:

| Domain | Decision |
|---|---|
| Driver, connection, queue, mailer, channel, store, cache key/tag, cookie name, table name, ability name, and telemetry tag | Normalize enum values to strings at the owning ingress |
| Manager default selection | Normalize first; exact null/empty fallback where upstream uses `?:`, null-only where upstream uses `??` |
| Queue/broadcast/job state stored in typed `?string` properties or later passed to strict string methods | Store the normalized string immediately |
| Validation values, query bindings, model attribute values, schema enum values, JSON/JS values, translation replacement values | Preserve native `int|string` values |
| Session keys and CoroutineContext keys | Preserve PHP array-key semantics, including integer enum values |
| SQS/message groups and other explicitly `int|string` domains | Preserve integer values |
| Carbon timezone helpers (`now()`, `date()`) | Preserve Carbon's intentional integer-offset support |
| Route and middleware strings already formed through concatenation/`implode()` | Retain the existing safe string boundary |
| Sanctum abilities | Preserve stored ability value semantics |
| MultipleInstanceManager `instance()`, Queue's Hypervel-specific pooled `purge()`, and other string-only `?:` boundaries | Keep their documented string-only contracts unchanged; replace truthiness with exact null-or-empty fallback so `"0"` is not lost |
| MultipleInstanceManager `purge()`/`forgetInstance()`, Filesystem `purge()`, and other string-only `??` maintenance boundaries | Keep their documented string-only contracts and null-only fallback unchanged because `??` already preserves `"0"` |
| Broadcast `purge()` | Widen with its current Laravel manager surface, normalize enums, and retain the existing null-only fallback so both enum zero and direct string `"0"` select the same connection |
| Inertia component rendering | Keep its explicit rejection of non-string-backed component enums; this is a deliberate validated boundary |

No call outside the actionable groups below may be changed merely for visual consistency.

## Verified defects and final design

### 1. Manager and public metadata convergence

The base `Hypervel\Support\Manager::driver()` currently rejects enums before its own Laravel-derived resolution logic can run. That affects every inherited manager: Hash, JWT, Foundation maintenance mode, Notifications, Reverb application/server-provider, Session, and Socialite. Socialite and Notifications have matching overrides or aliases that must expose the same contract.

Change the base manager to accept `UnitEnum|string|null`, normalize enum identifiers to strings, and select the default for exactly null or empty string as Laravel does. Then make every manager-specific surface agree:

| Surface | Required change |
|---|---|
| Support Manager | Widen `driver()` and normalize once |
| Broadcasting | Widen/normalize `connection()`, `driver()`, `setDefaultDriver()`, and pooled `purge()` |
| Mail | Widen/normalize `mailer()`, `driver()`, `setDefaultDriver()`, and pooled `purge()` |
| Notifications | Widen `channel()` and inherited `driver()` metadata/contract; normalize through Manager |
| Queue | Widen/normalize `connected()`, `connection()`, `setDefaultDriver()`, and route inputs; keep the Hypervel pooled `purge()` string-only while preserving its empty-string fallback |
| Session | Widen/normalize `setDefaultDriver()`; inherited `driver()` comes from Manager |
| Concurrency | Widen/normalize only `driver()` before delegating to the deliberately string-only MultipleInstanceManager |
| Socialite | Widen/normalize its manager override, contract, fake, and facade; keep `with()` and fake registration string-only as upstream declares |
| Hash, JWT, maintenance mode | Update facade metadata for the widened inherited Manager API |
| Reverb | Revalidate both inherited managers; no separate facade metadata exists |

Mail and Notification fakes must not escape or narrow the production facade contract:

- `MailFake::mailer()` normalizes and records an enum name;
- port the current upstream `MailFake::driver()` alias so `Mail::fake()->driver()` stays inside the fake;
- `NotificationFake::channel()` accepts the same enum union as the factory it replaces;
- `SocialiteFake::driver()` normalizes before its provider lookup and before delegating to the real factory.

Update the corresponding contracts and facade `@method` declarations. Do not widen unrelated methods such as `extend()`, `with()`, `deliverVia()`, `MultipleInstanceManager::instance()`, Queue's pooled `purge()`, or Filesystem's pooled `purge()`.

The normalized identifier must remain valid after delegation to a string-only lower boundary. `MultipleInstanceManager::instance()` and QueueManager's `getName()` and pooled `purge()` therefore use exact null/empty comparisons rather than truthiness. Their public types and existing empty-string behavior remain unchanged; only the incorrect interpretation of `"0"` as absence changes.

### 2. Cache key and tag boundaries

Cache's Laravel-specific contract methods already advertise `UnitEnum|string`, while its six PSR-16 methods deliberately remain inherited from the string-only PSR contract, matching Laravel. The concrete repository and Cache facade expose Laravel's broader enum-aware convenience surface, but Repository, cache events, tagged caches, stack-tagged caches, and Redis tagged caches pass integer enum values into strict string store/operation methods. Existing tests explicitly expect these `TypeError`s. All public cache key and tag boundaries must instead converge on the same string key used by direct string access. Keep the PSR methods inherited on the contract; add precise enum-aware iterable metadata to the concrete multi-key methods and keep the facade metadata aligned with the concrete runtime API.

Normalize keys before strict store, event, lock, limiter, tag, and tagged-operation calls in:

- `Repository`;
- `Events\CacheEvent`;
- `TaggedCache`;
- `StackTaggedCache`;
- `Redis\AnyTaggedCache`;
- `Redis\AllTaggedCache`.

Normalize `FailoverStore::getRaw()` before it delegates to an inner repository because the internal `RawReadable` capability explicitly accepts `UnitEnum|string` and its sibling `MemoizedStore` already honors that contract. Keep `FailoverStore::many()` on the Store contract's list-of-string boundary: Repository normalizes public enum inputs before the store call, and widening a lower-level array contract is neither required nor correct.

`AnyModeTaggedCache` rejects read, existence, forget, pull, and touch operations before it uses their keys. Its concrete Redis any/all implementations own the supported write operations and are the places that must normalize `put`, `add`, `forever`, `increment`, `decrement`, and remember-style identifiers. Verify this complete hierarchy rather than adding unreachable normalization to the throwing methods.

This includes diagnostics in `string()`, `integer()`, `float()`, `boolean()`, and `array()`: an invalid cached value requested through an enum key must still throw the intended `InvalidArgumentException`, not fail while interpolating an enum object.

Do not rebuild `putMany()` input arrays. PHP arrays cannot retain enum objects as keys, and the repository already preserves valid integer array keys across finite and forever writes. Changing this path would add bulk-write work without fixing an enum contract.

#### PSR multi-get numeric-key defect

`Repository::getMultiple()` currently builds an associative defaults array. A normalized integer enum value becomes a numeric PHP array key, which `many()` interprets as a list position; the default value is then read as the cache key. A stored value for enum `1` can therefore return under the wrong key or be replaced by the default.

Resolve the requested identifiers as a list of strings first, then apply the PSR default to the result:

```php
$resolvedKeys = [];

foreach ($keys as $key) {
    $resolvedKeys[] = $key instanceof UnitEnum
        ? (string) enum_value($key)
        : (string) $key;
}

return array_map(
    fn ($value) => $value ?? value($default),
    $this->many($resolvedKeys),
);
```

This preserves the repository's established rule that a missing or cached-null value resolves to the supplied default. It adds no new result wrapper and does not change `many()`'s Laravel-compatible list/associative input API.

The shared `RetrievesMultipleKeys` fallback must cast its reconstructed array key back to `string` before calling a strict store `get()`. PHP coerces numeric-string array keys to integers; without the cast, `ArrayStore` and every other store using the Laravel-derived fallback reject ordinary numeric-string keys. Native Redis, database, stack, Swoole, failover, and memoized multi-read paths were traced and do not repeat this strict-call defect. Numeric-string associative defaults remain subject to PHP's inherent key coercion and Laravel's list/associative heuristic; the enum-capable `getMultiple()` path uses unambiguous list input.

Cache event subclasses with their own scalar-key constructors must retain the base `CacheEvent` contract. Widen `CacheHit`, `KeyWriteFailed`, `KeyWritten`, and `WritingKey` to `UnitEnum|string` and let the base normalize once. Multi-key events retain their array payloads because repository callers already supply normalized key lists and the arrays are observational event data rather than strict string calls.

### 3. Queue, event, bus, and broadcasting carriers

Several methods accept enums but store `enum_value()` directly into `?string` properties or defer the strict call until a job/event runs. Normalize at configuration time so the object is valid as soon as the fluent method returns:

- Bus `Queueable`: connection, queue, chain connection, and chain queue;
- Bus `PendingBatch`: connection and queue options;
- Events `QueuedClosure`: connection and queue;
- Foundation `PendingChain`: connection and queue;
- Events queued-listener dispatch: normalize `viaQueue()`/attribute results before `pushOn()` or `laterOn()`;
- Broadcasting `InteractsWithBroadcasting`: normalize every scalar or array connection member;
- Broadcasting `PendingBroadcast::via()`: pass `null` or a normalized string to user events;
- Broadcasting manager/factory resolution: accept the normalized connection at dispatch time.
- Pipeline transactions: retain the public enum on `withinTransaction()` and rely on the corrected DatabaseManager boundary to resolve the matching string connection, including enum value `0` rather than the default.

Keep message groups as `int|string`; their integer semantics are explicit and valid.

Queue route registration must normalize scalar and array route values when they are registered. Preserve Laravel's route shape: a scalar route means queue-only, so `getConnection()` returns `null` and `getQueue()` returns the scalar. Hypervel currently returns the scalar as both connection and queue on the connection path. Fix that half-port while touching the route contract.

#### Preserve normalized zero identifiers through queue execution

Every lower queue boundary must use null, not truthiness, as the default sentinel. Otherwise an enum value `0` is correctly normalized to `"0"` and then silently replaced later during dispatch, storage, reserve, chain inheritance, command execution, or observation.

Apply the correction coherently across:

- Database, Beanstalkd, Redis, and SQS queue default resolution;
- Redis logical queue names, storage keys, and reserved-job queue state as one read/write transaction;
- SQS `push()` and `later()` payload queue selection independently from `getQueue()`, preserving the existing distinction between an unsuffixed logical payload name and the suffixed queue URL;
- Queueable and ChainedBatch connection/queue inheritance;
- ChainedBatch's direct queue/connection overlay guards when it rebuilds the PendingBatch;
- PendingChain's outer connection/queue guards and first-job inheritance;
- Mailable queued route handoff, passing the already-nullable queue name unchanged;
- Queue listen/work/clear parsing and Horizon clear/supervisor option construction;
- Telescope's observed job queue name.
- Horizon's optional wait-time queue filter.

Do not funnel SQS payload selection through `getQueue()`: that helper also resolves URLs and suffixes and would change serialized logical queue state. Redis must change all three derivations together so a write, reserve/read, and returned job cannot disagree about queue `"0"`.

`ChainedBatch` copies the source `PendingBatch` options verbatim before applying its own queue and connection properties. Preserve that Laravel behavior: a source empty-string option remains empty and resolves to the default at the downstream queue manager/backend boundary. The exact non-empty overlay guards exist to preserve a `"0"` route applied directly to the `ChainedBatch`, while the exact inheritance checks preserve a chained next job's `"0"` and let an empty next-job route inherit. Do not delete or normalize copied source options locally.

### 4. Database, Redis, and filesystem identifiers

Normalize database connection names before parsing, context storage, pool lookup, or direct resolution in:

- `DatabaseManager::connection()`, `purge()`, `disconnect()`, `reconnect()`, `usingConnection()`, and connection-name variant lookup;
- pooled, simple, and testing connection resolvers;
- Eloquent Model and Factory `getConnectionName()` return boundaries;
- `Connection::table()` when the table argument is an enum, without altering Closure or QueryBuilder inputs.

The Model and Factory currently declare `?string` but return an integer for an integer-backed enum. Preserve the public setter union and normalize only when exposing the connection name.

Normalize Redis connection names to strings before config lookup, context-key construction, proxy caching, custom creator lookup, and pool purge.

Revalidate Container's `#[Auth]`, `#[Cache]`, `#[Database]`, `#[Storage]`, and `#[Log]` contextual attributes plus Eloquent's `#[Connection]` attribute as delegated consumers. Auth, Cache, Database, Filesystem, Log, and Model own normalization; do not duplicate it in the attributes. Retain `#[Log]`'s already-correct channel normalization during this overlapping Log work unit rather than changing working code for consistency alone; its name still requires local normalization before `withName()`.

Fix Storage's `fake()` and `persistentFake()` default selection so integer-backed disk `0` becomes disk `"0"` instead of the configured default while empty string still selects the default disk. FilesystemManager's normal `disk()` path already stringifies integer enums but incorrectly treats empty string as explicit; correct it to the same Laravel-compatible exact fallback. Its string-only pooled `purge()` contract remains unchanged.

### 5. Cookie, scheduler, authorization, and permission boundaries

Normalize cookie names in `get()`, `make()`, `queued()`, `hasQueued()`, and `unqueue()` so all queue/request paths use the same string key. Existing tests that expect Symfony's constructor to throw for integer enums become successful interoperability regressions.

Normalization preserves equivalence with the direct string identifier; it does not bypass validation owned by the downstream domain. Symfony rejects the direct cookie name `"0"`, so enum value `0` remains invalid for cookie creation while still resolving the request-side key `"0"`. Regression coverage pairs the direct string and enum-zero creation failures, and uses enum value `1` to prove creation and queue interoperability.

Normalize scheduler queue, connection, cache-store, and enum timezone values before they reach typed state or dispatch callbacks. Preserve `DateTimeZone` objects unchanged. Integer enum timezone `1` becomes string `"1"`, which retains Carbon's established `+01:00` timezone interpretation without widening the event property.

In Foundation's `AuthorizesRequests`, normalize only an enum ability to string before the existing class-name/guessed-ability branch. Otherwise an integer-backed ability is misclassified as an object/class argument and a different inferred ability is checked.

In Permission middleware, pass the original enum into the existing parser instead of unwrapping it into an unsupported integer first. Normalize individual parsed enum names to strings inside the parser. Role and role-or-permission middleware already keep the enum until their parser and are retained.

Permission's role query scope also treats its nullable guard name as an identifier. Use exact null/empty defaulting so an explicit guard named `"0"` is not replaced by the model's default guard while empty string retains the established fallback.

The same nullable guard contract appears in every role-check branch and in the role model's guard-mismatch diagnostic. Use exact null/empty checks in the integer/UID, string, Collection, and all-roles paths so guard `"0"` selects only that guard, empty string retains the established fallback, and the mismatch reports only a non-empty explicitly requested guard. The corresponding HasPermissions paths already use their established null-only selection and remain unchanged.

### 6. Same-family identifier boundaries found by the completeness scan

Two additional public identifier surfaces have the same defect and belong in this transaction:

- Inertia's `ResolvesOnce::as()` stores an integer-backed enum value in a `?string` key property. Cast backed values to strings while retaining unit enum names and ordinary strings. Do not alter component rendering's deliberate string-backed-enum validation or flash-data value semantics.
- Telescope's client-request watcher promises to normalize enum tags to strings but currently retains integer enum values. This is a soft serialized-boundary defect rather than a `TypeError`: Telescope persists tags in a string database column and uses them for string monitoring and filters, so retaining an integer can record or compare the wrong tag. Cast only enum tag values to strings before creating the entry; ordinary string tags remain unchanged.
- Foundation's database testing helper accepts an explicit nullable connection name, then uses truthiness before its table-derived and database-default fallbacks. Use exact null/empty checks at both fallback boundaries so explicit connection `"0"` remains authoritative while retaining the established empty-string fallback.
- Pipeline Hub accepts a nullable named pipeline but currently routes `"0"` to `default`. Use exact null/empty default selection in `pipe()` so `"0"` remains explicit and empty string retains Laravel's default behavior; registration and public types remain unchanged.

These are small owner-boundary corrections, not a reason to broaden the work into a generic enum serialization layer.

### 7. String-zero sibling defects found by the completeness scan

The final truthiness scan found several string-only identifiers that do not accept enums directly but receive the normalized string `"0"`, expose a nullable string API, or read a configured string identifier. Correct these sites without changing their existing empty-string behavior:

- `Migrator::resolveConnection()` must use its nullable argument, including `"0"`, before the migrator's configured connection.
- Session's cache-backed handler and Sanctum's token cache must retain a configured store named `"0"` while preserving their existing null-or-empty fallback.
- Foundation maintenance mode must retain cache store `"0"` while preserving `''` as the configured-default sentinel. `app.maintenance.store` has the string config default `database`; an explicitly non-string value remains a typed configuration error.
- Scout's `--driver`, Database's `--database`, Cache Doctor and Benchmark `--store`, and Foundation's policy `--guard` options are nullable optional values. Preserve the existing fallback for null or an explicitly empty option while accepting `--...=0`. The shared Redis-store detector casts numeric configuration keys to strings, and Benchmark validates its nullable detection result before assigning its non-null store property so the no-store path fails descriptively instead of throwing a property `TypeError`.
- Broadcasting's anonymous event name must retain `as('0')` while preserving the existing class-name fallback for null or empty string.
- Translation's array loader must retain namespace `"0"` in both `load()` and `addMessages()` while preserving `'*'` for null or empty string.
- Permission guard discovery, default selection, role checks, Passport compatibility, and `permission:show --guard=0` must preserve guard `"0"` while retaining the established null/empty fallback. Numeric configuration keys are cast back to strings after collection key extraction.
- Database monitoring must retain `--databases=0`; route conversion must retain an explicit route name `"0"`; and schema index creation plus `dropMorphs(..., '0')` must target the explicit index name rather than deriving another name.
- Testbench's SQLite create/drop commands must retain `--database=0` as the filename `0.sqlite` rather than operating on the default `database.sqlite` file.

These exact null/empty comparisons are intentionally local. A shared falsey-value helper would obscure each domain's sentinel and add abstraction without reducing complexity.

The same scan found a separate Reverb identity defect. Presence user ID `"0"` is currently discarded during subscription, unsubscription, and client-event propagation; `ChannelConnection::data('0')` also returns the complete payload instead of key `"0"`. Normalize stored presence IDs to strings before testing the established empty-string sentinel, use null only at the shared-state absence boundary, preserve ID `"0"` in client rebroadcast/webhook payloads, and let only a null data key request the complete payload. Track this as a Reverb-scoped sibling correction rather than as enum normalization.

## Public API and compatibility

The work is additive and corrective:

- current Laravel manager/route enum inputs become available where Hypervel's strict signatures omitted them;
- existing Hypervel methods that already advertise enum inputs begin honoring the full union;
- method names, return types, configuration keys, driver names, extension callbacks, facade accessors, and normal string behavior remain unchanged;
- no API is removed or renamed;
- no deprecated surface is involved;
- invalid delayed `TypeError`s and falsey-default selection are replaced by the promised behavior.

The only behavior changes are fixes: integer-backed identifiers work as their string representation, `"0"` remains a real identifier through manager, queue, command, chain, test-helper, pipeline, configuration, translation, broadcasting, routing, schema, Testbench SQLite, and presence-channel delegation, scalar queue routes no longer masquerade as connection routes, MailFake retains `driver()` calls, and PSR multi-get no longer confuses a numeric identifier with a list index. Existing null and empty-string behavior remains unchanged at every Laravel-facing boundary, including Redis and Filesystem. CacheManager remains the sole null-only `??` resolution boundary in the reviewed manager family; MultipleInstanceManager purge/forget and Filesystem purge remain string-only null-coalescing maintenance boundaries, while Broadcast purge is enum-aware and retains the same null-only selection rule.

## Performance and coroutine safety

The change introduces no coroutine state, locks, yields, container resolutions, retries, allocations on unrelated requests, or worker-retained data.

- newly widened manager paths add one predictable `instanceof UnitEnum` check to the normal string path;
- lower string-only boundaries replace truthiness with exact comparisons matching their upstream sentinel and add no new call, allocation, lock, or retained state;
- existing enum-aware cache and carrier paths already call `enum_value()`; string casting replaces or completes that normalization rather than adding coordination;
- carrier normalization happens once when a job/event/schedule is configured, not repeatedly during execution;
- cache key conversion is required at each cache operation's existing normalization boundary and does not add I/O or hashing;
- the multi-get fix builds the same order-of-N key/result arrays the method already built;
- requests that do not use these APIs pay nothing.

There is no meaningful hot-path regression and no owner performance stop gate. The inline conditional is preferred over a framework-wide `enum_string()` helper because the helper would add a call, obscure which domains are string-only, and invite incorrect use in value domains.

## Implementation plan

Work one file at a time and preserve upstream method order.

### Contracts and public metadata

1. Widen the Broadcasting, Mail, Notification, Queue, and Socialite factory contracts at the exact manager methods that Laravel declares enum-capable.
2. Update facades for Broadcast, Concurrency, Hash, JWT, Mail, MaintenanceMode, Notification, Queue, Session, Socialite, and any matching concrete metadata discovered by the final search.
3. Re-run the complete `UnitEnum|string` signature search and verify every implementation, fake, facade, and contract agrees.

### Manager families

1. Correct Support Manager first and test its inherited contract with unit, string-backed, integer-backed, and zero-backed enum cases.
2. Correct Broadcasting, Mail, Notifications, Queue, Session, Concurrency, and Socialite without widening adjacent string-only APIs.
3. Correct MailFake, NotificationFake, and SocialiteFake; verify QueueFake already accepts and safely ignores the connection identifier.
4. Revalidate Hash, JWT, maintenance mode, both Reverb managers, Auth, Cache, Filesystem, and Log as inherited or sibling consumers.

### Data carriers and package-local boundaries

1. Correct queue/bus/events/foundation/broadcasting carriers and QueueRoutes.
2. Correct every downstream queue backend, chain, mail, command, Horizon, and Telescope default-selection boundary so normalized zero identifiers survive through execution and observation.
3. Correct Cache Repository/events/tagged implementations, including multi-get and diagnostics.
4. Correct Database manager/resolvers/model/factory/table, Redis manager, and Storage fake helpers.
5. Correct CookieJar, Schedule/ManagesFrequencies, AuthorizesRequests, PermissionMiddleware/role scope, Inertia ResolvesOnce, Telescope client tags, Foundation's database test helper, and Pipeline Hub.
6. Correct the complete downstream string-zero surface in ChainedBatch, Migrator, Horizon, Permission guard discovery/role checks/show command, Session, MaintenanceMode, Sanctum, Scout, Cache Doctor/Benchmark, Database monitor/seed commands, PolicyMakeCommand, AnonymousEvent, ArrayLoader, route conversion, schema index naming, and Testbench SQLite create/drop commands without changing existing empty-string sentinels.
7. Correct the complete Reverb presence identity and ChannelConnection data-key surface as a separately recorded sibling defect.
8. Search every changed symbol across `src/` and `tests/`; remove stale `TypeError` comments and assertions.

## Regression plan

Use enum fixtures that cover all distinct representations:

```php
enum IdentifierUnit
{
    case Primary;
}

enum IdentifierString: string
{
    case Primary = 'primary';
}

enum IdentifierInt: int
{
    case Primary = 1;
    case Zero = 0;
}
```

Tests must assert behavior, not only stored private state. Cover:

- base Manager inheritance and zero-backed default selection, paired with retained empty-string fallback;
- manager-specific contracts, facades where relevant, and fake non-escape behavior;
- Broadcasting scalar/array connections and pending dispatch;
- Queueable, PendingBatch, QueuedClosure, PendingChain, queued-listener dispatch, and queue routes;
- queue backends retain `"0"` consistently across payload creation, storage, reserve/read, returned jobs, and size/pop operations; chain and mailable routing retain an explicit `"0"`; queue/Horizon commands parse it without defaulting;
- cache read/write/forever/increment/decrement/lock/funnel/tag/event paths, typed-value diagnostics, tagged variants, numeric-enum `getMultiple()`, and string interoperability;
- direct `FailoverStore::getRaw()` enum handling and the deliberately string-only store-level `many()` boundary;
- Cookie request, creation, queue lookup, and unqueue paths, including enum/string validity equivalence for cookie name `"0"`;
- scheduled job dispatch inputs, cache mutex store, and timezone;
- database manager/resolver/pool naming, context override, Model/Factory return types, and enum table names;
- Pipeline transaction connection delegation plus representative Container and Eloquent contextual-attribute delegation;
- Redis connection/config/context/purge naming and Filesystem/Storage zero-backed disk handling, paired with their retained empty-string fallback;
- controller authorization and Permission middleware string generation;
- Permission guard discovery, role scopes/checks/mismatch diagnostics, Passport compatibility, `permission:show`, Foundation database assertions, and Pipeline Hub preserve explicit `"0"` identifiers;
- Inertia once keys and Telescope tags, including the stored/monitored string tag value;
- Session, maintenance mode, Sanctum, Scout, database monitoring/seeding, Cache Doctor, policy generation, anonymous broadcasting, routing, schema index creation/removal, Testbench SQLite create/drop commands, and Translation retain identifier `"0"` while preserving their existing null/empty fallback behavior;
- Reverb subscribes, unsubscribes, rebroadcasts, and emits webhooks for presence user ID `"0"`, and ChannelConnection retrieves data key `"0"`;
- unchanged integer semantics for at least representative session/context/message-group/value-domain APIs where a changed shared helper could otherwise regress them.

Prefer existing package test files. Create a focused Support Manager test rather than mixing identifier behavior into the callback-rebinding test. Replace old `TypeError` tests with successful behavior regressions; do not keep both.

Run each changed test file immediately. Then run the complete focused set for every affected package, followed by:

```bash
composer fix
```

## Completeness and review gates

Before implementation review:

1. regenerate every `enum_value()` source hit and classify each against the final string-identifier/value-domain rule;
2. regenerate every `UnitEnum|string` source, contract, facade, and fake hit and trace delegation across the strict boundary;
3. verify no truthiness-based default selection remains on an enum-capable identifier path or string-only lower boundary receiving a normalized enum; compare every replacement with the corresponding upstream operator so exact null/empty and null-only behavior remain intentional;
4. verify no enum-capable method stores raw `enum_value()` into a typed string property or passes it to a strict string parameter;
5. verify contracts, facades, fakes, and concrete implementations agree;
6. verify no value-domain site was stringified for consistency alone;
7. verify current Laravel/Socialite source and originating changed-file surfaces were used as references;
8. verify no prose documentation claims became stale and no unnecessary enum tutorial was added;
9. inspect the complete diff for stale tests/comments, accidental API widening, hot-path costs, and unnecessary abstraction;
10. obtain independent code-review sign-off after `composer fix` is green.

## Rejected alternatives

- Do not change `enum_value()` to always return a string; it would corrupt legitimate integer value domains.
- Do not add `enum_string()`, an identifier value object, a registry, a normalizer service, or a trait; the owning boundary is visible and the inline conversion is smaller and faster.
- Do not widen every string parameter in the framework to `UnitEnum|string`; follow current Laravel APIs or an already-declared Hypervel contract only.
- Do not widen MultipleInstanceManager, Queue pooled purge, Filesystem pooled purge, Socialite `with()`/`fake()`, notification `deliverVia()`, or manager extension keys without upstream/API evidence; correct falsey-zero selection without changing each method's existing empty-string behavior.
- Do not widen Queue or Database Capsule connection arguments, or Schema's connection accessor. Current Laravel documents these convenience wrappers as `string|null`; their untyped runtime acceptance of enums is incidental permissiveness rather than the declared API.
- Do not convert session/context keys, validation values, query bindings, model attributes, message groups, or JSON values to strings.
- Do not preserve delayed `TypeError` tests as compatibility behavior; they prove the current contract breach.
- Do not add fallback retries or catch `TypeError`; normalize before crossing the strict boundary.
- Do not add broad prose documentation for a type-level parity feature that upstream does not separately document; keep contracts and facade metadata accurate.
