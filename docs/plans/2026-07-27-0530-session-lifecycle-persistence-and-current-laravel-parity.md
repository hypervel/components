# Complete Session Lifecycles, Persistence, and Current Laravel Parity

## Status

Pre-implementation audit and second-opinion consensus are complete. This plan
consolidates the settled design, including the owner-approved Hypervel default
Redis session prefix. The owner approved the public/configuration gates and
test return-type scope. Plan review, implementation, validation, fresh
self-review, and post-implementation code review are complete.

## Scope

Complete the `session` package audit as one coherent work unit, including the
lowest owning Foundation, Filesystem, Contracts, Support facade, configuration,
metadata, testing, and documentation boundaries required by the verified
findings.

The final implementation must:

- isolate mutable `Store` state between Store objects and coroutines;
- marshal decoded JSON error bags before merging storage into live state;
- publish flash aging and JSON persistence state only after storage commits;
- never save a session whose startup did not commit;
- preserve the primary request failure when an after-response save retry also
  fails;
- delegate lock cleanup to Cache's established callback boundary;
- make cookie-backed sessions binary-safe and strictly validate their envelope;
- correct file and database handler state and write behavior without adding
  retries or new I/O;
- adopt current supported Laravel Session behavior and application defaults;
- declare every supported Session configuration key at its single owner;
- give Redis sessions a dedicated application-scoped key namespace by default;
- remove dead bindings, unsafe unused APIs, stale suppressions, and stale docs;
- make split-package dependencies complete and truthful; and
- preserve Hypervel's cloned cache-store isolation, Redis pooling, guard-scoped
  password confirmation, and other intentional Session behavior.

This is not a redesign around per-request Store clones, a context registry,
serializer service, persistence transaction object, retry loop, or new session
driver abstraction.

## Post-compaction recovery and anti-overengineering rules

After compaction, read `AGENTS.md` and this plan in full before resuming. This
plan carries the relevant anti-overengineering rules so the framework-wide
audit plan does not need to be reread during implementation.

- Require a supported, realistic path and meaningful harm before adding a fix.
  Merely conceivable states do not justify machinery.
- Prefer existing Laravel or Hypervel APIs and the lowest owner. Do not
  duplicate Cache lock cleanup, Filesystem behavior, config resolution, or
  coroutine-context lifecycle in Session.
- Add no registry, WeakMap, counter, mutex, state machine, retry loop,
  compatibility decoder, configurable policy, or abstraction unless a verified
  requirement below cannot be completed without it.
- Do not add defensive guards where native types already fail correctly. The
  three checks in this plan are justified exceptions: invalid serialization
  silently selects PHP, a false handler write is an explicit persistence
  failure, and a non-Redis session store currently fails with an opaque
  undefined-method error.
- Do not make source more complex to satisfy PHPStan. Use truthful types and
  local narrowing; retain a scoped ignore only where correct magic proxying
  cannot be modeled.
- Do not preserve stale code because it works or because removing it causes
  churn. Backward compatibility for flawed Hypervel-only internals is not a
  goal. Current Laravel public APIs and conventional extension points remain a
  goal unless a documented Swoole requirement demands a difference.
- Do not add enforcement for deliberate framework escape hatches or unsupported
  misuse. In particular, user replacement of the reserved `errors` session key
  does not warrant a second error-bag type path.
- Hot paths must not gain container resolution, locks, hashing, I/O, retries,
  logging, yields, or retained worker state. Any newly discovered meaningful
  cost must return to owner review before implementation.
- Avoiding overengineering never permits an incomplete fix. Every verified
  failure must be closed at its real owner, with stale comments/tests removed.

## Fixed architecture and research

### Runtime ownership

| Surface | Final owner and lifetime |
|---|---|
| Named Session drivers | Worker-cached by `SessionManager` |
| Active request Store | Fixed `Store::CONTEXT_KEY`, one value per coroutine |
| Store ID, attributes, and started flag | Per Store object and per coroutine |
| Handler request/existence state | Object-ID-derived context keys; Database existence resets at construction and cloning |
| Cache-backed handler | Isolated cloned `Repository` with a deep-cloned store |
| Durable HTTP-test state | Explicit child-to-parent synchronization of the active Store only |
| Session persistence | `Store::save()` publishes live state only after a successful handler write |
| Blocked request lock | Cache `Lock::block(..., $callback)` |
| Redis connection and store prefix | Applied once to the cloned Redis cache store |
| Application serialization default | Framework config (`json`) |
| Direct `new Store(...)` default | Constructor default (`php`), matching Laravel |

`Repository::__clone()` clones its underlying store. Redis session connection
and prefix changes therefore affect only the handler's clone; they never mutate
the shared Cache manager repository or Redis store.

### Upstream references

The implementation reference is the current local Laravel Framework checkout
at `examples/laravel/framework`, commit
`23e9e71f382b91510c70b5b6f9ae0776f1b88e12`. The current application-config
reference is `examples/laravel/laravel`, commit
`2eb457783ee0e1f034612c2fae690924532d4ca4`.

Historical commits are discovery evidence only:

| Change | Discovery commit | Complete introduced surface |
|---|---|---|
| Redis session prefix | `3093ff3a61` / Laravel PR #60700 | `SessionManager`, `SessionManagerTest` |
| Collection short-circuit sync | `dd3a9225c1` / Laravel PR #60745 | `Store` only for Hypervel |
| JSON default for new applications | `75cef503c6edc3447dd79053a648ea981857e15b` | application `config/session.php` only |

Port current source and tests, not historical diff text. Hypervel intentionally
adapts the Redis prefix check to preserve `"0"`, retains its cloned store and
default `session` Redis connection, and ships a dedicated prefix default:

