# JWT Correctness, Security, and Lifecycle Maintenance

## Status

Complete; implementation, verification, fresh self-review, final audit records, and independent code review are signed off.

## Scope

Correct the verified JWT validation, revocation, storage, key-publication, configuration, documentation, and test defects recorded in `.tmp/audit-findings/jwt.md`, together with the adjacent malformed-date, missing-`iat` refresh, and logout-settlement defects found while tracing those paths. Preserve the package's array-payload design, coroutine-scoped guard state, Laravel-shaped auth guard, current signing/refresh behavior, and direct provider/storage extension points.

This is not a fresh package-wide audit. Work is limited to the accepted findings, same-family issues discovered while tracing or implementing them, the routed `support-02` and `macroable-03` revalidations, and the audit records required to close JWT.

## References

- Repository rules: `AGENTS.md`
- Core audit plan: `docs/plans/2026-07-12-0900-framework-coroutine-state-lifecycle-audit.md`
- Audit ledger: `docs/plans/2026-07-12-0915-framework-coroutine-state-lifecycle-audit-ledger.md`
- Prior findings: `.tmp/audit-findings/jwt.md` in the main Components worktree, audited at Components `db70c7ce7def14382d7d22d2f90b15e8db0ae9d7`
- Current package: `src/jwt/`, `tests/Jwt/`, `src/support/src/Facades/Jwt.php`, and `src/boost/docs/jwt.md`
- Recorded current upstream: `PHP-Open-Source-Saver/jwt-auth` at `ce08363a9986e5253efd3663ed4f75c976bec89a`
- Installed JWT engine: `lcobucci/jwt` 5.6, especially `vendor/lcobucci/jwt/src/Token/Parser.php`
- Existing shared owners: `Filesystem::replace()`, `Filesystem::ensureDirectoryExists()`, Cache's boolean write/flush contracts, and `ServiceProvider::mergeConfigFrom()`
- Routed ledger entries: `Normalize framework enum identifiers at string boundaries` and `Complete Macroable callable and test-state handling`

## Existing contracts to preserve

- JWT payloads remain arrays; no mutable upstream token, payload, or claim DTO layer is restored.
- `JwtManager::encode()` and `decode()` retain their string/array contracts, and `ManagerContract` and the `Jwt` facade retain their current Laravel-shaped manager surface.
- Guard token, user, payload, claims, and TTL state remains in `CoroutineContext`; no state moves onto the worker-lifetime guard.
- The parser remains stateless and receives each request directly.
- Nullable token and refresh TTLs, explicit refresh endpoints, subject locking, custom claims, configurable validation chains, custom extractors, and custom provider/storage implementations remain supported.
- Tagged blacklist storage continues to support both all-mode and any-mode tag stores.
- `NotBeforeClaim` continues to run during refresh so a future token cannot be refreshed into an immediately valid token.
- `IssuedAtClaim` and `NotBeforeClaim` retain their current epoch-zero behavior; only expiration has a verified falsey-zero defect.
- `JwtGuard::logout()` continues to invalidate expired but structurally valid tokens because invalidation deliberately skips temporal validation.
- `JwtGuard` keeps Macroable and the split package keeps its direct `hypervel/macroable` dependency.
- Inherited `Manager::driver(UnitEnum|string|null)` remains the sole enum-driver normalization boundary; JWT adds no duplicate enum handling.

The accepted public cleanup removes one unsafe Hypervel-only storage adapter and one duplicate Hypervel-only configuration key. No compatibility alias or stale path remains. Supported Laravel auth APIs are unchanged.

## Anti-overengineering constraints

This audit is not permission to add defensive machinery for every imaginable failure. Do not add an abstraction, state machine, retry loop, configurable timeout, registry, mutex, context slot, cache, or compatibility API merely because it sounds robust.

Complexity must pay for itself with at least one of:

- a demonstrated failure;
- a complete source trace proving a realistic vulnerable schedule;
- a clear general capability with real consumers and owner approval;
- deletion of greater or riskier complexity elsewhere.

