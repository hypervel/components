# Pooled I/O and Resource Ownership Remediation Plan

## Scope and outcome

This plan resolves audit findings 1, 10–13, 22, 27–29, 81, and 155, plus the directly related contextual-attribute lifetime defect found while tracing resource ownership through the container and router.

The final architecture must:

- derive cheap, complete, process-local pool identities without merging behaviorally different resources;
- keep filesystem streams and pooled leases alive for exactly the caller-owned resource lifetime;
- generate scoped local URLs through the configured route that can actually serve the file;
- preserve Laravel filesystem, mail, and authentication APIs unless a verified defect requires otherwise;
- make deadline expiry local to one gRPC operation while keeping real transport failures connection-fatal;
- reject ambiguous or unsafe gRPC endpoint and TLS configuration before native I/O;
- resolve cached authentication services through the current execution's database connection;
- scope unbound consumers of execution-local contextual attributes through the container's existing scoped cache;
- retain the existing MySQL pooled reset behavior that already satisfies finding 81.

No generic connection registry, resource graph, route registry, proxy layer, retry system, or new pool lifecycle is introduced. Hot-path work is limited to cheap hashing, one `instanceof` branch at database-operation boundaries, and the scoped lookup already used by the container.

## Verified design constraints

1. Explicit pool fingerprints remain authoritative.
2. Whole-driver filesystem fingerprints include every normalized setting that changes adapter behavior, plus logical and route ownership that is not present in the config itself.
3. Built-in S3 and GCS drivers continue pooling their clients by client configuration; custom creators named `s3` or `gcs` use whole-driver identity like every other custom driver.
4. Bounded `readStream()` ranges return an ordinary PHP resource. Closing that resource closes the underlying stream and releases any borrowed filesystem lease.
5. Local signed URLs are owned by the nearest named disk in a scoped chain whose own configuration enables serving. Anonymous on-demand layers cannot own routes, but may use a named served ancestor's registered route.
6. Laravel's public `LocalFilesystemAdapter::diskName()` seam remains truthful and available. Route ownership is separate state because “unset” and “explicitly no owner” have different meanings.
7. The default Symfony mail transport is poolable, but on-demand transports remain direct unless explicitly opted in.
8. A deadline known to be expired before native gRPC I/O is an operation result, not a connection failure. Native send/write failures remain connection-fatal.
9. First-class gRPC TLS options own all overlapping native Swoole keys.
10. Laravel-compatible authentication constructors keep accepting a concrete connection. Framework-created cached services receive the resolver and connection name instead.
11. Execution-derived scope applies only to direct, unbound consumers. Explicit bindings, explicit class lifetime attributes, `SelfBuilding`, `Transient`, and parameterized builds retain their existing contracts.
12. The existing MySQL reset path already clears `lastInsertId`; finding 81 requires no production change.

## 1. Use a faster pool fingerprint digest

Update `src/object-pool/src/PoolFingerprint.php` so `fromConfig()` and `fromExplicit()` use `xxh128` over their existing inputs while retaining the existing `auto:` and `explicit:` output prefixes. Keep canonical map ordering, scalar typing, list ordering, and explicit/config domain separation unchanged.

This data is developer-supplied, non-adversarial, process-local, and never persisted. A seed or cryptographic hash provides no useful protection here.

Update `tests/ObjectPool/PoolFingerprintTest.php` to pin:

- deterministic `xxh128` output with a 32-character hexadecimal digest after the existing domain prefix;
- map-order normalization and list-order sensitivity;
- scalar-type and explicit/config domain separation;
- the exact digest algorithm without duplicating the canonicalization implementation.

## 2. Make filesystem pool identity behavior-complete

Update:

- `src/filesystem/src/FilesystemManager.php`;
- `tests/Filesystem/FilesystemManagerTest.php`;
- `src/docs/pools.md`.