```php
'prefix' => env('SESSION_PREFIX', app_id() . '_session:'),
```

Laravel's current application config deliberately uses the literal JSON
default, not an environment variable:

```php
'serialization' => 'json',
```

Keep it literal. Serialization-strategy drift between deployments can silently
read an existing session as empty; applications needing PHP object sessions
must make that code-owned compatibility decision in configuration.

## Finding summary

| ID | Category | Severity | Verified failure or gap | Final boundary |
|---|---|---:|---|---|
| `session-01` | Defect | Major | Fixed Store context keys make two Store objects share ID, attributes, and started state | Precomputed object-specific context keys |
| `session-02` | Defect and upstream defect | Major | Failed or false writes age flash state or replace the live error bag before commit; repeated JSON save crashes | Two local snapshots and post-write publication |
| `session-03` | Defect | Major | Failed startup registers a later empty write against the cookie-derived ID | `Request::hasSession()` commit flag |
| `session-04` | Defect | Major | Manual lock release can replace request failure or run after failed acquire | Cache lock callback form |
| `session-05` | Defect and upstream defect | Major | JSON cookie envelope rejects binary serialized data and accepts invalid decoded shapes | Private PHP-serialized envelope |
| `session-06` | Defect | Minor | File GC passes `string|false` and counts failed deletes | Finder pathname and successful-delete count |
| `session-07` | Defect | Minor | Direct database write caches stale false existence after `read()` updates context | Refresh local existence once |
| `session-08` | Current Laravel parity and configuration improvement | Minor | Redis sessions cannot own a distinct prefix | Truthful RedisStore setup and declared default |
| `session-09` | Current Laravel parity | Improvement | Store collection checks lag current upstream and `hasAny()` scans all keys | Current `doesntContain()` / `contains()` shape |
| `session-10` | Configuration and security defect | Major | Supported keys are undeclared, defaults are duplicated, and invalid serialization silently selects PHP | Canonical config plus constructor validation |
| `session-11` | Dead-code cleanup | Improvement | Redundant `StartSession` singleton, empty provider boot method, and unsupported-driver cache wrapper remain | Delete all three |
| `session-12` | Userland footgun and API cleanup | Minor | Unused Hypervel-only `setConnection()` mutates a worker-cached handler | Remove it; document retained upstream mutator |
| `session-13` | Metadata defect | Minor | Split package omits direct runtime dependencies | Complete manifest and metadata regression |
| `session-14` | Documentation defect and upstream defect | Minor | Custom-driver GC describes seconds as a Unix timestamp | Correct public wording |
| `session-15` | Contract defect | Major | Nullable Store IDs violate Symfony, handler, guard, and Laravel string boundaries | Lazy non-null `getId()` |
| `session-16` | Type-consistency improvement | Improvement | Array/Null handler signatures lag their four typed Hypervel siblings | Complete native interface types |
| `session-17` | Static-analysis maintenance defect | Minor | Nine unmatched ignores can hide future real errors | Delete only stale suppressions |
| `session-18` | Intentional runtime difference | Minor | Array sessions are worker-local and unsuitable for production | Concise task-oriented documentation |
| `session-19` | Defect | Major | A failed after-response save retry escapes the exception renderer and replaces the primary request failure | Contain only the retry failure |
| `session-20` | Defect and upstream defect | Major | Starting a JSON Store can replace an already-live validation error bag with an empty one | Marshal only the decoded storage payload before merging |
| `session-21` | Defect | Major | A reused Database handler object ID can inherit `exists=true`, update zero rows, and report a silently lost write | Initialize object-specific state on construction and cloning |
| `session-22` | Defect | Major | The file handler reports success after false or partial filesystem writes | Require the complete byte count |
| `filesystem-12` | Type-consistency improvement | Improvement | Concrete `Filesystem::delete()` alone omits its contract's native union | Add `array|string` |

## Owner decisions

The owner approved these public, configuration, and Improvement-category
decisions:

- the declared blocking keys and current Laravel JSON application default;
- the non-null `getId()` contract;
- removal of the unused Hypervel-only database-handler mutator;
- conversion of false handler writes into a persistence exception;
- containment of an after-response save retry failure so the primary request
  failure remains renderable;
- removal of the dead protected `createCacheBased()` wrapper;
- current Store collection synchronization;
- redundant provider cleanup;
- Array/Null handler native types;
- concrete Filesystem type convergence;
- concise array-driver runtime documentation;
- Database handler context initialization and complete file-write validation;
  and
- bounded metadata, documentation, and stale-suppression cleanup found during
  code review.

The owner approved adding `: void` to all 89 existing test methods across the
eight affected Session test files, as well as every new test method.

## 1. Isolate each Store's coroutine state

### Store keys and construction

In `src/session/src/Store.php`, keep the fixed active-request key and replace the
three state keys with prefixes:

```php
public const CONTEXT_KEY = '__session.store';
public const STARTED_CONTEXT_KEY_PREFIX = '__session.store.started.';
public const ATTRIBUTES_CONTEXT_KEY_PREFIX = '__session.store.attributes.';
public const ID_CONTEXT_KEY_PREFIX = '__session.store.id.';

protected readonly string $startedContextKey;
protected readonly string $attributesContextKey;
protected readonly string $idContextKey;
```

Validate serialization first, then derive and initialize every key before
calling `setId()`. Constructor initialization is required because PHP may reuse
an object ID after `Manager::forgetDrivers()` frees a Store in the same
coroutine:

