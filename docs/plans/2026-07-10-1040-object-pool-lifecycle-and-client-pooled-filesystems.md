# Object-Pool Lifecycle Rebuild and Client-Pooled Cloud Filesystems

Rebuild the object-pool package around immutable pool definitions with fingerprint-checked reuse, explicit object-ownership tracking, a real close/sweep/trim lifecycle, and idle-TTL eviction; switch built-in s3/gcs disks from whole-driver pooling to client-level pooling with per-operation adapter stacks; fix every deferred-result hole in the pool consumers (deleting HTTP client pooling and notification pooling outright — both pooled stateless or container-shared objects); add a dynamic-prefix scoped filesystem decorator pair; and fix the verified bugs cataloged in 1.1 (pooled callback mutators, `path()` traversal, the roundrobin `retry_after` bug, response-stream edge cases, cross-mode pool channels, loose comparison, test hygiene).

Every factual claim below was verified against the installed source; the granularity measurements in 1.2 were reproduced in two independent probe runs with matching results.

Hypervel 0.4 is unreleased. Backward compatibility, churn, and blast radius are irrelevant. The finished codebase must read as if designed this way from the start: no stale code, no dead methods, no compatibility shims, no comments or docs describing removed behavior.

## Part 1 — Research and evidence

### 1.1 Verified bugs in the current design

1. **Second on-demand poolable disk fatals.** `FilesystemManager::build()` resolves every on-demand disk under the fixed name `ondemand` (`src/filesystem/src/FilesystemManager.php:100`). For poolable drivers the pool name becomes `Hypervel\Filesystem\FilesystemManager:ondemand` (`src/object-pool/src/Traits/HasPoolProxy.php:30`), and `PoolManager::create()` throws on duplicates (`src/object-pool/src/PoolManager.php:49`). Two `Storage::build()` calls with s3/gcs configs fatal; so do two `scoped` disks over a poolable parent, since `createScopedDriver()` routes through `build()` (`FilesystemManager.php:369`).
2. **Purging a pooled driver breaks re-resolution.** `FilesystemManager::forgetDisk()`/`purge()` (`:460`, `:476`), `MailManager::purge()`/`forgetMailers()` (`:521`, `:576`), `BroadcastManager::purge()`/`forgetDrivers()` (`:461`, `:523`), and `Manager::forgetDrivers()` (used by `ChannelManager`) drop only the cached wrapper. The pool stays registered, so the next resolution constructs a new pool proxy and `PoolManager::create()` throws.
3. **Callback mutators configure one borrowed instance.** `serveUsing()`, `buildTemporaryUrlsUsing()`, and `buildTemporaryUploadUrlsUsing()` are per-instance setters (`src/filesystem/src/FilesystemAdapter.php:1012-1040`). Through `PoolProxy::__call()` they mutate whichever pooled adapter happened to be borrowed; other pool members and lazily-created future members never receive the callback. The docblocks ("persists on the cached disk adapter") are wrong for pooled disks.
4. **Deferred results outlive their borrow.** `PoolProxy::__call()` releases in `finally` (`src/object-pool/src/PoolProxy.php:53-65`), but:
   - `FilesystemAdapter::readStream()` with `stream_reads` returns a live HTTP stream (`@http => ['stream' => true]`, `vendor/league/flysystem-aws-s3-v3/AwsS3V3Adapter.php:464`; GCS `restOptions => ['stream' => true]`, `src/filesystem/src/GoogleCloudStorageAdapter.php:80`). The stream drains from the released client's connection while other coroutines borrow it.
   - `FilesystemAdapter::response()` opens the stream on the borrowed adapter (`:334`) and returns a streamed `Response` whose output callback reads it during emission — after release. `serve()` and `download()` share the path.
   - `getDriver()`/`getAdapter()`/`getClient()`/`getConfig()` return internals of a released borrow.
   - `ClientPoolProxy::sendAsync()`/`requestAsync()` return `PromiseInterface` after release; `send()`/`request()` with `stream => true` return live bodies (`src/http/src/Client/ClientPoolProxy.php`).
   - `QueuePoolProxy::pop()` returns jobs retaining the borrowed queue's backend client: `BeanstalkdJob` holds `PheanstalkManagerInterface`, `SqsJob` holds `SqsClient` (constructor-promoted, `src/queue/src/Jobs/`). Job `delete()`/`release()`/`bury()` later run on a client concurrently borrowed by other coroutines. `QueueManager::$poolables = ['beanstalkd', 'sqs']` — both affected drivers are poolable.
   - `QueuePoolProxy::setConnectionName(): static` forwards to the borrowed queue and returns it — a return-type violation (the borrowed `Queue` is not the proxy class) and a pooled-object leak.
5. **The recycler cannot reclaim anything meaningful.** `ObjectRecycler` never removes pools from the manager (`src/object-pool/src/ObjectRecycler.php:130-139`); `flushOne()` returns early at the `min_objects` floor (`ObjectPool.php:127`), so the last object — a live client — is retained forever; and with one idle object `floor(0.2 × 1) = 0`, so `TimeStrategy::recycle()` never even checks it (`Strategies/TimeStrategy.php:48`). Dynamic pools would grow without bound.
6. **Pooled HTTP clients share a cookie jar.** `Factory::createClient()` builds every pooled client with `'cookies' => true` (`src/http/src/Client/Factory.php:595`) — one `CookieJar` shared by all borrows of that client, leaking cookies across unrelated requests and coroutines.
7. **First-stack capture in the HTTP factory.** `Factory::resolve()` caches the pool proxy with `??=` and the resolver captures the first request's `HandlerStack` (`Factory.php:581`); `PendingRequest::buildClient()` builds a fresh stack per request (`PendingRequest.php:1345`), so per-request middleware after the first request for a connection is silently dropped.
8. **`FilesystemAdapter::path()` allows traversal.** `path()` is a raw `PathPrefixer` concatenation (`FilesystemAdapter.php:234-237`): `path('../secret')` escapes a local disk's root (and a scoped disk's prefix) when the returned path is used directly. Flysystem normalizes operation paths, but `path()` bypasses Flysystem.
9. **`Channel` splits storage across execution modes.** Both `src/object-pool/src/Channel.php` and `src/pool/src/Channel.php` keep two independent stores — an `EngineChannel` used inside coroutines and an `SplQueue` outside — selected per call via `Coroutine::id() > 0`. An object released outside a coroutine (boot, CLI) is invisible to coroutine borrowers of the same worker-lifetime pool and vice versa, while `currentObjectNumber` still counts it: the pool strands objects and can exhaust forever. Additionally, `SplQueue::shift()` throws on empty, so non-coroutine exhaustion crashes instead of following the timeout/false path.
10. **No ownership or release invariants.** `ObjectPool` never checks that a released object belongs to it or was actually borrowed: a double release enqueues the same object twice (two coroutines then share it) and can block on a full channel; releasing a foreign object corrupts the count; destroying twice drives the count negative; and a factory returning the same instance twice hands one object to two borrowers. The last case is live in-tree: `ChannelManager::createDriver()` resolves `SlackNotificationRouterChannel` from the container, and Hypervel auto-singletons unbound concretes — every pool "creation" returns the same router instance.
11. **`BroadcastPoolProxy::resolveAuthenticatedUser()` leaks callbacks across proxies.** It applies `authenticatedUserCallback` to the borrowed broadcaster only when non-null (`BroadcastPoolProxy.php:32-34`) — with pool convergence, a proxy holding no callback inherits whatever a previous borrower set. (It also reads `$this->pool` directly, which the per-operation-lookup rebuild removes anyway.)
12. **`createRoundrobinTransportOfClass()` overwrites its own config.** The loop reassigns `$config = $this->getConfig($name)` (`MailManager.php:411`), so the composite's `retry_after` is read from the *last child's* config, not the composite's (`:420`). Source bug in code this plan touches.
13. **`FilesystemAdapter::response()` range/stream handling mishandles edge cases.** `if (! $content = fread($stream, $chunkSize)) { continue; }` (`:341`) silently drops a chunk consisting of the string `"0"`, spins on `fread() === false` until `feof()`, and has no bound when an empty read occurs before EOF; range parsing casts un-validated header parts (`bytes=abc-def` becomes `0-0`); an oversized suffix (`bytes=-999` on a 10-byte file) computes a negative start and 416s where RFC 9110 says it means the whole representation (`:395`, `:409`); the `bytes=` gate is case-sensitive where range units are not (`:311`); `If-Range` accepts matching weak ETags even though RFC 9110 requires strong comparison; the emission loop never caps output at the range length — only server-enforced ranges mask that; the method resolves a `Request` from the container even on the `serve()` path that already received one (`:277`); and `readStreamRange()` only actually exists for GCS — the base throws (`:713-716`) and the S3 adapter has no override, so S3/local range responses are broken outright. The new base implementation's non-seekable positioning loop must also reject an empty non-EOF read: subtracting `strlen('')` makes no progress and otherwise recreates the same infinite loop.
14. **Mail transport construction reads beyond the mailer config.** SES/Resend/Cloudflare/Mailgun/Postmark creators pull fallback credentials from `services.*` config (`MailManager.php:272`, `:307`, `:318-322`, `:344`, `:372-373`) — real construction input that a mailer-config-only fingerprint would miss. Failover/roundrobin configs list child *names*, not resolved child configs, so child credential changes would not change a composite fingerprint.
15. **Queue jobs finalize before the backend call.** Concrete jobs run `parent::delete()`/`parent::release()` *before* the pheanstalk/SQS operation (`BeanstalkdJob.php:29-59`) — base-class lease release would return the connection before the backend call that needs it. Pheanstalk dispatches reserve/release/bury/delete through one connection object (verified in the installed `pheanstalk` source), so the reserving connection must be held until the terminal backend call completes.
16. **HTTP cookie typing pins the design.** `PendingRequest::$cookies` is `?CookieJar` and the before-send path assigns `$options['cookies']` into it (`PendingRequest.php:85`, `:227`) — Guzzle's default `cookies => false` would violate the property type, so cookie handling must construct per-request jars deliberately, not just drop the client-level flag.
17. **Sentry returns a failed transport to the pool.** `HttpPoolTransport::send()` catches an unexpected `Throwable` from `$transport->send()` and releases the transport back to the pool (`HttpPoolTransport.php:51-61`) — a transport that threw mid-send may hold failed or pending mutable state and is handed to the next borrower as healthy.
18. **`Pool::flushOne()` strands capacity when a health check throws.** The unforced path evaluates `$conn->check()` outside its try/catch (`src/pool/src/Pool.php:115`); a throwing check escapes with the connection popped from the channel, neither closed nor requeued, and `currentConnections` never decremented — a permanent ghost consuming a creation slot (`getConnection()` gates on `currentConnections < max_connections`, `Pool.php:194`). The exception also propagates into `ConstantFrequency`'s timer callback (`ConstantFrequency.php:34`). Latent today — all four in-tree `check()` implementations are pure flag/timestamp logic (`pool/src/Connection.php:82`, `KeepaliveConnection.php:69` via a bool property, `redis/src/RedisConnection.php:468`, `database/src/Pool/PooledConnection.php:157`) and `ConstantFrequency` has no in-tree production wiring (DB/redis pools use `Frequency`, whose low-frequency path calls `flush()`, which never calls `check()`) — but `ConnectionInterface::check()` is public contract surface and a heartbeat implementation may throw.
19. **Timer health checks reset idle clocks.** `check()` sets `lastUseTime = $now` on success (`pool/src/Connection.php:95`; `RedisConnection.php:490` and `PooledConnection.php:182` in their `availableForReuse` branches), so a `ConstantFrequency` probe that pops, passes, and requeues a connection pushes its idle deadline forward on every rotation: at the 10s tick and the 60s default `max_idle_time`, a pool holding five or fewer idle connections never idle-expires any of them. Same bug class as the object-pool recycler clock-reset (1.3: maintenance requeues preserve timestamps).
20. **The nullable response disposition is an unusable upstream-derived API.** `response()` advertises `?string $disposition = 'inline'` (`FilesystemAdapter.php:282`, mirrored by the `Storage` facade annotation), but when no `Content-Disposition` header is supplied the value goes straight into Symfony's `HeaderUtils::makeDisposition(string $disposition, ...)` (`:293`), which is non-nullable and accepts only `inline`/`attachment` (`vendor/symfony/http-foundation/HeaderUtils.php:165-168`). Null is a deterministic `TypeError` under strict types; upstream Laravel has the same defect (`string|null` docblock at `examples/laravel/framework/.../FilesystemAdapter.php:336`, unconditional pass at `:352` — null coerces to `''` and Symfony rejects it), and neither tree has a null-disposition test. Callers wanting custom or absent disposition already have full control through an explicit `Content-Disposition` header, which bypasses generation (`:290`).
21. **Temporary-URL callbacks reject most valid Closure kinds.** All four temporary-URL invocation paths bind unconditionally — `$callback->bindTo($this, static::class)(...)` in `FilesystemAdapter::temporaryUrl()`/`temporaryUploadUrl()` and duplicated in both `LocalFilesystemAdapter` overrides — but the mutators accept any `Closure`, and only anonymous non-static closures survive that bind (verified by probe on PHP 8.4: static anonymous → "Cannot bind an instance to a static closure"; first-class named/internal function → "Cannot rebind scope of closure created from function"; first-class instance method → "Cannot bind method"; first-class static method → static rejection). Upstream Laravel has the identical four paths (`examples/laravel/framework/.../FilesystemAdapter.php:858`, `:883`). Existing tests set static callbacks but only assert `providesTemporary*()`, so invocation never exposed it. `serveUsing()` is unaffected — its callback is invoked unbound via `call_user_func`.
22. **A global mail address without a name throws.** `MailManager::setGlobalAddress()` reads `$address['name']` unconditionally (`MailManager.php:611`), so a valid config like `'from' => ['address' => 'first@example.com']` dies with an undefined-array-key `ErrorException` during named-mailer resolution. A porting regression: Laravel coalesces — `$address['name'] ?? null` (`examples/laravel/framework/.../MailManager.php:517`) — and every `always*` receiver takes `?string $name = null` (`Mailer.php:84`, `:96`, `:120`).
23. **`QueueManager::setApplication()` cannot update a pooled connection.** It loops every cached connection calling `$connection->setContainer($app)` (`QueueManager.php:378-380`, mirroring Laravel `:409-411`). For a cached `QueuePoolProxy` the old magic forwarding mutated whichever single pool member happened to be borrowed — other current and future members kept the old container — and `setContainer()` is not on the queue contract, so against an enumerated proxy surface the call is an undefined method. An in-place update can never be correct for a proxy anyway: the proxy captures the manager's pool `Factory` at construction, so after an application swap its per-operation pool resolution still targets the old application's registry. The sibling managers are unaffected — `FilesystemManager`/`MailManager`/`BroadcastManager` `setApplication()` are bare assignments with no connection loop (`:701`, `:735`, `:517`).
24. **The S3 read-option merge drops sibling `@http` settings.** Upstream League's `readObject()` conditionally creates `$options['@http']['stream'] = true` and then merges with top-level array union — `$options + $this->options` (`vendor/league/flysystem-aws-s3-v3/AwsS3V3Adapter.php:464-468`). PHP's `+` does not recurse: once the left side owns `@http`, the configured `@http` array is discarded wholesale, so `'stream_reads' => true` with `'options' => ['@http' => ['timeout' => 12]]` silently loses the timeout. The `! isset($this->options['@http']['stream'])` guard hides the bug whenever the user explicitly configures `stream` (the left side never creates `@http`). This affects ordinary reads through the vendor adapter and the ranged reads that copied the shape. The vendor bug should also be reported upstream.
25. **The GCS adapter diverges from every sibling driver in two ways.** Its read helper always throws `UnableToReadFile` while both public wrappers advertise `null on failure` — it never honors `throwsExceptions()` the way the base `readStream()` and the S3 helper do, so a GCS disk with `'throw' => false` throws where S3/local return null. And `buildGcsDisk()` constructs `new Flysystem($adapter, $gcsConfig)` directly instead of routing through `createFlysystem()`, so GCS disks silently ignore the `read-only` and `prefix` config keys every other driver honors and pass the full camelCased config as Flysystem config instead of the shared six-key filter (inherited verbatim from the old `createGcsDriver()`). Its `url()` method also has a dead conditional that reassigns the exact URI already selected by the preceding fallback expression.
26. **Misc.** `getVisibility()` uses `==` (`FilesystemAdapter.php:528`). `tests/Filesystem/FilesystemManagerTest.php` extends raw PHPUnit `TestCase` and writes into the repository root (`__DIR__ . '/../../my-custom-path'`, `to-be-scoped`); `tests/ObjectPool/PoolProxyTest.php` extends raw PHPUnit `TestCase`. The filesystem README has no `Ported from:` line (object-pool is Hypervel-original — nothing to add there). `MailManager::build()` has no on-demand transport-pooling implementation. The `Storage` facade advertises lazy `listContents()` and raw `getDriver()` (`Facades/Storage.php:79`, `:94`).
27. **Scoped `putFileAs()` validates too late.** Prefixing only `$path` and forwarding a caller-controlled `$name` lets the inner adapter join a target containing `../`, write outside the resolved scope prefix, and only then fail while stripping the returned path. The containment check must cover the final joined storage target before any I/O.
28. **Fresh-object lifetime checking can livelock.** A legal tiny positive `max_lifetime` may expire an object between creation and the outer checkout check; the pool then destroys and recreates forever without waiting or consulting its deadline. Absolute expiry applies to objects retrieved from idle storage, while a newly created object always receives its first checkout.
29. **Database/Redis registry removal is ordered after close.** Their pool factories close an entry before unsetting it. Connection teardown may yield, allowing a concurrent resolver to retrieve the closing pool and fail. Every registry-drop operation must detach the exact instance before closing it.
30. **Managed destroy callbacks cannot survive re-creation.** `Factory::getOrCreate()` accepts a destroy callback, but the proxies that reacquire after purge or idle eviction do not retain or re-supply one. No in-tree managed caller supplies it. Keeping the parameter would advertise teardown that silently disappears, so managed factories drop it while standalone pool constructors retain deterministic teardown.
31. **The scoped no-path methods impose an unrelated context requirement.** `providesTemporaryUrls()`, `providesTemporaryUploadUrls()`, and `getConfig()` resolve the dynamic prefix only to discard it, so harmless boot-time capability/config inspection throws without request context. These methods cross no storage path boundary and pass through directly.
32. **Pool maintenance and signaling have silent/blocking failure modes.** `PoolRecycler` constructs a timer with no logger, so its coordinator-level catch drops maintenance exceptions. A registered pool closed through its public contract remains permanently wedged in `PoolManager`. Both channel wrappers use `push(..., 0.0)` as if it were non-blocking, but Swoole defines zero as wait forever; repeated signals can block on a full signal channel. Report maintenance through `PoolErrorReporter`, replace closed entries, and coalesce signals through an explicit capacity check.
33. **HTTP registration/validation state is duplicated and inconsistent.** Reserved-option tables exist in both factory and pending-request layers and evaluated globals are validated twice. A nullable `createClient()` cookie jar can silently create a jar detached from the owning request. `setConnectionConfig()` stores a visible preset without registering its name because a parallel boolean map is the actual registration authority. One reserved-options component, a required request jar, and `array_key_exists()` on the config map make each invariant single-source.
34. **Queue name typing contradicts its contract.** The base property and constructors allow null while `getQueue(): string`; all backend jobs already receive resolved strings, and only `SyncQueue` accepts null before its job hardcodes `'sync'`. Normalize at `SyncQueue` and use `string` throughout. `FakeJob` initializes neither inherited `$connectionName` nor `$queue`, so both inherited getters throw an uninitialized-property `Error` on the zero-argument fake installed by `InteractsWithQueue`; promote constructor defaults `connectionName: 'sync'` and `queue: 'default'` after the existing payload parameter so the state itself is total and tests may override it. The local Horizon `FailedJob` fixture has the same hole and initializes both names. Native `ConnectorInterface::connect(): Queue` also makes the proxy's repeated `instanceof Queue` guard unreachable.
35. **Touched test hygiene remains incomplete.** Four database integration tests use shared `sys_get_temp_dir()` SQLite filenames instead of parallel-worker directories, several touched legacy test methods lack `: void`, and new immutable pool value/error classes need the repository-required constructor docblocks.
36. **`FswatchDriver` hot-spins and leaks its subprocess when `fswatch` exits.** Its infinite watch loop ignores both `fread() === false` and `''`/EOF, so a dead output pipe is read continuously at full speed while the watcher silently stops detecting changes. The pipe handles are local to `watch()`, leaving `stop()` unable to close them, and both normal loop exit and exceptions leave the child process alive until a destructor that the forever-running watcher retains indefinitely. Read failures must stop loudly; normal channel/explicit-stop shutdown must return cleanly; and one deterministic lifecycle must own the process and every pipe.
37. **Watcher subprocess and find commands interpolate unescaped paths.** `FswatchDriver` and `ServerRestartStrategy` pass string commands to `proc_open()`, routing paths and configured arguments through a shell and making `proc_terminate()` target an intermediary shell rather than deterministically targeting the child. `FindDriver` and `FindNewerDriver` interpolate watched paths and reference-file paths into their required shell strings without escaping; `FindNewerDriver` also shells out four times merely to update reference-file timestamps. Real subprocesses use argv arrays, while the find-only shell boundary escapes every interpolated argument and reference files use checked filesystem operations.
38. **`FindNewerDriver` shares predictable reference files across processes.** Every instance alternates the same `/tmp/hypervel_find.php0` and `/tmp/hypervel_find.php1` paths. Concurrent watcher processes overwrite one another's comparison clocks, causing missed or duplicated changes; a pre-planted symlink can redirect `touch()` to another file; and the files are never removed. Each driver must atomically create, own, and deterministically remove its own unique pair. Cleanup must also account for an in-flight coroutine-aware `find` command: unlinking during its yield lets the resumed callback recreate detached paths through `touch()`.
39. **Temporary-file failure handling is incomplete.** Three cache-command `tempnam()` calls have explicit false checks whose named diagnostics are unreachable in bootstrapped processes because the preceding warning is promoted to `ErrorException`. Testbench's Blade helper passes a false result into `pathinfo()`, suppresses placeholder-removal failure, and never checks for a false or partial template write, so it can leak the placeholder or silently render truncated content. `Filesystem::replace()` is more serious: `tempnam()` can fall back from a missing/unwritable destination directory to the system temp directory and return a valid path; the method ignores `chmod()`, `file_put_contents()`, and `rename()` results; a partial write can therefore replace a valid target with truncated contents; and applying a restrictive mode before writing can replace the target with an empty file. Normalize checked `tempnam()` warnings, and make both write lifecycles checked transactions with failure cleanup.
40. **`FindNewerDriver` advances both cutoffs after a scan and can permanently miss a concurrent change.** A watched file can change after `find` has passed it but before the yielding scan returns. When the same scan found any other file, the callback touches both references after completion, moving both timestamps past the late change; it appears in neither the current result nor the next scan. The alternate reference must record the next cutoff before scanning, the current reference must remain authoritative for the active scan, and the roles must swap after every successful scan — including a quiet one. This intentionally provides at-least-once detection: a change during a scan may be reported twice but cannot be lost.
41. **Closed pool channels still accept data.** Both rebuilt `Channel::push()` methods enqueue into their `SplQueue` and return true after terminal closure while suppressing only the wake signal. Built-in pool release paths check their own closed state without yielding, so no current caller reaches this path, but the channel's lifecycle contract is incomplete and direct use can retain unreachable data after close. A closed channel rejects the push with `false`, matching its boolean API and engine-channel failure semantics.
42. **Recurring timer and keepalive failures can disappear silently.** `Timer::tick()` catches every callback `Throwable` to preserve recurring execution but reports only through a nullable logger; ten logger-less timer consumers therefore lose unexpected failures. One-shot `after()` callbacks are unaffected because they are not caught and already fail loudly. `KeepaliveConnection` catches heartbeat failures before they reach the timer and repeats the same nullable-logger hole after clearing the connection. Recurring timer failures and caught heartbeat failures must use the configured logger when present and `error_log()` as the last-resort fallback.

### 1.2 Granularity measurements (drives the s3/gcs design)

Probe: construct N=100 instances after one warm-up, fake credentials, no network, `memory_get_usage(false)`. Reproduced in two independent runs with matching results:

| Measurement | Result |
|---|---|
| Bare `S3Client` per instance | 1,762,483 bytes |
| Full driver stack (client + League adapter + Flysystem + `AwsS3V3Adapter`) per instance | 1,764,717 bytes |
| Wrapper delta | 2,234 bytes (**0.13%**) |
| Stack construction around an existing client (10k iterations) | **2.58 µs** |
| Bare GCS `StorageClient` per instance | 871,144 bytes |
| GCS wrapper delta | 3,608 bytes (0.41%) |

The SDK client is effectively the entire retained cost of a pooled driver; rebuilding the bucket/prefix-specific wrappers is microseconds. Whole-driver pooling therefore duplicates ~1.8 MB (S3) / ~0.9 MB (GCS) per pooled object for every disk that differs only by bucket/prefix/visibility. Client-level pooling eliminates exactly that. Related facts verified in the SDK: parsed API models are already cached process-wide (`\Aws\load_compiled_json()` static, `vendor/aws/aws-sdk-php/src/functions.php:146`), so client cost is handler/middleware/credential state, not re-parsed models; `getCommand()` embeds a clone of the creating client's handler list into the command (`AwsClient.php:345`) and `execute()` runs the command's own list (`AwsClientTrait.php:63`), so an SDK-method-level client proxy can never provide isolation — the client must be borrowed for the whole filesystem operation. GCS needs no client interface: the Hypervel adapter already constructs `Bucket` per call (`GoogleCloudStorageAdapter.php:129-132`).

### 1.3 Closed decisions