Typical Laravel lifecycle semantics define the supported contract. A package that intentionally relies on model events, middleware, listeners, transactions, or another documented mechanism is not defective merely because userland can explicitly bypass that mechanism. Do not build a parallel enforcement path for `withoutEvents()`, raw database writes, disabled middleware, direct transport access, or comparable deliberate bypasses unless the public contract explicitly promises behavior through that bypass.

Underengineering is equally a failure. Fix every verified defect completely at its lowest owning boundary, never with a partial fix or a local patch over a broken shared contract, and always surface meaningful evidence-backed improvements rather than dropping them to avoid effort. Restraint applies to speculative machinery and cosmetic change, not to complete fixes or worthwhile opportunities.

Do not treat an upstream difference as a bug without tracing it. Do not treat upstream parity as proof of correctness. A real Hypervel defect remains a defect when Laravel, Hyperf, Symfony, or an SDK has the same hole.

The audit categories are discovery lenses, not boundaries around what may be corrected. Any genuine issue discovered while auditing, implementing, testing, or reviewing must be investigated, assigned to its lowest owning boundary, and taken through the applicable consensus, implementation, validation, review, and approval workflow—even when it is outside the current package, initial taxonomy, or changed diff. Do not dismiss a verified issue as unrelated or defer it merely to preserve package order. This rule applies only after the evidence threshold is met; it does not turn speculative concerns, deliberate bypasses, unsupported use, or contract violations into work.

## Architecture and evidence

`JwtServiceProvider` creates a worker-lifetime manager, parser, and blacklist. Those objects retain only boot configuration and finite immutable caches. Request and operation state belongs to `JwtGuard`'s coroutine context. No finding requires cloning, locking, scoped rebinding, additional cleanup, or another worker cache.

The security-sensitive paths are:

```text
bearer token -> Lcobucci parser -> signature check -> array claims -> validations
logout/refresh/invalidate -> Blacklist -> StorageContract -> tagged cache
jwt:generate-certs -> OpenSSL -> Filesystem publication -> .env publication
```

The evidence fixes the following boundaries:

- Lcobucci converts `exp`, `nbf`, and `iat` before signature validation. Its private `convertDate(int|float|string)` throws `TypeError` for untrusted null/bool/array/object values before its own invalid-token guard. Hypervel currently catches only `Exception`, so an invalidly signed anonymous bearer token can escape as a 500.
- Lcobucci converts JSON string `"0"` and fractional `0.5` date claims to timestamp integer `0`. `ExpiredClaim` then treats zero as absent.
- `validateRefreshWindow()` returns immediately for an infinite refresh lifetime before reading `iat`. A signed token without `iat` can therefore refresh indefinitely when `refresh_iat` rebuilds the claim, or reach an uncaught encode `TypeError` when the old claim is retained.
- Current upstream has one refresh lifetime and feeds it to blacklist retention. Hypervel's separate `blacklist_refresh_ttl` can end revocation while refresh remains allowed; infinite refresh is especially unsafe.
- Carbon's signed minute difference is target minus receiver: `now->diffInMinutes(future)` is positive, while `future->diffInMinutes(now)` is negative. The current `abs()` therefore turns an elapsed revocation boundary into a new positive TTL.
- `Blacklist::add()` currently checks the boundary against one clock read, performs a cache lookup, then calculates the TTL against a later clock read. The shipped Redis-backed lookup can yield across the boundary, producing a nonpositive TTL after the method has selected the write path. Cache may then either report failed invalidation or report success without storing the revocation.
- Expiration validation accepts a token through `exp + leeway`, so blacklist retention and its terminal-I/O skip must use that same acceptance boundary rather than bare `exp`.
- Cache repository and tagged-cache writes expose booleans, but JWT's storage contract discards them. False persistence currently looks like successful revocation.
- PSR-16 cannot clear only JWT keys. The unused shipped `PsrCache::flush()` calls the default store's application-wide `clear()` and raw keys can collide.
- Lcobucci rejects RSA keys below 2048 bits, while the command currently publishes 512/1024-bit pairs as successful.
- `Filesystem::replace()` already writes a sibling temporary file, applies mode before rename, atomically publishes it, and cleans up failures. JWT should consume that owner rather than duplicate it.
- `mergeConfigFrom()` shallow-merges top-level package defaults. Top-level JWT keys therefore need no repeated literal defaults, while the replaceable nested `providers` array needs explicit child fallbacks.