```php
protected const SUPPORTED_SERIALIZATIONS = ['json', 'php'];

public function __construct(
    protected string $name,
    protected SessionHandlerInterface $handler,
    ?string $id = null,
    protected string $serialization = 'php'
) {
    if (! in_array($serialization, self::SUPPORTED_SERIALIZATIONS, true)) {
        throw new InvalidArgumentException(sprintf(
            'Session serialization [%s] is not supported. Supported: "%s".',
            $serialization,
            implode('", "', self::SUPPORTED_SERIALIZATIONS),
        ));
    }

    $suffix = (string) spl_object_id($this);

    $this->startedContextKey = self::STARTED_CONTEXT_KEY_PREFIX . $suffix;
    $this->attributesContextKey = self::ATTRIBUTES_CONTEXT_KEY_PREFIX . $suffix;
    $this->idContextKey = self::ID_CONTEXT_KEY_PREFIX . $suffix;

    CoroutineContext::set($this->startedContextKey, false);
    CoroutineContext::set($this->attributesContextKey, []);

    $this->setId($id);
}
```

The constant stays untyped to match every existing constant in `Store.php`.
Tests assert the public exception, not the protected constant. The validation
must precede all context writes so rejected construction leaves no orphan
slots.

Route all Store state methods through the precomputed properties:

```php
protected function getAttributes(): array
{
    return CoroutineContext::get($this->attributesContextKey, []);
}

public function isStarted(): bool
{
    return CoroutineContext::get($this->startedContextKey, false);
}

public function setId(?string $id): void
{
    CoroutineContext::set(
        $this->idContextKey,
        $this->isValidId($id) ? $id : $this->generateSessionId()
    );
}
```

Do not add clone handling. There is no supported Store clone consumer, and
manager-per-request cloning would bypass the established cached-driver model.

### Non-null ID contract

Restore Laravel's truthful string contract in:

- `Hypervel\Contracts\Session\Session::getId()`;
- `Store::getId()` and `Store::id()`; and
- `Hypervel\Support\Facades\Session` metadata.

A manager-cached Store may first be used in a coroutine other than its
construction coroutine, so `getId()` lazily creates the missing value:

```php
public function getId(): string
{
    /** @var string|null $id */
    $id = CoroutineContext::get($this->idContextKey);

    if ($id === null) {
        $id = $this->generateSessionId();
        CoroutineContext::set($this->idContextKey, $id);
    }

    return $id;
}
```

This is one normal context read and an exceptional first-use write. Delete the
now-dead nullable-ID workaround from Foundation's
`Testing\Concerns\InteractsWithSession::startSession()`.

The lazy ID also means an unsupported `save()` on a manager-cached Store in a
different coroutine, without first assigning or starting its request session,
writes an empty session under a generated ID instead of reaching a nullable-ID
type failure. Add no guard for that stray path.

### Foundation test-context bridge

`MakesHttpRequests` must synchronize the active Store's object-specific keys.
Use one testing-local helper shared by snapshot creation and key enumeration:

```php
protected function sessionStoreContextKeys(SessionStore $session): array
{
    $suffix = (string) spl_object_id($session);

    return [
        SessionStore::STARTED_CONTEXT_KEY_PREFIX . $suffix,
        SessionStore::ID_CONTEXT_KEY_PREFIX . $suffix,
        SessionStore::ATTRIBUTES_CONTEXT_KEY_PREFIX . $suffix,
    ];
}
```

The snapshot maps those keys to `isStarted()`, `getId()`, and `all()`. The full
sync-key list contains `Store::CONTEXT_KEY` plus the dynamic keys derived from
the active Store still present in the child context:

```php
protected function sessionContextKeys(): array
{
    $keys = [SessionStore::CONTEXT_KEY];
    $session = CoroutineContext::get(SessionStore::CONTEXT_KEY);

    if ($session instanceof SessionStore) {
        array_push($keys, ...$this->sessionStoreContextKeys($session));
    }

    return $keys;
}
```

The derivation must run eagerly inside the waiter child: that child's copied
context still contains the prior active Store even when the completed request
has no session. `RequestContextSynchronizer` then removes all four absent values
from the parent. Array ordering is not significant because both method
arguments are evaluated before parent synchronization begins. Do not use a
generator, scan the entire context, or retain every discarded Store identity.

Keep one concise WHY comment at that derivation site: it must read the child's
copied active Store even when the completed request has no session.

### Regressions

- Two Store objects in one coroutine retain independent IDs, attributes, and
  started flags.
- Destroying/forgetting one Store and constructing another with a reused object
  ID starts with empty attributes and `started=false`.
- A Store constructed outside a request coroutine lazily creates a valid ID in
  the request coroutine.
- Model object-ID reuse deterministically by seeding all three stale slots in
  an anonymous Store subclass immediately before its parent constructor.
- HTTP-test flash/session state still synchronizes to the parent.
- A following request without a session clears the fixed active Store and its
  three dynamic slots.

## 2. Make persistence transactional and serialization explicit

### Pure storage error-bag marshalling

Marshal only the decoded storage payload before merging it into live state:

```php
protected function loadSession(): void
{
    // Marshal the decoded payload before merging: marshalling the merged result
    // would iterate an already-live ViewErrorBag and replace it with an empty one.
    $this->replaceAttributes($this->marshalErrorBagIn($this->readFromHandler()));
}

protected function marshalErrorBagIn(array $attributes): array
{
    if ($this->serialization !== 'json' || ! array_key_exists('errors', $attributes)) {
        return $attributes;
    }

    $errorBag = new ViewErrorBag;

    foreach ($attributes['errors'] as $key => $value) {
        $messageBag = new MessageBag($value['messages']);

        $errorBag->put($key, $messageBag->setFormat($value['format']));
    }

    $attributes['errors'] = $errorBag;

    return $attributes;
}
```

Use the truthful pure-transformation title, "Marshal the ViewErrorBag in the
given session attributes." Likewise, title
`prepareErrorBagForSerialization()` "Prepare the ViewErrorBag in the given
session attributes for JSON serialization."

