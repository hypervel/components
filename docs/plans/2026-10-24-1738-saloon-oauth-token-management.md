# Saloon token management

## Outcome

Add two opt-in capabilities to `hypervel/saloon`:

- Cache independently reacquirable tokens, such as client-credentials grants, without an application repeating expiry and concurrency handling.
- Persist connected OAuth accounts, cache their credentials, and renew rotating refresh tokens without holding database connections through provider calls.

Keep manual grant methods, immutable connectors, existing authenticators, request modifiers and application-owned provider credentials. Applications choose their cache stores and publish the account migration only when using durable accounts. No new package, store-driver abstraction, provider registry, scheduler or UI is needed.

Status: implemented, verified and reviewed; ready for a framework PR. The only approved existing API departure is scope metadata on `OAuthAuthenticator`, `AccessTokenAuthenticator` and the corresponding grant factories. No Socialite API change is required.

## Existing boundaries and evidence

Read the current implementations when changing their callers:

| Existing source | Consequence for this design |
|---|---|
| `Contracts/OAuthAuthenticator.php`, `Http/Auth/AccessTokenAuthenticator.php` | Token values already own expiry and authentication; extend them instead of adding a parallel token DTO. |
| `Traits/OAuth2/{ClientCredentialsGrant,ClientCredentialsBasicAuthGrant,AuthorizationCodeGrant,ParsesOAuthTokenResponses}.php` | Keep existing grant construction, configuration and per-call modifiers. Both response factories must carry scopes. |
| `SaloonManager::send()`, `Http/PendingRequest.php` | Authentication runs when each pending request is built. A normal business-request retry creates a new pending request; never send a business request from token-management code. |
| `Cache/CacheKey.php` | Default response-cache identity includes authorization headers. Token renewal naturally creates a new response-cache identity. |
| `Cache/ModelCacheCoordinator.php`, `ModelCacheStoreValidator.php` | Reuse committed model caching, fill/invalidation coordination and supported-store validation. Do not build another coherence protocol. |
| `Database/Eloquent/Model.php` | Ordinary saves do not compare a version. Use fresh credential revisions and conditional renewal writes. Models are transient; serialization clears cast caches. |
| `Coroutine/Locker.php`, `Database/ConnectionResolver.php` | Keyed owner/waiter coordination already exists. Release idle connections before provider calls and backoff; do not hold transactions across them. |
| `Socialite/Two/{Token,AbstractProvider}.php` | A refresh callback can adapt the existing token. An empty approved-scopes array does not distinguish an omitted field. |

Saloon paths are relative to `src/saloon/src`; other package anchors refer to `src/{package}/src`. Existing tests under `tests/Saloon/Feature/Oauth2`, authentication tests and cache integration tests establish the surrounding behavior.

Relevant external evidence:

- [RFC 6749](https://www.rfc-editor.org/rfc/rfc6749.html), §§3.3, 5.1, 5.2 and 6: scope metadata, token errors and refresh behavior.
- [RFC 9700 §4.14.2](https://www.rfc-editor.org/rfc/rfc9700.html#section-4.14.2): replay of a rotated refresh token can revoke the active grant.
- [OAuth2 Proxy renewal](https://github.com/oauth2-proxy/oauth2-proxy/blob/master/pkg/middleware/stored_session.go): lock, reread, persist, release; transient and rejected-credential failures differ.
- [Supabase session recovery](https://supabase.com/docs/guides/auth/sessions#what-is-refresh-token-reuse-detection-and-what-does-it-protect-from): retries can be supported by provider reuse grace and parent-token recovery. Do not assume those guarantees for arbitrary providers.
- [Saloon OAuth addon](https://github.com/SimpleSquid/saloonphp-oauth), available in `references/saloonphp-oauth`: useful comparison, not a port. Do not copy class-name-only token keys, mandatory persistence, or best-effort saving of rotated tokens.

## 1. Token metadata

Append `?array $scopes = null` to `AccessTokenAuthenticator` and add `getScopes(): ?array` to `OAuthAuthenticator`, documented as `null|list<string>`. Preserve current parameter names/order. Append the same optional metadata to both protected `createOAuthAuthenticator()` signatures and update overrides and fixtures together.

- `null`: provider did not report scopes; a renewal retains stored scopes.
- `[]`: explicitly supplied empty set; do not replace it with a fallback.
- Parse supplied space-delimited scope strings at the shared OAuth response boundary. Treat absent/null/empty-string metadata as unknown, and reject other wire types. An empty wire string is not a valid reported OAuth scope; it must not erase stored scopes. Custom callbacks can supply normalized lists, including an intentional `[]`, directly. Do not infer effective permissions from the requested scopes when the response omits them.
- Preserve omitted refresh tokens through the existing fallback path. Scope handling must work for client credentials, Basic-auth client credentials and authorization-code refreshes.
- Mark credential parameters sensitive. Do not log token response bodies, credentials, or serialized authenticators.

## 2. Cached acquisition

Public usage:

```php
use Hypervel\Saloon\Contracts\OAuthAuthenticator;
use Hypervel\Saloon\Http\Auth\CachedTokenAuthenticator;

$authenticator = new CachedTokenAuthenticator(
    key: $grantIdentity,
    resolver: fn (): OAuthAuthenticator => $oauth->getAccessToken(),
    store: 'redis',
);

$authenticator = $oauth->cachedAccessToken(
    scopes: ['read'],
    store: 'redis',
    requestModifier: fn ($request) => $request->timeout(20),
);

$authenticator->forget();
```

`CachedTokenAuthenticator` implements `Authenticator`. Its constructor configuration is immutable; it holds one completed entry for its fixed identity. The entry contains the actual `OAuthAuthenticator` and one computed renewal time, preserving custom token authentication behavior. Never serialize resolver closures or pending requests.

Add `cachedAccessToken()` to the existing client-credentials trait, inherited by the Basic-auth variant. Keep scopes, scope separator, optional store, per-call request modifier, and an optional extra `key` contribution. This convenience returns an authenticator; it does not fetch during connector construction.

### Identity and lifetime

- Hash an unambiguous encoding with SHA-256. The convenience identity includes connector class, resolved token endpoint, client ID, client secret, effective requested scopes and explicit key contribution. The generic constructor requires the caller to identify its complete grant. Never put raw credentials in a key.
- A modifier, overridden request factory or custom audience can change the grant. Document the extra key contribution for those cases; do not hash closures or prepare a whole pending request on each cache hit.
- Resolve stores as explicit store → `saloon.cache.store` → framework default. `worker-array` shares entries within a worker; the ordinary `array` driver does not share across request coroutines. Redis supports sharing between workers.
- Calculate renewal time once: expiry minus a small early allowance, capped at half the token's remaining lifetime. Store that absolute time; rereading an entry does not restart its life. Use a 30-second maximum allowance.
- Cache only through renewal time. A token without expiry follows the existing non-expiring contract; the caller must provide expiry when the provider documents one. Reject an already-expired newly acquired token without an internal fetch loop.
- `forget()` clears this object's entry and its shared cache entry. It is not provider revocation and does not erase entries retained by other live authenticator instances.

### Coordination

Add one internal `Http/Auth/TokenAcquisition.php` service, used by both cached acquisition and account renewal, reusing `Locker` for owner/waiter coordination. Keep **active acquisitions only** in an instance property, keyed by operation kind, full identity and store. A `Data/TokenAcquisitionResult.php` holder carries the completed token or provider failure for existing waiters. Remove the service entry in `finally`, including owner cancellation; waiters retain their captured holder until they resume. This avoids serial retry chains after a shared failure. No completed-token registry or exception history remains in worker memory. The service is safe to auto-singleton; container teardown resets it, so it needs no static `flushState()` hook.

Keep cancellation local to the canceled coroutine; a canceled waiter must not release another caller's lock. An owner cancellation cleans up and wakes waiters without sharing its `CanceledException` as their cancellation. With no completed result, waiters use Locker's next-owner rule: one becomes the new owner, rereads the cache/account and starts a fresh attempt; others wait. Durable claim cleanup/recovery still applies. No detached work is spawned. A genuinely later call may also try again. Separate workers may acquire independent client-credentials tokens; do not add a distributed acquisition lock for grants where this is harmless.

Use the existing cache serialization allowlist for built-in token/entry classes. Custom authenticators use its normal application registration mechanism. Capture the selected token locally while applying it; resource headers and merchant context stay on the connector/request, never in shared token state.

## 3. Durable accounts and public API

Add these files relative to `src/saloon/src`:

- `OAuthAccount.php` and `OAuthAccounts.php` for the model and account service, following Sanctum's root model placement.
- `Traits/HasOAuthAccounts.php` for the owner relation.
- `Http/Auth/CachedTokenAuthenticator.php` and `Http/Auth/OAuthAccountAuthenticator.php` beside existing authenticators, plus the shared acquisition files specified above.
- `Console/Commands/OAuthAccountsTableCommand.php` and `Console/Commands/stubs/oauth-accounts.stub`, following Saloon's existing command directory and `MigrationGeneratorCommand`'s stub loading.

`OAuthAccounts` owns model selection, partition configuration and account operations; it is a worker-safe service with no current-account or current-partition field. Use dependency injection and existing auto-singleton semantics, not a new facade or redundant bindings.

```php
use Hypervel\Saloon\OAuthAccounts;

// Application provider; optional model customization.
OAuthAccounts::useAccountModel(App\Models\OAuthAccount::class);

$account = $user->oauthAccounts()->create([
    'provider' => 'gitlab',
    'provider_account_id' => $providerUserId,
    'access_token' => $token->getAccessToken(),
    'refresh_token' => $token->getRefreshToken(),
    'expires_at' => $token->getExpiresAt(),
    'scopes' => $token->getScopes(),
]);

$authenticator = $oauth->accountAuthenticator($account);

// Other OAuth clients can supply the same token contract.
$authenticator = $account->authenticator(
    refreshUsing: fn ($current) => $provider->refresh($current),
);
```

`AuthorizationCodeGrant::accountAuthenticator()` adapts its existing `refreshAccessToken()` method; retain a per-call request modifier. The generic callback receives `OAuthAuthenticator` and returns `OAuthAuthenticator`. The account authenticator captures model class, resolved connection/table, primary key and persisted partition, not a mutable account model or a permanent token copy.

`OAuthAccount::newOAuthAuthenticator()` constructs the current token from persisted fields and is the model extension point for custom authentication behavior; `authenticator()` constructs the renewing account authenticator. The default token represents OAuth bearer credentials. Persist defined credential fields, not arbitrary serialized provider objects. Provide `clearCredentialCache()` for application-controlled bulk/quiet mutations, following the existing token-model cache conventions. Credential writes through these escape hatches must also replace the revision, calculate the renewal time and clear obsolete claims; cache invalidation alone does not fence an in-flight renewal.

### Migration and casts

Add `make:oauth-accounts-table`, alias `oauth-accounts:table`, using `MigrationGeneratorCommand`. Generate `oauth_accounts`; do not load or run a migration automatically. Applications may adjust the published migration and model table/connection.

| Column | Contract |
|---|---|
| `id` | UUID primary key; framework UUID model concern |
| `owner_type`, `owner_id` | Morph owner, using framework morph-key conventions |
| `provider`, nullable `provider_account_id` | Provider reference and optional external account identifier |
| `access_token`, nullable `refresh_token` | Text columns, encrypted casts and hidden serialization |
| nullable `expires_at` | UTC `dateTime`, immutable cast |
| nullable `refresh_at` | UTC `dateTime`, immutable cast; fixed early-renewal time calculated when credentials are installed |
| nullable `scopes` | `text()`, array cast; opaque list read back whole, preserving omission versus empty set |
| `credential_revision` | Fresh UUID whenever credentials change |
| nullable `refresh_claim`, `refresh_started_at` | UUID ownership of one renewal attempt and UTC `timestamp()` recording its start |
| timestamps | Ordinary created/updated timestamps |

Index owner lookup and provider/external-account lookup. Do not impose uniqueness on owner + provider + external account: distinct client registrations or permission grants may legitimately share those values. Reconnect replaces a selected account row's credentials; it is not inferred from that tuple.

Keep client secrets in application configuration. Encrypt stored access/refresh tokens, including cached model attributes; never hash tokens that must later be sent to a provider. Use existing encryption casts and key management. These protections complement normal cache/database access controls; cached standalone token values still contain usable credentials.

### Ordinary mutations and cache configuration

Use model events for normal saves/deletes and committed invalidation. The credential `saving` hook assigns a fresh UUID revision and clears an obsolete claim when credential attributes change. On creation or a changed access token/expiry, that same hook computes `refresh_at` from save time using the capped allowance in §4, or sets it to null when expiry is absent. Thus relation `create()` and ordinary reconnect `save()` need no manually supplied deadline. Unrelated metadata saves must neither move the deadline nor clear a live claim.

All account reads, claims, conditional writes and cleanup start from the account model's `newQuery()`, preserving global scopes and custom Eloquent builders. Do not use base/raw queries to bypass them. Conditional builder updates need explicitly cast/encrypted attributes, revision/deadline calculation and invalidation because they do not run model events. Raw, quiet and bulk application mutations retain normal Eloquent responsibilities: callers invalidate affected account keys; do not build a second Eloquent mutation system.

Add `saloon.oauth_accounts.cache` with `enabled` (false), `store` (null = framework default), `ttl` (300 seconds), and `prefix` (`saloon:oauth-accounts:`). Add `saloon.oauth_accounts.lock_store` (null = selected account-cache store). Validate selected stores lazily when durable accounts are used; unused installations need no account table or lock connection.

- Cache enabled: use `ModelCacheCoordinator` and `ModelCacheStoreValidator`; cache a relations-unset model, using normal encrypted casts and model serialization. Register the configured model with the cache serialization policy.
- Warm cache hits require no database query and no per-request write. No `last_used_at` update or worker-lifetime private token slot for durable accounts.
- Cache disabled: read the authoritative row; renewal still uses the shared lock store.
- Key identity encodes model class, captured resolved connection **name**, table name, account ID and partition, hashed with SHA-256. Capture the selected connection name even when the model normally follows the default. Do not inspect physical database configuration; connection names separate ordinary multiple-connection/table uses, while the partition separates scoped databases behind one connection. Reuse the identity for reads, writes, invalidation and locks; cache-fill and renewal locks are distinct.
- Stores must span the deployment using those accounts. Redis/database suit multiple hosts; file/Swoole scope must match the deployment. Reject worker-local/stack/failover stores for durable credential caching; never hide a cache outage by serving stale credentials.
- Use writer reads for renewal and recovery. Never publish uncommitted credentials. Cache writes and invalidations follow `afterCommitOrNow`; normal application transactions bypass shared fills through the existing coordinator.

## 4. Renewal and recovery

Renewal uses a short cache lock for waiting and a committed row claim for coordination that survives a cache restart. Neither provides exactly-once provider execution. The recovery policy intentionally permits another attempt after transient failures or an abandoned claim; delayed provider requests can still overlap and trigger reuse detection.

### When to renew

When installing credentials, compute `refresh_at` once using the same expiry calculation as cached acquisition: `expires_at - min(30 seconds, remaining lifetime / 2)`. Recompute on successful renewal or a replacement access token/expiry, never on cache reads or unrelated metadata saves. Persisting this deadline avoids both resetting it on reads and immediately re-renewing short-lived tokens.

- Before `refresh_at`, apply usable credentials without renewal. A current claim still takes precedence over the old token.
- At/after `refresh_at`, renew if a refresh token exists. Without one, use the access token until its actual expiry, then throw `OAuthAccountReconnectRequiredException` before taking a claim.
- No expiry means no automatic renewal and a null renewal time.

### One attempt

1. Resolve/check the current partition, then enter worker-local acquisition coordination. Existing same-worker waiters share the entire bounded attempt chain and its final result/error.
2. Acquire the account's cache lock, then reread the writer. If another attempt installed usable credentials, use them. Missing/deleted accounts fail; never recreate them. A present claim blocks use of the old token because some providers invalidate it immediately on refresh.
3. Reject renewal while the account connection has an applicable caller transaction. This avoids remote rotation followed by rollback, uncommitted account reads, and caller/child row-lock deadlocks. Do not escape the caller into a detached coroutine.
4. Validate locally before claiming. Atomically set a fresh `refresh_claim` UUID and `refresh_started_at`, conditioned on account, partition, credential revision and a null or abandoned claim. A claim is abandoned after 300 seconds. This is a recovery interval, not proof that the old provider operation has ended.
5. Commit the claim and invalidate its cache before provider I/O. Proceed only when one row changed; an uncertain claim write does not authorize a provider request. Release idle database connections.
6. Invoke one provider refresh attempt. The Saloon convenience applies the caller's modifier, then disables transport retries for this request. Custom callbacks must also perform one provider attempt.
7. On success, reject an already-expired result, prepare encrypted credentials through the model's casts, retain omitted refresh token/scopes, calculate `refresh_at`, and conditionally store credentials + a fresh revision + cleared claim. Match account, partition, read credential revision **and claim UUID**. Commit and invalidate before exposing the result through the model's token factory. On a lost comparison, reread the account; never return the losing result or overwrite a reconnect. Do not repeat provider refresh merely because persistence or cache invalidation failed.
8. On failure/cancellation, conditionally clear only this attempt's claim and invalidate. Do not clear a successor's claim. A process crash or failed cleanup leaves a reclaimable claim. Propagate cancellation unchanged; never start its next retry. Preserve the primary failure if cleanup also fails, reporting the cleanup failure without credentials.
9. Release the cache lock in `finally`. No transaction or pooled connection is held while waiting or during HTTP.

Use a 120-second cache-lock lease and a 120-second acquisition wait. Default single-attempt HTTP timeouts are currently 10 seconds connecting and 30 seconds total; these leave headroom for ordinary processing but do not enforce a whole-operation deadline. Custom timeouts/callbacks must respect the documented budget. The durable claim still blocks a second attempt if the cache lease expires before the abandonment interval. A late owner loses local write authority after its claim is replaced. Do not wait for the full abandonment interval on a request that finds a still-owned row after the cache lease expired; fail explicitly and let a later operation recover.

### Retry ownership

- Built in, fixed small budget: three provider attempts total; exponential full-jitter delays capped at 250 ms then 500 ms. No policy selector or collection of retry configuration keys.
- Release the distributed lock and row claim before sleeping; retain worker-local result sharing for callers already waiting on this operation. Reacquire and reread before every attempt. Another operation may have succeeded during the delay.
- Retry provider connection failures, HTTP 408/429/500/502/503/504 and OAuth `server_error`/`temporarily_unavailable`. Read structured OAuth error codes from the actual response, never substring-match exception messages. `invalid_grant`, `invalid_client`, invalid scope and malformed configuration take precedence over a generic server status and are not retried. Restrict this classification to the provider invocation, not local database/cache failures.
- Respect `Retry-After`; if it requires a delay outside the small retry budget, surface the provider response for the caller to schedule later rather than retry early. Reuse existing parsing logic at its owning boundary if needed, without building a general retry subsystem.
- Handle Saloon/HTTP client exceptions and the corresponding Guzzle exceptions from Socialite callbacks. Unknown callback exceptions are not assumed transient. Do not wrap provider errors in a new parallel exception hierarchy.
- On `invalid_grant`, reread first. If the credentials changed, use/reassess the replacement instead of rejecting it. Otherwise clear only this claim and surface the original rejection without another attempt. The application handles reconnecting/disabling use of the account; do not silently delete credentials or introduce a persisted account-status system.
- `invalid_client` requires correcting client configuration, not reconnecting every account. Preserve stored grants and surface it without automatic retries. Transient exhaustion likewise retains credentials for a later operation.
- Same-worker waiters share errors through the active holder, rather than each starting its own chain. Across workers, authoritative claims serialize provider attempts; do not serialize exception objects into the cache or promise distributed error broadcasting.

Tests must exercise late completion after claim replacement and cancellation, not just a mocked lock call. Keep clocks, delays and operation budgets explicit; do not add a timer heartbeat, detached completion, durable retry queue or periodic cleanup job.

## 5. Partitioning and state lifetime

Expose boot-only `OAuthAccounts::resolvePartitionUsing(string $column, Closure $resolver)`, beside `useAccountModel()` and its `accountModel()` getter. Follow Permission's SQL-column validation and registration-before-use guard. Seal configuration on first account operation, not a harmless model-class getter, so application boot can override a default model before use. A configured resolver returns the current nonempty string or integer partition; missing context fails explicitly. An unconfigured ordinary application has no partition column requirement.

- Creating an account fills the partition column from the resolver and rejects a conflicting supplied value.
- Capture the **persisted** partition when constructing its authenticator. Validate it against the current resolver on every apply, including cache hits, and after yielding before using credentials or publishing changes.
- Saloon's account reads, claims, conditional writes and invalidations always include that partition. A UUID alone is not authorization.
- Do not add an application-wide Eloquent global scope in Saloon. Applications/integrations own query policy and schema columns. A model scope alone cannot protect warmed cache entries or retained authenticators.
- Platform-owned accounts use an explicit platform partition. There is no special global-credential bypass flag.
- Reacquirable-token keys follow credential/grant identity, not the current partition automatically. Identical platform credentials may share their token; distinct grants supply distinct identity. Resource/merchant headers remain request-owned.
- Boot callbacks capture only worker-safe services and resolve current context when called. Never change global config per request or retain a tenant/account object in a singleton. Mark configuration mutators boot-only and register static cleanup in the existing testing subscriber.

## 6. Documentation and integration

Update `src/docs/saloon.md` in its existing Laravel-docs style:

- Replace the boot-fetch example with cached authentication; explain worker-array versus shared storage, identity, expiry, invalidation and custom token classes.
- Show account migration, owner relation, custom model, OAuth callback persistence, reconnect/delete, encryption and optional cache setup. Do not imply installing Saloon creates a table.
- Show a Socialite callback mapping `token`, `refreshToken`, `expiresIn` to an absolute immutable expiry, and `approvedScopes ?: null`. Initial Socialite user data already exposes the raw response when exact scope presence matters. Custom provider adapters can explicitly supply an empty scopes list.
- Explain transaction rejection, transient recovery, `invalid_grant` versus client configuration failures, custom callback single-attempt bounds, cancellation and the remaining provider-side overlap risk.
- Document generic partition registration and request-owned credentials/headers. No private integration names or dependencies.
- Explain that default response-cache entries stop matching after bearer renewal. Apps choosing stable custom keys/scopes must retain account/permission isolation; no automatic cache-key rewrite.
- Keep the package README's lasting API differences accurate, including the approved scope contract/factory additions. Purely additive helpers belong in the guide. Update porting guidance only for an actual affected upstream contract.

## 7. Implementation and verification

Implement metadata and cached acquisition, then account persistence/cache/partitioning, then renewal/recovery and documentation as coherent slices. One final code-review boundary covers the complete feature.

Before adding tests, inspect existing coverage and extend it where practical. The focused matrix is:

| Area | Meaningful coverage |
|---|---|
| Token metadata | Omitted/empty/nonempty scopes, both grant factories and custom override signatures; existing refresh-token fallback |
| Acquisition | Memory/shared reuse; fixed short/no-expiry lifetime; identities across credentials/environments/custom audience; separate instances coalesce; owner failure reaches waiters; canceled waiter leaves owner intact; explicit forget and normal request retry |
| Persistence | Generated migration and morph-key conventions; encrypted DB/cache representation; hidden secrets; custom model/connection; save/delete/reconnect invalidation and rollback |
| Database load | Warm shared-cache authentication performs no SQL; cache disabled works; no repeated account writes on ordinary API calls |
| Renewal | Fixed early deadline including short/no-expiry tokens; non-refreshable token remains usable until actual expiry; two competing callers invoke once; success saved before use; rejected/expired returned token; omitted metadata retained; failed save surfaces; cache lease expiry alone does not bypass a fresh claim |
| Recovery | Transient backoff/jitter is bounded; no nested HTTP retries; fresh writer read before retry; rejected grant versus invalid client; abandoned claim takeover; old success/cleanup cannot overwrite new claim/reconnect/delete |
| Transactions/cancellation | Caller transaction rejected before provider call; cancellation propagates and releases owned coordination; cleanup failure does not hide cancellation; no detached child or held DB connection |
| Partitioning | Cold/warm cross-partition denial, captured authenticator after context switch, concurrent partitions, missing context, create mismatch, post-yield checks |
| HTTP integration | New pending request receives renewed auth; provider response/errors remain available. Run existing response-cache and OAuth suites for unchanged behavior, without duplicating their tests. |

Use real supported shared-store/database integration for competing claims and invalidation; mock-only tests cannot prove cross-worker behavior. Use testbench SQLite for portable model behavior, with focused Redis and database integration where the shared-state claim needs it. Reuse existing service traits and CI discovery conventions; avoid a full driver/HTTP/provider cross-product. Never contact real provider accounts in tests.

Run each changed test file immediately during implementation. At completion run `composer lint:fix`, `composer analyse`, then affected Saloon tests and touched integration suites sequentially, from the worktree root, with execution timeouts. Use the full suite only for framework-wide changes. Self-review callers/callees, retry multiplication, cache keys, context changes, secret exposure and API signatures before requesting review. Do not add benchmark claims; obtain owner idle-machine confirmation before benchmarking.