Remove the S3/GCS special cases from whole-driver fingerprint construction. Built-in S3/GCS clients return through their client pools before that path; retaining these branches only gives custom creators incomplete identities that omit bucket, root, name, and other adapter behavior.

When no explicit fingerprint is supplied, whole-driver identity is derived from:

- the complete normalized disk configuration excluding `pool`;
- the nullable configured disk name, without substituting the internal `ondemand` label;
- the resolved local serving-route owner and scoped URL prefix when those values are baked into the adapter.

The fingerprint input must distinguish a configured disk literally named `ondemand` from an anonymous `Storage::build()` disk, and distinguish scoped adapters that share storage settings but generate different routed paths.

Retain client-only identities for the built-in S3 and GCS client pools. Retain explicit fingerprint precedence for all drivers.

Update the canonical pool documentation to say that whole-driver identity uses the nullable logical name plus any serving owner/prefix that changes constructed adapter behavior. Widen its explicit-fingerprint warning accordingly: matching fingerprints assert complete construction equivalence, including route behavior.

Add coverage for:

- custom creators named `s3` or `gcs` with matching credentials but different bucket/root/name;
- configured `ondemand` versus anonymous byte-identical local configuration;
- byte-identical scoped storage settings and logical names whose inline versus named parents produce different route ownership;
- anonymous scoped builds that differ only in whether serving was requested;
- explicit fingerprint override behavior.

## 3. Return correctly bounded filesystem stream resources

Update:

- `src/filesystem/src/FilesystemAdapter.php`;
- `src/filesystem/composer.json`;
- `tests/Filesystem/FilesystemAdapterTest.php`;
- `tests/Filesystem/FilesystemPoolProxyTest.php`;
- `tests/Filesystem/PackageMetadataTest.php`;
- `tests/Sentry/Features/StorageIntegrationTest.php`.

Add a direct `guzzlehttp/psr7` dependency because the filesystem component will directly construct `LimitStream` and use `StreamWrapper`.

Preserve the current range normalization contract:

| Range | Result |
| --- | --- |
| `(start, end)` | inclusive length `end - start + 1` |
| `(null, suffix)` | start at `max(0, size - suffix)` and bound to suffix length |
| `(start, null)` | position the raw stream and leave it open-ended |
| `(null, null)` | return the raw stream unchanged |

Position seekable resources with `fseek()`. Retain the controlled discard loop for non-seekable resources so short and stalled reads are handled correctly. Once positioned, wrap only bounded ranges:

```php
$stream = Utils::streamFor($resource);
$limited = new LimitStream($stream, $length, $stream->tell());

return StreamWrapper::getResource($limited);
```

Pass the achieved current offset so `LimitStream` does not attempt another seek. Keep the PSR streams local; ownership is transferred through the returned PHP stream wrapper. Closing that wrapper must close the underlying resource and release an attached filesystem-pool lease.

Any positioning or wrapping failure must close the raw stream, create `UnableToReadFile::fromLocation(..., previous: $exception)`, and follow the adapter's existing `throw`/`report` policy. The non-throwing, non-reporting path returns `null`.

`LimitStream` requires `tell()` while enforcing its bound. Pin a non-seekable but tell-capable source, such as a socket-pair stream, rather than claiming support for a resource whose position cannot be observed.

Cover bounded ranges beginning at zero, single-byte ranges, ordinary inclusive ranges, suffixes larger than the stream, open-ended ranges, full-range raw identity, seekable and non-seekable/tell-capable streams, stalled non-seekable streams, failure policy, close propagation, and exact pooled-lease release on wrapper close. Flip Sentry's existing local-storage range assertion from the unbounded remainder to the requested inclusive bytes while preserving its instrumentation assertion. Do not assert `fstat()` equivalence because a PSR wrapper may not know the underlying size.

## 4. Separate local disk identity from serving-route ownership

Update:

- `src/filesystem/src/FilesystemManager.php`;
- `src/filesystem/src/LocalFilesystemAdapter.php`;
- `tests/Filesystem/FilesystemManagerTest.php`;
- `tests/Filesystem/FilesystemAdapterTest.php`;
- `tests/Integration/Filesystem/ServeFileTest.php`;
- `tests/Integration/Filesystem/ReceiveFileTest.php` and focused service-provider coverage where appropriate;
- `src/filesystem/README.md`;
- `src/docs/filesystem.md`.

Keep `diskName()` unchanged. Add explicit serving-route state to the adapter:

- a boolean indicating whether the manager configured route ownership;
- a nullable configured owner name;
- a normalized URL prefix composed with `/`.

The adapter exposes one Laravel-style fluent setter:

```php
public function servingRoute(?string $disk, string $prefix = ''): static;
```

Direct Laravel-style construction does not call it and therefore continues using the disk name as the fallback owner. The disk property remains nullable, matching Laravel's uninitialized `null` state: before `diskName()` is called, capability probes return `false` and temporary URL methods retain their generic unsupported-operation errors. Manager-built adapters always call `servingRoute()`, including an explicit `null` owner for anonymous builds.

Keep `shouldServeSignedUrls` meaning “serving was requested”, independently of whether the manager found a usable owner. Capability requires a callback, or all three of: serving requested, a URL resolver, and a non-null effective owner. If serving was requested but manager construction explicitly found no owner, `temporaryUrl()` and `temporaryUploadUrl()` throw the precise no-registered-route-owner message. A disk on which serving was never requested retains Laravel's generic unsupported message.

Do not add parameters to the public `createLocalDriver()` extension seam. A single private construction-descriptor resolver produces route metadata for every driver configuration before whole-driver pool identity is constructed, not only for scoped drivers. It starts with the requested disk/configuration as layer zero. A non-scoped configuration returns immediately with zero scoped layers: a configured served disk owns its named route, while an anonymous build has an explicitly absent owner even when it requests serving. This keeps configured, anonymous, direct, and scoped local construction on one ownership path.

Apply the resolved metadata inside the whole-driver resolver closure immediately after each pooled object is created. Apply the same helper immediately after direct/custom driver creation, and make it a no-op unless the result is a `LocalFilesystemAdapter`. Route metadata is construction state, not borrow state: `FilesystemPoolProxy::configureBorrowed()` and `ClientPooledFilesystem` require no route changes and pay no per-borrow type check.

Replace the Hypervel-original `expandScopedConfig()` / `expandScopedConfigRecursively()` pair with that private descriptor resolver. It walks each configured or inline scoped layer once without mutating a parent before visiting it, and returns the effective storage configuration, serving-route owner, requested-serving fact, and accumulated URL prefix. Preserve all five existing scoped-configuration validations and their current messages: missing `disk`, missing `prefix`, invalid `disk` type, invalid `prefix` type, and a named-disk cycle. A missing named parent reports that followed parent name rather than the outer scoped disk. Record every original scoped prefix and compose the final storage prefix once with the concrete base disk's configured directory separator. Compose route prefixes independently with `/`; custom creators still receive only the ordinary effective configuration.

Carry the first outer non-null scoped override for `visibility`, `throw`, `report`, `read-only`, and `pool`, including explicit `false` values. Do not turn scoped configuration into a generic overlay: base construction settings such as `root`, `directory_visibility`, and `permissions` remain owned by the base disk. Do not carry a scoped record's `url` into the collapsed adapter. The raw `url` registers that scoped disk's route, while the collapsed adapter retains the base URL and the complete effective storage prefix so ordinary `url()` paths are not prefixed twice.

Scoped `pool` options apply to the resulting view. Built-in S3 and GCS scopes still share their base client's construction identity, so their pool options must match every other view of that client; the existing pool-definition mismatch exception rejects conflicting options instead of silently discarding the scoped options. Strip `pool` before constructing every adapter or storing the public configuration on a pooled proxy. The pool definition owns that metadata, and `getConfig()` must return the same construction-facing shape for direct, whole-driver-pooled, and client-pooled disks.