Current Laravel has the same defect: it merges decoded data into live
attributes and then marshals the merged value, so an already-live
`ViewErrorBag` is iterated as if it were a decoded array and replaced with an
empty bag. The pure boundary preserves a live bag when storage has no `errors`
key, while a persisted error bag correctly wins when storage supplies one.
This also removes repeated coroutine-context access from JSON error-bag
startup.

### Pure flash aging

Replace the mutating-only flash aging implementation with one pure array
transformation used by both public `ageFlashData()` and `save()`:

```php
protected function ageFlashDataIn(array $attributes): array
{
    Arr::forget($attributes, Arr::get($attributes, '_flash.old', []));
    Arr::set($attributes, '_flash.old', Arr::get($attributes, '_flash.new', []));
    Arr::set($attributes, '_flash.new', []);

    return $attributes;
}

public function ageFlashData(): void
{
    $this->setAttributes($this->ageFlashDataIn($this->getAttributes()));
}
```

Do not introduce a transaction object or rollback bookkeeping.

### Two-snapshot save boundary

`save()` owns:

1. an aged live snapshot that retains `ViewErrorBag`;
2. a storage-only snapshot where JSON error bags become arrays;
3. serialization and a truthfully checked handler write; and
4. publication of the live snapshot only after the write succeeds.

```php
public function save(): void
{
    // Publish the aged attributes only after the handler commits, so a failed
    // write leaves the live flash data and error bag intact for the retry.
    $attributes = $this->ageFlashDataIn($this->getAttributes());
    $attributesForStorage = $this->prepareErrorBagForSerialization($attributes);

    $serialized = $this->serialization === 'json'
        ? json_encode($attributesForStorage, JSON_THROW_ON_ERROR)
        : serialize($attributesForStorage);

    $written = $this->handler->write(
        $this->getId(),
        $this->prepareForStorage($serialized)
    );

    if ($written === false) {
        throw new RuntimeException('Unable to write the session data.');
    }

    $this->setAttributes($attributes);
    CoroutineContext::set($this->startedContextKey, false);
}
```

Make `prepareErrorBagForSerialization(array $attributes): array` pure. If the
strategy is not JSON or the top-level `errors` key is absent, return the input.
Otherwise, transform the reserved `ViewErrorBag` in the supplied copy and return
it. Do not add an `instanceof` guard for user misuse of the reserved key.

`JSON_THROW_ON_ERROR` makes cyclic and unsupported values fail at the real
serialization boundary. A failed encoding, false handler write, or throwing
handler write leaves attributes, flash markers, error bag, and started state
untouched. False is an explicit `SessionHandlerInterface` failure reachable
through `CacheBasedSessionHandler`; converting it to a `RuntimeException` with
the new Session-owned message makes the response truthful and uses the
after-response retry path already used by throwing stores. Cache keeps its
existing `KeyWriteFailed` event; Session adds no event, logger, retry loop, or
exception class.

`save()` deliberately calls the pure helper rather than the public mutating
`ageFlashData()`. A subclass that customized save-time aging by overriding that
public method must instead override the protected pure helper. Do not add a
compatibility hook that would reintroduce pre-commit mutation.

### App default and direct-construction default

Add Laravel's current Session Serialization config section verbatim in style,
with Hypervel naming:

```php
'serialization' => 'json',
```

Keep the constructor defaults on `Store` and `EncryptedStore` as `'php'`.
`SessionManager` always supplies the application config, while direct
construction is a Laravel public/testing surface whose default remains PHP.
Do not remove the constructor default, add padding arguments to callers, or add
`SESSION_SERIALIZATION`.

The constructor guard deliberately rejects third strategy strings. Such strings
were never a working extension point: the implementation has four exact JSON
checks and otherwise silently chooses PHP. The supported subclass hooks remain
`prepareForStorage()` and `prepareForUnserialize()`. Do not add an enum,
validator service, or serializer registry.

### Regressions

- Throwing PHP write leaves live flash data unchanged; retry writes it once
  and ages it once.
- Throwing JSON write leaves a live `ViewErrorBag`; retry succeeds.
- False cache-backed write throws the persistence exception, leaves live state
  unchanged, and a successful retry persists the flash exactly once.
- Two consecutive successful JSON saves with a live error bag succeed and keep
  the live bag.
- Cyclic/unencodable JSON throws `JsonException` before handler write.
- Invalid serialization fails at construction with the rejected and supported
  values in the message and leaves no per-Store context slots.
- Framework config defaults to JSON; an explicit application config override to
  PHP constructs a PHP Store.
- Direct `new Store(...)` and `new EncryptedStore(...)` retain PHP defaults.
- Starting a JSON Store with a live error bag and no persisted errors retains
  the exact live bag and its messages.
- Persisted JSON error arrays override and reconstruct correctly when a live
  error bag is already present.
- The existing Foundation JSON-session assertion fixture uses an explicit JSON
  Store and reloads the persisted payload into a second JSON Store over the
  same handler and the first Store's ID before asserting the reconstructed
  error bag. Do not retain its current misleading PHP-default construction and
  save-only comment.

## 3. Correct middleware commit and lock boundaries

### Failed startup

In `StartSession::handleStatefulRequest()`, register after-response persistence
only after the request owns a successfully started session:

```php
} catch (Throwable $throwable) {
    if ($request->hasSession()) {
        $this->exceptionHandler->afterResponse(function () use ($request): void {
            try {
                $this->saveSession($request);
            } catch (Throwable) {
                // The request failure stays primary; a retry failure must not
                // replace it or escape the exception renderer.
            }
        });
    }

    throw $throwable;
}
```