Storage false-success, absolute blacklist lifetime, and malformed registered-date conversion are inherited upstream defects. The duplicate blacklist lifetime and array-payload falsey key guard are Hypervel-specific. Record that provenance, but do not open an external issue or pull request without owner authorization.

## Final finding decisions

| ID | Category | Severity | Confidence | Final treatment |
|---|---|---:|---:|---|
| `jwt-01` | Defect | Major | High | Treat absent/null expiration as optional but validate timestamp zero; cover direct zero and an external-style JSON string-zero round trip. |
| `jwt-02` | Defect | Major | High | Delete `blacklist_refresh_ttl`; use nullable `refresh_ttl` as the one refresh and revocation lifetime. Genuinely refreshable tokens with infinite refresh require permanent, grace-aware revocation. |
| `jwt-03` | Defect | Major | High | Make storage writes/flush truthful booleans, propagate failure, throw from manager invalidation, and make guard logout transactional. |
| `jwt-04` | Defect | Major | High | Method-inject Filesystem and atomically publish each private and public key at modes `0600`/`0644`; create the directory through the existing `0755` Filesystem boundary. |
| `jwt-05` | Defect | Major | High | Include expiration leeway, use one clock snapshot for the terminal decision and positive remaining lifetime, and skip cache I/O only when the unified acceptance boundary has elapsed. |
| `jwt-06` | Defect (`support-02`) | Major | High | Accept non-empty strings and integers, including zero, as blacklist identifiers; normalize to string and reject every other shape with `TokenInvalidException`. |
| `jwt-07` | Defect | Major | High | Delete the unused destructive PSR adapter and its split dependency; retain tagged storage plus the custom `StorageContract` extension point. |
| `jwt-08` | Defect | Major | High | Reject RSA sizes below 2048 before generation; keep EC unchanged and avoid repeated slow RSA generation in tests. |
| `jwt-09` | Defect | Minor | High | Preserve interactive passphrase string `"0"`; only null/empty means no passphrase. |
| `jwt-10` | Defect | Minor | High | Add nested provider fallbacks and remove redundant top-level defaults without deep-merge machinery. |
| `jwt-11` | Defect | Minor | High | Bring the surviving JWT tests to repository setup/type conventions and remove dead fixture state. |
| `jwt-12` | Defect | Minor | High | Correct signing-key/config/storage guidance, document existing custom drivers, and make the README a thin public-difference index. |
| `jwt-13` | Defect | Major | High | Convert every parser failure from untrusted registered date claims into `TokenInvalidException` before guard handling. |
| `jwt-14` | Defect | Major | High | Use the finite expiration boundary when `iat` is absent but `exp` exists; retain forever only when no safe terminal boundary exists. |
| `jwt-15` | Defect | Major | High | Reject missing/null `iat` before the infinite-refresh return so a signed token cannot refresh indefinitely or reach malformed replacement encoding. |

## Implementation design

### 1. Untrusted parse and temporal validation boundaries

In `Providers\Lcobucci::decode()`, widen only the catch around the third-party parser call to `Throwable`:

```php
try {
    /** @var \Lcobucci\JWT\Token\Plain */
    $token = $this->config->parser()->parse($token);
} catch (Throwable $exception) {
    throw new TokenInvalidException(
        'Could not decode token: ' . $exception->getMessage(),
        $exception->getCode(),
        $exception,
    );
}
```

Do not wrap signature validation, claim mapping, or `encode()`. Decode accepts attacker-controlled bytes and owns conversion to a JWT exception; encode receives application-owned claims and malformed reserved claims should fail fast.

In `ExpiredClaim`, replace truthiness with a null boundary:

```php
$exp = $payload['exp'] ?? null;

if ($exp === null) {
    return;
}
```

Retain the existing leeway and strict time comparison. Do not change the sibling `IssuedAtClaim` or `NotBeforeClaim` truthiness checks: timestamp zero is valid and non-future there, so the change would be style-only.