Removing `expandScopedConfig()` is deliberate: it has one repository caller, no test or documentation contract, and no Laravel counterpart. More importantly, a config-only override cannot participate in route metadata, so retaining it as a partial extension seam would permit storage and URL prefix derivation to diverge. `createScopedDriver()` remains the public Laravel-compatible scoped-construction customization point.

`createScopedDriver()` consumes the descriptor through `resolveWithLogicalName()` rather than routing back through public `build()`. This intentionally stops a userland `build()` override from intercepting internal scoped construction; no repository implementation relies on that reach, and preserving it would require either widening a public signature with internal metadata or carrying mutable handoff state on the worker singleton. Direct calls to `Storage::build()` retain their public behavior and enter the same descriptor resolver normally.

Resolve route ownership by traversing the scoped chain from the outer disk inward:

1. If the current configured disk's own record has `serve === true`, it is the owner and no prefixes above it remain.
2. Otherwise, accumulate that scoped layer's prefix and continue to its configured parent.
3. The first named configured parent whose own record has `serve === true` is the owner.
4. Inline and anonymous layers cannot own a registered route, but they may prefix paths into a named served ancestor's route.
5. If no owner exists, configure the adapter with an explicitly absent owner.

The accumulated prefix contains only scoped layers above the owner because the owner's adapter already applies its own storage prefix. Nested prefixes are normalized as URL paths, never with the platform directory separator.

Both temporary download and temporary upload URLs use the owner route and the prefixed path. User callbacks continue taking precedence. The capability methods follow the requested-serving, resolver, and owner rule above.

Test base configured disks, one and multiple scoped layers, an outer served disk, the nearest served parent, inline and unserved parents, download and upload routes, anonymous and named non-scoped local builds with `serve => true`, anonymous scoped builds using a named served ancestor, callbacks, custom non-local drivers, all five scoped-configuration failures including new coverage for missing `disk` and missing `prefix`, a missing named parent's precise error, anonymous and named inline bases without a driver, and an end-to-end signed request reaching the correctly prefixed file. Vary chain shape through inline parents, parent-owned prefixes, and nested scopes, and assert that the URL path delta below the owner matches the effective storage prefix in every supported serving shape. Pin a two-level named chain's exact signed-route arguments, separator-aware storage-prefix composition on a non-serving chain, scoped `report`, `read-only`, and `pool` overrides, stripped pool metadata on direct, whole-driver-pooled, and client-pooled disks, and conflicting S3/GCS scoped client-pool options.

Replace the now-wrong on-demand-disk sentence in `src/docs/filesystem.md`; do not merely append a qualification. Document that a named on-demand build with serving enabled generates URLs through the configured served disk of that name and therefore needs equivalent storage configuration. Also document that named scoped disks use the nearest served configured route, anonymous layers cannot own routes, and anonymous scoped builds may use a named served ancestor. Explain that visibility on an outer scope does not replace the serving owner's signature policy. A scoped disk that owns its own route must configure a distinct raw `url`; otherwise it and a served parent both default to `/storage` and application boot rejects the collision. That value registers the route and is not copied into the collapsed adapter's base URL. Document positively that base and scoped disks honor `read-only`, and that scoped disks honor `report` and their own pool options, including the client-pool option-matching rule above. Tighten the README's existing construction paragraph so an explicit fingerprint is described as asserting equivalence of all adapter behavior, including route ownership; do not add a new README difference entry for these correctness fixes.

Add one concise action-oriented Filesystem note to `src/docs/porting-from-laravel.md`: unlike Laravel, Hypervel honors `read-only` on scoped disk records, so remove that option from any scoped disk that must accept writes. This warning is warranted because the default non-throwing configuration otherwise changes silently from successful writes to rejected writes.

## 5. Document the filesystem hash default once at each useful boundary

Hypervel's `Filesystem::hash()` defaults to `xxh128`; Laravel defaults to `md5`. This is a deliberate, lasting public output difference.