`setHypervelSession()` runs only after `start()` succeeds, making
`hasSession()` the existing commit flag. Do not add another boolean. Route,
render, and immediate-save failures still register persistence because startup
already committed.

The first save failure still propagates into `Kernel::handle()`, which reports
and renders it. Only the after-response retry is contained: it runs from the
exception renderer after the response is built, so allowing a second failure
to escape would discard that response and replace the already-reported primary
exception. Do not report the retry from this catch; reporting may itself throw,
the primary failure was already reported, and cache-backed writes already emit
their existing failure event. This is the same cleanup-failure precedence
pattern used by Cache locks, not a general exception-swallowing policy.

### Lock ownership

Replace manual acquisition plus `finally` release with Cache's callback API:

```php
return $lock->block(
    $request->route()->waitsFor()
        ?? $this->manager->defaultRouteBlockWaitSeconds(),
    fn (): Response => $this->handleStatefulRequest($request, $session, $next),
);
```

Retain the truthful `Repository&LockProvider` local narrowing, but remove its
stale `@phpstan-ignore`. Cache already preserves a callback failure if release
also fails and does not release when acquisition times out.

### Canonical config reads

Declare these keys in `src/foundation/config/session.php`:

```php
'block' => (bool) env('SESSION_BLOCK', false),
'block_store' => env('SESSION_BLOCK_STORE'),
'block_lock_seconds' => (int) env('SESSION_BLOCK_LOCK_SECONDS', 10),
'block_wait_seconds' => (int) env('SESSION_BLOCK_WAIT_SECONDS', 10),
```

Remove corresponding call-site defaults in `SessionManager` and use typed
getters for non-null values. Keep nullable `driver`, `connection`, `store`,
`block_store`, and `prefix` on nullable reads.

In `StartSession`:

- read declared cookie fields directly;
- type cookie `domain` as `?string`;
- use `$config ??= $this->manager->getSessionConfig()` rather than truthiness;
- read declared `lifetime`, `expire_on_close`, and `driver` directly; and
- delete dead `??` fallbacks that mask malformed merged configuration.

Callback-returned malformed cookie config may fail naturally; do not add a
second validator.

### Regressions

- A throwing session `read()` neither registers nor performs an after-response
  write.
- Route failure after successful startup remains persistable.
- A persistently failing save is reported and rendered from the first failure;
  the after-response retry cannot escape or replace it.
- Callback failure stays primary when lock release also throws.
- Lock acquisition timeout never calls release.
- Cookie config resolves the canonical merged shape and retains null domain.
- Config tests cover declared block defaults and environment conversion.

## 4. Correct handler boundaries

### Cookie handler

Replace the JSON outer envelope with private PHP serialization:

```php
public function write(string $sessionId, string $data): bool
{
    $this->cookie->queue($sessionId, serialize([
        'data' => $data,
        'expires' => $this->availableAt($this->minutes * 60),
    ]), $this->expireOnClose ? 0 : $this->minutes);

    return true;
}
```

Read untrusted cookie data through the immediately checked native boundary:

```php
$decoded = @unserialize($value, ['allowed_classes' => false]);

if (! is_array($decoded)
    || ! isset($decoded['data'], $decoded['expires'])
    || ! is_string($decoded['data'])
    || ! is_int($decoded['expires'])
    || $this->currentTime() > $decoded['expires']) {
    return '';
}

return $decoded['data'];
```

The suppression is justified only because the native warning is converted
immediately into the documented empty-read result. Symfony raw-URL-encodes
non-raw cookie values, so serialized binary bytes remain header-safe. Do not
base64 the envelope, invent a frame, or retain a compatibility decoder.

Tests cover binary PHP-serialized payload round-trip, expiry, garbage input,
top-level objects, missing `data`, non-string `data`, and non-integer
`expires`.

### File handler

Type the constructor properties:

```php
protected string $path,
protected int $minutes,
```

Use Finder's always-string pathname and count only successful deletion:

```php
foreach ($files as $file) {
    if ($this->files->delete($file->getPathname())) {
        ++$deletedSessions;
    }
}
```

Propagate false and partial writes through the handler's existing bool
contract:

```php
public function write(string $sessionId, string $data): bool
{
    return $this->files->put($this->path . '/' . $sessionId, $data, true) === strlen($data);
}
```

`Filesystem::put()` returns the byte count or false. Exact comparison handles
empty content, false failures, and short writes without another I/O operation.

Move the GC regression to `ParallelTesting::tempDir()` and guarantee cleanup in
`finally`. Change the existing false/true delete assertion from two deletions
to one. Keep `destroy()` idempotently true.

### Database handler

Refresh the local existence value after the cold read:

```php
$exists = $this->getExists();

if (! $exists) {
    $this->read($sessionId);
    $exists = $this->getExists();
}
```

Reset the handler-specific existence state at both lifecycle boundaries:

```php
public function __construct(...)
{
    $this->setExists(false);
}

public function __clone(): void
{
    $this->setExists(false);
}
```

Keep `getExists()` and `setExists()` on the existing dynamic object-ID-derived
key. PHP can reuse the handler object ID after `Session::forgetDrivers()` frees
the Store and handler together, and cloning bypasses the constructor. Without
both resets, stale `exists=true` can skip the cold read, update zero rows, and
falsely report success. Do not precompute the key: the saved nanoseconds are
irrelevant beside SQL I/O, while a copied key would break the public,
Laravel-compatible clone path.

The normal started-session write retains one context lookup. Only direct write
against unknown local state adds a second in-memory lookup, replacing an
avoidable duplicate insert exception and update round trip.

Add an integration regression that preinserts a row, uses a fresh tracking
subclass, directly calls `write()`, proves `performUpdate()` rather than
`performInsert()` ran, and verifies stored data. Use the existing protected
methods; add no production seam.