Add a concise class explanation to `NotBeforeClaim`: it deliberately does not implement `TemporalValidation`, because skipping it during refresh would replace a future `nbf` with the current time. Existing future/past refresh tests already prove the behavior; do not duplicate them.

### 2. One revocation lifetime and truthful storage

Remove `blacklist_refresh_ttl` and `JWT_BLACKLIST_REFRESH_TTL` everywhere. Change `Blacklist::$refreshTTL`, its constructor argument, `setRefreshTTL()`, and `getRefreshTTL()` to `?int`. Add a constructor-held integer leeway beside the existing grace period. `JwtServiceProvider` must pass nullable `$config->get('jwt.refresh_ttl')` and the configured integer `jwt.leeway`; an integer getter for refresh TTL would silently break infinite refresh.

`Blacklist::add()` follows these rules in order:

1. Missing/null `exp` has no finite normal-authentication boundary, so retain the revocation forever while honoring the grace period.
2. Start with `exp + leeway`. If `iat` is missing/null, select that finite boundary even when refresh TTL is null: `validateRefreshWindow()` rejects the payload before the infinite-refresh return, so it cannot be refreshed.
3. With a present `iat`, null refresh TTL permits refresh forever, so retain the revocation forever while honoring the grace period.
4. Otherwise select `max(exp + leeway, iat + refreshTTL)`.
5. Add one minute to whichever finite boundary was selected so revocation survives the strict equality instant at which normal decode still accepts the token.
6. Snapshot the current time once, compute the signed remaining minutes from that snapshot to the future boundary, and never use `abs()` from the boundary to now.
7. If the boundary is no longer future at that snapshot, return true before any cache read or write. When the write path is selected, reuse the snapshot so time passing during the cache lookup cannot produce a nonpositive TTL.
8. If the key already exists, return true; otherwise return the storage adapter's boolean.

Both automatic permanent branches call a protected `addForeverWithGracePeriod()` helper. It resolves the key, returns early when storage already contains the entry so repeated revocations cannot restart the grace period, then stores `['valid_until' => $this->getGraceTimestamp()]` forever. Add one short WHY comment to the guard because rewriting would restart the grace period. `has()` already applies that timestamp before treating the entry as active. The public `addForever()` remains the explicit immediate permanent-revocation API and keeps the `'forever'` sentinel.

The early skip and lifetime unification must land together. Use a positive `iat !== null` branch and explain that only a present `iat` can extend the boundary because refresh rejects a missing claim before the infinite-refresh return. Add one short WHY comment at the skip stating that the unified boundary covers both expiration acceptance, including leeway, and the refresh window. These comments record the cross-file invariant that makes finite retention and skipped I/O safe.

Make the storage contract truthful:

```php
public function add(string $key, mixed $value, int $minutes): bool;
public function forever(string $key, mixed $value): bool;
public function flush(): bool;
```

Return the tagged-cache results directly. `Blacklist::addForever()` and `clear()` return their storage results. Update the sole custom test implementation. Do not retry failed cache writes.

Replace `JwtManager::invalidate()`'s dynamic call with an explicit finite/forever branch, inspect the returned boolean, throw a descriptive `JwtException` on false, and return true only after persistence succeeds. Its public signature stays `bool`; the contract becomes truthful true-or-exception. Refresh inherits that settlement rule after encoding the replacement token. This order is deliberate: invalidating first could revoke the caller's old token and then fail to produce a replacement, while encoding first allows a failed invalidation to discard an undelivered replacement without locking out the caller.

Make `JwtGuard::logout()` transactional:

```text
capture user and token
invalidate first when blacklisting applies
clear user, token, and payload context only after success
dispatch Logout last
```

A revocation failure retains guard state and emits no Logout event. A missing token still clears local state normally. Structurally invalid/badly signed tokens fail logout because they cannot be revoked; expired but structurally valid tokens still revoke because manager invalidation decodes with temporal validation disabled.

In `validateRefreshWindow()`, reject absent/null `iat` with `TokenInvalidException` before reading or returning for null `refresh_ttl`. Add a short WHY comment that blacklist retention for missing-`iat` payloads depends on this rejection preceding the infinite-refresh return. This closes both current failure modes: `refresh_iat = true` can no longer rebuild a missing claim and refresh indefinitely, while `refresh_iat = false` cannot carry null into replacement encoding. Do not add generic temporal coercion; provider-decoded registered dates are integers after the corrected parse boundary.