Add:

- one concise entry under `Differences From Laravel` in `src/filesystem/README.md`;
- one action-oriented sentence in `src/docs/porting-from-laravel.md` telling porters to pass `md5` explicitly when Laravel-compatible digests are required.

Do not add internal fingerprint details or repeat the same explanation elsewhere.

## 6. Pool the default mail transport

Update:

- `src/mail/src/MailManager.php`;
- `tests/Mail/MailManagerTest.php`;
- `src/docs/mail.md`;
- `src/foundation/config/mail.php`.

Add `mail` to the manager's poolable transports. Symfony's default `SendmailTransport` owns an interactive process and is unsafe to share directly across concurrent executions. Reuse the existing transport pool/proxy path; do not change `createMailTransport()` or add a special wrapper.

Rewrite the source comment above the poolable list so it describes persistent connections, mutable clients, interactive processes, and composite transports rather than claiming the entire list consists of API transports.

Verify configured mailers are proxied by default, pool options are accepted, on-demand mail transports remain direct by default and can opt in, and different construction identities remain isolated. Update the supported-transport list and the configuration comment without adding a Laravel-difference entry.

## 7. Keep pre-I/O gRPC deadline expiry local to the operation

Update `src/grpc/src/Client/Connection.php` and `tests/Grpc/ConnectionTest.php`.

In `start()`, calculate the operation timeout after the pending-call collision check but before registering abandonment state. Catch a deadline `RpcException` at that boundary, set the call state status, and return without invoking native send or changing connection health.

In `write()`, calculate the operation timeout before entering the native write failure boundary. If it throws for deadline expiry, set the call status and rethrow while leaving the connection usable. A native send or write failure remains connection-fatal even if the deadline also elapsed while native I/O was running.

Use a mutable clock in tests to prove that expiration at timeout computation skips native I/O, records the deadline result, clears or avoids pending state as appropriate, and permits a later sibling call. Retain regression coverage that real native failures terminate the connection.

## 8. Reserve first-class gRPC TLS settings

Update `src/grpc/src/Client/BaseClient.php` and `tests/Grpc/BaseClientTest.php`.

Reject these raw Swoole settings whenever they appear in the generic settings array:

```text
ssl_verify_peer
ssl_cafile
ssl_cert_file
ssl_key_file
ssl_passphrase
ssl_host_name
```

First-class TLS configuration owns those keys. Validate this before merging native settings so array replacement cannot silently override the typed API. Apply the rejection in plaintext and TLS configurations alike; unrelated native settings remain valid.

## 9. Validate gRPC hostnames without rejecting service discovery names

Update:

- `src/grpc/src/Client/Endpoint.php`;
- `src/grpc/src/Client/BaseClient.php`;
- `tests/Grpc/EndpointTest.php`;
- `tests/Grpc/BaseClientTest.php`.

Replace generic domain filtering with a small label validator:

- lowercase the host as today;
- allow and remove one trailing dot only from the validation copy;
- require non-empty labels of at most 63 bytes and a canonical host of at most 253 bytes;
- allow ASCII letters, digits, `_`, and `-` in labels;
- reject labels beginning or ending with `-`, empty labels, multiple trailing dots, and all other characters;
- when the final label is numeric, accept only a valid complete IPv4 address;
- retain the existing bracketed IPv6 handling.

Preserve the supplied lowercased trailing dot in `Endpoint::$host` and its authority. An absolute DNS name must not be silently changed into a search-list-relative name. When no explicit TLS `server_name` is supplied, remove the optional terminal dot only from the endpoint-derived `ssl_host_name`, because SNI carries a hostname rather than an absolute DNS presentation form. Apply validation consistently to domains and valid IPv4 addresses. Cover label and total boundaries, underscores, service-style names, hyphen placement, invalid characters, empty labels, preserved trailing dots, default SNI normalization, valid IPv4, and numeric lookalikes such as incomplete, oversized, or overlong IPv4 forms.