Add a second deterministic integration regression whose anonymous tracking
subclass seeds its own stale existence slot immediately before
`parent::__construct()`. Prove construction resets it and a direct write
inserts and persists rather than updating zero rows.

Add a clone regression whose anonymous subclass seeds the clone's new-ID slot
before `parent::__clone()`. Prove clone initialization resets it without
changing the source handler's state, then inserts and persists through the
clone. Retain Laravel's existing clone test because it catches key sharing but
not object-ID reuse.

Do not classify every `performInsert()` `QueryException`, add an upsert, or add
another existence query. The canonical schema's supported race is already
handled, and no broader realistic failure was established.

Remove unused Hypervel-only `setConnection()`. Add the standard warning to
retained upstream `setContainer()`:

> Boot or tests only. Mutating the container on a shared handler during request
> handling can expose the wrong request or authentication state to concurrent
> coroutines.

Keep public `connection()` because pooled access requires a fresh connection.

### Complete SessionHandlerInterface types

Bring only the seven missing parameter lists into line with the typed handlers:

```php
// ArraySessionHandler
public function open(string $savePath, string $sessionName): bool;
public function read(string $sessionId): false|string;
public function write(string $sessionId, string $data): bool;
public function destroy(string $sessionId): bool;
public function gc(int $lifetime): int;

// NullSessionHandler
public function write(string $sessionId, string $data): bool;
public function destroy(string $sessionId): bool;
```

Do not widen `NullSessionHandler::read()` from its correct `string` return.
Update the `FakeNullSessionHandler` test subclass signature. This is
package-local type completion, not a broad Laravel typing sweep.

## 5. Give Redis sessions an isolated prefix

Add a Session Redis Prefix config section near the existing Session Cache Store
configuration:

```php
'prefix' => env('SESSION_PREFIX', app_id() . '_session:'),
```

`SESSION_PREFIX` is the RedisStore-level prefix for session keys. The lower
phpredis connection-wide `REDIS_PREFIX` remains independent and still applies.
This dedicated default prevents session records from being mislabeled with
`cache.prefix` and remains safe when the connection-wide prefix is empty.

Update `SessionManager::createRedisDriver()`:

```php
$handler = $this->createCacheHandler('redis');
$store = $handler->getCache()->getStore();

if (! $store instanceof RedisStore) {
    throw new InvalidArgumentException(
        'The [session.driver] value [redis] requires [session.store] to reference a Redis cache store.'
    );
}

$store->setConnection(
    $this->config->get('session.connection') ?? 'session'
);

$prefix = $this->config->get('session.prefix');

if ($prefix !== null && $prefix !== '') {
    $store->setPrefix($prefix);
}
```

The exact null/empty check preserves valid `"0"`. An application may explicitly
set null/empty to retain the selected cache store's prefix, matching current
Laravel fallback semantics. The default Hypervel config chooses the dedicated
session prefix. The construction-time store guard makes the local type truthful
and replaces an opaque undefined-method failure.

Tests prove:

- the framework default is `app_id() . '_session:'`;
- custom and `"0"` prefixes apply;
- explicit null/empty retains the clone's cache prefix;
- the original repository/store retains its own prefix and connection;
- the default `session` Redis connection remains;
- an incompatible `session.store` fails with the descriptive message.

No live Redis service is needed because this behavior ends at store
construction and performs no command.

## 6. Complete parity, cleanup, metadata, and documentation

### Store collection sync

Port the current upstream implementation without changing signatures:

```php
return collect($keys)->doesntContain($missingCallback);
return collect($keys)->doesntContain($nullCallback);
return collect($keys)->contains($presentCallback);
```

`exists()` and `has()` are merge-alignment only.
`hasAny()` gains early exit. Add/merge current upstream tests and preserve
Hypervel enum-key coverage.

### Provider cleanup

Delete:

- the redundant `StartSession::class` singleton;
- its now-unused import;
- the empty `boot()` method; and
- dead `SessionManager::createCacheBased()`, whose only Laravel callers are the
  three unsupported cache drivers Hypervel intentionally omits.

Unbound concrete auto-singletoning already gives `StartSession` identical
worker lifetime. Keep canonical `session` and `session.store` bindings and
command registration. Custom session handlers continue to use
`Session::extend()`; do not retain a dead protected wrapper as a second
extension mechanism. Record the omitted drivers and wrapper in the package
README's Laravel-differences section and at the wrapper's upstream insertion
point in `SessionManager`.

### Stale PHPStan suppressions

Delete only:

- all four ignores in `StartSession`; and
- ignores on `AuthenticateSession` lines currently attached to Session
  `has()`/`get()`, the array element, auth default-driver argument, and local
  `redirectTo()`; and
- the two unmatched adapter-wrapper ignores in `FilesystemManager`.

Keep the three live `Route` guards themselves because `Request::route()` is
natively `mixed`. Keep the six genuine AuthManager magic-proxy ignores. Do not
refactor guard resolution merely to eliminate valid suppressions.

Track the remaining framework-wide unmatched inline ignores and global patterns
in `docs/todo.md`; do not turn this package-local cleanup into a broad
suppression sweep.

### Filesystem concrete type

Change only the concrete boundary:

```php
public function delete(array|string $paths): bool
```

Remove its now-redundant type-only `@param`. The contract and three sibling
implementations already declare the union. Preserve the existing array and
extra-argument behavior. This adds no runtime work and does not replace the
Session GC fix.

### Contract import cleanup

While changing the Session contract's `getId()` return type, import
`Hypervel\Http\Request` and use the short name on `setRequestOnHandler()`.
This is the file's only inline class FQCN and violates the repository import
rule.

### Split metadata

Add these proven direct dependencies to `src/session/composer.json`:

```text
ext-ctype
ext-mbstring
hypervel/container
symfony/console
symfony/finder
symfony/http-foundation
```

Use `^8.1` for all three Symfony packages, matching the root and every sibling
split package.

Add `tests/Session/PackageMetadataTest.php` covering the complete direct
runtime dependency list, not only the new rows:

```text
ext-ctype
ext-mbstring
ext-session
hypervel/auth
hypervel/cache
hypervel/collections
hypervel/console
hypervel/container
hypervel/context
hypervel/contracts
hypervel/cookie
hypervel/database
hypervel/filesystem
hypervel/http
hypervel/macroable
hypervel/routing
hypervel/support
symfony/console
symfony/finder
symfony/http-foundation
```

Keep both `hypervel/session -> hypervel/auth` and
`hypervel/auth -> hypervel/session`. Both are honest: Session imports
auth-owned `PasswordConfirmation` and `AuthenticationException`, while Auth's
SessionGuard consumes Session. Do not relocate those public auth concepts,
create a bridge package, or add shims solely to make the Composer graph acyclic.

### Public documentation

Update `src/boost/docs/session.md` in its existing Laravel-style prose:

- explain JSON as the application default, its scalar/array suitability, and
  explicit PHP object-session opt-in/security implication;
- clarify that the array driver is held only in one worker's memory and is not
  suitable for production sessions;
- document `SESSION_PREFIX` under Redis prerequisites, including its dedicated
  default and distinction from connection selection;
- document `SESSION_BLOCK=true` and the optional block store/lock/wait settings
  while retaining route-level `block()` guidance;
- describe custom-driver `$lifetime` as an age in seconds, not a Unix
  timestamp; and
- state that custom-handler `write()` returns true on success and false on
  failure, with a false result rejecting the request rather than accepting
  lost persistence.

Keep explanations task-oriented. Do not add architecture prose, internal
context-key details, handler commit internals, or an exhaustive config catalog.

The previously suspected duplicate Markdown fence does not exist and receives
no change.

### Audit records

After implementation, validation, self-review, and code-review sign-off:

- update the Session route and checklist state in
  `docs/plans/2026-07-12-0900-framework-coroutine-state-lifecycle-audit.md`;
- update `session-01` through `session-22` and `filesystem-12` in the audit
  ledger from the final implementation; and
- record under `session-01` that constructor initialization must cover every
  per-object context slot; record under `session-02` that `save()` now uses the
  protected pure aging
  seam instead of the public mutator, that false writes are persistence
  failures, and that the corrected Foundation JSON round trip independently
  proves why the live error bag must survive publication. Record under
  `session-15` the unsupported never-started save consequence described above.
  Record under `session-19` that the first failure remains reported and
  rendered while only the after-response retry failure is contained. Record
  under `session-20` that JSON error-bag marshalling is a pure decoded-storage
  transformation and a shared Laravel defect. Record under `session-21` that
  Database handler construction and cloning reset every object-specific state
  slot.
  Record under `session-22` that the file handler validates the complete byte
  count while `destroy()` remains idempotently true.

Do not mark either audit record complete before the full workflow is complete.

### Test return-type scope

Add `: void` to the 89 existing test methods across the eight affected Session
test files and to every new test method. Work one file at a time and do not use
bulk modification tools.

## File-oriented implementation checklist

Work one file at a time. This list is a routing checklist, not a second
description of the design above.

### Source and public metadata

- [ ] `src/session/src/Store.php`
- [ ] `src/contracts/src/Session/Session.php`
- [ ] `src/support/src/Facades/Session.php`
- [ ] `src/foundation/src/Testing/Concerns/InteractsWithSession.php`
- [ ] `src/foundation/src/Testing/Concerns/MakesHttpRequests.php`
- [ ] `src/session/src/Middleware/StartSession.php`
- [ ] `src/session/src/Middleware/AuthenticateSession.php`
- [ ] `src/session/src/CookieSessionHandler.php`
- [ ] `src/session/src/FileSessionHandler.php`
- [ ] `src/session/src/DatabaseSessionHandler.php`
- [ ] `src/session/src/ArraySessionHandler.php`
- [ ] `src/session/src/NullSessionHandler.php`
- [ ] `src/session/src/SessionManager.php`
- [ ] `src/session/src/SessionServiceProvider.php`
- [ ] `src/session/src/EncryptedStore.php` if constructor-adjacent typing/docs require alignment
- [ ] `src/filesystem/src/Filesystem.php`
- [ ] `src/filesystem/src/FilesystemManager.php`
- [ ] `src/foundation/config/session.php`
- [ ] `src/session/composer.json`
- [ ] `src/boost/docs/session.md`
- [ ] `docs/todo.md`
- [ ] `docs/plans/2026-07-12-0900-framework-coroutine-state-lifecycle-audit.md`
- [ ] `docs/plans/2026-07-12-0915-framework-coroutine-state-lifecycle-audit-ledger.md`

### Tests

- [ ] `tests/Session/SessionStoreTest.php`
- [ ] `tests/Session/SessionManagerTest.php`
- [ ] `tests/Session/SessionConfigTest.php`
- [ ] `tests/Session/Middleware/StartSessionTest.php`
- [ ] `tests/Session/Middleware/AuthenticateSessionTest.php` only if stale-ignore behavior needs no source-only validation
- [ ] `tests/Session/ArraySessionHandlerTest.php`
- [ ] new `tests/Session/CookieSessionHandlerTest.php`
- [ ] `tests/Session/CookieSessionHandlerCoroutineSafetyTest.php`
- [ ] `tests/Session/FileSessionHandlerTest.php`
- [ ] `tests/Integration/Session/CookieSessionHandlerTest.php`
- [ ] `tests/Integration/Session/DatabaseSessionHandlerTest.php`
- [ ] `tests/Integration/Session/SessionPersistenceTest.php`
- [ ] `tests/Foundation/Testing/RequestContextSynchronizerTest.php`
- [ ] `tests/Foundation/Testing/Concerns/MakesHttpRequestsTest.php`
- [ ] `tests/Filesystem/FilesystemTest.php`
- [ ] new `tests/Session/PackageMetadataTest.php`