| Decision | Reason |
|---|---|
| Client-level pooling for built-in s3/gcs; whole-driver pooling only for custom poolable drivers | Measurements above. The framework knows how to split its own drivers into client + cheap stack; it cannot know that for arbitrary custom drivers. |
| No SDK-method-level client proxy | Commands/paginators/promises bind the creating client's handler list; only operation-scoped borrowing of a concrete client is sound. |
| Pool identity = immutable `PoolDefinition` (identity string + construction fingerprint + normalized options); reuse via `getOrCreate()`; any mismatch under an existing identity throws | Identical construction converges (repeated `build()`, per-request disks); conflicting construction under one identity is always a bug and must fail fast, including under explicit `pool.name` (silent reuse could cross different credentials). |
| Automatic fingerprints hash the pooled object's construction input only (client config for s3/gcs, transport config for mail, connector config for queue) — never the manager's full named-entry config | Disk/mailer/connection presentation keys must not split resource pools. |
| Canonicalization: tagged-tree over a strict whitelist; SHA-256 | Type tags prevent enum/list encoding collisions; whitelist (null/bool/int/float/string/enum/list/map) rejects objects, closures, resources with an explicit remedy; SHA-256 because a collision could join different credentials. |
| Identities are namespace-disjoint: `{Manager}:auto:{resource}:{fingerprint}` vs `{Manager}:named:{pool.name}` | An explicit name can never collide with an automatic identity; explicit names stay readable. |
| `pool.fingerprint` legal with or without `pool.name`; explicit fingerprints are domain-tagged and hashed | Declared equivalence for un-canonicalizable configs; raw values never concatenated into identities. |
| Every automatic resource pool gets a finite default `idle_ttl` (300s, one centralized default in `PoolOptions`); disabling is explicit | Pools are shared resources decoupled from manager caches; TTL is the reclamation mechanism. Idle disks re-create clients lazily (~1.8 MB + connect) on next use — correct for long-lived workers. |
| forget = cache-only; purge = cache + close the underlying pool (works for never-cached names by deriving the identity from current config) | TTL only reclaims *abandoned* pools; a broken pool still being borrowed never idles. Purge is the operator's discard lever and its docblocks already promise disconnection. |
| Strategy layer deleted (`RecycleStrategy`, `TimeStrategy`, `recycle_strategy`, `recycle_ratio`, `lastRecycledAt`) | The only shipped strategy is redundant once expiry sweeping exists, its math is the bug in 1.1(5), and no consumer outside object-pool references any of it (verified by grep). Distinct real policies are modeled directly: `max_lifetime` (absolute expiry), `max_idle_time` (per-object idle), `idle_ttl` (whole-pool). |
| `min_objects` → `min_retained_objects`; it is an idle-trim floor only | It never pre-created or replenished anything (the constructor builds zero objects — verified). Expiry (`max_lifetime`) destroys below it; `close()` ignores it; nothing replenishes. Truthful name, no eager machinery. |
| All durations via `hrtime()` | `microtime(true)` is wall-clock, not monotonic. |
| `lastUsedAt` updates only on user checkout/release; maintenance requeues preserve timestamps | Otherwise every recycler pass would reset the idle clock and TTL eviction would never fire. |
| Flat Laravel-shaped disk configs stay supported; client config is derived positively (S3: `S3Client::getArguments()` key set — verified at `vendor/aws/aws-sdk-php/src/S3/S3Client.php:287`; GCS: framework-owned map), merged with an optional `client` block (explicit block wins) | Invariant: the array hashed for client identity is exactly the array passed to the client constructor. A blacklist would silently split pools whenever a new disk-only key is forgotten. |
| No config-resolution hook on `FilesystemManager`, and no source comment about its absence | The `$disks` cache is name-keyed, so a hook can never be per-context; Laravel has no such hook either, so this is not a recorded intentional difference. Dynamic-disk guidance goes in `filesystem.md`. |
| Scoped decorator is a strict boundary: fail-closed empty prefix, traversal rejection, no unprefixed `__call` escape | It exists to isolate independent users, workspaces, projects, or tenants; every escape is a security bug. |
| One canonical object store in the pool channel; coroutine-aware waiting layered on top; the same primitive fixes `src/pool` | Dual-mode storage strands objects across boot/CLI/coroutine boundaries (1.1 #9). |
| Pools track managed and borrowed object identity and reject violations (double release, foreign release, duplicate factory output) with clear exceptions; `discard()` added | The close/late-release lifecycle is only sound if every object is released exactly once by its owner; the notification router shows duplicate factory output happens in-tree today. |
| No public magic proxying anywhere: `PoolProxy` exposes a protected synchronous `invoke()` and every consumer proxy enumerates its surface; unknown methods throw | A generic `__call` cannot know whether a return value is lazy (stream, promise, iterator, `DirectoryListing`) — silent release-before-consumption is the bug class this plan exists to end. |
| `PoolDefinition` carries a `resourceType` compared on reuse | An explicit `pool.name` + fingerprint + options match must never hand an `S3Client` pool to a GCS disk (or an smtp pool to a different transport type). Equivalence is only meaningful within a resource type. |
| HTTP client pooling is deleted; transport reuse moves to a shared per-connection low-level handler | The Guzzle `Client` is a stateless option holder, but the low-level handler (`CurlMultiHandler`) owns the keep-alive state today's pooled client incidentally retained. Named connections share one lazily-created transport handler wrapped by fresh per-request middleware stacks — middleware bug fixed, connection reuse kept, verified by a concurrency test with a specified handler-pool contingency. |
| Built-in notification pooling is deleted | `SlackNotificationRouterChannel` holds only the container; the real Slack channels are resolved later per send, so pooling the router isolates nothing — and as a container auto-singleton it triggers the duplicate-factory ownership bug. No resource, no pool. |
| Preserve pooling by state-owning resource, not by historical proxy shape | Every distinct state-owning resource that was pooled remains pooled or equivalently retained. HTTP keeps its connection-owning handler shared while removing request-specific client wrappers; notification pooling repeatedly returned one stateless auto-singleton and therefore never managed distinct resources. This is correction of two flawed designs, not a general policy change to unpool resources. |
| Reverb remains unpooled | Reverb's application-side broadcaster uses the Pusher-compatible HTTP publish API, but installed Pusher SDK request state is method-local and its Guzzle client is safe to share; Swoole-hooked synchronous I/O yields between coroutines. `triggerAsync()` followed immediately by `wait()` would add no useful concurrency. Matching Pusher's historical pool by symmetry would pool a resource without a demonstrated state boundary. Existing Pusher and Ably pooling stays unchanged pending the explicit review recorded in `docs/todo.md`. |
| Sentry's standalone pool force-disables `idle_ttl`/`max_idle_time` and drops them from its config surface | Nothing maintains an unmanaged pool; shipping knobs that knowingly do nothing is dishonest config. Absolute `max_lifetime` still works (checked on borrow). |
| Destroy callbacks are constructor-immutable | `setDestroyCallback()` is a worker-lifetime mutable footgun with no non-test callers; construction behavior belongs to construction. |
| Destruction and reporting are no-throw; every cleanup path preserves the primary exception | `destroyObject()` removes bookkeeping in a finally and reports (never throws) destroy-callback failures; `PoolErrorReporter::report()` catches its own failures and falls back to `error_log()`. Cleanup failure must never leave a managed ghost, abort a close halfway, or mask the operation/release exception that caused it. |
| A failed backend operation discards the borrowed object; success releases it | A protocol or network failure is not evidence of a clean connection, and `max_lifetime` is not a corruption detector. Applies to queue terminal operations and sentry transport sends; exceptional-path-only cost. |
| Resource construction never sees the `pool` key | Fingerprints exclude it, so the fingerprint-equals-construction-input invariant only holds if custom factories/connectors cannot read it. Managers pass `pool`-stripped config to all resource construction; the full config lives only on manager/proxy `getConfig()`. |
| `src/pool` gets the same terminal close model as object-pool; pool-level `flushAll()` is deleted | Every in-tree `flushAll()` use is already terminal: both pool factories call it and immediately drop the registry entry; the `DbPool`/`RedisPool` overrides clear heartbeat timers and `DbPool` nulls the shared in-memory SQLite PDO (reuse would break the shared-database invariant); tests assert exactly those terminal effects; and every consumer resolves its pool through the factory per operation, so registry removal + lazy re-creation already is the "invalidate" story. A reusable generation model would add retired-ownership machinery for a capability with no consumer. Terminal `close()` is also required for reclamation: the heartbeat closure runs in a `Timer::tick()` coroutine that captures the pool, so an abandoned pool with a live heartbeat can never reach its destructor. |

## Part 2 — Object-pool package rebuild

Final file layout of `src/object-pool/src/` (deletions marked):

```
Channel.php                     (rewritten — single canonical store; see 2.0)
Contracts/Factory.php           (rewritten)
Contracts/ObjectPool.php        (rewritten)
Contracts/Recycler.php          (updated: getLastRecycledAt removed)
Contracts/RecycleStrategy.php   (DELETED)
Contracts/TimeStrategy.php      (DELETED)
Lease.php                       (new)
Listeners/StartRecycler.php     (unchanged)
ObjectPool.php                  (rewritten)
ObjectPoolServiceProvider.php   (updated binding: PoolRecycler)
PoolDefinition.php              (new)
PoolFingerprint.php             (new)
PoolManager.php                 (rewritten)
PoolOption.php                  (DELETED — replaced by PoolOptions)
PoolOptions.php                 (new)
PoolProxy.php                   (rewritten)
PoolRecycler.php                (renamed from ObjectRecycler, rewritten)
SimpleObjectPool.php            (setCallback removed)
Strategies/TimeStrategy.php     (DELETED, directory removed)
Traits/HasPoolProxy.php         (rewritten)
```

### 2.0 `Channel` rewrite — one canonical store

The dual-store design (1.1 #9) is replaced by a single canonical store plus a pool-state signal. The channel stores; the **pool** owns the wait loop, because waiters must react to every state change, not only to released objects.

- **Storage:** one `SplQueue` holds idle objects regardless of execution mode. Objects released in any mode are visible to borrowers in every mode. `pop()` is non-blocking (returns `false` when empty); `length()` reports the count.
- **Signal:** an `EngineChannel` used purely as a state-change notifier. Every capacity-relevant transition signals parked waiters: object pushed, checked-out object discarded, expired object destroyed, maintenance destroyed an object, a concurrent factory failed, the pool closed. A waiter woken by any of these re-evaluates the full state — an idle object is only one of the ways a wait can succeed; freed *creation capacity* is the other.
- **Close wakes everyone:** `close()` closes the signal channel, which wakes all parked waiters at once (an engine channel close releases every popper); woken waiters observe the closed flag and throw immediately instead of sleeping out their timeout and creating from a closed pool. The pool's wait primitive distinguishes closure from timeout: closure is a wake (re-enter the loop, hit the closed check), timeout is the exhaustion failure. `signal()` and `close()` are safe after closure (signaling a closed engine channel is a no-op), so late destroys and maintenance never need closed-state guards; the `SplQueue` store stays drainable after the signal channel closes — `close()` drains and destroys through it.
- **Closed channels reject new data:** `push()` checks the wrapper-local closed flag before enqueueing and returns `false` after closure. Pool release and maintenance paths already prevent post-close requeues and therefore need no dead false-return branch; this guard makes the channel's own terminal contract complete and prevents direct callers from retaining data after close. The object-pool and connection-pool siblings remain identical in this behavior.
- **Channel closure is locally flagged and requires a live runtime:** the wrapper owns a PHP `$closed` flag that is the single authority for its own state — `close()` sets it first and returns immediately when already set, and `signal()`/`wait()` short-circuit on wrapper-local state (the closed flag, the waiter count) before any native call. The native close cannot be guarded by catching: a pool destroyed after its creating coroutine/native context is gone raises `Swoole\Error('must call constructor first')` through an **uncatchable fatal path** — verified directly on Swoole 6.2.2: `Swoole\Error` subclasses `Throwable`, yet `try/catch (Throwable)` around `close()` on a constructor-less channel never enters the catch and the process dies — and no PHP-side probe distinguishes a zombie handle (`isClosing()`/`getCapacity()` read only PHP properties, `engine/src/Channel.php:43-46`, `:116-119`; a real zombie still reports its full capacity). So `close()` is deterministic-lifecycle API for live contexts only, and destructors never reach native channel calls (previous bullet). The one destructor-context path that touches the channel at all — `Lease::__destruct` → `release()` → `push()`/`signal()` on the object-pool side — stays native-free structurally: `push()` enqueues the `SplQueue`, and `signal()` returns before any native call when the waiter count is zero or the wrapper is closed; `wait()` decrements its waiter count in a `finally` so that short-circuit stays truthful. `Engine\Channel::close()`'s docblock gains a warning that native channel methods are uncatchably fatal once the native handle is torn down — never call them from GC/destructor paths.
- **Cross-mode wakeups:** a release/destroy from outside a coroutine must not leave a parked coroutine waiter sleeping until timeout. When waiters are registered and the signaler is outside coroutine context, schedule the signal through the engine's coroutine-creation API (a parked waiter proves a scheduler is active); the waiter's re-check loop remains the correctness backstop, but timeout-noticing is not part of the design.
- **Signals are non-blocking and coalesced:** Swoole interprets a channel push timeout of `0.0` as wait forever, not as non-blocking. `signal()` therefore keeps the waiter/closed short-circuits, checks the signal channel's capacity state, and pushes only when it is not full; the same check runs inside the coroutine scheduled for an outside-coroutine signal. Cooperative execution has no yield between `isFull()` and the immediate push, so no other coroutine can fill the channel in between. A full signal channel already holds pending wake state, and every waiter re-checks pool state after waking, so skipping an extra push loses no information. Tests drive repeated release/destroy signals in a tight loop with zero and one parked waiter, prove no caller blocks, and prove coalescing still lets every waiter re-check before its deadline.

`src/pool/src/Channel.php` has the identical dual-store bug for pooled connections (verified — same shape, typed to `ConnectionInterface`). Fix it with the same design. The two packages are independent Composer subtrees, so implement the corrected logic in each package's own class (same shape, package-local types) rather than introducing a cross-package dependency; note the sibling in each class docblock so future edits touch both.

**`src/pool/src/Pool.php` gets the same invariants**, not just its channel: it has the identical unguarded release surface (foreign/double releases) and the same waiter-starvation problem on creation failure. Read it first, then apply managed/borrowed connection tracking, the reserve-before-create capacity accounting, the single monotonic checkout deadline, and the state-change wait loop (all per 2.6). Its public API changes deliberately: `get`/`release`/`flush` keep their surfaces; `flushOne()` and pool-level `flushAll()` are deleted; `close()`/`isClosed()` are added.

`Pool` today has no closed state — its lifecycle is `flush()` (trim excess) and `flushAll()` (drain everything) — but every in-tree `flushAll()` use is already terminal, so the rebuild gives `Pool` ObjectPool's close model instead of inventing reusable invalidation nothing consumes. Verified: both pool factories call `flushAll()` and immediately drop the registry entry (`redis/src/Pool/PoolFactory.php:28-49`, `database/src/Pool/PoolFactory.php:88-96`, `:128-135`); the `DbPool`/`RedisPool` overrides clear their heartbeat timers and `DbPool` nulls the shared in-memory SQLite PDO (`DbPool.php:165-171`, `RedisPool.php:79-84`) — reusing that pool would break the shared-database invariant; the heartbeat tests assert the timer is cleared by `flushAll()` and `testFlushAllClearsSharedPdo` asserts the PDO is nulled; and every consumer resolves its pool through the factory per operation (`ConnectionResolver.php:72`, `RedisProxy.php:283`, sentry's `RedisFeature`), so nobody holds a pool instance across an invalidation. Terminal teardown is also required, not merely sufficient: `Timer::tick()` runs the heartbeat closure in a coroutine that captures the pool (`coordinator/src/Timer.php:57-93`), so a pool with a live heartbeat can never be garbage-collected and `__destruct()` can never be the teardown path.

The model:

- pool-level `flushAll()` is deleted; `close()` (idempotent) and `isClosed()` replace it with exactly ObjectPool's semantics: set closed, close the signal channel (all parked waiters wake and throw the closed-pool exception), drain idle connections through the single destruction path. A factory completing after close registers-then-destroys its orphan and throws; a borrowed connection released after close is destroyed, never requeued; borrows on a closed pool throw;
- `DbPool::close()` clears the heartbeat, runs the base close, then nulls the shared in-memory SQLite PDO; `RedisPool::close()` clears the heartbeat and runs the base close. The destructors do **not** delegate to `close()` — they keep only their current timer-registration clear (no channel calls): a destructor can only run when no heartbeat coroutine and no parked waiter retains the pool through its closure/frame graph, plain GC then frees connections through their own native finalizers, and a native channel call from GC context risks the uncatchable teardown fatal (next bullet). `close()` is deterministic-lifecycle API for live contexts — the factories, `PoolManager::remove()`/`flush()`, purge;
- the factory surfaces keep their names and registry semantics (`flushPool()`, `flushAll()`, `flushPoolsForConnection()`), but every registry-drop path first removes and retains the exact pool instance, then closes that detached instance. `close()` may yield while destroying PDO/socket resources; close-before-detach would let a concurrent resolver receive a closing pool and fail. The next `getPool()` may therefore create a fresh pool immediately — identical to `PoolManager::remove()` plus per-operation re-resolution on the object-pool side;
- the `DbPool`/`RedisPool` heartbeat sweeps are rewritten onto the rebuilt primitives: no direct `--currentConnections` or raw channel arithmetic — unhealthy/expired connections destroy through the single destruction path (bookkeeping in a finally, waiters signaled), healthy ones requeue without touching idle clocks, and the `heartbeatGeneration` counters are deleted: `clearHeartbeat()` now only runs inside `close()`, so the closed flag is the only mid-sweep invalidation signal a completing heartbeat needs to check (the existing mid-ping `flushAll()` fixture becomes a mid-ping `close()` test).

Capacity after invalidation needs no cross-generation accounting: the fresh pool is a new instance with its own counters, so a still-borrowed old connection can never block it, and each pool's `getCurrentConnections()` stays truthful for the connections it actually manages.

The existing contract dishonesty gets fixed in the same pass: `PoolInterface::flush()` is documented as "Close and clear the connection pool" (`contracts/src/Pool/PoolInterface.php:25`) while `Pool::flush()` only trims down to `min_connections`. `flush()` keeps its name with a truthful docblock — "Close idle connections in excess of the minimum pool size" — and `PoolInterface` gains `close()`/`isClosed()` (terminal teardown is behavior every conforming pool must provide; the old "Close and clear" language moves to `close()`, where it is finally true).

`flushOne()` does not survive the rebuild. Its bool parameter hides two different operations — `flushOne(true)` closes one idle connection, `flushOne(false)` health-checks one — and the unforced path strands capacity when a health check throws (1.1 #18). `close()` drains through the single destruction path, so nothing needs a one-at-a-time popper. The timer maintenance becomes an honestly named `checkIdleConnection()`: pop one idle connection; treat `false` **and a throwing `check()`** as unhealthy (the throw is reported through the pool's logger); destroy through the single destruction path (bookkeeping removal in a finally, close failures reported, waiters signaled — a maintenance destroy frees capacity); requeue healthy connections without touching idle clocks; never throw from maintenance. Callers migrate: `ConstantFrequency.php:34` calls the new method; Horizon's `tests/Integration/Horizon/IntegrationTestCase.php` drain calls (`flushOne(true)`) become factory `flushPool()` calls (close + registry removal; the next `getPool()` recreates); `tests/Pool/PoolTest.php` and `FrequencyTest.php` follow.

The maintenance probe must also stop resetting idle clocks (1.1 #19): `check()` becomes a pure predicate — the `lastUseTime = $now` mutation is deleted from all three implementations (`pool/src/Connection.php:95`, `RedisConnection.php:490`, `PooledConnection.php:182`). That is safe because every real-use path already sets `lastUseTime` itself — DB connection establish (`PooledConnection.php:146`) and query execution (`:234`), redis reconnect (`RedisConnection.php:523`) and command execution (`:673`), SimplePool `reconnect()` (`SimplePool/Connection.php:44`) — and idle measurement is anchored by `lastReleaseTime`, written on every release (`Connection.php:52` for pool/redis, `PooledConnection.php:282` for DB); a connection only re-enters the idle channel through a release, so the anchor is always fresh.

`PoolOption` gains the validation it never had — today the promoted constructor and all eight setters assign unchecked (`PoolOption.php:35-46`), so invalid capacities, non-positive timeouts, NAN, and ambiguous negative sentinels all reach live pool arithmetic. The mutable setter API and config keys stay; every value validates: counts require `min_connections >= 0`, `max_connections >= 1`, and `min <= max` (each setter checks against the current counterpart); durations must be finite (`is_finite()`, the same rule as `PoolOptions`) with `connect_timeout > 0`, `wait_timeout > 0`, `heartbeat_timeout > 0`, `max_idle_time > 0`; `heartbeat` and `max_lifetime` accept exactly `-1.0` or `> 0` — `-1` is the single documented disable sentinel (the docblocks say so and every in-tree config and doc uses it; zero and other negatives act as undocumented disables through the `<= 0` guards today and are rejected); `events` must be a list of non-empty strings. Violations throw `InvalidArgumentException` naming the field and rule. `Pool::initOption()` also rejects unknown config keys, naming the valid set — the same typo protection `PoolOptions::fromArray()` gives every object-pool consumer's `pool` subarray; `DbPool` strips its own domain metadata first (`testing_enabled`, which the testing resolver reads from the same subarray — each pool subclass owns its metadata keys, the base stays ignorant of them).

`PoolOption::jitteredLifetimeDeadline()` remains a validated public static boundary: callers may supply raw floats outside a constructed option instance, and a negative/non-finite lifetime would otherwise create an always-expired or invalid deadline. `RedisPool::discardHeartbeatConnection()` also stays as the Redis-side named counterpart to DB heartbeat discard; although currently a one-line delegation, it keeps the two sweep algorithms structurally aligned and provides the correct extension point for backend-specific cleanup.

Its consumers (database/redis pool factories) are exercised by the existing integration workflows — run them as the verification gate for this step.

Channel tests (both packages): release outside a coroutine → borrow inside one; release inside → borrow outside; non-coroutine exhaustion returns the failure path (no `SplQueue` crash); coroutine waiter woken by a release, by a discard, by an expiry destroy, and by a concurrent factory failure; outside-coroutine release wakes an already-parked coroutine waiter (where the runtime permits that interleaving); waiter timeout; `close()` idempotent, `signal()`/`wait()` no native calls after local closure; explicit factory/manager close wakes parked waiters (live-context path); DB/redis pool destructors make no channel calls (the native-teardown reproducer has no deterministic unit form — the DB integration suite's cleanup is its regression gate). Object-pool close tests: close wakes all parked waiters immediately; close during a suspended factory destroys the orphan and the borrower gets the closed-pool exception. `tests/Pool/` close tests mirror them: close wakes all parked waiters (closed-pool exception, not timeout); close during a suspended factory destroys the orphan and the borrower throws; a connection released after close is destroyed, never requeued; borrows on a closed pool throw; close is idempotent; and the fresh-capacity proof — with `max_connections = 1`, borrow, close the pool via the factory (`flushPool()`), then successfully borrow from the freshly created pool **before** the old borrow releases, asserting each pool's `getCurrentConnections()` counts only its own connections and the late release destroys the old connection.

### 2.1 `PoolOptions` (new, replaces `PoolOption`)

Immutable and validated. Normalization happens here, once, before any identity or equality comparison — defaults-vs-explicit and key order can never make equal options compare unequal. Unknown keys throw (typo protection).

```php
final class PoolOptions
{
    public const DEFAULT_IDLE_TTL = 300.0;

    private function __construct(
        public readonly int $minRetainedObjects,
        public readonly int $maxObjects,
        public readonly float $waitTimeout,
        public readonly float $maxLifetime,   // seconds; 0.0 disables absolute expiry
        public readonly float $maxIdleTime,   // seconds; 0.0 disables per-object idle trimming
        public readonly ?float $idleTtl,      // seconds; null disables whole-pool eviction (explicit)
    ) {
    }

    /**
     * Create a normalized, validated options instance from a pool config array.
     */
    public static function fromArray(array $options): self
    {
        // Reject unknown keys with the full list of known keys in the message.
        // Validate every field's TYPE (int for counts, int|float for durations)
        // and VALUE: min_retained_objects >= 0; max_objects >= 1; min <= max;
        // wait_timeout > 0; max_lifetime >= 0; max_idle_time >= 0; idle_ttl
        // null or > 0; all durations finite (reject NAN/INF via is_finite() —
        // comparison operators alone do not catch NAN). Throw
        // InvalidArgumentException naming the field and the constraint.
        // 'idle_ttl' => null in the array is the explicit opt-out; an absent key
        // gets DEFAULT_IDLE_TTL. Use array_key_exists, not ??, for that distinction.
    }

    /**
     * Determine if these options equal another normalized instance.
     */
    public function equals(self $other): bool
    {
        // Strict property-by-property comparison.
    }

    /**
     * Get the options as an array (used in mismatch error messages).
     */
    public function toArray(): array;
}
```

Config keys and defaults (all defaults live here, nowhere else):

| Key | Default | Semantics |
|---|---|---|
| `min_retained_objects` | `1` | floor for idle trimming only (never pre-created, never replenished) |
| `max_objects` | `10` | pool capacity |
| `wait_timeout` | `3.0` | seconds a coroutine borrower waits on an exhausted pool |
| `max_lifetime` | `60.0` | absolute object expiry, seconds; `0` disables |
| `max_idle_time` | `0.0` (disabled) | per-object idle expiry, seconds |
| `idle_ttl` | `300.0` (`DEFAULT_IDLE_TTL`) | whole-pool eviction, seconds; explicit `null` disables |

The `pool.name` and `pool.fingerprint` control fields are stripped by the definition builder before `fromArray()` ever sees the array (2.5).

### 2.2 `PoolFingerprint` (new)

Stateless canonicalizer. Tagged-tree encoding makes type boundaries unambiguous (a backed enum can never collide with a two-element string list).

```php
final class PoolFingerprint
{
    /**
     * Fingerprint a construction config array.
     *
     * @throws InvalidArgumentException when the config contains a value that
     *         cannot define pool identity (object, resource, closure)
     */
    public static function fromConfig(array $config): string
    {
        return 'auto:' . hash('sha256', serialize(self::canonicalize($config, '$')));
    }

    /**
     * Fingerprint an explicitly declared equivalence value.
     */
    public static function fromExplicit(string $fingerprint): string
    {
        return 'explicit:' . hash('sha256', $fingerprint);
    }

    /**
     * Canonicalize a value into a tagged tree.
     */
    private static function canonicalize(mixed $value, string $path): array
    {
        return match (true) {
            $value === null => ['null'],
            is_bool($value) => ['bool', $value],
            is_int($value) => ['int', $value],
            is_float($value) => ['float', $value],
            is_string($value) => ['string', $value],
            $value instanceof BackedEnum => ['enum', $value::class, $value->value],
            $value instanceof UnitEnum => ['enum', $value::class, $value->name],
            is_array($value) && array_is_list($value) => ['list', array_map(
                fn ($item, $index) => self::canonicalize($item, "{$path}[{$index}]"),
                $value,
                array_keys($value),
            )],
            is_array($value) => self::canonicalizeMap($value, $path),
            default => throw new InvalidArgumentException(
                "Pool fingerprint config value at [{$path}] is of type [" . get_debug_type($value) . '] '
                . 'and cannot define pool identity. Remove it from the construction config or declare '
                . 'construction equivalence explicitly via the pool config\'s "fingerprint" key.'
            ),
        };
    }

    private static function canonicalizeMap(array $map, string $path): array
    {
        $entries = [];
        foreach ($map as $key => $item) {
            $entries[] = [
                is_int($key) ? ['int', $key] : ['string', $key],
                self::canonicalize($item, "{$path}.{$key}"),
            ];
        }

        // Sort by the tagged key representation (type, then value), never by
        // PHP's regular key comparison: ksort() compares int 1 and string "01"
        // as numerically equal, so two maps with reversed insertion order would
        // stay differently ordered and fingerprint differently.
        usort($entries, function (array $a, array $b): int {
            return [$a[0][0], (string) $a[0][1]] <=> [$b[0][0], (string) $b[0][1]];
        });

        return ['map', $entries];
    }
}
```

The `auto:`/`explicit:` domain tags mean the two forms can never collide with each other. Canonicalization never mutates its input.

### 2.3 `PoolDefinition` (new)

```php
final readonly class PoolDefinition
{
    public function __construct(
        public string $identity,
        public string $resourceType,
        public string $fingerprint,
        public PoolOptions $options,
    ) {
        // Validate all three strings non-empty — this class is public API and
        // callers are not required to build definitions through HasPoolProxy.
    }
}
```

`final` is justified here by the immutability invariant (per AGENTS.md's `final` rule): a definition stored in the manager must never diverge from the pool it describes.

`resourceType` names the kind of pooled object (`s3`, `gcs`, `smtp`, a custom driver name, ...). It is compared on reuse alongside the fingerprint: an explicit `pool.name` + explicit fingerprint + matching options must still never hand an `S3Client` pool to a GCS disk or a differently-typed transport. Explicit fingerprints declare construction equivalence *within* a resource type, never across types.

### 2.4 `PoolManager` (rewritten)

`create()`, `set()`, and `setPools()` are deleted (`set`/`setPools` have no callers outside the class — verified). The registry stores pools and their definitions in lockstep.

```php
class PoolManager implements Factory
{
    /** @var array<string, ObjectPool> */
    protected array $pools = [];

    /** @var array<string, PoolDefinition> */
    protected array $definitions = [];

    /**
     * Get the pool registered for the definition's identity, creating it when absent.
     *
     * Returns the existing open pool untouched when the identity exists with a
     * matching resource type, fingerprint, and options. A closed registered
     * pool is detached with its definition and treated as absent, so an
     * out-of-band close cannot wedge the identity permanently. Throws when an
     * open identity exists with a different resource type, fingerprint, or
     * options.
     */
    public function getOrCreate(PoolDefinition $definition, callable $callback): ObjectPool
    {
        $identity = $definition->identity;

        if ($pool = $this->pools[$identity] ?? null) {
            if ($pool->isClosed()) {
                unset($this->pools[$identity], $this->definitions[$identity]);
            } else {
                $current = $this->definitions[$identity];

                if ($current->resourceType !== $definition->resourceType) {
                    throw new RuntimeException(
                        "Pool [{$identity}] already exists for resource type [{$current->resourceType}]; "
                        . "requested [{$definition->resourceType}]. Explicit pool identities never span resource types."
                    );
                }

                if ($current->fingerprint !== $definition->fingerprint) {
                    throw new RuntimeException(
                        "Pool [{$identity}] already exists with a different construction fingerprint "
                        . "[{$current->fingerprint}] (requested [{$definition->fingerprint}]). "
                        . 'Purge the pool or use a distinct explicit pool name.'
                    );
                }

                if (! $current->options->equals($definition->options)) {
                    // diffOptions() reports only the fields whose values differ,
                    // e.g. {"max_objects": {"registered": 10, "requested": 20}};
                    // json_encode(..., JSON_THROW_ON_ERROR).
                    throw new RuntimeException(
                        "Pool [{$identity}] already exists with different options: "
                        . $this->diffOptions($current->options, $definition->options)
                        . '. Align the pool options or use a distinct explicit pool name.'
                    );
                }

                return $pool;
            }
        }

        $pool = new SimpleObjectPool($callback, $definition->options);

        $this->definitions[$identity] = $definition;

        return $this->pools[$identity] = $pool;
    }

    /**
     * Get a managed pool by identity.
     */
    public function get(string $identity): ObjectPool;   // throws RuntimeException when missing

    /**
     * Determine if a pool exists for the identity.
     */
    public function has(string $identity): bool;

    /**
     * Get all registered pools, keyed by identity.
     */
    public function pools(): array;

    /**
     * Get the definition registered for the identity, if any.
     */
    public function definition(string $identity): ?PoolDefinition;

    /**
     * Remove and close the pool registered for the identity.
     *
     * When $expected is supplied, removal only happens if the registry still
     * holds that exact pool instance — the recycler uses this so an eviction
     * that yields while closing can never delete a replacement pool. Registry
     * entries are removed before closing so re-acquirers never see a closing
     * pool. Returns whether a pool was removed.
     */
    public function remove(string $identity, ?ObjectPool $expected = null): bool
    {
        $pool = $this->pools[$identity] ?? null;

        if ($pool === null || ($expected !== null && $pool !== $expected)) {
            return false;
        }

        unset($this->pools[$identity], $this->definitions[$identity]);

        $pool->close();

        return true;
    }

    /**
     * Remove and close all registered pools.
     */
    public function flush(): void
    {
        $pools = $this->pools;
        $this->pools = [];
        $this->definitions = [];

        foreach ($pools as $pool) {
            $pool->close();
        }
    }
}
```

Managed `getOrCreate()` deliberately has no destroy-callback parameter. Every managed re-acquisition comes through proxies that currently carry only the immutable definition and construction resolver; accepting teardown once at initial registration would silently lose it after purge or idle eviction, while accepting competing callbacks on convergence creates first-registration-wins behavior that cannot be compared. Standalone `ObjectPool`/`SimpleObjectPool` constructors retain their constructor-immutable destroy callback and its tested deterministic-teardown semantics. If a managed consumer later needs custom teardown, the callback must become constructor state on every recreating proxy and be supplied on every `getOrCreate()` call as part of one complete design; the manager must never expose a parameter that current proxies cannot preserve.

`Contracts/Factory` is rewritten to exactly this surface: `getOrCreate`, `get`, `has`, `pools`, `definition`, `remove`, `flush`.

### 2.5 `HasPoolProxy` (rewritten)

The trait keeps two invariants: manager-class namespacing is applied in one place, and the logical driver name (release-callback key) is separate from pool identity.

```php
trait HasPoolProxy
{
    protected array $releaseCallbacks = [];

    /**
     * Create a new pool proxy for the driver.
     */
    protected function createPoolProxy(string $driver, Closure $resolver, PoolDefinition $definition, string $proxyClass): mixed
    {
        if (! is_a($proxyClass, PoolProxy::class, true)) {
            throw new InvalidArgumentException('The pool proxy class must be an instance of ' . PoolProxy::class);
        }

        return new $proxyClass(
            $definition,
            $resolver,
            $this->poolFactory(),
            $this->getReleaseCallback($driver)
        );
    }

    /**
     * Build a pool definition for a resource with manager-class namespacing.
     *
     * $resource names the pooled resource kind (driver/transport name), and
     * $fingerprintSource is the construction input for the pooled object.
     * The `pool` key's `name`/`fingerprint` control fields select explicit
     * identity; the remaining pool keys become the normalized options.
     */
    protected function poolDefinition(string $resource, array $poolConfig, array $fingerprintSource): PoolDefinition
    {
        $explicitName = $poolConfig['name'] ?? null;
        $explicitFingerprint = $poolConfig['fingerprint'] ?? null;

        // Validate both as non-empty strings when present; throw otherwise.

        $options = PoolOptions::fromArray(Arr::except($poolConfig, ['name', 'fingerprint']));

        $fingerprint = $explicitFingerprint !== null
            ? PoolFingerprint::fromExplicit($explicitFingerprint)
            : PoolFingerprint::fromConfig($fingerprintSource);

        $identity = $explicitName !== null
            ? static::class . ':named:' . $explicitName
            : static::class . ':auto:' . $resource . ':' . $fingerprint;

        return new PoolDefinition($identity, $resource, $fingerprint, $options);
    }

    /**
     * Get the pool factory used to register this manager's pools.
     */
    abstract protected function poolFactory(): Factory;

    // setReleaseCallback / getReleaseCallback / addPoolable / removePoolable /
    // getPoolables / setPoolables stay exactly as they are today.
}
```

The proxy class is a required explicit argument — there is no `$poolProxyClass` property anywhere. The class is a compile-time choice each manager passes at its call sites (`TransportPoolProxy::class`, `QueuePoolProxy::class`, `BroadcastPoolProxy::class`); a property fallback would be mutable-looking state for a constant, and it breaks trait users that never call the helper — `FilesystemManager` uses the trait's other facilities while constructing its two proxy kinds directly (3.7). The `is_a()` validation stays: it guards subclass callers passing a non-proxy class.

Each manager implements `poolFactory()` as a one-liner resolving `Hypervel\ObjectPool\Contracts\Factory` from its container. Identity namespaces are disjoint by construction: automatic identities start `:auto:` and end in a domain-tagged hash; explicit identities start `:named:` — an explicit name can never collide with an automatic one, and two equal explicit names are equal on purpose. When automatic canonicalization fails (objects in `$fingerprintSource`) and no explicit fingerprint was supplied, the canonicalizer's exception propagates with the remedy text.

**Every value a managed pooled factory captures must derive from the fingerprinted construction input.** `getOrCreate()` keeps the first registrant's resolver on convergence (2.4), so a captured per-caller value the fingerprint excludes — a logical connection name in an error message, for instance — makes converged resolvers non-equivalent: the first registrant's diagnostics leak into every later caller's constructions. Where a manager wants the logical name for unpooled diagnostics, the pooled resolver goes name-free and reports the driver/resource instead (4.4 applies this to broadcasting). Standalone pools may own constructor-immutable teardown callbacks because they do not converge or recreate through the manager.

**Resource construction never sees the `pool` key.** Fingerprints exclude it, so the "fingerprint source equals construction input" invariant only holds if the constructed resource cannot read it either — and today every custom factory/connector receives the full config (`FilesystemManager::callCustomCreator($config)`, mail's custom creator invocation, `BroadcastManager::callCustomCreator()` at `BroadcastManager.php:324-327`, queue's `ConnectorInterface::connect($config)` + `setConfig($config)` at `QueueManager.php:258-262`). Every manager therefore passes construction config with `pool` stripped (`Arr::except($config, ['pool'])`) to custom creators, connectors, and pooled-resource resolver closures; the full config survives only on the manager/proxy surface (`getConfig()`). Tests in each consumer package assert a custom factory never receives pool-control metadata.

### 2.6 `ObjectPool` (rewritten lifecycle)

Ownership is tracked explicitly: the pool knows every object it manages and which of them are checked out. Double releases, foreign releases, and factories that return an already-managed instance (the container-auto-singleton trap, 1.1 #10) all fail fast instead of corrupting state.

```php
abstract class ObjectPool implements ObjectPoolContract
{
    protected Channel $channel;

    /** @var array<int, true> spl_object_id of every object this pool created and still manages */
    protected array $managed = [];

    /** @var array<int, true> spl_object_id of currently checked-out objects */
    protected array $borrowed = [];

    /** @var array<int, int> spl_object_id => hrtime ns at creation */
    protected array $creationTimes = [];

    /** @var array<int, int> spl_object_id => hrtime ns at last user release */
    protected array $releaseTimes = [];

    /** Last user activity (checkout or release), hrtime ns. */
    protected int $lastUsedAt;

    protected bool $closed = false;

    /** Checkouts in flight: waiting, running a factory, or replacing an expired object. */
    protected int $acquiring = 0;

    protected ?Closure $destroyCallback;

    public function __construct(
        protected PoolOptions $options,
        ?Closure $destroyCallback = null,
    ) {
        $this->destroyCallback = $destroyCallback;   // constructor-immutable; setDestroyCallback() is gone
        $this->channel = new Channel($options->maxObjects);
        $this->lastUsedAt = hrtime(true);   // a created-but-unused pool becomes evictable after the TTL
    }

    /**
     * Retrieve an object from the pool.
     */
    public function get(): object
    {
        if ($this->closed) {
            throw new RuntimeException('Cannot borrow from a closed pool.');
        }

        $this->lastUsedAt = hrtime(true);

        // One monotonic deadline for the complete checkout, including expired-
        // object replacement — a replacement never resets wait_timeout.
        $deadline = $this->deadline($this->options->waitTimeout);

        // The acquisition counter spans waiting, yielding factories, and the
        // window where a popped object is expiry-checked or destroyed but not
        // yet marked borrowed: isIdle() requires zero acquisitions, so the
        // recycler can never evict a pool out from under a live checkout, and
        // no object is ever in flight outside the pool's accounting.
        ++$this->acquiring;

        try {
            // getObject() expiry-checks only objects popped from idle storage.
            // A freshly-created object always receives its initial checkout;
            // otherwise a legal sub-operation-latency max_lifetime can make
            // create/destroy loop forever without reaching the wait deadline.
            $object = $this->getObject($deadline);

            $this->borrowed[spl_object_id($object)] = true;

            return $object;
        } finally {
            --$this->acquiring;
        }
    }

    /**
     * Assert an object is currently checked out from this pool.
     */
    protected function assertBorrowed(object $object): int
    {
        $id = spl_object_id($object);

        if (! isset($this->managed[$id])) {
            throw new RuntimeException('Cannot release or discard an object this pool does not manage.');
        }

        if (! isset($this->borrowed[$id])) {
            throw new RuntimeException('Cannot release or discard an object that is not checked out (double release?).');
        }

        return $id;
    }

    /**
     * Release an object back to the pool.
     */
    public function release(object $object): void
    {
        $id = $this->assertBorrowed($object);
        unset($this->borrowed[$id]);

        if ($this->closed) {
            $this->destroyObject($object);

            return;
        }

        $now = hrtime(true);
        $this->lastUsedAt = $now;
        $this->releaseTimes[$id] = $now;

        $this->channel->push($object);
    }

    /**
     * Destroy a checked-out object instead of returning it to the pool.
     *
     * For borrowers that corrupted or partially mutated the object (failed
     * configuration, failed reset) — returning it would poison later borrows.
     */
    public function discard(object $object): void
    {
        $id = $this->assertBorrowed($object);
        unset($this->borrowed[$id]);

        $this->destroyObject($object);
    }

    /**
     * Return an object to the idle channel without recording user activity.
     *
     * Maintenance requeues must not touch lastUsedAt or the object's release
     * time — otherwise every recycler pass would reset the idle clocks and
     * neither idle trimming nor pool eviction could ever fire.
     */
    protected function requeue(object $object): void
    {
        $this->channel->push($object);
    }

    /**
     * Destroy idle objects that exceed max_lifetime, ignoring the retention floor.
     */
    public function sweepExpired(): void
    {
        if ($this->options->maxLifetime <= 0.0) {
            return;
        }

        $count = $this->channel->length();

        while ($count-- > 0 && $object = $this->channel->pop()) {
            $this->exceedsMaxLifetime($object)
                ? $this->destroyObject($object)
                : $this->requeue($object);
        }
    }

    /**
     * Destroy idle objects past max_idle_time, down to the retention floor.
     */
    public function trimIdle(): void
    {
        if ($this->options->maxIdleTime <= 0.0) {
            return;
        }

        $count = $this->channel->length();
        $threshold = $this->nanoseconds($this->options->maxIdleTime);
        $now = hrtime(true);

        while ($count-- > 0 && count($this->managed) > $this->options->minRetainedObjects
            && $object = $this->channel->pop()
        ) {
            $idleSince = $this->releaseTimes[spl_object_id($object)] ?? $this->creationTimes[spl_object_id($object)];

            ($now - $idleSince) > $threshold
                ? $this->destroyObject($object)
                : $this->requeue($object);
        }
    }

    /**
     * Close the pool: destroy all idle objects and reject further checkouts.
     *
     * Idempotent. Objects borrowed when closure begins are destroyed on their
     * eventual release() — destroy callbacks always run; garbage collection
     * never runs them.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;

        // Closing the signal channel wakes every parked waiter at once; each
        // re-checks state, observes closure, and throws instead of sleeping
        // out its timeout. Checkouts suspended inside a factory observe
        // closure on completion and destroy their orphan (getObject()).
        $this->channel->close();

        while ($this->channel->length() > 0 && $object = $this->channel->pop()) {
            $this->destroyObject($object);
        }
    }

    public function isClosed(): bool;

    /**
     * Determine if the pool is evictable: idle-TTL configured, zero borrowed
     * objects, zero in-flight checkouts, and no user activity within the TTL.
     */
    public function isIdle(): bool
    {
        // $acquiring covers waiters and yielding factories: a checkout can be
        // parked (or creating) for longer than idle_ttl, and borrowed-only
        // accounting would let the recycler evict its live pool mid-borrow.
        return $this->options->idleTtl !== null
            && $this->acquiring === 0
            && $this->getBorrowedObjectNumber() === 0
            && (hrtime(true) - $this->lastUsedAt) > $this->nanoseconds($this->options->idleTtl);
    }

    /**
     * Return the number of objects currently checked out.
     */
    public function getBorrowedObjectNumber(): int
    {
        return count($this->borrowed);   // tracked state, never derived from channel arithmetic
    }

    public function getOptions(): PoolOptions;

    /**
     * Return statistics about the pool's current state.
     */
    public function getStats(): array
    {
        return [
            'total' => count($this->managed),
            'idle' => $this->getObjectNumberInPool(),
            'borrowed' => count($this->borrowed),
            'closed' => $this->closed,
        ];
    }

    // getObject() is rebuilt as a state-machine loop with reserved creation
    // capacity (see below).
    //
    // destroyObject() is the single destruction path and is no-throw beyond
    // its managed assertion: it asserts the object is managed (a second
    // destroy throws — that is a programming error caught before any state
    // change), then runs the destroy callback (when set) inside try/catch — a
    // throwing callback is reported through PoolErrorReporter, never
    // propagated — and removes the id from $managed/$creationTimes/
    // $releaseTimes and signals waiters (freed capacity) in a finally.
    // Cleanup failure can therefore never leave a managed ghost, abort
    // close()/PoolManager::remove()/flush() halfway, or mask the operation or
    // release exception that triggered a discard — which is what makes the
    // Lease semantics in 2.7 implementable.
    //
    // getCurrentObjectNumber() returns count($this->managed).
    // exceedsMaxLifetime() compares hrtime ns.
    // initOption()/PoolOption/setDestroyCallback() are gone.
}
```

`getObject()` — the borrow state machine:

```php
/** Creation slots reserved by factories currently running. */
protected int $creating = 0;

protected function getObject(int $deadline): object
{
    while (true) {
        if ($this->closed) {
            throw new RuntimeException('Cannot borrow from a closed pool.');
        }

        if ($object = $this->channel->pop()) {
            if ($this->exceedsMaxLifetime($object)) {
                $this->destroyObject($object);

                continue;
            }

            return $object;
        }

        // Reserve a creation slot BEFORE invoking the factory: factories may
        // yield (network, file I/O), so without the reservation multiple
        // coroutines observe spare capacity simultaneously and create past
        // max_objects. Every completion path releases the slot, and every
        // path that frees the slot without an object entering circulation
        // signals parked waiters — freed creation capacity is one of the two
        // things they are waiting for.
        if (count($this->managed) + $this->creating < $this->options->maxObjects) {
            ++$this->creating;

            try {
                $object = $this->createObject();
            } catch (Throwable $exception) {
                --$this->creating;
                $this->channel->signal();

                throw $exception;
            }

            --$this->creating;

            $id = spl_object_id($object);

            if (isset($this->managed[$id])) {
                // This reservation was capacity observed by parked waiters —
                // rejecting the duplicate frees it, so signal exactly like the
                // factory-failure path.
                $this->channel->signal();

                throw new RuntimeException(
                    'The pool factory returned an object this pool already manages '
                    . '(a container-resolved auto-singleton?). Factories must construct fresh instances.'
                );
            }

            $this->managed[$id] = true;
            $this->creationTimes[$id] = hrtime(true);

            // close() may have run while the factory was suspended. Register-
            // then-destroy so the destroy callback runs for the orphan, then
            // refuse the borrow — a closed pool never hands out objects.
            if ($this->closed) {
                $this->destroyObject($object);

                throw new RuntimeException('Cannot borrow from a closed pool.');
            }

            return $object;
        }

        // No idle object, no capacity: park until the pool state changes,
        // then loop and re-evaluate everything against the one deadline. A
        // wake from channel closure re-enters the loop and throws via the
        // closed check.
        if (! $this->waitForStateChange($deadline)) {
            throw new RuntimeException('Object pool exhausted. Cannot create new object before wait_timeout.');
        }
    }
}
```

`waitForStateChange()` parks on the signal channel until the remaining slice of the monotonic deadline elapses (returns `false` on timeout, `true` on any wake — including close, which the loop's next iteration turns into the closed-pool exception). Outside coroutine context it returns `false` immediately: non-coroutine execution is single-threaded, so no concurrent actor can change the pool state while the caller blocks.

**All second→nanosecond conversions saturate instead of wrapping.** `PoolOptions` rejects only non-finite values; a large *finite* duration still overflows the cast — `(int) (1e10 * 1e9)` wraps negative and `(int) (1e100 * 1e9)` is `0` (verified) — producing immediate timeouts and inverted expiry. Two private helpers on `ObjectPool` close this: `nanoseconds(float $seconds): int` returns `PHP_INT_MAX` when `$seconds >= PHP_INT_MAX / 1e9`, else the ordinary cast; `deadline(float $seconds): int` saturates the addition too (`$duration > PHP_INT_MAX - $now ? PHP_INT_MAX : $now + $duration`) — `hrtime(true)` is nanoseconds since boot, so even a non-wrapping near-maximum duration overflows when added on a long-running machine. `waitTimeout`, `maxLifetime`, `maxIdleTime`, and `idleTtl` all convert through `nanoseconds()`; the checkout deadline builds through `deadline()`. Oversized finite values get honest "never within process uptime" semantics with one comparison of overhead; no arbitrary configuration ceiling is introduced (none could account for variable boot-time headroom). `src/pool` mirrors both helpers package-locally for its `wait_timeout` deadline, per the sibling rule.

`Contracts/ObjectPool` is rewritten to match: `get`, `release`, `discard`, `sweepExpired`, `trimIdle`, `close`, `isClosed`, `isIdle`, `getBorrowedObjectNumber`, `getCurrentObjectNumber`, `getObjectNumberInPool`, `getOptions`, `getStats`. `flush()`/`flushOne()`/`getRecycleStrategy()`/`setRecycleStrategy()`/`getLastRecycledAt()`/`setLastRecycledAt()`/`getOption()`/`setDestroyCallback()` are removed. `SimpleObjectPool` keeps only the constructor (callback + `PoolOptions` + destroy-callback passthrough) and `createObject()`; `setCallback()` is deleted (no callers; definitions own construction).

`trimIdle()`'s floor compares against `count($this->managed)` (total managed, matching the old `currentObjectNumber` floor semantics), and both maintenance loops requeue via `requeue()` as specified. Maintenance destroys signal waiters (capacity freed) through `destroyObject()`.

### 2.7 `Lease` (new)

The exactly-once finalization primitive for every borrow — synchronous and deferred paths run the identical finalizer, so release callbacks can never be skipped on deferred paths.

```php
class Lease
{
    protected bool $finalized = false;

    /**
     * @param ?Closure(object): void $releaseCallback reset behavior to run
     *        before the object returns to the pool (the proxy's configured
     *        release callback travels with the lease)
     */
    public function __construct(
        protected ObjectPoolContract $pool,
        protected object $object,
        protected ?Closure $releaseCallback = null,
    ) {
    }

    /**
     * Get the borrowed object.
     */
    public function get(): object
    {
        if ($this->finalized) {
            throw new RuntimeException('The pool lease has already been finalized.');
        }

        return $this->object;
    }

    /**
     * Finalize the borrow: run the release callback, then return the object.
     *
     * Idempotent — deferred consumers finalize on multiple terminal paths. If
     * the release callback throws, the object is discarded (a partially-reset
     * object must not poison the pool) and the callback exception propagates
     * after the discard. The pool is contract-typed, so a conforming
     * implementation's discard() may throw — that failure is reported, never
     * allowed to mask the callback failure.
     */
    public function release(): void
    {
        if ($this->finalized) {
            return;
        }

        $this->finalized = true;

        try {
            if ($this->releaseCallback) {
                ($this->releaseCallback)($this->object);
            }
        } catch (Throwable $exception) {
            try {
                $this->pool->discard($this->object);
            } catch (Throwable $discardException) {
                PoolErrorReporter::report($discardException);
            }

            throw $exception;
        }

        $this->pool->release($this->object);
    }

    /**
     * Destroy the borrowed object instead of returning it.
     */
    public function discard(): void
    {
        if ($this->finalized) {
            return;
        }

        $this->finalized = true;
        $this->pool->discard($this->object);
    }

    /**
     * Finalize on destruction so abandoned deferred results cannot leak borrows.
     *
     * Destructors must never let exceptions escape: failures are reported and
     * swallowed.
     */
    public function __destruct()
    {
        try {
            $this->release();
        } catch (Throwable $exception) {
            PoolErrorReporter::report($exception);
        }
    }
}
```

`Lease` types its pool as `Contracts\ObjectPool` (imported as `ObjectPoolContract`), never the concrete base: it consumes only `release()`/`discard()`, both contract methods, and it receives its pool from `Factory::getOrCreate()`, which is contract-typed — a contract-conforming pool that does not extend the base class must work. `PoolProxy::pool()` returns the same contract type for the same reason.

`PoolErrorReporter` (new, object-pool package) is the one concrete reporting mechanism for contexts where throwing is impossible (destructors) or would mask a primary failure (`invoke()`'s finalization path): a small static class resolving `Hypervel\Contracts\Debug\ExceptionHandler` through `Container::getInstance()` when bound (static access is the sanctioned fallback where injection is unavailable — destructors are exactly that) and falling back to `error_log()` in bare containers. `report()` is itself contractually no-throw: resolving the handler and `ExceptionHandler::report()` can each throw (the contract declares it), so every internal failure is caught and falls back to `error_log()` — the destructor, destroy-callback, and cleanup-path guarantees all rest on this. `PoolProxy`, `Lease`, and `LeasedStream` all report through it, so the behavior is implementable from the package's actual dependencies rather than a hand-wave comment.

### 2.8 `PoolProxy` (rewritten)

```php
class PoolProxy
{
    public function __construct(
        protected PoolDefinition $definition,
        protected Closure $resolver,
        protected Factory $pools,
        protected ?Closure $releaseCallback = null,
    ) {
    }

    /**
     * Resolve the current pool for this proxy's definition.
     *
     * Resolved per operation, never cached: a proxy retained across purge or
     * idle eviction transparently re-acquires (or re-creates) the current pool.
     */
    protected function pool(): ObjectPoolContract
    {
        return $this->pools->getOrCreate($this->definition, $this->resolver);
    }

    /**
     * Borrow an object under a lease.
     *
     * The single borrow primitive for synchronous and deferred paths alike.
     * Configuration happens inside the guarded region: if configureBorrowed()
     * throws, the partially-configured object is discarded — never returned
     * to the pool — and no borrow leaks. The pool is contract-typed, so a
     * conforming discard() may throw: that failure is reported, never
     * allowed to mask the configuration failure.
     */
    protected function lease(): Lease
    {
        $pool = $this->pool();
        $object = $pool->get();

        try {
            $this->configureBorrowed($object);
        } catch (Throwable $exception) {
            try {
                $pool->discard($object);
            } catch (Throwable $discardException) {
                PoolErrorReporter::report($discardException);
            }

            throw $exception;
        }

        return new Lease($pool, $object, $this->releaseCallback);
    }

    /**
     * Invoke a synchronous method on a borrowed object.
     *
     * Protected by design: there is no public magic proxying anywhere in the
     * pool system. A generic __call() cannot know whether a return value is
     * lazy (stream, promise, iterator, the borrowed object itself) and would
     * silently release before the result is consumed. Consumer proxies
     * enumerate their full surface explicitly and route synchronous methods
     * through this primitive; unknown methods on consumer proxies throw.
     */
    protected function invoke(string $method, array $arguments): mixed
    {
        $lease = $this->lease();

        // A plain try/finally would let a throwing release callback replace
        // the operation's exception. Preserve the primary failure: when both
        // fail, the finalization failure is reported and the operation failure
        // rethrown; when the operation succeeded, finalization failure
        // propagates normally. The same rule applies to every synchronous
        // lease helper (ClientPooledFilesystem::withStack(), the with*
        // accessors, queue terminal operations).
        try {
            $result = $lease->get()->{$method}(...$arguments);
        } catch (Throwable $operationException) {
            try {
                $lease->release();
            } catch (Throwable $finalizationException) {
                PoolErrorReporter::report($finalizationException);
            }

            throw $operationException;
        }

        $lease->release();

        return $result;
    }

    /**
     * Apply proxy-held state to a freshly borrowed object.
     *
     * Subclasses that hold per-proxy state must write every managed slot on
     * every borrow — including null/defaults — so state set through one proxy
     * can never leak to another proxy sharing the same pool.
     */
    protected function configureBorrowed(object $object): void
    {
    }

    public function getDefinition(): PoolDefinition;

    /**
     * Get the pool identity for this proxy.
     */
    public function getPoolName(): string;   // $this->definition->identity

    /**
     * Remove and close this proxy's current pool.
     *
     * Deliberate shared-resource invalidation, not ownership teardown: other
     * proxies with the same identity keep working and re-acquire a fresh pool
     * on their next operation. Returns whether a pool was removed.
     */
    public function invalidatePool(): bool
    {
        return $this->pools->remove($this->definition->identity);
    }
}
```

### 2.9 `PoolRecycler` (renamed from `ObjectRecycler`, rewritten)

```php
protected function maintainPools(): void
{
    foreach ($this->manager->pools() as $identity => $pool) {
        if ($pool->isIdle()) {
            // Identity-conditional: closing a pool can yield (destroy callbacks
            // close sockets); by removing first and matching the exact instance,
            // a replacement pool registered mid-close can never be deleted.
            $this->manager->remove($identity, $pool);

            continue;
        }

        $pool->sweepExpired();
        $pool->trimIdle();
    }
}
```

Timer/interval/start/stop mechanics stay as today (default 10.0s), with one validation fix: the constructor routes the interval through `setInterval()` instead of a promoted direct assignment, and `setInterval()` requires `is_finite($interval) && $interval > 0.0`. The old `<= 0` check accepted `NAN` and `INF` (NAN fails every comparison), and the promoted constructor bypassed even that check, so `0.0`/negative intervals constructed successfully and only failed late as nonsensical `Timer::tick()` input. The rejection throws `InvalidArgumentException` ("The recycler interval must be a finite number greater than 0.") — the same duration rule and exception type as `PoolOptions::fromArray()`. The recycler's timer callback wraps `maintainPools()` and reports any `Throwable` through `PoolErrorReporter`; the coordinator `Timer` is constructed without a logger, so relying on its nullable logger catch would silently discard maintenance failures every interval. `Contracts/Recycler` drops `getLastRecycledAt()`. `ObjectPoolServiceProvider` binds `Recycler::class => PoolRecycler::class`; `Listeners/StartRecycler` is unchanged.

### 2.10 Seventh consumer: sentry

`src/sentry/src/Transport/Pool.php` extends `ObjectPool` directly and is used standalone (never registered in `PoolManager`, borrowed/released by `HttpPoolTransport`). Nothing maintains an unmanaged pool, so every maintenance-driven option is inert there: `idle_ttl`, `max_idle_time`, **and `min_retained_objects`** (it is consulted only by `trimIdle()`, which nothing calls). The effective configurable keys are exactly `max_objects`, `wait_timeout`, and `max_lifetime` (absolute expiry executes — it is checked on borrow). `SentryServiceProvider` accepts only those three in `sentry.pool` and rejects everything else (fail-fast, not silent ignoring), always building the options with `idle_ttl => null`, `max_idle_time => 0`, and the `min_retained_objects` default (irrelevant, never consulted); the sentry config file/docs list only the three supported keys.

`HttpPoolTransport` retains borrowed transports in coroutine context until its `close()`/defer path releases them — a legal long borrow, but it must be re-tested against the new ownership tracking (release-once assertions) and the closed-pool late-release behavior. Update its usage for any contract changes and keep its lifecycle semantics identical, with one deliberate change: `send()` currently releases a transport back to the pool after an unexpected `Throwable` from `$transport->send()` (`HttpPoolTransport.php:51-61`, 1.1 #17) — it now calls `discard()` instead, because a transport that threw mid-send may hold failed or pending mutable state and must not be handed to the next borrower. The failed `Result` return is preserved; test that the next borrow after a failure creates a replacement transport.

## Part 3 — Filesystem: client-pooled cloud disks

### 3.1 Client config derivation

New protected methods on `FilesystemManager`, one per built-in cloud driver. The invariant: **the normalized array hashed for client identity is exactly the array passed to the client constructor.**

```php
/**
 * Derive the S3 client construction config from a disk config.
 */
protected function s3ClientConfig(array $config): array
{
    $config = $this->formatS3Config($config);   // existing key/secret/token → credentials normalization

    // Positive selection: only keys the installed SDK declares as client
    // constructor arguments. Adapter-level keys (bucket, root, stream_reads,
    // visibility, throw, ...) are ignored by the SDK today and are excluded
    // from identity by construction here.
    $clientConfig = Arr::only($config, static::s3ArgumentNames());

    return array_merge($clientConfig, $this->clientConfigBlock($config, static::s3ArgumentNames()));
}

/**
 * Get the S3 client constructor argument names.
 */
protected static function s3ArgumentNames(): array
{
    // Static property cache (worker-lifetime; the set is immutable per process):
    return self::$s3ArgumentNames ??= array_keys(S3Client::getArguments());
}

/**
 * Get the explicit client-option block from a disk config.
 */
protected function clientConfigBlock(array $config, array $supportedKeys): array
{
    $block = $config['client'] ?? [];

    if (! is_array($block)) {
        throw new InvalidArgumentException('The disk "client" configuration option must be an array.');
    }

    // An unknown key would be silently ignored by the SDK but still hashed,
    // splitting equivalent pools — and a typo would go unnoticed. Fail fast.
    if ($unknown = array_diff(array_keys($block), $supportedKeys)) {
        throw new InvalidArgumentException(
            'Unknown client option(s) [' . implode(', ', $unknown) . '] in the disk "client" configuration.'
        );
    }

    return $block;   // explicit block wins in the array_merge above
}
```

S3 passes `static::s3ArgumentNames()` as `$supportedKeys`; GCS passes the framework-owned `StorageClient` option map. The invariant holds strictly: the validated, merged array is both what the client constructor receives and what gets fingerprinted.

GCS equivalent: `gcsClientConfig(array $config): array` — the existing `formatGcsConfig()` camel-casing runs first, then the flat Laravel-shaped keys are selected (`keyFilePath`, `keyFile`, `projectId`, `apiEndpoint` — what `createGcsClient()` at `FilesystemManager.php:332-353` recognizes today), merged with the `client` block. The `client` block's allowlist is **the installed `StorageClient`'s full documented constructor surface**, not the four flat keys — freezing the old creator's limitation into the new extension point would be a new arbitrary restriction. Verified against the installed SDK's constructor docblock: `apiEndpoint`, `projectId`, `authCache`, `authCacheOptions`, `authHttpHandler`, `credentialsFetcher`, `httpHandler`, `keyFile`, `keyFilePath`, `requestTimeout`, `retries`, `retryStrategy`, `restDelayFunction`, `restCalcDelayFunction`, `restRetryFunction`, `restRetryListener`, `scopes`, `quotaProject`. Object/callable options (`authCache`, `credentialsFetcher`, the handlers, the retry callbacks) make automatic fingerprinting throw with the explicit-`pool.fingerprint` remedy, as designed. One SDK fact recorded here because the docs must reflect it: the installed SDK deprecates `keyFile`/`keyFilePath` in favor of `credentialsFetcher` (security guidance for externally-sourced credential configs); the flat keys stay supported for Laravel-shaped configs, and `filesystem.md` documents `credentialsFetcher` via the `client` block plus an explicit fingerprint as the recommended alternative. `createGcsClient()` is updated to consume exactly the derived array.

`self::$s3ArgumentNames` gets `flushState()` and an `AfterEachTestSubscriber` entry per the static-state conventions.

Client factories, split out of the current creators:

```php
/**
 * Create an S3 client from derived client config.
 */
protected function createS3Client(array $clientConfig): S3Client
{
    return new S3Client($clientConfig);
}
```

(`ClientResolver` ignores unknown input keys, so positive selection does not change what the client sees — verified.) Objects/closures inside the derived client array (e.g. a `credentials` provider callable in the `client` block) make automatic fingerprinting throw with the explicit-identity remedy, as designed.

### 3.2 Stack factories

The existing `createS3Driver()`/`createGcsDriver()` are split so the adapter stack can be built around any client:

```php
/**
 * Build the S3 disk adapter stack around a client.
 */
protected function buildS3Disk(S3Client $client, array $config): AwsS3V3Adapter
{
    $s3Config = $this->formatS3Config($config);
    $root = (string) ($s3Config['root'] ?? '');
    $visibility = new AwsS3PortableVisibilityConverter($config['visibility'] ?? Visibility::PUBLIC);
    $streamReads = $s3Config['stream_reads'] ?? false;

    $adapter = new S3Adapter($client, $s3Config['bucket'], $root, $visibility, null, $config['options'] ?? [], $streamReads);

    return new AwsS3V3Adapter($this->createFlysystem($adapter, $config), $adapter, $s3Config, $client);
}

/**
 * Create an instance of the Amazon S3 driver.
 */
public function createS3Driver(array $config): Cloud
{
    return $this->buildS3Disk($this->createS3Client($this->s3ClientConfig($config)), $config);
}
```

Same split for GCS (`buildGcsDisk(StorageClient $client, array $config)` around the existing adapter construction), with one correction to that construction (1.1 #25): `buildGcsDisk()` routes through the shared `createFlysystem()` instead of constructing `Flysystem` directly, restoring `read-only`/`prefix` support and the common six-key Flysystem-config filter for GCS. The regression test builds a real GCS stack around mocked SDK objects and asserts the outer `PathPrefixedAdapter` (exact prefix) wrapping the `ReadOnlyFilesystemAdapter`, surviving visibility/URL config, and no leakage of `bucket`/`root`/`prefix`/`read-only`/`throw` into the Flysystem config. `createS3Driver()`/`createGcsDriver()` remain the unpooled compositions, used when the driver has been removed from `$poolables`.

### 3.3 `ClientPooledFilesystem` (new)

The per-disk operation context for built-in cloud drivers. It is cached in `$disks` like any disk; it is **not** a `PoolProxy` — the pool holds clients, not driver stacks.

```php
class ClientPooledFilesystem implements Cloud
{
    protected ?Closure $serveCallback = null;

    protected ?Closure $temporaryUrlCallback = null;

    protected ?Closure $temporaryUploadUrlCallback = null;

    /**
     * @param Closure(): object $clientFactory creates a client from the derived client config
     * @param Closure(object): FilesystemAdapter $stackFactory builds the disk stack around a borrowed client
     */
    public function __construct(
        protected PoolDefinition $definition,
        protected Closure $clientFactory,
        protected Closure $stackFactory,
        protected Factory $pools,
        protected array $config,
        protected ?Closure $releaseCallback = null,
    ) {
    }

    /**
     * Run an operation against a disk stack built around a borrowed client.
     *
     * Uses the same primary-exception-preservation pattern as
     * PoolProxy::invoke(): a throwing finalization is reported, never allowed
     * to mask the operation's own exception.
     */
    protected function withStack(Closure $operation): mixed
    {
        [$lease, $stack] = $this->leaseStack();

        try {
            $result = $operation($stack);
        } catch (Throwable $operationException) {
            try {
                $lease->release();
            } catch (Throwable $finalizationException) {
                PoolErrorReporter::report($finalizationException);
            }

            throw $operationException;
        }

        $lease->release();

        return $result;
    }

    /**
     * Borrow a client under a lease and build a stack for deferred-result methods.
     *
     * @return array{0: Lease, 1: FilesystemAdapter}
     */
    protected function leaseStack(): array
    {
        $pool = $this->pools->getOrCreate($this->definition, $this->clientFactory);
        $lease = new Lease($pool, $pool->get(), $this->releaseCallback);

        try {
            return [$lease, $this->buildStack($lease->get())];
        } catch (Throwable $exception) {
            try {
                $lease->discard();
            } catch (Throwable $discardException) {
                PoolErrorReporter::report($discardException);
            }

            throw $exception;
        }
    }

    /**
     * Build the disk stack around a borrowed client, applying disk callbacks.
     */
    protected function buildStack(object $client): FilesystemAdapter
    {
        $stack = ($this->stackFactory)($client);

        if ($this->serveCallback) {
            $stack->serveUsing($this->serveCallback);
        }
        if ($this->temporaryUrlCallback) {
            $stack->buildTemporaryUrlsUsing($this->temporaryUrlCallback);
        }
        if ($this->temporaryUploadUrlCallback) {
            $stack->buildTemporaryUploadUrlsUsing($this->temporaryUploadUrlCallback);
        }

        return $stack;
    }

    public function __call(string $method, array $parameters)
    {
        throw new BadMethodCallException(
            "Method [{$method}] is not supported on pooled disks: an unmapped call could return "
            . 'a lazy result (stream, iterator, DirectoryListing) that outlives its borrow. '
            . 'Use withDriver()/withAdapter()/withClient() for borrow-scoped raw access.'
        );
    }
}
```

There is no dynamic forwarding: an unknown method (including Flysystem's lazy `listContents()`, whose `DirectoryListing` would escape the borrow, and macros, whose return values are unknowable) throws. Raw or custom work goes through the borrow-scoped `with*` callbacks. The `Storage` facade annotations are updated accordingly (drop `listContents()`/`getDriver()`, add the `with*` and lifecycle surfaces).

Method surface:

| Group | Methods | Implementation |
|---|---|---|
| Full synchronous adapter surface, explicitly enumerated | `path`, `exists`, `missing`, `fileExists`, `fileMissing`, `directoryExists`, `directoryMissing`, `get`, `json`, `put`, `putFile`, `putFileAs`, `writeStream`, `getVisibility`, `setVisibility`, `prepend`, `append`, `delete`, `copy`, `move`, `size`, `checksum`, `mimeType`, `lastModified`, `files`, `allFiles`, `directories`, `allDirectories`, `makeDirectory`, `deleteDirectory`, `url`, `temporaryUrl`, `temporaryUploadUrl`, `providesTemporaryUrls`, `providesTemporaryUploadUrls`, the four assertions, **and the six synchronous Flysystem `FilesystemOperator` methods the `Storage` facade advertises** — `has`, `read`, `fileSize`, `visibility`, `write`, `createDirectory` (fully consumed within the call; direct disks serve them through `FilesystemAdapter::__call`'s driver forwarding, so pooled disks must enumerate them for parity) | Explicit signatures matching `FilesystemAdapter` delegating through `withStack()`. Results are computed inside the borrow, so try/finally is sound. Assertions return `$this`. |
| Fluent control flow | `when`, `unless` | Via the `Conditionable` trait on the proxy (also on `FilesystemPoolProxy` and the scoped decorators). Proxy-level control flow: the callback receives the proxy itself and uses its enumerated surface — no borrow involved, no lazy result. Macros stay rejected: macro bodies are registered against `FilesystemAdapter`, expect adapter internals, and have unknowable result lifetimes. |
| Deferred streams | `readStream`, `readStreamRange` | `[$lease, $stack] = $this->leaseStack();` open the stream; if the result is not a resource (adapter returns null on failure), release the lease and return the non-resource result; on exception, discard/release guarded (a throwing finalization is reported, never masking the stream failure) and rethrow; otherwise return `LeasedStream::wrap($resource, $lease)` (3.5). |
| Deferred responses | `response`, `serve`, `download` | Proxy-level via the shared `FileResponseBuilder` (3.6): short metadata borrows, and the returned response holds only a stream resolver that borrows when streaming begins. When a `serveCallback` is set, `serve()` invokes it directly at proxy level — the callback receives `(request, path, headers)`, not an adapter, so no borrow is involved. |
| Rejected internals | `getDriver`, `getAdapter`, `getClient` | Throw `RuntimeException`: "Pooled disks do not expose borrowed internals. Use withDriver()/withAdapter()/withClient() for borrow-scoped access." |
| Borrow-scoped access | `withDriver(Closure)`, `withAdapter(Closure)`, `withClient(Closure)` | `withStack()` delegating the respective object into the callback; return the callback's result. |
| Disk config | `getConfig()` | Returns `$this->config` (shallow copy semantics; docblock states nested objects remain shared references — no deep-immutability claim). |
| Callback mutators | `serveUsing`, `buildTemporaryUrlsUsing`, `buildTemporaryUploadUrlsUsing` | Store on the proxy. Every stack is built per-disk/per-operation, so cross-disk leakage is structurally impossible and no slot-clearing is needed. Keep `Boot-only.` docblocks (still worker-lifetime manager-cached state). |
| Pool lifecycle | `getDefinition()`, `getPoolName()`, `invalidatePool()` | As on `PoolProxy` (same semantics; delegates to `Factory::remove`). |

Release-callback semantics change for client-pooled drivers and must be documented on `setReleaseCallback()`: the callback receives the **pooled object** — a concrete SDK client for built-in s3/gcs, a whole driver adapter for custom poolable drivers. It travels inside the `Lease` (2.7), so synchronous and deferred paths run it identically.

### 3.4 `FilesystemPoolProxy` (whole-driver pooling for custom drivers)

Stays a `PoolProxy` subclass implementing `Cloud`, for `extend(..., poolable: true)` drivers where the framework cannot split the resource. Changes:

- Constructor extended with the disk config (the manager constructs it directly rather than through the trait helper): `__construct(PoolDefinition $definition, Closure $resolver, Factory $pools, array $config, ?Closure $releaseCallback = null)`.
- The full synchronous surface is enumerated explicitly (same list as 3.3) through the protected `invoke()`; there is no dynamic forwarding — `PoolProxy` no longer has a public `__call`, and this class's `__call` throws the same `BadMethodCallException` as 3.3 (lazy `listContents()`, macros, and any unknown method are rejected).
- `readStream`/`readStreamRange` switch to `$this->lease()` + `LeasedStream::wrap()`, with the same non-resource and exception handling as 3.3.
- `response`/`serve`/`download` implemented proxy-level via `FileResponseBuilder`, same shape as 3.3.
- `getDriver`/`getAdapter`/`getClient` throw; `withDriver`/`withAdapter`/`withClient` added (borrow-scoped callbacks via `invoke()`-style leases).
- `getConfig()` returns the stored disk config.
- `serveUsing`/`buildTemporaryUrlsUsing`/`buildTemporaryUploadUrlsUsing` store on the proxy; `configureBorrowed()` writes **all three slots on every borrow, including null** — pooled driver objects are shared by every proxy with the same identity, so a callback set through one proxy must never leak into another's borrow. This requires the three `FilesystemAdapter` mutators to accept `?Closure` (they are non-nullable today, so null slots could not be written) — see 3.8.
- **Capability rule for the slot writes:** the mutators are Hypervel `FilesystemAdapter` methods, not `Filesystem` contract methods, and a custom poolable creator may return any contract implementation. `configureBorrowed()` checks `instanceof FilesystemAdapter`: capable borrows get the write-all-slots rule above; for a non-adapter borrow, all-null slots skip configuration entirely (a plain contract driver pools fine), and a set callback throws a `RuntimeException` naming the inner class and stating that serve/temporary-URL callbacks require a `FilesystemAdapter`-based driver. The check runs at borrow time because that is the first moment the resolver's concrete type exists; the message carries the remediation.

`ClientPooledFilesystem` and `FilesystemPoolProxy` share their byte-identical enumerated facade, response-building, rejection, config, callback-slot, and pool-lifecycle surface through one narrow package-local concern in `src/filesystem/src/Concerns/`. The concern depends on one explicit abstract lease/borrow hook implemented by each proxy; it does not own driver construction or path rewriting. Keep `ScopedFilesystemProxy` fully explicit because its method bodies form a security boundary and differ by path mapping. This removes real same-package duplication without building a generalized filesystem-proxy hierarchy.

### 3.5 `LeasedStream` (new, filesystem package)

A user-space stream wrapper that owns a `Lease` and forwards to an inner resource, releasing the lease exactly once on explicit close or wrapper destruction (abandonment) — **never on EOF**: a seekable stream can be rewound and read again after EOF was observed, so EOF is not a terminal event.

```php
final class LeasedStream
{
    public const PROTOCOL = 'hypervel-leased';

    /** @var resource */
    public $context;

    /** @var resource */
    protected $inner;

    protected Lease $lease;

    /**
     * Wrap a resource so the lease is released when the stream is closed or destroyed.
     *
     * @param resource $resource
     * @return resource
     */
    public static function wrap($resource, Lease $lease)
    {
        if (! is_resource($resource)) {
            throw new InvalidArgumentException('LeasedStream::wrap() expects an open stream resource.');
        }

        // Transactional: once wrap() has accepted the resource and lease it
        // owns them until ownership transfers to the successfully opened
        // wrapper. Any failure — protocol collision, registration failure,
        // context/open error — closes the raw resource and finalizes the
        // lease exactly once, with cleanup failures reported rather than
        // allowed to mask the primary failure.
        try {
            static::register();

            $context = stream_context_create([self::PROTOCOL => ['inner' => $resource, 'lease' => $lease]]);

            $stream = fopen(self::PROTOCOL . '://stream', 'r', false, $context);

            if ($stream === false) {
                throw new RuntimeException('Unable to open the leased stream wrapper.');
            }

            return $stream;
        } catch (Throwable $primaryException) {
            try {
                fclose($resource);
            } catch (Throwable $cleanupException) {
                PoolErrorReporter::report($cleanupException);
            }

            try {
                $lease->release();
            } catch (Throwable $cleanupException) {
                PoolErrorReporter::report($cleanupException);
            }

            throw $primaryException;
        }
    }

    protected static bool $registered = false;

    /**
     * Register the stream wrapper protocol once per process.
     */
    protected static function register(): void
    {
        if (static::$registered) {
            return;
        }

        // Detect protocol squatting instead of trusting it: a pre-existing
        // foreign wrapper under this name would receive the inner resource
        // and lease from the context and do who-knows-what with them.
        if (in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            throw new RuntimeException(
                'The [' . self::PROTOCOL . '] stream wrapper protocol is already registered by other code; '
                . 'leased streams cannot hand their lease to a foreign wrapper.'
            );
        }

        if (! stream_wrapper_register(self::PROTOCOL, self::class)) {
            throw new RuntimeException('Unable to register the [' . self::PROTOCOL . '] stream wrapper.');
        }

        static::$registered = true;
    }

    // stream_open() pulls inner + lease from the context options.
    //
    // The wrapper preserves the practical read-stream contract, not just the
    // read loop: stream_read/stream_eof/stream_seek/stream_tell/stream_stat
    // forward to the inner resource (fread/feof/fseek/ftell/fstat);
    // stream_cast() returns the inner resource so stream_select() and other
    // resource-casting APIs keep working; stream_set_option() forwards
    // blocking, read-timeout, and write-buffer options to the inner resource
    // and returns false for anything else — false, not a silent behavioral
    // change. Its verified PHP 8.4 callback shape (probed via a recording
    // wrapper): the signature is (int $option, int $arg1, ?int $arg2) — the
    // engine passes NULL as $arg2 for stream_set_blocking(), so an `int`
    // third parameter is a deterministic TypeError before any forwarding
    // runs. Branch mapping: STREAM_OPTION_BLOCKING forwards (bool) $arg1;
    // STREAM_OPTION_READ_TIMEOUT forwards stream_set_timeout($inner, $arg1,
    // $arg2 ?? 0) (seconds, microseconds); STREAM_OPTION_WRITE_BUFFER's
    // $arg1 is the STREAM_BUFFER_* mode and $arg2 the size — NONE forwards
    // stream_set_write_buffer($inner, 0), FULL forwards ($inner, $arg2 ?? 0),
    // any other mode returns false. Forwarding $arg1 as the size is wrong:
    // stream_set_write_buffer($s, 8192) arrives as (mode FULL = 2,
    // size 8192), so the inner buffer would be set to 2 bytes.
    //
    // stream_close() fcloses the inner resource and releases the lease in a
    // finally — the lease is released even when closing the inner resource
    // fails. PHP stream callbacks and destructors are no-throw reporting
    // paths: finalization failures during close are reported through
    // PoolErrorReporter and never propagate, so an fclose() in cleanup code
    // (the response builder's finally, a caller's try/finally) can never mask
    // the primary read/write exception that got it there. The wrapper
    // instance's destructor does the same (idempotent Lease), covering
    // abandonment without a close.
}
```

The inner resource and lease travel through the stream context — no static per-resource registry, so there is no shared state to flush between tests. The `$registered` flag needs no `flushState()`: it mirrors the wrapper registration itself, which is intentionally process-global and permanent — resetting the flag without unregistering would turn every later registration attempt into a false collision.

### 3.6 `FileResponseBuilder` (new) and `FilesystemAdapter` response refactor

The range/streamed-response algorithm currently lives inside `FilesystemAdapter::response()` (`:274-352`). Extract it so direct and pooled disks share one implementation and pooled disks never capture a temporary adapter:

```php
class FileResponseBuilder
{
    /**
     * Build a (range-aware) streamed file response.
     *
     * @param Closure(): (false|string) $mimeType resolves the content type;
     *        called once while building headers
     * @param Closure(): int $size resolves the file size; called only for
     *        Range requests (keeps non-range responses to a single metadata
     *        call, matching current behavior)
     * @param Closure(?int $start, ?int $end): resource $streamResolver
     *        opens the (range) read stream; called inside the response's
     *        output callback when streaming begins. Pooled disks return a
     *        LeasedStream-wrapped resource, so the builder's post-emission
     *        fclose() releases the borrow; an unemitted response never
     *        borrows at all.
     */
    public function build(Request $request, Response $response, string $path, ?string $name, array $headers, string $disposition, Closure $mimeType, Closure $size, Closure $streamResolver): Response;
}
```

- `FilesystemAdapter::response()` becomes a thin call: `$mimeType`/`$size` read `$this->mimeType()`/`$this->size()`; the stream resolver calls `$this->readStream()`/`$this->readStreamRange()` directly (no borrow involved — plain disks return the raw resource).
- `ClientPooledFilesystem`/`FilesystemPoolProxy` pass: each metadata closure is one short borrow; the stream resolver is a `leaseStack()` borrow returning `LeasedStream::wrap($resource, $lease)`. Closing the stream releases the lease. The returned `Response` retains only closures — never an adapter instance.
- The header/If-Range/206 logic moves into the builder (`hasValidIfRangeHeader()` and `validateRangeHeaders()` with it — verified: no callers outside `FilesystemAdapter`; `fallbackName()` too, a pure string transform), **improved rather than moved verbatim**:
  - The `Request` is an explicit parameter — `serve()` passes the request it received instead of the builder re-resolving one from the container (`response()` without a request argument still resolves the context request, once, at the call site).
  - The output callback validates the resolver returned a resource, wraps the streaming loop in try/finally so the stream is closed (and the lease released) even when `StreamOutput::write()` throws mid-emission, and distinguishes `fread()` results: `false` is a read error (stop; the current code spins until `feof()`); `''` triggers an EOF re-check and fails when the blocking stream produced no data before EOF (never spin or use an arbitrary retry count); and content `"0"` is written (the current `if (! $content)` silently drops a chunk that is exactly the string `"0"` — 1.1 #13). When the emission already failed and closing the stream (or releasing its lease) also fails, the close failure is reported through `PoolErrorReporter` and the primary read/write exception propagates — the same masking rule as every other cleanup path.
  - `If-Range` entity tags use the RFC-required strong comparison: weak validators on either side fall back to the full response, and entity-tag validation never falls through into the HTTP-date path.
  - **Range responses cap emitted bytes at `end - start + 1`** regardless of what the resolver returns. Today's loop streams until EOF and only server-enforced ranges (GCS) mask that; a generic seeked stream or a faulty custom adapter must never emit through EOF under a 206 status.
  - Range parsing follows the single supported byte-range grammar strictly — anchored `^bytes=(\d*)-(\d*)$` with a case-insensitive range unit (`Bytes=0-1` is valid; RFC 9110 range units are case-insensitive, today's `bytes=` gate is not) and at least one side non-empty, preserving all three valid forms: `bytes=N-M`, `bytes=N-` (open-ended), and `bytes=-N` (suffix). A suffix longer than the file (`bytes=-999` for a 10-byte file) is satisfiable and means the whole representation (today it computes a negative start and 416s — an RFC violation); `bytes=-0` and starts at/past the file size keep the 416 behavior. Malformed values (`bytes=abc-def`) and multi-range lists get the full-content 200 response instead of cast garbage (today they cast to `0`).
  - `fread() === false` raises a stream-read exception — a truncated body must never be served as a successful response.
  - Tests cover: output-write exceptions, a body of exactly `"0"`, `false`/non-resource stream results (exception, not truncation), suffix and open-ended ranges, oversized suffixes (whole file, 206 with full range), case-insensitive range units, the exact byte cap (resolver stream longer than the range), malformed and multi-range headers, unsatisfiable ranges (416), close-failure reporting under a primary emission exception, and range handling driven by the passed request.

`readStreamRange()` becomes real for every built-in driver — today the base implementation throws (`FilesystemAdapter.php:713-716`) and only GCS overrides it, so S3 and local range responses are broken and §6.2's local-disk range tests could never pass:

- **One argument contract across all three drivers**, enforced before any I/O by a protected normalization helper on `FilesystemAdapter` (returns `array{?int, ?int}`; S3/GCS call it before building their native `Range` header, the base before suffix-size resolution — one implementation, no drift). `null, null` means the full stream and delegates to `readStream($path)` in every driver (a blind cloud interpolation would send the invalid `bytes=-` header). Everything else validates fail-fast with `InvalidArgumentException` naming the valid forms: provided values must be non-negative; `start <= end` when both are provided; the zero-byte suffix `null, 0` is rejected (no satisfiable range — the HTTP 416 decision belongs to the response builder, which never forwards it here). The valid forms are exactly `start, end` (bounded), `start, null` (open-ended), and `null, positiveEnd` (suffix length) — narrowing away the suffix form would orphan the native suffix support the response builder uses.
- Base `FilesystemAdapter::readStreamRange()`: a sound generic path — open `readStream()`, resolve a null `$start` (suffix form) against `size()`, then position the stream: `fseek()` when seekable, read-and-discard up to `$start` when not. The end bound is enforced by the builder's byte cap, not by the stream.
- Hypervel `AwsS3V3Adapter` overrides **both `readStream()` and `readStreamRange()`** through one shared `readStreamWithOptions(string $path, array $operationOptions)` helper (the same structure the GCS adapter already has), which also fixes the upstream option-merge bug at the wrapper boundary (1.1 #24): start from the configured adapter `options`; overwrite the framework-owned `Bucket`, prefixed `Key`, and optional `Range` (configured options never replace those); when `stream_reads` is enabled and `@http.stream` is absent, merge only `stream => true` into the existing `@http` array — siblings like `timeout` survive, and an explicit `stream => false` wins. Ranges go through a native ranged `GetObject`, so S3 serves them without downloading the whole object. Error semantics align with `readStream()`'s: catch `UnableToReadFile`, rethrow when `throwsExceptions()`, otherwise report and return null — matching the advertised nullable result (the first-cut range method always threw). The pooled lease paths are unchanged: a null result releases immediately, a throw follows the guarded failure path. Tests: regular and ranged reads preserve a sibling `@http.timeout` while adding `stream`; explicit `stream => false` wins; framework-owned `Bucket`/`Key`/`Range` win over configured options; throw-vs-null behavior per the `throw` config.
- `GoogleCloudStorageAdapter` keeps its native `Range`-header path (verified at `GoogleCloudStorageAdapter.php:89-102`), now behind the shared normalization, and its read helper aligns with S3/base error semantics (1.1 #25): both failure branches — client exceptions and detached non-resource results — wrap in `UnableToReadFile`, rethrow when `throwsExceptions()`, otherwise report and return null. Tests cover the throw-vs-null matrix for plain and ranged reads.
- Range-resolver tests run against all three: local (generic seek path), S3, and GCS (fake clients, native range option asserted on the captured request), plus the shared contract per driver — both-null delegates to `readStream()`, and the invalid matrix (negative start/end, `start > end`, `null, 0`) rejects before I/O.

### 3.7 `FilesystemManager` restructure

```php
protected function resolve(string $name, ?array $config = null): mixed
{
    $config ??= $this->getConfig($name);

    if (empty($config['driver'])) {
        throw new InvalidArgumentException("Disk [{$name}] does not have a configured driver.");
    }

    $driver = $config['driver'];
    $hasPool = in_array($driver, $this->poolables, true);

    // Resources never see pool-control metadata (2.5): custom creators and
    // pooled-resource resolvers receive the construction config, so the
    // fingerprint (which excludes `pool`) equals the real construction input.
    $constructionConfig = Arr::except($config, ['pool']);

    if (isset($this->customCreators[$driver])) {
        return $hasPool
            ? $this->createDriverPooledDisk($driver, $config, null, fn () => $this->callCustomCreator($constructionConfig))
            : $this->callCustomCreator($constructionConfig);
    }

    if ($hasPool && ($driver === 's3' || $driver === 'gcs')) {
        return $this->createClientPooledDisk($driver, $config);
    }

    $driverMethod = 'create' . ucfirst($driver) . 'Driver';

    if (! method_exists($this, $driverMethod)) {
        throw new InvalidArgumentException("Driver [{$driver}] is not supported.");
    }

    if ($hasPool) {
        // Poolable built-in drivers other than s3/gcs (e.g. addPoolable('local')
        // in tests, or sftp made poolable at boot) keep whole-driver pooling —
        // the framework only knows how to split its cloud drivers.
        return $this->createDriverPooledDisk($driver, $config, $name, fn () => $this->{$driverMethod}($constructionConfig, $name));
    }

    return $this->{$driverMethod}($config, $name);
}

/**
 * Create a whole-driver pooled disk for a driver the framework cannot split.
 *
 * The fingerprint covers every real factory argument. Built-in driver methods
 * receive ($config, $name) and use the name (createLocalDriver() configures
 * diskName() and signed-URL serving from it), so the disk name is construction
 * input: two identically-configured named local disks must not converge on a
 * pool of adapters built with whichever name won first. Custom creators
 * receive only ($app, $config), so their construction input is config-only
 * (diskPoolDefinition() nulls the name for them).
 */
protected function createDriverPooledDisk(string $driver, array $config, ?string $name, Closure $resolver): FilesystemPoolProxy
{
    return new FilesystemPoolProxy(
        $this->diskPoolDefinition($driver, $config, $name),
        $resolver,
        $this->poolFactory(),
        $config,
        $this->getReleaseCallback($driver)
    );
}

/**
 * Create a client-pooled cloud disk for a built-in driver.
 */
protected function createClientPooledDisk(string $driver, array $config): ClientPooledFilesystem
{
    $clientConfig = $driver === 's3' ? $this->s3ClientConfig($config) : $this->gcsClientConfig($config);

    return new ClientPooledFilesystem(
        $this->poolDefinition($driver, $config['pool'] ?? [], $clientConfig),
        fn () => $driver === 's3' ? $this->createS3Client($clientConfig) : $this->createGcsClient($clientConfig),
        fn (object $client) => $driver === 's3' ? $this->buildS3Disk($client, $config) : $this->buildGcsDisk($client, $config),
        $this->poolFactory(),
        $config,
        $this->getReleaseCallback($driver),
    );
}
```

- The fingerprint source for client-pooled disks is the derived client config — `documents` and `archives` on one account converge on `FilesystemManager:auto:s3:{digest}`. Pool options are shared-resource options: two disks converging with different `pool` sizing hit the `getOrCreate()` options-mismatch error, which names the identity and the differing fields.
- `FilesystemManager` constructs both proxy kinds explicitly and never calls the trait's `createPoolProxy()` helper; it keeps the trait for pool definitions, release callbacks, and the poolable controls. No `$poolProxyClass` property exists on any manager (2.5). The remaining `createPoolProxy()` callers are `MailManager`, `QueueManager`, and `BroadcastManager` (HTTP and notification pooling are deleted).
- `disk()`, `build()`, `createScopedDriver()`, `getDefaultDriver()`, `extend()`, `set()`, `setApplication()` keep their surfaces. `build()` needs no special casing anymore — every construction converges by fingerprint.
- `forgetDisk()` and `set()` stay cache-only (docblocks updated to say so and why: pools are shared resources reclaimed by TTL or purge).
- `purge()` becomes cache + pool invalidation:

```php
/**
 * Disconnect the given disk: drop it from the local cache and close its pool.
 *
 * Closing is a deliberate shared-resource invalidation — other disks
 * converging on the same pool re-acquire a fresh pool on their next
 * operation, and objects borrowed mid-purge are destroyed on release.
 * Boot or tests only, plus operational recovery of broken pooled resources.
 */
public function purge(?string $name = null): void
{
    $name ??= $this->getDefaultDriver();

    $disk = $this->disks[$name] ?? null;
    unset($this->disks[$name]);

    if ($disk instanceof ClientPooledFilesystem || $disk instanceof FilesystemPoolProxy) {
        $disk->invalidatePool();

        return;
    }

    // Never cached (or cached unpooled): derive the identity from current
    // config so a broken pool can be discarded without resolving a disk.
    // Scoped configs expand to their effective parent first — resolution
    // and invalidation must derive the same construction config, and a
    // scoped disk resolves through build(), so the derivation also uses
    // the on-demand resolution name rather than the scoped disk's own
    // (the name is fingerprint input for whole-driver pools).
    $config = $this->getConfig($name);

    if (($config['driver'] ?? null) === 'scoped') {
        $config = $this->expandScopedConfig($config);
        $name = self::ON_DEMAND_DISK_NAME;
    }

    $driver = $config['driver'] ?? null;

    if ($driver !== null && in_array($driver, $this->poolables, true)) {
        $this->poolFactory()->remove($this->diskPoolDefinition($driver, $config, $name)->identity);
    }
}

/**
 * Derive the pool definition for a disk config — client config identity for
 * built-in cloud drivers, full construction input for everything else.
 */
protected function diskPoolDefinition(string $driver, array $config, ?string $name): PoolDefinition
{
    $fingerprintSource = match (true) {
        $driver === 's3' => $this->s3ClientConfig($config),
        $driver === 'gcs' => $this->gcsClientConfig($config),
        // Custom creators never receive the disk name; built-in driver methods do.
        default => [
            'config' => Arr::except($config, ['pool']),
            'name' => isset($this->customCreators[$driver]) ? null : $name,
        ],
    };

    return $this->poolDefinition($driver, $config['pool'] ?? [], $fingerprintSource);
}
```

(`resolve()`'s pooled branches and `purge()` share `diskPoolDefinition()` — one derivation, used everywhere; `createDriverPooledDisk()` builds its fingerprint source through it.)

Scoped-config expansion is one protected helper, `expandScopedConfig(array $config): array`, extracted from `createScopedDriver()` and shared by resolution and purge: it validates `disk`/`prefix`, loads the named parent config (or accepts an inline parent array), composes prefix/visibility/throw exactly as today, **recurses while the resulting parent driver is itself `scoped`**, and detects named scoped-disk cycles, throwing an `InvalidArgumentException` naming the cycle — today a cycle (scoped A over B, scoped B over A) recurses unboundedly through `build()`. `createScopedDriver()` becomes expansion + `build()`. `build()`'s fixed resolution name becomes a protected `ON_DEMAND_DISK_NAME` constant (`'ondemand'`), defined once and used by both `build()` and purge's scoped derivation — this is the uniform on-demand resolution name, not name-keyed pool special-casing: identity still comes from the fingerprint, and the Part 7 gate against `ondemand` special-casing refers to pool identities, which no longer embed resolution names. Without the expansion, `purge()` on a never-cached scoped name reads `driver === 'scoped'`, finds it non-poolable, and silently misses the converged parent client pool that resolution (through the parent disk, another scoped disk, or `build()`) already created.

### 3.8 `FilesystemAdapter` fixes

- `response()`'s disposition narrows to non-nullable `string $disposition = 'inline'` (1.1 #20): null never worked in either tree — it dies in Symfony's `makeDisposition()`, which only defines `inline`/`attachment` — and inventing `null => omit the header` would be a Hypervel-only semantic duplicating the explicit-header bypass that already exists (a caller-supplied `Content-Disposition`, including null, skips generation). The narrowing applies everywhere the parameter appears: `FileResponseBuilder::build()` (3.6), the enumerated `ClientPooledFilesystem`/`FilesystemPoolProxy` signatures (3.3/3.4), the scoped decorator's forwarded signature (Part 5), and the `Storage` facade annotation.
- `path()` normalizes through `League\Flysystem\WhitespacePathNormalizer` before prefixing — `PathTraversalDetected` propagates for `../` escapes, matching what Flysystem already does for every operation path. This also closes the static `scoped` driver's `path()` escape (its prefix lives in `PathPrefixedAdapter`, inside Flysystem, but `path()` bypassed it).
- `getVisibility()` `==` → `===` (`Visibility::PUBLIC` is a string constant; behavior identical, standard enforced).
- `GoogleCloudStorageAdapter::url()` collapses its fallback selection to one expression; remove the following conditional that reassigns the exact same `storageApiUri` value and can never change the result.
- `response()`/`serve()`/`download()` refactored onto `FileResponseBuilder` (3.6).
- `readStreamRange()` gains the generic base implementation and `AwsS3V3Adapter` the native ranged-`GetObject` override (3.6) — the base currently throws for every driver but GCS. While positioning a non-seekable stream, `false` or EOF before the target keeps the ordinary positioning failure; an empty non-EOF read gets a distinct `UnableToReadFile` diagnostic and closes immediately instead of spinning with an unchanged byte count.
- `Filesystem::replace()` becomes a checked atomic transaction: create the temporary file in the target directory; write and verify the exact byte count while the file retains `tempnam()`'s private 0600 mode; apply and verify the final requested/default mode; then verify the rename. A missing/unwritable directory may make `tempnam()` fall back to the system temp directory rather than return false, so the checked rename is the required containment boundary. Every post-creation failure removes the temporary file and preserves the primary named exception. The three cache-command sites use `@tempnam()` followed by their explicit framework-owned failure checks; unlike `replace()`, they request `sys_get_temp_dir()` directly and have no fallback-location ambiguity. Testbench's Blade helper writes and byte-count-checks its private placeholder, then checked-renames the complete file to the `.blade.php` path; failure removes both candidates before preserving the named primary exception, so the view finder never observes partial content.
- `serveUsing()`/`buildTemporaryUrlsUsing()`/`buildTemporaryUploadUrlsUsing()` accept `?Closure` (null clears the slot) — required so `FilesystemPoolProxy::configureBorrowed()` can deterministically write all three slots including null (3.4); also honest API for direct disks (a set callback becomes clearable). Docblocks stay accurate for direct disks; pooled behavior is defined by 3.3/3.4.
- Temporary-URL callbacks are normalized once at setter time (1.1 #21): both `buildTemporary*Using()` setters store the result of a protected `prepareTemporaryUrlCallback(?Closure $callback): ?Closure` — null passes through; anonymous non-static closures are bound to `$this, static::class` (Laravel's useful `$this`-is-the-adapter behavior, identical to invocation-time binding since the setter's `$this`/`static::class` are the invoking adapter's); every other kind (`isStatic() || ! isAnonymous()` via `ReflectionFunction`) is stored as-is because rebinding throws for all of them — the probe-verified matrix. If binding an anonymous non-static closure still returns null (reachable in standalone package use where warnings are not promoted; `bindTo()` warns and returns null there), throw a `RuntimeException` instead of silently storing a cleared slot. All four invocation sites (`temporaryUrl()`/`temporaryUploadUrl()` and both `LocalFilesystemAdapter` overrides) then invoke the stored closure directly — the unconditional `bindTo()` calls are deleted. The pooled paths compose unchanged: the proxies store the caller's original closure and write it through these setters per stack/borrow, so each bind targets the concrete adapter instance it runs on.

## Part 4 — Other pool consumers and adjacent lifecycle fixes

### 4.1 Mail

- `createSymfonyTransport()` loses its `$poolName` parameter and always constructs a direct transport — matching Laravel's public signature and its own current no-pool-name behavior. This keeps failover/roundrobin composites correct: `createRoundrobinTransportOfClass()` builds nested child transports through `createSymfonyTransport($config)` (`MailManager.php:417`), and those children must stay direct — the pooled resource is the whole composite, never a pool-proxy-inside-a-pooled-object. Update the `Facades/Mail.php` `@method` annotation to the single-argument signature. Existing transport unit tests calling `createSymfonyTransport()` keep getting raw transports, unchanged.
- Pooling moves to the call sites that own it. Named mailers preserve their existing default: built-in poolable transports are pooled unless their config explicitly sets `'pool' => false`. On-demand `build()` mailers are direct unless their config explicitly opts in. One parser gives the `pool` key the same meaning in both contexts, with only the absent-key default differing:

  | `pool` value | Named poolable mailer | On-demand `build()` mailer |
  |---|---|---|
  | absent | pooled with defaults | direct |
  | `false` | direct | direct |
  | `true` | pooled with defaults | pooled with defaults |
  | `[]` | pooled with defaults | pooled with defaults |
  | option array | pooled with supplied partial overrides | pooled with supplied partial overrides |

  Parsing uses `array_key_exists()` and explicit `is_bool()`/`is_array()` checks, so an empty array is never mistaken for false; every other type throws `InvalidArgumentException` naming the accepted forms. `true` normalizes to an empty pool-options array, so all omitted values come from `PoolOptions`. An explicit pooling request for a transport absent from `$poolables` throws and names the transport rather than silently constructing it directly. For custom transports, `extend(..., poolable: true)` remains the author-owned declaration that the transport is safe to reuse, while `pool => true|array` is the caller-owned retention choice; both gates are required for `build()`. A named custom poolable transport follows the same named-context default as built-ins after the author has opted the driver into `$poolables`.

  Poolable resolutions wrap the direct transport in `TransportPoolProxy` with resolver `fn () => $this->createSymfonyTransportFromConstructionConfig($constructionConfig)`. Identity remains `MailManager:auto:{transport}:{digest}`: two equivalent named or explicitly pooled on-demand mailers converge without a caller-supplied identity key, while different resolved credentials/config create different pools. Delete the obsolete `@TODO` in `MailManager::build()`.
- **The fingerprint source is a resolved transport construction config, not the mailer config.** Transport creators read fallback values from `services.*` (SES `MailManager.php:272`, Resend `:307`, Cloudflare `:318-322`, Mailgun `:344`, Postmark `:372-373`) — real construction input a mailer-config-only fingerprint would miss (rotating `services.ses.key` must produce a new pool). Add a per-transport `transportConstructionConfig(array $config): array` builder that returns exactly the values the corresponding creator consumes (mailer config keys merged with the resolved `services.*` inputs, minus presentation keys — `from`, `reply_to`, `return_path`, `to`, `name`, `pool`). Both fingerprinting and the creators consume this builder's output, so fingerprint and construction can never diverge — the same invariant as the filesystem client config (3.1). **The presentation-key exclusion applies to built-in creators only.** A custom creator registered via `extend()` receives the full mailer config (`MailManager.php:190`) and may consume any key — `from`, `name`, anything — so for custom poolable transports the fingerprint source is the full mailer config minus the `pool` control key, the same rule as custom filesystem drivers (3.7). The custom-creator invocation itself passes that same `pool`-stripped config (2.5), so the fingerprint equals the callback's actual input rather than merely being documented as such.
- **Composite transports fingerprint their resolved children.** For `failover`/`roundrobin`, the construction config is the ordered list of each child's *resolved transport construction definition* (recursive — a child may itself be a composite) plus the composite's own `retry_after`. Child mailer names alone are not construction input: rotating a child's credentials must change the composite fingerprint. Detect definition cycles (mailer A failing over to B failing over to A) and throw naming the cycle.
- **Fix the `$config` overwrite bug while restructuring** (1.1 #12): `createRoundrobinTransportOfClass()` reuses `$config` as its loop variable (`MailManager.php:411`), so `retry_after` is read from the last child instead of the composite (`:420`). The rebuilt creator uses a distinct child variable and the composite's own `retry_after`. Regression test: composite with `retry_after` differing from its last child's.
- **Fix `setGlobalAddress()` while restructuring** (1.1 #22): `$address['name']` becomes `$address['name'] ?? null`, restoring Laravel's behavior for global addresses configured without a name. Regression test: a mailer with `'from' => ['address' => ...]` (no `name`) resolves and applies the global address.
- `forgetMailers()` stays cache-only (docblock updated). `purge($name)` drops the cached mailer and invalidates its transport pool: when cached, via `$mailer->getSymfonyTransport()` (verified, `Mailer.php:510`) being a `TransportPoolProxy` → `invalidatePool()`; when not cached, run the same named-context `pool` parser and derive/remove a definition only when that configuration is effectively pooled. An explicit named `pool => false` never derives or removes an unrelated identity.
- An explicitly pooled on-demand mailer has no manager registration name, so it is not passed to `purge()`. Call `$mailer->getSymfonyTransport()->invalidatePool()` on its `TransportPoolProxy` for immediate operational invalidation; otherwise its configured/default `idle_ttl` reclaims the unused pool. Document this distinction without adding a second on-demand registry.
- `TransportPoolProxy::send()` returns Symfony `SentMessage` (message + envelope; no transport retained — verified). No deferred-result changes needed; audit `__toString()` similarly (string result, safe).
- `createSymfonyTransportFromConstructionConfig()` trusts only output from `transportConstructionConfig()`. Every call path, including recursively resolved composite children, is normalized first, so the duplicate transport-existence check is removed; validation stays once at the raw-config boundary.

### 4.2 Queue

- Resource-based identity: `poolDefinition($config['driver'], $config['pool'] ?? [], Arr::except($config, ['pool']))`. The connector config includes per-connection keys like the default `queue`, so only truly identical connections converge; the logical connection name is excluded and reapplied per borrow. The resolver passes that same `pool`-stripped config to both `ConnectorInterface::connect()` and `setConfig()` (today both receive the full config, `QueueManager.php:258-262`) — per the 2.5 rule, the constructed queue and its jobs can never read pool-control metadata the fingerprint excluded.
- `QueuePoolProxy` gains proxy-level connection-name state, fixing the `static` return violation and enabling convergence:

```php
protected string $connectionName = '';

public function getConnectionName(): string
{
    return $this->connectionName;
}

public function setConnectionName(string $name): static
{
    $this->connectionName = $name;

    return $this;
}

protected function configureBorrowed(object $object): void
{
    $object->setConnectionName($this->connectionName);
}
```

`QueueManager::resolve()` drops `setConnectionName()` from the resolver closure and calls it on the proxy instead (`setContainer`/`setConfig` stay in the resolver — they are construction state).

`QueuePoolProxy::configureBorrowed()` does not re-check `instanceof Queue`: `ConnectorInterface::connect()` has a native `Queue` return type, so PHP enforces the invariant for built-in and custom connectors before an object can enter the pool. Keep runtime capability checks only at boundaries PHP cannot enforce.

Queue names become non-nullable end to end, matching `Job::getQueue(): string` in the contract. Every backend constructor already receives a resolved string; narrow the base property and all five promoted job constructor properties to `string`. `SyncQueue` normalizes its nullable input to `'sync'` before constructing `SyncJob`; `SyncJob::getQueue()` keeps its Laravel-parity hardcoded `'sync'` result.

- `QueuePoolProxy`'s enumerated methods route through the protected `invoke()` like every proxy (no `__call`). **Job lease:** `pop()` is the exception — it borrows under a `Lease`, pops, and attaches the lease to the returned job; a null pop releases immediately:

```php
public function pop(?string $queue = null): ?Job
{
    $lease = $this->lease();

    // Everything after the borrow — pop, the lease-awareness check, and the
    // attachment itself — sits inside one primary-exception-preserving
    // structure: any failure releases the borrow (reported, never masking),
    // and the caller only ever receives a job that carries its lease.
    try {
        $job = $lease->get()->pop($queue);

        if ($job === null) {
            $lease->release();

            return null;
        }

        // Fail closed on a job that cannot carry a lease — but never strand
        // it: the popped job is reserved/invisible on the backend until TTR
        // or visibility timeout. Requeue it through the still-pinned
        // connection first, then refuse.
        if (! $job instanceof QueueJob) {   // imported Hypervel\Queue\Jobs\Job, the lease-aware base
            try {
                $job->release(0);
            } catch (Throwable $requeueException) {
                // The backend failed mid-operation: discard, don't release.
                // The discard itself is cleanup under an active failure —
                // a throwing contract-pool discard is reported, never
                // allowed to mask the fail-closed exception below.
                try {
                    $lease->discard();
                } catch (Throwable $discardException) {
                    PoolErrorReporter::report($discardException);
                }

                throw new RuntimeException(
                    'Pooled queue connections require jobs extending Hypervel\Queue\Jobs\Job; requeueing the popped job also failed.',
                    previous: $requeueException,
                );
            }

            throw new RuntimeException('Pooled queue connections require jobs extending Hypervel\Queue\Jobs\Job.');
        }

        // Attachment can itself hit the backend: BeanstalkdJob primes its
        // attempts cache in onPoolLeaseAttached() (4.2). withPoolLease()
        // attaches the lease BEFORE running the hook, so a hook failure
        // always leaves a lease-carrying job whose own terminal release(0)
        // performs the recovery: requeue the reserved job through the
        // still-pinned connection, then releasePoolLease() on success or
        // discardPoolLease() on backend failure — the exact terminal
        // machinery specified in 4.2. The attachment exception stays
        // primary; a recovery failure is reported. The generic release in
        // the outer catch never gets to declare a connection healthy after
        // a failed backend call: recovery finalizes the lease either way,
        // so the outer release is a no-op by then.
        try {
            return $job->withPoolLease($lease);
        } catch (Throwable $attachmentException) {
            try {
                $job->release(0);
            } catch (Throwable $recoveryException) {
                PoolErrorReporter::report($recoveryException);
            }

            throw $attachmentException;
        }
    } catch (Throwable $operationException) {
        try {
            $lease->release();   // idempotent: a no-op after the discard/release/recovery paths above
        } catch (Throwable $finalizationException) {
            PoolErrorReporter::report($finalizationException);
        }

        throw $operationException;
    }
}
```

The signature keeps the contract type `Hypervel\Contracts\Queue\Job` (as today, `QueuePoolProxy.php:106`; the contract guarantees `release(int $delay = 0)`, so the requeue needs no concrete type). Silently returning an unpinned job would resurrect the exact concurrency bug this fixes; silently dropping a reserved job would trade it for an invisible-until-timeout one.

**Lease finalization lives in the concrete terminal operations, after the backend call.** Verified: concrete jobs run `parent::delete()`/`parent::release()` *before* the backend operation (`BeanstalkdJob.php:29-59`), so base-class finalization would return the connection before pheanstalk/SQS uses it. Each concrete terminal method (`delete()`, `release()`, `BeanstalkdJob::bury()`) finalizes on both paths, differently: backend success → `$this->releasePoolLease()`; backend failure → `$this->discardPoolLease()` and the backend exception propagates (a throwing discard is reported, never allowed to mask it). A failed pheanstalk/SQS operation is not evidence of a clean connection — a protocol or network failure can leave the connection desynced, and `max_lifetime` is not a corruption detector — so the object is destroyed and the next borrower gets a fresh one. `Hypervel\Queue\Jobs\Job` provides `withPoolLease(Lease $lease): static` and the protected idempotent pair `releasePoolLease(): void` / `discardPoolLease(): void`; the lease destructor covers crashed/abandoned jobs.

This is uniform across drivers and *required* for beanstalkd: the installed pheanstalk dispatches reserve/release/bury/delete through the same connection object, so the reserving connection must be held until the terminal backend call completes — record this requirement in the `BeanstalkdJob` class docblock now, not as an implementation-time note. For SQS the lease pins a connection for the job's duration, acceptable and simple; pool `max_objects` must cover worker concurrency, which the per-connection `pool` config controls.

**Post-terminal client access is made safe, not just documented.** `BeanstalkdJob::attempts()` calls `statsJob()` through the retained client, and event listeners or failed-job callbacks legitimately inspect jobs *after* the terminal operation — documentation cannot stop them racing a returned client. Lazy caching is not enough: the worker is not guaranteed a pre-terminal `attempts()` read — verified, `Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts()` short-circuits on `$maxTries === 0` before `$job->attempts()` (`Worker.php:681`, the `||` never evaluates it), which is a normal successful-job path, so a `JobProcessed` listener could hit an uninitialized cache. So initialization happens while the lease is provably active: `withPoolLease()` calls a protected `onPoolLeaseAttached()` hook after attaching, and `BeanstalkdJob` overrides it to prime its attempts cache (one `statsJob()` round-trip per pop — the honest price of a guaranteed post-terminal contract). The attach-before-hook ordering is load-bearing: a hook failure leaves the lease attached, so `pop()`'s recovery path — the job's own `release(0)` — runs the full terminal machinery (requeue, then release-or-discard by backend outcome) instead of stranding the reserved job and returning a suspect connection as healthy. While the lease is active, `attempts()` reads live and refreshes the cache; after finalization it returns the cached value. `SqsJob::attempts()` needs none of this — it reads `ApproximateReceiveCount` from the already-received payload, no client involved. `getPheanstalk()`/`getSqs()` throw only in the "pooled and finalized" state; jobs from non-pooled queues (no lease attached) keep today's accessor behavior untouched. Tests: a `JobProcessed` listener calls `attempts()` after `delete()` on a `maxTries === 0` run (no pre-terminal worker read); live reads still refresh while the lease is active.

- Queue worker loop: confirm `Worker` calls `delete()`/`release()` on every terminal path (it does in Laravel's model); any path that abandons a job object relies on the lease destructor.
- `QueueManager` gains `purge(?string $name = null)` with the same cache + invalidate semantics (cached proxy → `invalidatePool()`, else derive from config).
- `setApplication()` differentiates the cached kinds (1.1 #23): direct queues keep Laravel's in-place `$connection->setContainer($app)`; a cached `QueuePoolProxy` is invalidated (`invalidatePool()`) and evicted from `$connections`, so the next resolution binds both its pool factory and its construction container to the new application. Converged pools close once (later invalidations are no-ops); a proxy retained outside the manager is deliberately stale, the same rule as any retained manager-cached resource across this Tests-only swap. The sibling managers keep their bare-assignment `setApplication()` — no defect there (1.1 #23), and `purge()`/forget are their eviction levers.

### 4.3 HTTP client — pooling deleted, transport reuse kept at the handler level

Two facts drive the design. First, a Guzzle `Client` is not the resource: with per-request middleware stacks and per-request cookie jars it is a stateless bag of default options, so object-pooling it isolates nothing. Second, the *transport* is a resource: the bottom of a `HandlerStack` owns the low-level handler (`CurlMultiHandler` by default), which owns reusable cURL handles and keep-alive connection state. Today's pooled client incidentally retains the first request's handler — that is where the connection reuse the feature documents actually lives (and why the first-stack capture, 1.1 #7, froze middleware too). Building `HandlerStack::create()` fresh per request would fix the middleware bug by deleting the reuse — a regression, not a fix.

The design is **handler-level reuse, no object pool**:

1. Named connections own a **shared low-level transport handler** plus immutable default option presets. `Factory` caches one lazily-created low-level handler per registered connection (`Utils::chooseHandler()`'s result — the same primitive `HandlerStack::create()` would wrap); `registerConnection(string $name, array $config)` stores the option preset. **Reserved option keys are classified at every tier, never passed blindly** — factory globals (validated on the evaluated array for each new request: `globalOptions()` accepts a closure, `Factory.php:136`, so set-time checks cannot see its product), connection registration, per-call overrides, and fluent `withOptions()` all reject the reserved keys with messages naming the sanctioned API. A tier-scoped check is not an invariant: Guzzle honors a per-request `handler` option over the client's stack (`Client.php:537`, `:1035`), and a global `cookies` jar is the shared-jar bug (1.1 #6) through a different door.
   - `pool` — rejected everywhere with a message pointing at this change (there are no HTTP pools).
   - `handler` — rejected everywhere: an option-borne handler silently replaces the per-request middleware stack at transfer time; the connection's low-level handler and `PendingRequest::setHandler()` are the sanctioned paths.
   - `cookies` — rejected everywhere: every `PendingRequest` owns its jar (point 4); a shared option-borne jar breaks that isolation and `false` violates the `?CookieJar` typing. `withCookies()` is the API.
   - `transport_sharing` — extracted and passed to `Utils::chooseHandler(['transport_sharing' => ...])` when the connection's handler is lazily created, never into client options. Verified in the installed Guzzle: `Client` only consumes it when building its own default handler and throws when a require-mode value meets a custom handler (`vendor/guzzlehttp/guzzle/src/Client.php:64-75`) — exactly the configuration this design produces. Routing it to the handler keeps the knob usable per connection. **Registration-only:** `transport_sharing` anywhere else — factory globals, a per-call `connection($name, $config)` override, or fluent options — is rejected with a message pointing at `registerConnection()`: handler construction happens once per connection, a handler-construction option cannot alter it per request, and silently ignoring it or mutating the shared handler for other requests are both wrong.
   A small final `ReservedOptions` class in the HTTP client namespace owns this one message table and the tier-aware rejection method. `Factory` and `PendingRequest` delegate to it rather than carrying copies. Closure-valued globals are validated exactly once where evaluated for each request, not again in the `PendingRequest` constructor.
2. Every `PendingRequest` builds its own **fresh middleware `HandlerStack` around the shared low-level handler** (`buildHandlerStack()` passes the connection's handler instead of null into `HandlerStack::create()`); per-request middleware works, transport state is shared. A request-level custom handler deliberately overrides the connection handler. Clients are constructed per request — stateless and cheap.
   **Option precedence, lowest to highest, every tier tested:** factory global defaults/middleware → registered connection preset → per-call `connection($name, $config)` override (replaces the registered preset for that request) → `PendingRequest` fluent options (`withOptions()` and friends override preset keys) → explicit `setClient()`/`setHandler()` (`setClient()` bypasses preset application entirely — the caller owns the client; `setHandler()` overrides only the transport handler beneath the fresh middleware stack).
   **The precedence is implemented as separate option layers, merged once at build/send time.** Today it cannot be: `Factory::newPendingRequest()` applies the global options straight through `withOptions()` (`Factory.php:526`), and every later fluent call mutates the same `$options` array — the implementation cannot tell a factory global from a fluent override, so a Client-defaults preset would sit below globals instead of above them. `PendingRequest` therefore keeps three layers: the factory globals (set at construction), the effective connection options (registered preset or per-call replacement), and the request/fluent options; they combine in precedence order with the existing mergeable-option rules exactly once, when the request is built. Chaining order is irrelevant by construction: `Http::timeout(...)->connection(...)` and `Http::connection(...)->timeout(...)` produce identical effective options.
   **Per-call override semantics:** the override applies when `$config !== null` — `connection($name, [])` is a real replacement that clears the preset, not a no-op (today's `if ($config)` at `PendingRequest.php:1754` treats an empty array as no override; that gate becomes a null check).
   **Re-registration invalidates:** `registerConnection()`/`setConnectionConfig()` on an existing name drop the cached low-level handler along with the preset; the next request lazily creates a fresh handler from the new config. In-flight requests hold their own reference to the old handler and complete safely on it — handlers carry no registry back-pointer.
   Registration has one source of truth: `array_key_exists($name, $connectionConfigs)`. This distinguishes an explicitly registered empty preset from an absent name without a parallel boolean map. Public `setConnectionConfig()` validates, stores, and therefore registers the name before invalidating its handler; it cannot leave a preset visible through `getConnectionConfig()` while `connection()` rejects the same name. Delete `$registeredConnections`.
3. No object pool is involved: Guzzle's multi-handler is designed to multiplex concurrent transfers. **Verify this under the coroutine runtime with a handler-identity + concurrency test** (N coroutines through one connection: all requests use the same low-level handler instance, all complete correctly) **and an instrumented connection-reuse check** (handler reuse observable across sequential requests). Contingency, only if the concurrency test proves the shared handler unsafe under the Swoole hook: pool the low-level handler itself (a small pool of transport handlers via this plan's own definitions/leases — never clients). The test decides; both outcomes are specified.
4. **Cookies:** every `PendingRequest` owns its own `CookieJar`, created at construction and passed on every send — including retries, preserving response-cookie capture across a retry chain exactly as the current shared jar did (per request-chain, not per process). This keeps the `?CookieJar` property typing sound against Guzzle's prepared `cookies => false` default (the option is always a jar). `Factory::createClient()` requires that jar; it has no nullable parameter or fallback that could silently manufacture a jar disconnected from the request. `withCookies()` seeds/mutates the request-owned jar deliberately — jars are objects and must never pass through the recursive option-array merge.
5. Delete `ClientPoolProxy`, `Factory::$poolables`, and the `HasPoolProxy` usage in `Factory`. `Http` facade annotations and `registerConnection()` docs are updated; remove the unreachable no-argument `getConnectionConfig()` annotation because the concrete factory method requires a name. The existing connection tests (`HttpClientTest.php:4010-4018`) are rewritten for the preset + shared-handler semantics.

Tests: handler identity across concurrent and sequential requests per connection; per-request middleware isolation (the 1.1 #7 regression); request-level handler override; every precedence tier (preset → per-call override → fluent options → `setClient()`/`setHandler()`); reserved-key rejection at every tier (factory globals in array and closure forms, registration presets, per-call overrides, fluent `withOptions()` — `pool`, `handler`, `cookies`, plus `transport_sharing` outside registration); `transport_sharing` reaching `Utils::chooseHandler()` and excluded from client options; re-registration swapping the handler while an in-flight request completes on the old one; concurrent cookie-jar isolation; retry-chain cookie persistence.

No purge/invalidate surface is needed — there are no HTTP object pools. Connection handlers are invalidated by re-registering the connection.

### 4.4 Broadcasting and notifications

- `BroadcastManager`: resource-based definitions (`poolDefinition($config['driver'], $config['pool'] ?? [], Arr::except($config, ['pool']))`); custom creators and pooled resolvers receive the `pool`-stripped config per the 2.5 rule (`callCustomCreator()` passes the full config today, `BroadcastManager.php:324-327`); `forgetDrivers()` cache-only; `purge()` cache + invalidate (cached `BroadcastPoolProxy` → `invalidatePool()`, else derive).
- **Pooled construction is name-free** (the 2.5 captured-values invariant): `doResolve(?string $name, array $config)` accepts null; the pooled resolver passes null and a construction failure reports the driver — `Failed to create broadcaster for driver "{$config['driver']}"...` — while unpooled resolution keeps the logical name and today's connection-named diagnostic. The name is deliberately outside the fingerprint, so a name-capturing pooled resolver would make converged callbacks non-equivalent: `getOrCreate()` retains the first registrant's closure, and a lazy construction reached through connection B would report connection A. Tests: two equivalent named connections converge; a pooled lazy construction failure through either proxy names the driver, never another connection's name; the unpooled diagnostic is unchanged.
- `BroadcastPoolProxy` is rebuilt on the `invoke()` model: the explicit surface enumerates the `Broadcaster` contract (`auth`, `validAuthenticationResponse`, `broadcast`, plus the fluent `channel(): static` returning the proxy and `getChannels(): Collection` — computed within the borrow, safe). `resolveAuthenticatedUserUsing()` stores the callback on the **proxy**; `resolveAuthenticatedUser()` becomes a plain `invoke()` call, with `configureBorrowed()` writing the callback slot on **every borrow including null** — the current apply-only-when-set shape (`BroadcastPoolProxy.php:32-34`) leaks one proxy's resolver into another proxy's borrows under pool convergence. The underlying `Broadcaster::resolveAuthenticatedUserUsing()` therefore accepts `?Closure` (null clears), same pattern as the filesystem mutators (3.8). **Capability rule, mirroring 3.4:** both `resolveAuthenticatedUser*` methods are base-class extensions, not contract methods (the contract declares only `auth`/`validAuthenticationResponse`/`broadcast`/`getChannels`), and `extend()` + `addPoolable()` can legally pool a contract-only implementation — so `configureBorrowed()` checks `instanceof` the Hypervel base broadcaster: base borrows get the write-all-slots rule; a contract-only borrow with a null proxy callback skips configuration and serves the contract surface normally; a contract-only borrow with a set callback throws a `RuntimeException` naming the inner class and stating that authenticated-user resolver callbacks require a base-broadcaster implementation. Calling the beyond-contract `resolveAuthenticatedUser()` on a contract-only pooled broadcaster fails naturally as an undefined method — the extension belongs to the base, and the stack trace names the class and method. Tests: two base-broadcaster proxies sharing one pool with set/null resolvers (leak regression); a contract-only pooled custom broadcaster serves contract methods with a null callback; setting a callback then borrowing throws the capability error.
- **Notification pooling is deleted entirely.** `SlackNotificationRouterChannel` holds only the container; the real Slack channels are resolved from the container per send, so pooling the router isolates nothing — and because the router is an unbound concrete, the container auto-singletons it and every pool "creation" returns the same instance (the duplicate-factory ownership violation of 2.6, live in-tree). Its `send()` also returns `?ResponseInterface`, not void. Remove: `slack` from `$poolables`, `NotificationPoolProxy`, `$poolProxyClass`/`$poolConfig`/`setPoolConfig()`/`getPoolConfig()` and the pooling branch in `ChannelManager::createDriver()`, and the `HasPoolProxy` trait usage. Custom notification channels needing pooling pool inside their own channel implementation with the object-pool primitives — the manager-level wrapping added nothing but the bug.
- `reverb` stays outside `BroadcastManager::$poolables`. `createReverbDriver()` delegates to the Pusher-compatible publisher, but the installed SDK stores request state in method locals and shares only immutable settings plus a concurrency-safe Guzzle client. Hypervel's synchronous `trigger()` yields at hooked network I/O under Swoole; changing to `triggerAsync()` only to wait immediately would add promise machinery without improving scheduling. Add regression coverage that Reverb resolves directly without registering an object pool. Preserve the already-pooled Pusher/Ably behavior in this work, and add one entry to `docs/todo.md` for an owner review of whether their broadcaster pools manage any state that cannot be shared like Reverb; no source TODO or user-doc digression.

### 4.5 Watcher subprocess lifecycle

`FswatchDriver` owns both the `proc_open()` handle and every pipe. A protected `openProcess()` is the narrow test seam for deterministic pipe outcomes; it is not a second process abstraction. `watch()` captures the output pipe locally, then uses identical pre-read and post-read guards: a closing channel is observed before the next read or after the read is otherwise unblocked, while a process already detached by an explicit `stop()` returns normally. Channel closure is not an I/O interrupt; `stop()` is the family-wide deterministic shutdown API and is what releases resources and unblocks suspended I/O. `DriverInterface` declares both methods and documents `stop()` as idempotent because loop cleanup, destructors, and external callers may invoke it repeatedly. Empty-at-EOF throws `The fswatch process exited unexpectedly.`; `false` or empty-before-EOF throws `Unable to read output from the fswatch process.`. The loop never retries a no-progress read.

The whole watch loop runs inside `try/finally`, and `finally` calls the idempotent `stop()`. `stop()` detaches the process first (the cross-coroutine shutdown signal), terminates it when still running, closes and clears all retained pipes, then calls `proc_close()`. This path owns cleanup for normal channel shutdown, explicit stop, child exit, and read failure. `Watcher::run()` deliberately adds no catch around the driver coroutine: a dead development watcher fails loudly with its stack trace instead of swallowing the failure and leaving the command apparently healthy.

Both watcher-owned subprocesses launch without a shell. `FswatchDriver::getCommand()` returns the complete argv list (including a literal `%p` format argument), and `ServerRestartStrategy` builds `[$bin, base_path($script), ...$arguments]` from a non-empty `watcher.command` list of non-empty strings. `watcher.bin` is a non-empty executable path, never a command fragment. Passing argv arrays to `proc_open()` preserves spaces, quotes, and shell metacharacters literally and ensures lifecycle signals target the real child process. The config stub and README document the list-shaped command.

The find drivers retain shell strings because `AbstractDriver::exec()` is backed by Swoole's string-only shell execution and `FindNewerDriver` intentionally composes concurrent `find` commands with `&`. `AbstractDriver::shellArguments()` is the single escaping boundary for every interpolated target and reference path, and the `exec()` contract explicitly requires escaping. Reference-file creation and timestamp bumps use a checked `touch()` helper instead of shell redirection, throwing a named `RuntimeException` on failure.

`FindNewerDriver` owns two atomically-created `tempnam()` reference files instead of process-global predictable paths. Construction creates the pair after the `find` probe; a second-file creation failure removes the first without masking the primary exception. Each tick touches the alternate reference **before** scanning against the previous cutoff, then advances the index after a successful scan even when the result is empty. The pre-scan timestamp becomes the next scan's cutoff, so a change made after the active scan passed its path remains eligible next time; the unconditional role swap is load-bearing. Touch or scan failure leaves the index unchanged and the old cutoff authoritative for retry. `stop()` marks the lifecycle as stopping before clearing its timer. With no active scan it detaches and removes immediately; while a reference update or scan is suspended it defers cleanup to the callback's `finally`. The callback checks stop state after each overridable I/O boundary: a stop during the pre-scan update skips the expensive scan, while a stop during the scan skips index advancement and publication. This prevents in-flight work from recreating detached files or delaying shutdown unnecessarily. Starting again while the previous callback is still unwinding throws a named `RuntimeException`; after it quiesces, `watch()` recreates a fresh pair normally. Removal includes replaced symlinks, reports a failed unlink through `error_log()`, and never throws through its destructor path.

Recurring timer callbacks survive exceptions by design, but never silently: `Timer::tick()` reports through its injected logger or falls back to `error_log()` before continuing to the next tick. `Timer::after()` remains fail-fast and uncaught because a one-shot callback has no later execution to preserve. `KeepaliveConnection` uses the same logger-or-`error_log()` rule inside its own heartbeat catch, after clearing the failed connection; that exception is intentionally handled before the timer boundary.

## Part 5 — Scoped filesystem decorators

New `src/filesystem/src/ScopedFilesystemProxy.php` (implements `Filesystem`) and `ScopedCloudFilesystemProxy extends ScopedFilesystemProxy implements Cloud` (adds `url()`, requires a `Cloud` inner disk). Framework ships mechanism; the resolver closure is consumer policy (a tenancy package reads coroutine context; an app can scope per user).

```php
class ScopedFilesystemProxy implements Filesystem
{
    protected PathNormalizer $normalizer;

    public function __construct(
        protected Filesystem $disk,
        protected Closure $prefixResolver,
        protected bool $allowRootPassthrough = false,
    ) {
        $this->normalizer = new WhitespacePathNormalizer();
    }

    /**
     * Resolve the prefix for the current operation.
     *
     * Called exactly once per public method; fails closed on an empty prefix
     * unless root passthrough was explicitly enabled at construction.
     */
    protected function prefix(): string
    {
        // Normalize FIRST, then check emptiness: a resolver returning "." or
        // "scope/.." normalizes to "" — checking the raw value would let
        // those bypass the fail-closed guarantee and operate on the disk root.
        $prefix = $this->normalizer->normalizePath(($this->prefixResolver)());

        if ($prefix === '' && ! $this->allowRootPassthrough) {
            throw new RuntimeException(
                'The scoped filesystem prefix resolver returned an empty prefix. '
                . 'Enable root passthrough explicitly if operating on the disk root is intended.'
            );
        }

        return $prefix;
    }

    /**
     * Apply the resolved prefix to a user path, rejecting traversal.
     */
    protected function applyPrefix(string $prefix, string $path): string
    {
        $normalized = $this->normalizer->normalizePath($path);   // throws PathTraversalDetected on escape

        return $prefix === '' ? $normalized : trim($prefix . '/' . $normalized, '/');
    }

    /**
     * Strip the resolved prefix from a returned path, failing closed.
     */
    protected function stripPrefix(string $prefix, string $path): string
    {
        // Normalize the RETURNED path before checking containment: a custom
        // inner disk could return "scope/../outside", which passes a raw
        // string-prefix check and would be handed back as "../outside".
        $path = $this->normalizer->normalizePath($path);

        if ($prefix === '') {
            return $path;
        }

        if ($path !== $prefix && ! str_starts_with($path, $prefix . '/')) {
            throw new RuntimeException("Path [{$path}] returned by the scoped disk is outside the resolved prefix [{$prefix}].");
        }

        return ltrim(substr($path, strlen($prefix)), '/');
    }

    public function __call(string $method, array $parameters)
    {
        throw new BadMethodCallException(
            "Method [{$method}] is not supported on scoped filesystems: unmapped calls could bypass the path prefix."
        );
    }
}
```

Beyond-contract adapter methods (`json`, `missing`, `mimeType`, `temporaryUrl`, the assertions, ...) cannot be called on the `Filesystem`-typed `$disk` property directly without failing static analysis. The decorator uses the `StackStoreProxy` pattern — a dynamic dispatch helper — for those:

```php
/**
 * Forward a mapped method to the inner disk.
 */
protected function call(string $method, array $arguments): mixed
{
    if (! method_exists($this->disk, $method) && ! is_callable([$this->disk, $method])) {
        throw new BadMethodCallException("The scoped disk's inner filesystem does not support [{$method}].");
    }

    return $this->disk->{$method}(...$arguments);
}
```

Full method map (each path-sensitive public method resolves the prefix exactly once into a local, then maps; no-path config/capability methods pass through without invoking the resolver because they cannot cross the storage boundary; deferred results are the inner disk's responsibility — a pooled inner disk returns leased streams/responses, and the decorator only rewrites path arguments):

| Method(s) | Mapping |
|---|---|
| `path`, `exists`, `missing`, `fileExists`, `fileMissing`, `directoryExists`, `directoryMissing`, `get`, `readStream`, `getVisibility`, `size`, `lastModified`, `mimeType`, `makeDirectory`, `deleteDirectory` | prefix the single path argument |
| `json(string $path, int $flags = 0)` / `checksum(string $path, array $options = [])` / `readStreamRange(string $path, ?int $start, ?int $end)` | prefix `$path` |
| `has`, `read`, `fileSize`, `visibility` (single path) / `write(string $path, string $contents, array $config = [])` / `createDirectory(string $path, array $config = [])` | prefix the path (the six facade-advertised Flysystem methods; the decorator's `call()` accepts `is_callable([$disk, $method])` alongside `method_exists()` so a direct adapter's magic driver forwarding is reachable — but only through these explicitly mapped methods; `__call` stays fail-closed) |
| `when` / `unless` | `Conditionable` trait on the decorator — the callback receives the decorator, so every path it touches stays prefixed |
| `put(string $path, $contents, $options)` | prefix `$path`; when the inner call returns a stored-path string (File/UploadedFile contents), strip it; booleans pass through |
| `putFile` / `putFileAs` | replicate `FilesystemAdapter`'s argument-shift normalization first (file-as-first-argument, `FilesystemAdapter.php:488`, `:502`). For a caller-supplied string name, validate and prefix the actual final stored target — `trim($path . '/' . $name, '/')` — before any I/O, then forward that already-prefixed target in a form that cannot re-interpret `..`; checking or stripping only after the inner write permits a scope escape before the exception. This deliberately allows Flysystem-consistent internal resolution such as `a/../b` while rejecting escape from the joined target. When the normalized overload leaves name null/array and the inner adapter generates the hash name, prefix the path normally because no user-controlled filename exists. Strip the returned stored path (`false` passes through). |
| `writeStream(string $path, $resource, array $options)` / `setVisibility(string $path, string $visibility)` | prefix `$path` |
| `prepend(string $path, string $data, string $separator = PHP_EOL)` / `append(...)` | prefix `$path` (adapter signature incl. separator) |
| `delete(array\|string $paths)` | prefix every path |
| `copy(string $from, string $to)` / `move(...)` | prefix both |
| `files` / `allFiles` / `directories` / `allDirectories` (`?string $directory`) | prefix the directory (null → the prefix itself); strip every returned path (fail-closed on foreign paths) |
| `response(string $path, ...)` / `download(string $path, ...)` | prefix `$path`, forward |
| `serve(Request $request, string $path, ...)` | prefix `$path` (second argument), forward |
| `temporaryUrl` / `temporaryUploadUrl` | prefix `$path`, forward |
| `providesTemporaryUrls` / `providesTemporaryUploadUrls` / `getConfig` | pass through (no path arguments; config visibility is not a boundary concern — the decorator's consumers are app code, not untrusted callers) |
| `assertExists(array\|string $path, ?string $content = null)` / `assertMissing(array\|string $path)` | prefix each path; call inner; return `$this` (fluent — never the inner disk) |
| `assertCount(string $path, int $count, bool $recursive = false)` / `assertDirectoryEmpty(string $path)` | prefix `$path`; return `$this` |
| `url(string $path)` | Cloud variant only: prefix `$path` |
| Rejected | everything else — `__call` throws (raw Flysystem calls, macros); `getDriver`/`getAdapter`/`getClient` throw (inner-disk internals); `serveUsing`/`buildTemporaryUrlsUsing`/`buildTemporaryUploadUrlsUsing` throw (a dynamically scoped wrapper must not mutate shared base-disk behavior) |

Design notes recorded in the class docblock: the decorator maps the complete path-taking surface of `FilesystemAdapter` plus the `Filesystem`/`Cloud` contracts; every unmapped call is rejected because it could bypass the prefix or mutate the shared base disk. Fail closed by default — extend the map deliberately, never forward blindly.

What the normalizer guarantees (verified in `vendor/league/flysystem/src/WhitespacePathNormalizer.php`): backslashes are converted to `/` before processing (`..\..\x` is caught like `../../x`); NUL and every other Unicode control/format character throw `CorruptedPathDetected` (`\p{C}` match); `..` segments resolve within the path and throw `PathTraversalDetected` the moment they would escape it. Internal, resolvable `..` (`a/../b` → `b`) is allowed, matching Flysystem's own operation-path semantics. Percent-encoded sequences (`%2e%2e%2f`) are intentionally treated as literal file-name characters: the decorator operates on decoded paths, URL decoding happens exactly once at the HTTP boundary, and decoding again here would corrupt legitimate `%`-containing names and reintroduce the double-decode bug class. State this in the class docblock.

## Part 6 — Tests

Run each file's tests immediately after writing it (`./vendor/bin/phpunit --no-progress <file>`), phpstan per touched package (`./vendor/bin/phpstan`, no flags), and finish with `composer test:parallel`.

### 6.1 Object-pool (`tests/ObjectPool/`)

| File | Action | Coverage |
|---|---|---|
| `ChannelTest.php` | new | cross-mode visibility (release outside a coroutine → borrow inside; the reverse); non-coroutine exhaustion returns false (no `SplQueue` crash); coroutine waiter woken by release; waiter timeout; rapid repeated signals from release/destroy with zero and one parked waiter never block; a skipped/coalesced push still lets every waiter wake and re-check before its deadline. Mirror the suite for `src/pool`'s channel in `tests/Pool/` |
| `PoolOptionsTest.php` | new | validation matrix (every rule; type errors; NAN/INF rejection; unknown keys throw; `idle_ttl` explicit-null vs absent), normalization equality (defaults vs explicit, key order), `toArray()` |
| `PoolFingerprintTest.php` | new | canonicalizer matrix: maps vs lists, list order preserved, map key order irrelevant, nested reordering, int vs string keys, **mixed `1`/`"01"` keys with reversed insertion order fingerprint identically**, backed/unit enums, enum-vs-list non-collision, closure/resource/object rejection with key path in message, explicit domain tag ≠ auto tag |
| `PoolDefinitionTest.php` | new | non-empty identity/resourceType/fingerprint validation |
| `PoolManagerTest.php` | rewrite (merge) | `getOrCreate` create/reuse; managed API has no teardown callback; resource-type mismatch throws (same `pool.name` + fingerprint + options, different type); fingerprint mismatch throws; options mismatch throws naming only differing fields; a closed registered pool and definition are detached and replaced without stale mismatch checks; `remove` closes + returns bool; `remove` with `$expected` mismatch is a no-op; `flush` closes all; `definition()` |
| `ObjectPoolTest.php` | rewrite (merge) | closed-state matrix (get throws, release destroys with destroy callback, idempotent close, close wakes all parked waiters immediately, close during a suspended factory destroys the orphan and the borrower throws); ownership matrix (double release throws, foreign release throws, double destroy throws, duplicate factory output throws **and signals a parked waiter**, `discard()` destroys a borrow, borrowed count from tracked state); capacity matrix (yielding factory under concurrency never exceeds `max_objects`; waiter woken by creation failure, by discard, and by maintenance destroy creates a replacement within its deadline); `sweepExpired` below the floor; `trimIdle` respects floor + `max_idle_time`, disabled at 0; requeue preserves idle clocks; `isIdle` needs zero borrowed **and zero in-flight checkouts (parked waiter and suspended factory both block it)**; idle checkout replaces consecutive expired objects under one deadline, while a freshly created object always receives its first checkout; `max_lifetime => 1e-9` with finite `wait_timeout` completes instead of entering a scheduler-starving create/destroy loop; destroy-callback failure during discard/close/maintenance is reported, bookkeeping stays consistent, and the triggering exception (when any) still propagates; constructor-immutable standalone destroy callback; saturating duration arithmetic (a huge finite `wait_timeout`/`max_lifetime`/`max_idle_time`/`idle_ttl` — e.g. `PHP_INT_MAX` seconds — never immediately times out or expires; conversion and deadline saturation asserted deterministically through an exposed test subclass); stats schema |
| `SimpleObjectPoolTest.php` | update | constructor with `PoolOptions` + destroy callback; `setCallback` gone |
| `LeaseTest.php` | new | get/release/idempotence, throw-after-finalize on `get()`, release callback runs on release, **release callback throwing → object discarded + exception propagates**, **release callback throwing + contract pool whose `discard()` also throws → the callback exception is the exact propagated instance, the discard failure reported once, no destructor retry (finalized stays set)**, `discard()`, destructor finalizes and never throws; constructed against a standalone `Contracts\ObjectPool` implementation that does not extend the base class (the abstraction is honored) |
| `PoolErrorReporterTest.php` | new | reports through the bound `ExceptionHandler`; falls back to `error_log()` when no container binding exists **and when the handler itself throws**; never propagates |
| `PoolProxyTest.php` | rewrite + convert to `Hypervel\Tests\TestCase` | per-operation pool resolution (invalidate → next call re-creates); `invoke()` borrow/release; **`configureBorrowed()` throwing → object discarded, no leak**; **`configureBorrowed()` throwing against a contract-only pool whose `discard()` also throws → the configuration exception is the exact propagated instance, the discard failure reported once**; **primary-exception preservation** (operation throws + release callback throws → operation exception rethrown, finalization failure reported); release callback on synchronous and leased paths; `getPoolName`/`getDefinition`; `invalidatePool` |
| `PoolRecyclerTest.php` | rewrite from `ObjectRecyclerTest` | idle pool evicted via identity-conditional remove; replacement pool survives a stale eviction attempt; a pool with an in-flight checkout (parked waiter or suspended factory) is not evicted; non-idle pools swept + trimmed; a maintenance throwable is reported through `PoolErrorReporter` and does not disappear through the timer's absent logger; timer start/stop; interval validation through constructor and setter (`0`, negative, `NAN`, `INF`, `-INF` all rejected) |
| `TimeStrategyTest.php` | delete | strategy layer removed |

Coroutine-safety tests (`parallel()` + `usleep()` per AGENTS.md) for: concurrent borrows during close (late release destroyed), concurrent `getOrCreate` convergence.

### 6.2 Filesystem (`tests/Filesystem/`)

| File | Action | Coverage |
|---|---|---|
| `FilesystemManagerTest.php` | convert base class (raw PHPUnit → `Hypervel\Tests\TestCase`), replace repo-root writes with `ParallelTesting::tempDir()`, add `: void` | existing coverage + new: two s3 disks/one account converge on one pool identity; different credentials split; `build()` twice with identical config shares; `pool.name`/`pool.fingerprint` identities; options conflict throws; `purge()` closes (cached + never-cached paths); `forgetDisk()` cache-only; custom poolable driver gets `FilesystemPoolProxy` with full-config fingerprint; custom creators never receive the `pool` key; scoped-over-s3 resolves without collision; uncached scoped purge closes the converged parent client pool (created via the parent disk, another scoped disk, or `build()`); uncached scoped purge over a whole-driver-pooled parent derives with the on-demand resolution name (name-parity regression); nested scoped disks compose prefixes and purge the same client pool; a named scoped-definition cycle throws naming the cycle; cached scoped purge stays correct |
| `AwsS3V3AdapterTest.php` | new | native ranged reads, S3 option merge preserving sibling `@http` settings, throw/non-throw failure behavior |
| `GoogleCloudStorageAdapterTest.php` | new | native ranged reads, throw/non-throw failure behavior, URL selection with and without `storageApiUri` after dead-branch removal |
| `ClientPooledFilesystemTest.php` | new | fake-credential S3/GCS clients (no network): client config derivation (flat keys selected positively, adapter keys excluded, `client` block wins, unknown `client` keys throw), advanced scalar/array GCS `client` options accepted (`requestTimeout`, `retries`, `scopes`), object/callable `client` options require an explicit `pool.fingerprint` (throw without, converge with), stack built per operation, callbacks applied per stack, `withClient`/`withDriver`/`withAdapter`, the six Flysystem methods (`has`/`read`/`fileSize`/`visibility`/`write`/`createDirectory`) served within a borrow, `when`/`unless` callbacks receive the proxy, `getDriver`-family throws, `__call` throws (no lazy escape), `getConfig`, `readStream` non-resource result releases the lease, a stack-factory failure against a contract-only pool whose `discard()` also throws propagates the stack exception as the exact instance with the discard failure reported once (no destructor retry), `invalidatePool` + next-op re-acquire, release callback runs on synchronous and leased paths |
| `FilesystemPoolProxyTest.php` | new | over a poolable-registered local driver: `configureBorrowed` clears/writes all callback slots (cross-proxy leak regression), lease-backed `readStream`, the six Flysystem methods through `invoke()` (a contract-only driver lacking them fails naturally), `when`/`unless` receive the proxy, rejected internals + `with*` access; over a contract-only custom `Filesystem` (no `FilesystemAdapter`): pooling works with all-null slots, setting any callback then borrowing throws the capability error |
| `LeasedStreamTest.php` | new | wrap a temp-file stream: read-through, EOF, close releases exactly once, abandonment (unset without close) releases via destructor, seek/tell/stat forwarding, `stream_cast` (usable with `stream_select()`), all option forwarding asserted through one recording inner wrapper as exact callback tuples — blocking records `[BLOCKING, 0, null]` (the null-`$arg2` TypeError regression), timeout records `[READ_TIMEOUT, seconds, microseconds]`, `stream_set_write_buffer($leased, 8192)` records `[WRITE_BUFFER, FULL, 8192]` (a mode-as-size bug would record size 2), size 0 records mode `NONE`, unsupported read buffering fails with no inner record; never assert `stream_get_meta_data()['blocked']` — under `SWOOLE_HOOK_ALL` a hooked socket's metadata does not reflect `stream_set_blocking()` (verified: the call returns true, `blocked` stays true), so forwarding is observed at the inner callback and nowhere else, transactional wrap failures (protocol collision, registration failure, open failure) each assert the raw resource was closed **and** the lease finalized exactly once — not just that an exception was thrown, close-path failures reported without propagation |
| `FileResponseBuilderTest.php` | new | full + range (206/If-Range) responses over a local disk; strong and weak `If-Range` entity-tag comparison; malformed ranges (`bytes=abc-def`, multi-range) fall back to full content; suffix (`bytes=-N`), open-ended, and oversized-suffix (whole representation, not 416) ranges; case-insensitive range unit (`Bytes=0-1`); HEAD response-level body suppression (including ignored Range); emitted bytes capped at `end - start + 1` when the resolver stream is longer; body of exactly `"0"` served intact; `false`/non-resource stream results; empty non-EOF reads and premature range EOF fail without spinning; output-write exceptions close the stream (lease released) with close failures reported, primary exception preserved; range handling driven by the passed request; range resolvers exercised for local (generic seek), S3 (native `Range` on the captured command), and GCS (native `Range` header); pooled path: no borrow held between build and emission, stream resolver borrow released after emission |
| `ScopedFilesystemProxyTest.php` | new | **every mapped public method tested individually** (this is a security boundary — no representative-group sampling); every path-sensitive method asserts the prefix resolver is called exactly once, while `getConfig` and temporary-URL capability probes assert it is not called; write/read/delete under prefix; per-call resolution proven concurrently (two coroutines, different context prefixes, `parallel()` + `usleep()`); empty prefix throws / passthrough opt-in; resolver returning `"."` or `"scope/.."` throws (normalizes to empty — fail-open regression); traversal rejected in prefix and paths — `../` escapes, backslash variants (`..\`), NUL and control characters, and literal `%2e%2e` treated as a plain file name (no decoding); internal resolvable `..` allowed; upload overloads (file-first args); `putFileAs('dir', ..., '../../evil.txt')` throws before I/O and proves no file exists outside the resolved prefix, while internal `a/../b` resolution stays inside it; returned-path stripping incl. fail-closed foreign path and normalized-return escape (`scope/../outside`); array deletes; copy/move both args; listing strip; separators; wrong resolver return type fails naturally; rejected mutators + `getDriver`-family; `response`/`download`/`serve`; temporary URLs; `json`/`checksum`/`mimeType`; the six Flysystem methods prefix their paths (including through a direct adapter's magic driver forwarding via the loosened `call()`); `when`/`unless` callbacks receive the decorator; every fluent assertion returns the proxy; `__call` throws; Cloud variant `url()` |
| `FilesystemAdapterTest.php` | update | `path()` traversal rejection; `getVisibility` strict comparison; nullable callback mutators; base `readStreamRange()` generic path (seekable fseek, non-seekable read-discard, suffix resolution via `size()`) and the shared range-argument contract (both-null delegates to `readStream()`; negative values, `start > end`, and `null, 0` throw `InvalidArgumentException` before any I/O); temporary-URL callback kinds all invoke (static anonymous, first-class function and method closures — the bind regression), anonymous non-static still observes `$this` as the concrete adapter, both `LocalFilesystemAdapter` overrides exercised with static callbacks |

### 6.3 Other packages

- `tests/Mail/`: named built-in poolable transport absent `pool` keeps automatic pooling; a named custom transport registered `poolable: true` and configured without `pool` also follows the named default and pools; named `false` opts out; on-demand absent/`false` stay direct; on-demand `true`, `[]`, and partial option arrays pool; omitted array settings use defaults; invalid scalar/string/null forms throw; explicit pooling of a non-poolable transport throws; custom on-demand transport requires both `extend(..., poolable: true)` and per-build opt-in; repeat equivalent builds share; different resolved credentials split; transport-pool convergence across two identically-configured mailers; named `purge()` invalidates (cached + uncached); an explicitly pooled on-demand transport can invalidate its proxy immediately and otherwise expires by `idle_ttl`; presentation keys don't split pools **for built-in transports but do split custom poolable transports** (custom creators fingerprint the full config minus `pool`); custom creators never receive the `pool` key; **resolved `services.*` changes rotate the fingerprint**; composite fingerprints change with child credential changes and child order; composite `retry_after` regression (differs from last child's); definition-cycle detection; nested children stay direct; a global address without a `name` resolves; internal construction config is validated once at its raw boundary.
- `tests/Queue/`: `QueuePoolProxy` connection-name-per-borrow (two logical connections, one pool, names correct on popped jobs); connectors and `setConfig()` receive the `pool`-stripped config (custom connector asserts the key is absent); queue name is a string across every backend job and null SyncQueue input normalizes to `'sync'`; zero-argument `FakeJob` reports connection `'sync'` and queue `'default'`, explicit named constructor values are honored, and the Horizon failed-job fixture initializes both inherited names; job lease lifecycle (mocked pheanstalk/SQS: lease released after the backend call in `delete`/`release`/`bury` — assert ordering; on null pop; on pop exception with the pop failure preserved over a throwing release; via destructor on abandonment); backend failure discards the pooled object — it never returns to the pool and the next borrower gets a replacement; attachment failure (Beanstalk attempts-prime `statsJob()` throws): successful recovery requeues the reserved job and the backend returns to the pool exactly once, failed recovery discards the backend and the next borrow creates a replacement — attachment exception primary in both; non-lease-aware job → requeued via `release(0)` through the pinned connection, then fail-closed exception with the borrow released (and requeue failure → discard, requeue exception attached as previous); `attempts()` primed at lease attachment — a `JobProcessed` listener reads it post-terminal on a `maxTries === 0` run with no pre-terminal worker read, live reads refresh while the lease is active; post-terminal `getPheanstalk()`/`getSqs()` throw; worker-driven success/delete, failure/release, and failure/fail terminal paths; `setApplication()`: a cached pooled connection's pool is closed and its cache entry evicted, the next resolution uses the new container's `PoolFactory`, and a direct cached queue still gets the in-place `setContainer($app)`.
- `tests/Broadcasting/`: purge/forget split; definitions; custom creators never receive the `pool` key; two proxies sharing one pool with set/null authenticated-user resolvers (leak regression); contract-only pooled custom broadcaster serves contract methods with a null callback, and a set callback throws the capability error at borrow; pooled construction-failure diagnostics name the driver (never another converged connection's name) while unpooled failures keep the connection name; `channel()` fluency; Reverb resolves directly and creates no pool while Pusher/Ably retain their current poolable status.
- `tests/Notifications/`: Slack resolves directly with no proxy or pool registration; repeated resolution proves the old auto-singleton wrapper added no distinct pooled object or behavior.
- `tests/Http/`: shared-handler identity across sequential and concurrent requests per connection; per-request middleware isolation (first-stack-capture regression); request-level handler override; every option-precedence tier (factory globals → preset → per-call `connection()` override → fluent options → `setClient()`/`setHandler()`), including globals-vs-preset layering (a preset key overrides a factory-global key) and order-independent chaining (`timeout()->connection()` === `connection()->timeout()`); `connection($name, [])` clears the preset (empty array is a real replacement); `setConnectionConfig()` registers a previously absent name and explicit empty config remains distinguishable from absence without a parallel map; reserved keys rejected through one canonical `ReservedOptions` implementation at every tier — factory globals (array and closure forms, closure product checked once), registration presets, per-call overrides, and fluent `withOptions()` (`pool`, `handler`, `cookies`, plus `transport_sharing` outside registration); `transport_sharing` routed to `Utils::chooseHandler()` at registration and excluded from client options; re-registration swaps the handler while in-flight requests finish on the old one; `createClient()` requires the request-owned jar; per-request cookie jars isolated across concurrent requests; retry-chain cookie persistence.
- `tests/Pool/`: `PoolOptionTest` gains the constructor and setter validation matrices (count rules including the cross-field min/max checks through both setters; finite-duration rules including NAN/INF; the `-1` sentinels with `0` and other negatives rejected for `heartbeat`/`max_lifetime`; the events list rule) and `initOption()` unknown-key rejection (with `DbPool` stripping `testing_enabled` proven by a testing-mode config); channel cross-mode and rapid-signal/coalescing suite (mirroring object-pool's), including a closed channel rejecting a push without retaining it, plus `Pool` ownership/capacity/wait-loop coverage including saturating `wait_timeout` arithmetic (a huge finite value never yields an immediate exhaustion timeout) and the close suite (close wakes parked waiters; close during a suspended factory destroys the orphan and the borrower throws; a connection released after close is destroyed, not requeued; borrows on a closed pool throw; close is idempotent; the `max_connections = 1` fresh-capacity proof through factory `flushPool()` — the new pool's borrow succeeds before the old pool's borrow releases, each pool's `getCurrentConnections()` counting only its own); every database/Redis registry-drop API removes the entry before a yielding close and concurrent resolution receives a fresh pool; the maintenance suite (a throwing `check()` destroys the connection, frees its slot — a replacement is immediately creatable — is reported, and does not throw from maintenance; a passing probe preserves idle clocks so `max_idle_time` eviction still fires after repeated probes; `ConstantFrequency` drives `checkIdleConnection()`); heartbeat failures are logged through the configured logger or `error_log()` fallback after clearing the connection; heartbeat lifecycle migrations in the DB/redis suites (timer-cleared and shared-PDO-cleared assertions move from `flushAll()` to `close()`; teardown drains become `close()`/factory `flushPool()`; the mid-ping invalidation fixture closes instead of flushing; heartbeat sweeps destroy/requeue through the rebuilt primitives with `heartbeatGeneration` deleted); database/redis integration workflows as the end-to-end gate.
- `tests/Integration/Cache/`: `CacheFunnelTestCase` moves its lock cleanup from `setUp()`/`tearDown()` into `setUpInCoroutine()`/`tearDownInCoroutine()` (existing best-effort swallow preserved). PHPUnit 13 runs `setUp()`/`tearDown()` outside the test coroutine, and the cleanup performs backend I/O (`releaseFunnelLocks()`, eleven `forceRelease()` calls); the dual-store channel bug (1.1 #9) masked that by handing outside-coroutine callers a fresh blocking-mode connection, while the corrected single store (2.0) hands them an idle connection created inside a prior test's coroutine — a Swoole-hooked socket whose use outside a coroutine is the uncatchable `Swoole\Error('API must be called in the coroutine')`. The hooks run inside the test coroutine after `InteractsWithRedis` setup and before its teardown (`RunTestsInCoroutine.php:88-116`); no funnel subclass overrides them.
- Parallel-safe SQLite scratch paths: `DbPoolHeartbeatTest.php`, `InMemorySqliteSharedPdoTest.php`, `PoolConnectionManagementTest.php`, and `QueryDurationThresholdPooledTest.php` use `ParallelTesting::tempDir()` rather than shared `sys_get_temp_dir()` filenames. Every touched legacy test method gains its missing `: void`; all new immutable pool value/error classes retain the required constructor docblocks; and the heavily rewritten `src/pool/src/Pool.php` constructor gains its missing method docblock.
- `tests/Sentry/`: pool constructor update; unsupported `sentry.pool` keys rejected (only `max_objects`/`wait_timeout`/`max_lifetime` accepted); `HttpPoolTransport` defer/close lifecycle against ownership tracking; a `send()` throwable discards the transport (failed `Result` preserved, next borrow creates a replacement).
- `tests/Watcher/`: immediate child exit and scripted false/empty-non-EOF pipe reads fail with the correct diagnostics and release the process/pipes; explicit `stop()` before channel closure returns cleanly; repeated stop is idempotent; the existing stop test also asserts every pipe is closed; fswatch and server launch argv preserve spaces, quotes, and shell metacharacters literally (including the exact `%p` argument); invalid server command/bin configuration fails fast; find commands escape every target and reference path; reference-file touch creates, bumps, and reports failure; two live FindNewer drivers own four distinct files; stop removes them idempotently; watch after stop creates a fresh pair; second-file creation failure removes the first; each scan touches only the alternate reference before scanning, a successful quiet scan still swaps roles, a failed scan leaves the old cutoff authoritative, and a change made after the active scan passed its path remains eligible on the next scan; stop during a yielding reference update skips the scan and removes every file on unwind; stop during a yielding scan performs its one pre-scan touch but skips index advancement and publication, removes every file on unwind, and rejects restart until quiescent.
- `tests/Coordinator/TimerTest.php`: an unexpected recurring callback failure reaches the injected logger when present; without one it reaches a redirected `error_log` destination, and in both cases the next tick still runs. One-shot `after()` behavior remains uncaught and unchanged.
- `tests/Filesystem/FilesystemTest.php`: restrictive replacement modes apply after the complete content is written; missing and unwritable target directories fail with named exceptions and leave no fallback file in the system temp directory.

## Part 7 — Docs, cleanup, and finishing

1. **boost docs** (`src/boost/docs/`): rewrite `filesystem.md` "Driver Pools" (identity model: convergence, `pool.name`/`pool.fingerprint`, options, TTL/purge semantics, `with*` accessors, on-demand disks now safe to build repeatedly) and "Scoped and Read-Only Filesystems" (dynamic decorator with a per-request closure example, fail-closed rules); update `mail.md` with the complete named-vs-build `pool` semantics table (`false`/`true`/empty array/partial array), defaults, automatic construction fingerprints, custom-driver two-gate rule, runtime-selected provider account examples, and the distinction between named `purge()` and immediate on-demand `TransportPoolProxy::invalidatePool()` versus automatic `idle_ttl` reclamation; update `queues.md`; rewrite `http-client.md` connection docs to the preset/shared-handler model and request-local middleware/cookies; update `notifications.md` to explain direct stateless routing rather than a pool wrapper. Rename the object-pool guide from `pools.md` to `object-pools.md`, update `src/boost/docs-ported.md` and every internal link, and add one short introductory distinction: `Hypervel\ObjectPool` is the public general-purpose API documented there, while `Hypervel\Pool` is lower-level connection-pool infrastructure configured through the database/Redis/consumer guides rather than constructed through this guide. Every doc example that discards under an active failure models the guarded pattern (report the discard failure, rethrow the primary) — docs must demonstrate the universal masking rule, not contradict it.
2. **Facade annotations, all of them:** `Storage` (drop `listContents()`/`getDriver()`/`getAdapter()` and the Macroable set — `macro`/`mixin`/`hasMacro`/`flushMacros`/`macroCall`, rejected on pooled disks with unknowable macro result lifetimes; keep the six Flysystem methods and `when`/`unless`, which every implementation now serves; retype `when`/`unless` and the four `assert*` returns to `\Hypervel\Contracts\Filesystem\Filesystem` — the honest common type across adapter, pooled proxies, and scoped decorators, replacing the concrete `FilesystemAdapter` returns; add `withDriver()`/`withAdapter()`/`withClient()`, `invalidatePool()`, updated mutator signatures), `Mail` (single-arg `createSymfonyTransport()`), `Queue` (new `purge()`), `Http` (preset-model `registerConnection()`), `Notification` (pooling surface removed), `Broadcast` (purge semantics).
3. **READMEs:** filesystem README gains `Ported from: https://github.com/laravel/framework (illuminate/filesystem)` and a Differences-From-Laravel entry for client-level pooling + the scoped decorator; object-pool README documents the model (it is Hypervel-original — no upstream line).
4. **Deletions:** `src/object-pool/src/Strategies/` (+ dir), `Contracts/RecycleStrategy.php`, `Contracts/TimeStrategy.php`, `PoolOption.php`, `tests/ObjectPool/TimeStrategyTest.php`, `src/http/src/Client/ClientPoolProxy.php`, `src/notifications/src/NotificationPoolProxy.php`, and the `@TODO` in `MailManager`. Grep for `recycle_ratio|recycle_strategy|RecycleStrategy|TimeStrategy|lastRecycledAt|PoolOption\b|min_objects|flushOne|setDestroyCallback|ClientPoolProxy|NotificationPoolProxy` across `src/` and `tests/` — zero hits when done, with one expected survivor: references to `Hypervel\Pool\PoolOption`, src/pool's connection-pool options class, which stays (the `PoolOption\b` token exists to catch stale references to the deleted `Hypervel\ObjectPool\PoolOption`; hits in `src/pool`, `src/redis`, `src/database`, and their tests that resolve to the `Hypervel\Pool` class are correct). `flushOne` genuinely reaches zero — the 2.0 replacement removes it from `src/pool` too.
5. **Config templates:** update any `pool` key examples (`sentry.pool`, queue/mail/broadcast config stubs, `.env.example` if pool env vars exist) to the new option names; remove `pool` keys from HTTP connection examples.
6. **Standing design register:** add one concise `docs/todo.md` entry to review whether the already-pooled Pusher/Ably broadcasters own state that cannot safely be shared like Reverb, linking back to this plan's evidence. Do not add a source TODO or user-facing docs note for an unsettled policy question.
7. **Final gates:** phpstan clean per touched package; `composer test:parallel` green; the greps in (4) empty; no occurrence of `ondemand` pool-name special-casing anywhere; no stale `pools.md` link or file remains after the move.

## Part 8 — Implementation order

Each step: read the listed source first, implement, run that step's tests, run phpstan for the touched package, then move on. Test files are converted to the proper base classes/temp dirs **the first time a step touches them** (`FilesystemManagerTest` in step 8, `PoolProxyTest` in step 5) — never edited under the old hygiene and converted later.

1. `Channel` rewrite in object-pool + the sibling fixes in `src/pool` (`Channel` and `Pool` invariants, closed-push rejection, non-blocking coalesced signaling, detach-before-close in every database/Redis factory path, option validation, keepalive failure reporting) (+ tests for both packages; database/redis integration workflows as the gate for the `src/pool` changes).
2. `PoolOptions`, `PoolFingerprint`, `PoolDefinition` (+ tests).
3. `ObjectPool` lifecycle + ownership rewrite, fresh-object expiry/livelock correction, `SimpleObjectPool`, `Lease`, contract updates (+ tests).
4. `PoolManager` + `Contracts/Factory` (closed-entry self-heal; no managed destroy callback) (+ tests).
5. `PoolProxy` + `HasPoolProxy` (+ tests, incl. `PoolProxyTest` conversion).
6. `PoolRecycler` + `Contracts/Recycler` + provider binding, maintenance error reporting, delete strategy layer (+ tests).
7. Sentry pool constructor/config update (+ tests).
8. Filesystem, file by file so every file's tests are green the moment they are written (the response-builder range tests need the range primitives to exist first):
   1. `FilesystemAdapter.php`: base `readStreamRange()` generic implementation, `path()` normalization, `getVisibility()` strict comparison, nullable callback mutators; `Filesystem.php`: checked atomic replacement transaction (+ tests).
   2. `AwsS3V3Adapter.php`: native ranged-`GetObject` `readStreamRange()` override (+ tests); verify `GoogleCloudStorageAdapter`'s existing native range path, failure semantics, and URL selection while removing its dead `storageApiUri` reassignment (+ tests).
   3. `FilesystemManager` client config derivation + client/stack factory split (+ tests, incl. the `FilesystemManagerTest` base-class/temp-dir conversion — this is the first sub-step touching it).
   4. `LeasedStream` (+ tests).
   5. `FileResponseBuilder` + `FilesystemAdapter` response refactor (+ tests — the pooled-path assertions use a hand-built `Lease` and `LeasedStream` resolver, so they don't depend on the later proxy classes).
   6. `ClientPooledFilesystem` (+ tests).
   7. Shared narrow pooled-filesystem concern + `FilesystemPoolProxy` rework (+ tests).
   8. `FilesystemManager` resolve/purge/forget restructure (+ tests).
9. Scoped decorators, including pre-I/O joined-target upload validation and no-path pass-through (+ tests).
10. Mail: construction-config builders, explicit `build()` pooling parser, composite fingerprints, `retry_after` bug fix, pooling relocation, purge (+ tests).
11. Queue: connection-name typing/state, job leases with concrete-method finalization, fail-closed third-party jobs, worker terminal paths, purge (+ tests).
12. Broadcasting rebuild, Reverb direct-resolution regression, notification pooling removal (+ tests).
13. HTTP: delete client pooling, shared handlers, connection presets/registration source of truth, canonical reserved-option validation, required per-request cookie jars (+ tests).
14. Watcher subprocess lifecycle, argv-native launching, find-argument escaping, unique reference-file ownership, pre-scan cutoff rotation, recurring timer failure reporting, and read-failure regressions.
15. Cache/Testbench temporary-file boundary normalization; docs, `object-pools.md` move/index links, `docs/todo.md` entry, facade annotations, READMEs, config templates, deletions, hygiene fixes, final greps.
16. `composer test:parallel`; investigate all failures per AGENTS.md before finishing.