## 10. Resolve authentication database connections at operation time

Update:

- `src/auth/src/DatabaseUserProvider.php`;
- `src/auth/src/Passwords/DatabaseTokenRepository.php`;
- `src/auth/src/CreatesUserProviders.php`;
- `src/auth/src/Passwords/PasswordBrokerManager.php`;
- the corresponding auth and password repository tests;
- `src/docs/authentication.md`.

Preserve Laravel-compatible constructors by widening only the first parameter and appending an optional connection name after existing parameters:

```php
ConnectionInterface|ConnectionResolverInterface $connection,
// existing parameters remain in their current order
?string $connectionName = null,
```

Framework-created cached services receive the worker-safe `DatabaseManager` resolver and configured connection name. They must not capture `$app->make('db')->connection()` during construction.

Constructor PHPDoc must name the concrete failure: a pooled borrow is returned at coroutine teardown, so an object retained beyond that execution can otherwise issue queries through a connection another coroutine now owns.

Centralize access in a protected `getConnection()` method on `DatabaseUserProvider` and the existing public `getConnection()` method on `DatabaseTokenRepository`. Both use the same body; the provider form is:

```php
protected function getConnection(): ConnectionInterface
{
    if ($this->connection instanceof ConnectionResolverInterface) {
        return $this->connection->connection($this->connectionName);
    }

    return $this->connection;
}
```

Check the resolver first so a userland object implementing both contracts follows the resolver path. Replace every direct operation on the stored property with the accessor. The one predictable `instanceof` at each database operation is negligible and avoids retaining a pooled connection after its owning coroutine ends.

Keep direct concrete connections valid for non-pooled applications, Capsule-style use, and test doubles. Document the resolver form for custom cached database-backed providers without presenting it as a Laravel incompatibility.

Test direct connections, resolver-backed repeated operations, configured/default selection, dual-interface precedence, lazy construction by the framework managers, and reuse across execution teardown with a fresh resolved connection.

Add one concise Database section note to `src/docs/porting-from-laravel.md` for the constructors that genuinely differ from Laravel and require a resolver plus connection name when instantiated directly: `DatabaseStore`, `DatabaseSessionHandler`, `DatabaseQueue`, and `DatabaseBatchRepository`. Do not include the compatible auth constructors.

## 11. Derive scoped lifetime from execution-local contextual attributes

Update:

- `src/contracts/src/Container/ExecutionScopedAttribute.php`;
- `src/container/src/Attributes/Authenticated.php`;
- `src/container/src/Attributes/Cache.php`;
- `src/container/src/Attributes/Context.php`;
- `src/container/src/Attributes/Database.php`;
- `src/container/src/Attributes/RouteParameter.php`;
- `src/container/src/BuildRecipe.php`;
- `src/container/src/Container.php`;
- focused container, contextual-attribute, coroutine-safety, and route-controller-caching tests;
- `src/docs/container.md`.

Add the narrow capability:

```php
interface ExecutionScopedAttribute extends ContextualAttribute
{
    public function isExecutionScoped(): bool;
}
```

Return `true` for `Authenticated`, `Context`, `Database`, and `RouteParameter`; `CurrentUser` inherits the authenticated behavior. `Cache` returns `$this->memo`, because ordinary cache repositories are worker-safe while memo repositories carry execution-local memoized state.

Also correct `Database::resolve()` to return `ConnectionInterface`, matching the resolver contract rather than the concrete connection class.

Compute a boolean `executionScoped` once while creating an unbound class's `BuildRecipe`. Classes implementing `SelfBuilding` or `Transient` are ineligible, so do not instantiate their contextual parameter attributes for lifetime classification. For eligible classes, instantiate only the already-recorded contextual parameter attributes that implement this capability and OR their results. Do not repeat immutable class or marker checks at resolution time.

Keep `getScopedType()` limited to the class-level `Singleton` and `Scoped` attributes it is named and documented to inspect. Attribute-driven binding registration also calls that method, so derived constructor scope must not be folded into it.