### 3. Blacklist identifier boundary and storage surface

Make `Blacklist::getKey()` return `string`. Accept only:

- a non-empty string, including `"0"`;
- an integer, including `0`, normalized to its decimal string.

Reject missing, null, empty string, bool, float, array, and object values with the existing `TokenInvalidException` family. This is the local string-only storage boundary required by `support-02`; do not stringify enums, Stringable objects, or arbitrary payload values.

Use one accurate message for both absent and invalid identifier shapes: the configured claim is “missing or invalid in payload for blacklist”.

Delete:

- `src/jwt/src/Storage/PsrCache.php`;
- `tests/Jwt/Storage/PsrCacheTest.php`;
- JWT's direct `psr/simple-cache` split dependency.

Keep the root dependency because other packages own it. Do not add a key index, prefix-only third mode, unsupported `flush()`, registry, or synchronization layer. The shipped implementation requires a taggable store. Applications using another store may configure their own `StorageContract` implementation.

Reword the service-provider error to name that exact extension point rather than imply another shipped adapter exists.

### 4. Certificate generation and publication

Change `JwtGenerateCertsCommand::handle()` to method-inject `Hypervel\Filesystem\Filesystem`.

For RSA, reject `--bits < 2048` before OpenSSL generation. The floor applies equally to RS256, RS384, and RS512 and is not configurable because it comes from the installed signer. EC curve/SHA validation remains unchanged.

Assign and validate the generated public-key contents before publication so the typed Filesystem call receives a proven string:

```php
$publicKey = $details === false ? null : ($details['key'] ?? null);

if (! is_string($publicKey)) {
    throw new RuntimeException('Unable to export JWT public key.');
}
```

Publish through existing owners:

```php
$files->ensureDirectoryExists($directory);
$files->replace($privateKeyPath, $privateKey, 0600);
$files->replace($publicKeyPath, $publicKey, 0644);
```

The directory's default changes deliberately from permissive `0777` to `0755`. Add `hypervel/filesystem` as a direct, sorted JWT split dependency. The root already contains the package.

JWT tests own directory creation, generated contents, final modes, algorithm/env output, and command validation. They do not repeat Filesystem's checked-write, temporary-file cleanup, or failure-injection suite. Do not add a cross-file transaction, lock, backup, rollback layer, or JWT-specific writer.

In `resolvePassphrase()`, preserve every non-empty string returned by the secret prompt, including `"0"`; map only null/empty to null. Add no passphrase-strength policy.

### 5. Configuration ownership

Retain explicit fallbacks only for children of the replaceable `providers` array:

```php
$config->string('jwt.providers.jwt', Lcobucci::class);
$config->string('jwt.providers.storage', TaggedCache::class);
```

Remove duplicated literal defaults from top-level reads in:

- `JwtManager` (`blacklist_enabled`, `driver`, `validations`, `ttl`, `refresh_iat`, `persistent_claims`, and nullable `refresh_ttl`);
- `JwtServiceProvider` (`token`, `parser`, `blacklist_enabled`, `blacklist_grace_period`, and the guard's nullable global `ttl`);
- `ClaimFactory` (`lock_subject`).

Use typed getters for non-null settings and plain `get()` for documented nullable values. Package config is top-level shallow-merged before these services resolve, so no fallback is lost; explicit null remains meaningful. Do not introduce deep merge, a normalized config object, or a default registry.

Add a provider regression that replaces the whole `jwt.providers` array with only one child and proves the omitted sibling uses its intended default. The previously missing storage child currently throws during eager blacklist resolution on the first `jwt` resolution when blacklisting is enabled; test that real boundary.

Update the `refresh_ttl` config comment to state its second public responsibility: it also bounds blacklist retention for refreshable tokens, and null retains those entries forever.

### 6. Documentation and package metadata

Update `src/boost/docs/jwt.md` in simple Laravel-docs prose:

- add a concise `Custom Drivers` subsection near signing algorithms that names `ProviderContract`, registers a provider during service-provider boot with `Jwt::extend('custom', fn ($app) => $app->make(CustomJwtProvider::class))`, and selects it through `jwt.driver`;
- remove `blacklist_refresh_ttl` and explain that blacklist retention follows `refresh_ttl`, with null refresh retaining revocation forever for refreshable tokens;
- state that the shipped tagged storage requires a taggable cache store and non-taggable stores require a custom `StorageContract` implementation;
- state the 2048-bit minimum for generated RSA keys;
- narrow the refresh-endpoint example to token-validity exceptions so configuration and infrastructure failures are not reported as authentication failures;
- document invalidation-first logout settlement: failed persistence retains guard state, emits no `Logout` event, and propagates `JwtException`;
- document that force-forever invalidation bypasses the grace period and takes effect immediately;
- retain the current warning about node-local cache tiers and the current explicit-refresh guidance.

Correct the public/private key config comments to “key contents or a `file://` URI”. Bare paths and resources are not supported.

Make `src/jwt/README.md` follow the thin package format:

1. package header;
2. `Documentation: https://hypervel.org/docs/jwt`;
3. unquoted `Differences From php-open-source-saver/jwt-auth` containing only user-visible API/feature differences;
4. upstream link last.

Remove the DeepWiki badge, duplicated package description/docs pointer, and the parser-singleton implementation bullet. Retain the actual public differences: array payloads, facade surface, parser sources/integrations, explicit refresh endpoint, and omitted upstream options.

Update `src/jwt/composer.json` in sorted order: add `hypervel/filesystem`, retain the already-correct direct `hypervel/macroable`, and remove `psr/simple-cache` only from this split.

### 7. Test cleanup and audit records

Apply the bounded cleanup:

- call `parent::setUp()` in `BlacklistTest`;
- type the Blacklist invalid-value provider argument as `mixed`;
- add `: void` to the three `ProviderTest`, two `RequiredClaimsTest`, and one `JwtGuardStaticStateTest` methods;
- remove the unused untyped `ProviderTest::$provider` property;
- delete PsrCache's source and test rather than leaving stale fixtures.
- make the invalidation success fixtures state their exact contracts: one blacklist write, no validation for expired-token invalidation, and no separate blacklist read before the idempotent write;
- correct all nine malformed `shouldNotReceive()` calls found during self-review: six false multi-method declarations with strict-mock backstops, two partial-mock declarations whose current call order still reaches the sole registered prohibition first, and one correct Database prohibition carrying dead arguments. Mockery's variadic method forwards through a single-parameter closure despite promising one or many method names; use `shouldReceive(...)->never()` for multiple methods and `shouldNotReceive('commit')` for the single Database method. Do not patch Mockery, add recurrence machinery, or open an external report without authorization.

At final audit bookkeeping:

- record `jwt-01` through `jwt-15`, important rejected concerns, inherited-upstream evidence, validation, performance, and review in the companion ledger;
- mark `support-02` revalidated through inherited `Manager::driver()` plus local `jwt-06`;
- mark `macroable-03` revalidated through the existing direct split dependency;
- retain the active JWT routing fields during implementation, then update the dependency rows and JWT checklist only after the completed work has passed every gate and owner review;
- preserve no abandoned design or external-report promise in the records.

The single active-package routing field will conflict mechanically with other parallel audit branches. Reconcile those three lines during merges; do not redesign the routing model in this package work.

## Regression plan

### Provider and validation

- `ExpiredClaimTest`: absent/null expiration remains optional; integer zero throws `TokenExpiredException`; existing future/leeway behavior remains.
- `LcobucciTest`: an encoded external-style JSON string `"0"` decodes to timestamp zero and is rejected by expiration validation; a compact data provider feeds null, bool, array, and object date values across representative `exp`, `nbf`, and `iat` claims in an invalidly signed token and always receives `TokenInvalidException` with the native failure chained.
- Existing manager tests continue proving that future `nbf` blocks refresh and past `nbf` permits it; no duplicate NotBefore test is added for the explanatory comment.

### Revocation and storage

- `BlacklistTest`: finite and null refresh TTLs; missing/null `exp`; missing/null `iat` using the finite `exp + leeway + margin` boundary even when refresh TTL is null; automatic permanent writes honor grace without sliding it on repeated calls, while explicit `addForever()` remains immediate; permanent writes only when no finite acceptance boundary exists; elapsed/exact/sub-minute boundaries; `exp + leeway`-dominant and refresh-dominant bounds; a bare-expiration boundary that has elapsed while `ExpiredClaim` still accepts the payload, with one comment identifying the shared leeway and the written entry then observed by `has()`; cache access before the leeway-aware terminal boundary and no cache access after it; a finite write whose mocked cache lookup advances time past the boundary and still receives a positive TTL; finite/forever/clear false propagation; nullable accessor; string/integer zero keys; and one invalid-shape data provider with accurate failure wording.
- `TaggedCacheTest`: boolean results propagate from finite write, forever write, and flush, while existing all-mode/any-mode key behavior remains.
- `JwtManagerTest`: finite and force-forever writes are called exactly once; false persistence throws; invalidation delegates existing-key idempotence to the blacklist write without a separate read; refresh does not report success after failed invalidation; missing/null `iat` fails through `TokenInvalidException`; specifically, null `refresh_ttl`, `refresh_iat = true`, required claims without `iat`, and a payload containing `exp` but no `iat` must fail before replacement construction; and expired but structurally valid payloads invalidate without consulting temporal validation.
- `JwtGuardTest` / `JwtGuardEventTest`: successful logout clears the same context and emits the same event; failed revocation retains user/token/payload state and emits no Logout event; force-forever delegation remains.
- `JwtServiceProviderTest`: nullable and finite refresh TTL wiring, integer leeway wiring, shallow replacement of `providers`, default storage fallback, custom storage boolean contract, and exact non-taggable-store guidance.

### Commands, config, docs, and conventions

- `JwtGenerateCertsCommandTest`: one real 2048-bit RSA success path proves key contents, private/public modes, directory creation, and env output; below-floor RSA fails before key generation; non-RSA-specific tests use valid fast `prime256v1` fixtures without ignored RSA options; interactive passphrase `"0"` is recorded and actually encrypts the private key; overwrite, missing-env, SHA, curve, and invalid-algorithm behavior remains.
- `JwtConfigTest`: retain nullable/integer `refresh_ttl`, remove the duplicate blacklist-TTL test, prove the obsolete key/env string is absent, and retain the published defaults.
- Existing provider/storage/config tests receive only expectation changes required by removing redundant defaults and truthful booleans. The missing-`iat` manager fixture explicitly configures null `refresh_ttl` and `refresh_iat = true` while asserting the failure outcome rather than pinning config-read order.
- The six surviving untyped test methods and one setup omission are corrected without a repository-wide sweep.
- The nine verified Mockery expectation corrections are validated through their existing JWT, Auth, HTTP Server, WebSocket Server, Console, and Database test files; no new test tests the test correction.

## Validation sequence

During implementation, run each changed test file immediately. After each coherent slice, run the affected JWT group rather than the full repository gate.

Before code review:

```shell
./vendor/bin/phpunit --no-progress tests/Jwt
composer validate --strict src/jwt/composer.json
composer facade -- --lint 'Hypervel\Support\Facades\Jwt'
git diff --check
composer fix
```

`composer fix` is the authoritative checkpoint and runs formatting, both PHPStan configurations, the complete parallel Components suite, Testbench package mode, and dogfood in script order. Do not run those full checks separately around it.

Run broad stale/reference checks for:

- `blacklist_refresh_ttl` and `JWT_BLACKLIST_REFRESH_TTL`;
- `Hypervel\Jwt\Storage\PsrCache` and JWT's `psr/simple-cache` dependency;
- discarded `void` storage implementations;
- redundant top-level JWT config defaults;
- stale README/docs wording, quoted Differences heading, and internal parser bullet;
- route/dependency/checklist consistency for `support-02`, `macroable-03`, and JWT.

After the full gate, perform a fresh self-review without trusting this plan. Trace every changed caller/callee, provider parse boundary, revocation lifetime, storage false result, logout state transition, key publication, config merge, public type/config removal, documentation statement, normal-path allocation/I/O, and stale symbol. Any unexpected issue returns to focused investigation and second opinion before modification. Then request independent code review through signoff.

## Performance and lifecycle assessment

- No new cache/network call, retry, lock, registry, context slot, or service resolution is introduced. The blacklist retains one additional immutable boot-configured integer for leeway and no mutable request state.
- Dead tokens perform fewer cache operations because terminal blacklist writes are skipped.
- Truthful storage returns inspect results already produced by Cache; no additional I/O occurs.
- Automatic permanent revocation performs the same existing-entry read as finite revocation so repeated revocations cannot restart grace. Already-overlapping first writes may shift a non-zero deadline by cache or scheduling latency; this bounded tradeoff avoids cross-store atomic-insertion machinery. Explicit `addForever()` remains a write-only operation.
- The decode correction changes only the caught throwable type around an existing parser call.
- Exact key checks and config fallback cleanup add no meaningful hot-path work.
- Leeway is read once when the worker-lifetime blacklist is constructed; the boundary calculation adds one date modifier and no I/O. Its terminal decision and TTL reuse one clock snapshot, while the grace timestamp keeps its independent write-time clock read.
- Permanent storage when a present `iat` makes refresh genuinely infinite is required security retention, not avoidable overhead.
- Filesystem work is console-only and replaces direct publication with the existing atomic primitive.
- RSA command coverage stays fast by retaining one supported real RSA generation and using EC for unrelated tests.
- Guard state remains coroutine-local; the logout reorder adds no state and performs the same invalidation call earlier.

## Explicitly rejected designs

- No parser pre-validator, wrapper, claim DTO, recursive temporal coercion, or catch around application-owned encode input.
- No truthiness rewrite for `IssuedAtClaim` or `NotBeforeClaim` without a behavioral defect.
- No validation-chain introspection in blacklist retention; explicitly removing `ExpiredClaim` while continuing to stamp `exp` is a deliberate validation bypass, not a second retention contract.
- No required `exp`, new refresh validator, HMAC-zero special case, resource-key conversion, or generic payload traversal.
- No revocation retry, write-ahead log, cache-key registry, prefix-only incomplete mode, third shipped storage mode, or external Redis suite.
- No deep config merge, config normalizer, default registry, compatibility alias, or deprecated setting reader.
- No certificate lock, two-file transaction, backup, cross-file rollback, JWT-owned atomic writer, configurable RSA floor, or duplicated Filesystem failure tests.
- No guard cloning, scoped rebinding, subject lock, coroutine cleanup hook, static registry, or new worker cache.
- No leeway getter or mutator; leeway is immutable boot configuration and has no runtime consumer or upstream public counterpart.
- No external upstream issue or pull request without owner authorization.

## Completion criteria

- Every accepted finding is corrected at its existing owner, with `jwt-02`/`jwt-05` and `jwt-14`/`jwt-15` each landing inseparably.
- Storage failure cannot report successful invalidate, refresh, or logout.
- Untrusted malformed registered dates never escape as native `Error`/`TypeError`.
- Epoch-zero expiration is rejected, while `iat`/`nbf` zero behavior is unchanged.
- Finite, infinite, missing-claim, leeway-extended, already-terminal, and cache-delayed revocation lifetimes are safe and tested.
- Missing/null `iat` cannot bypass an infinite refresh window or reach replacement encoding, and its blacklist entry remains finite when `exp` provides the complete acceptance boundary.
- Certificate files are complete before publication and have explicit final modes; unsupported RSA sizes fail before generation.
- No destructive PSR adapter, duplicate blacklist TTL, stale dependency, stale comment, or duplicated README documentation remains.
- Custom drivers and custom storage remain clear, Laravel-shaped extension points.
- JWT revalidates `support-02` and `macroable-03` without duplicate machinery.
- Focused tests, strict split metadata, facade lint, stale scans, `git diff --check`, and `composer fix` are green.
- Fresh self-review and independent code review find no unresolved correctness, security, lifecycle, API, documentation, performance, dead-code, or overengineering issue.
- Final audit records are concise, accurate, and updated only after the implementation is complete.