Do not touch a listed file merely because it appears here. If the implemented
boundary requires no code or regression change in that file, leave it clean.

## Test and validation plan

### Immediate cadence

After changing each test file, run that file immediately with
`./vendor/bin/phpunit --no-progress`. Do not postpone failures to the package
gate.

Focused groups:

```bash
./vendor/bin/phpunit --no-progress tests/Session
./vendor/bin/phpunit --no-progress tests/Integration/Session
./vendor/bin/phpunit --no-progress tests/Foundation/Testing
./vendor/bin/phpunit --no-progress tests/Filesystem/FilesystemTest.php
```

The Database session integration runs through the existing database test
harness and should be exercised against configured local supported services
where available. Redis-prefix tests are construction tests and require no Redis
service.

### Regression matrix

| Area | Required proof |
|---|---|
| Store identity | Two Stores and reused object IDs cannot share state |
| Coroutine reuse | Manager-cached Store gets an ID and clean state in a new coroutine |
| Foundation bridge | Active state copies; no-session request clears prior dynamic state |
| JSON startup | Live error bag survives absent storage; persisted errors win when present |
| Save failure | Throwing and false writes leave flash/error/started state unchanged; retry succeeds |
| JSON success | Consecutive saves retain live error bag |
| Serialization validation | Invalid value fails before context writes; config/direct defaults differ intentionally |
| Startup and retry failure | No retry before `Request::hasSession()`; retry failure cannot escape the renderer |
| Blocking | Timeout does not release; request failure beats release failure |
| Cookie | Binary round-trip, strict envelope shape, expiry, malformed input |
| File GC | Pathname is always string; false deletion is not counted; temp path is isolated |
| File write | Complete byte count succeeds; false and short writes fail and preserve Store state |
| Database direct write | Existing row selects update; constructed and cloned reused identities reset and insert |
| Redis config | default/custom/zero/null/empty prefix, clone isolation, invalid store |
| Parity | Store `exists`/`has`/`hasAny` behavior and enum keys |
| Metadata | Complete direct split dependencies |
| Filesystem | Existing array and string deletion behavior remains |

### Full gate

After focused tests are green, run only the authoritative combined gate:

```bash
composer fix
```

Do not redundantly run PHP CS Fixer or PHPStan immediately before it. The gate
owns formatting, both PHPStan configurations, the full parallel suite, and both
Testbench suites.

## Fresh self-review requirements

After the full gate, review the complete diff without trusting this plan:

1. Trace every Store key read/write and verify no fixed state key remains.
2. Trace construction in root, request, copied child, and reused-object-ID
   contexts.
3. Trace JSON/PHP load and save through live/persisted error-bag precedence,
   encrypted/plain, handler failure, encoding failure, retry, and consecutive
   success.
4. Trace middleware startup, route/render/save exceptions, after-response
   callbacks, retry failures, precognition, and lock acquire/release failures.
5. Trace every cookie envelope shape through CookieJar and Symfony.
6. Trace direct and normal Database writes across fresh and reused-object-ID
   existence state.
7. Verify Redis connection and prefix mutation remains confined to the clone
   and adds no command or checkout.
8. Compare all ported methods/tests/config prose with the current upstream
   default branches and originating changes.
9. Search for removed constants, `setConnection()` callers, stale call-site
   defaults, unmatched PHPStan ignores, old GC wording, and omitted metadata.
10. Check hot-path allocations, context lookups, container resolutions, I/O,
    locks, and retained worker state.
11. Remove any dead helper, stale comment, workaround, duplicated decision, or
    abstraction that does not solve a verified requirement.

Unexpected bugs, same-family omissions, Swoole defects, or design
contradictions return to focused investigation and second-opinion consensus
before implementation continues.

## Expected API, performance, and complexity result

- Laravel-shaped Session APIs remain intact. `getId()` becomes truthfully
  non-null; the unused unsafe Hypervel-only Database handler mutator is removed.
- Current Laravel Redis-prefix and Store collection behavior are present.
- Hypervel's application config deliberately adds a dedicated Session prefix
  default and current Laravel's JSON default.
- Normal Store state operations retain one context lookup using a precomputed
  key. Three short strings are allocated once per manager-cached Store.
- `getId()` adds only an absent-slot branch; ordinary calls remain one lookup.
- JSON error-bag startup transforms the decoded storage array and avoids
  repeated context reads and writes.
- Save adds no lock, retry, container lookup, yield, or I/O. PHP copy-on-write
  copies only modified arrays; one strict success check follows the existing
  handler I/O.
- Blocked routes allocate one callback beside existing lock I/O; unblocked
  requests are unchanged.
- Redis setup adds one construction-time type check and prefix assignment, with
  no network command or pool checkout.
- The database direct-write cold path adds one memory lookup and removes an
  exception plus extra database work.
- Database existence state resets only at construction and cloning; normal
  lookup work remains unchanged and negligible beside SQL I/O.
- Cookie and file changes occur beside existing serialization/filesystem work;
  file write validation compares the already-returned byte count and adds no
  I/O.
- No registry, WeakMap, counter, state machine, serializer abstraction,
  compatibility decoder, retry mechanism, new worker cache, or unbounded state
  is introduced.

The final code should read as if Store identity, persistence commit, config
ownership, and handler contracts were designed this way from the beginning.