Add one private derived-scope helper used by `isShared()` and `isScoped()` only when no explicit class lifetime applies. It must reject non-class strings before requesting a build recipe, reject classes with an explicit `Singleton` or `Scoped` attribute, and otherwise return the recipe's precomputed `executionScoped` flag. This prevents arbitrary container keys from growing the worker-lifetime recipe cache and ensures explicit class lifetimes cannot be replaced by a dynamic constructor attribute. Give `isScoped()` the same early non-class guard as `isShared()` so failed resolutions of arbitrary string keys do not grow its worker-lifetime attribute cache.

`isShared()` and `isScoped()` must retain their existing binding checks and use the existing scoped instance store for a direct unbound consumer classified by that helper. Preserve precedence:

1. explicit binding and instance lifetime;
2. explicit class `Singleton` or `Scoped` attributes;
3. existing `SelfBuilding` and `Transient` behavior;
4. derived execution scope for otherwise unbound classes.

Internal concrete resolution for a binding (`raiseEvents: false`) means the outer binding owns lifetime. Compute that ownership decision inline once at `resolve()` entry so a binding registered later by `getConcreteBindingFromAttributes()` cannot make the read and publication phases disagree. Put `raiseEvents` first so ordinary resolutions do not call another method or inspect lifetime metadata. Implicit lifetimes—auto-singleton and derived execution scope—must neither serve nor capture the inner concrete at the shared-resolution await, scoped-context lookup, auto-singleton lookup, or shared/scoped publication. Explicit bindings, registered instances, and class `Singleton` or `Scoped` attributes continue to apply. Keep auto-singleton publication's existing `raiseEvents` guard. Keep `shouldCoordinateSharedResolution()` unchanged and document why it tests `isScoped()` before `isShared()`: derived-scoped classes are shared in the broad sense but must never create worker-shared coordination.

After the shared-resolution await, check the gated auto-singleton cache before asking whether the abstract is scoped. Auto-singleton and scoped publication are mutually exclusive, every binding or instance registration clears stale caches before changing lifetime, and class-derived scope is immutable. A warmed ordinary auto-singleton therefore returns on its existing map lookup without repeating class lifetime analysis. Keep the explicit `$instances` lookup after scoped context: moving it would change how a concurrent execution with an existing scoped value observes a boot/test-time instance swap and has no hot-path benefit.

This deliberately stops a shared outer binding and a separately resolved unbound concrete from sharing one implicit cached instance. Each container key owns its declared lifetime, matching Laravel's binding boundary and removing resolution-order-dependent aliasing. At most one additional object is constructed when both keys are used.

Parameterized `make()`/`build()` calls continue bypassing shared instance caching. Do not propagate scope transitively through the dependency graph; a longer-lived owner that directly retains a scoped dependency remains a captive-dependency error, consistent with normal scoped services. Attribute-driven `bind()`, `scoped()`, and `singleton()` registration continues to depend only on the explicit class lifetime attributes returned by `getScopedType()`.

`Route::shouldCacheControllerOnRoute()` already asks the container whether the controller is scoped. Verify that a controller with a dynamic constructor attribute is cached in coroutine context rather than the shared `Route` object, without adding router-specific lifetime machinery.

Test dynamic versus static attributes, ordinary versus memo cache, same-execution reuse and cross-execution isolation, direct route controller isolation, explicit lifetime precedence, `SelfBuilding`, `Transient`, parameterized builds, arbitrary non-class lookups without either lifetime-cache growing, unchanged attribute-driven binding registration, single recipe classification, and custom `ConnectionInterface` resolution. For a derived-scope class, assert that both `isShared()` and `isScoped()` report the scoped lifetime consistently, resolution publishes to coroutine context, and the object is absent from the worker-wide instance cache.

Pin the internal binding boundary with plain transient bindings as well as attribute-declared bindings: repeated interface resolutions must not capture the concrete in either implicit cache; a prior direct resolution of an ordinary auto-singleton or a derived-scoped concrete must not change that result; explicit concrete singleton and scoped lifetimes must still apply; and a transient interface resolution in one coroutine must not adopt another coroutine's suspended direct concrete resolution. Use a real coroutine yield for the in-flight coordination regression.

Document the direct-owner rule and custom attribute capability in `src/docs/container.md`. Update examples to type database dependencies against `ConnectionInterface`. Do not add README or porting-guide entries for this transparent correctness fix.

## 12. Confirm the existing MySQL reset contract

Finding 81 is already satisfied: `MySqlConnection::resetForPool()` clears `lastInsertId` after the parent reset, and existing database plus pooled-connection tests cover the second-borrow behavior.

Make no production change. Keep the master audit ledger accurate: the second borrow's `getLastInsertId()` currently throws because no insert ID exists; it does not return `null`.

## 13. Documentation and ledger hygiene

Update only documentation that changes real user decisions:

- filesystem hash default in the filesystem README and porting guide;
- scoped serving ownership, scoped behavior overrides, and anonymous-build behavior in filesystem documentation;
- scoped read-only enforcement in the central porting guide;
- mail transport pooling in mail documentation and configuration comments;
- resolver-backed custom authentication providers in authentication documentation;
- genuinely incompatible direct database constructors in the central porting guide;
- execution-scoped contextual attributes in container documentation.

Do not add correctness fixes, internal pool identities, deadline handling, endpoint validation, or compatible auth constructors to package README difference sections or the porting guide.

Keep the active master remediation ledger's rows 10 and 81 aligned with this plan. Do not edit unrelated or historical focused plans. The generic connection-owned capability item in `docs/todo.md` remains open: none of these concrete fixes needs a universal lifecycle registry, and inventing one here would be speculative machinery.

## Verification

Run focused checks after each coherent slice:

- `PoolFingerprintTest`;
- filesystem adapter, manager, pool proxy, package metadata, Sentry storage integration, and serving integration tests;
- `MailManagerTest`;
- gRPC connection, base client, and endpoint tests;
- authentication provider, password repository, manager, and execution-lifetime tests;
- container contextual-attribute, container lifetime, coroutine-safety, and route-controller-caching tests.

Run package-level static analysis and formatting for every touched package as work progresses. After implementation, run `composer fix`, then perform a source-level self-review that traces public API compatibility, route ownership, resource close propagation, pool lease release, failure policies, coroutine teardown, connection resolution, and all affected hot paths.

Record local before/after measurements outside PHPUnit for representative pool fingerprint inputs and warmed container resolution of an ordinary unbound class. The fingerprint path must improve materially; ordinary static auto-singleton hits must remain effectively unchanged. For the remaining changes, pin the relevant cost structurally rather than with noisy timing thresholds: no buffered range copy, no additional route-time lookup, no additional mail wrapper, no database connection resolution before an auth operation, and no new I/O or lock on container cache hits. Do not add a permanent benchmark harness or production counters for these bounded changes.

## Completion criteria

- Pool identities are fast and behavior-complete.
- Bounded streams cannot expose bytes outside their requested range and release pooled ownership on close.
- Every generated local signed URL names a route that can serve the effective scoped path.
- Default mail transports no longer share one interactive process across executions.
- Pre-I/O deadline expiry cannot terminate healthy sibling gRPC calls.
- Raw TLS settings cannot override first-class configuration.
- Valid service-discovery hostnames are accepted without admitting malformed numeric hosts.
- Cached auth services never retain a coroutine-owned pooled connection.
- Direct consumers of execution-local contextual attributes cannot leak request state through worker-wide auto-singletons.
- Existing Laravel-compatible constructor and adapter extension seams remain intact.
- Documentation records only differences and choices that users genuinely need to act on.
- No stale helper, duplicate lifetime system, workaround layer, or speculative abstraction remains.
