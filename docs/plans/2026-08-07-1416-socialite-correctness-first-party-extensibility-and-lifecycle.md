# Socialite Correctness, First-Party Extensibility, and Lifecycle

## Scope and outcome

Complete the Socialite audit against the current Hypervel package, Laravel Socialite, the SocialiteProviders Manager/Providers ecosystem, OAuth 2.0 and OpenID Connect requirements, and the current container/coroutine runtime. Preserve the existing high-performance shape: one cached provider per driver and worker, with request-mutated state isolated in `CoroutineContext`.

The final package must provide secure request transport, exact request/tenant isolation, bounded worker-local JWKS reuse, truthful OAuth response models and types, Laravel-style first-party provider extension APIs, and complete user documentation. Fix the related Support, Object Pool, and Reverb container-owner defects at their actual owners. Do not add compatibility wrappers for superseded Hypervel APIs.

References checked for this design:

- current Hypervel Socialite source, tests, split metadata, facade, README, and Boost guide;
- Laravel Socialite `27702f45183f1ee4b00cc3a7237b626678b3b4ae`, including Bitbucket, LinkedIn, user factories, tests, and docs;
- SocialiteProviders Manager `35372dc62787e61e91cfec73f45fd5d5ae0f8891` and Providers `257a17f2033fd7bbbc2620d51952ad2a339fe8d4`;
- RFC 6749 token refresh/expiry behavior, OpenID Connect Core 3.1.3.7 audience rules, Google issuer/JWKS guidance, Meta Secure Requests guidance, and installed `firebase/php-jwt` 7.x;
- Hypervel Container alias replacement, Support Manager state, Object Pool manager/recycler state, and Reverb channel-manager state.

Evidence baseline: Hypervel `128c71b7384be0cd94eaae7b38594141a9cd0145`. Focused probes reproduced a released transient provider object's recycled ID carrying tenant A's credentials, Guzzle client, and mutable context into tenant B; the current Factory binding resolving a different `SocialiteManager` concrete; and level-5 PHPStan accepting the nullable OIDC result passed to an array-only boundary.

## What this audit is not

The following wording is retained verbatim from the core audit plan. Its principle numbering is also retained; principles 1–6 remain in the core operating plan. In principle 9, “later in this plan” refers to that plan's **Established remediation vocabulary** section.

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

### 8. Remove superseded design completely

When a fix changes the owning model, delete obsolete helpers, callbacks, properties, config keys, comments, tests, and documentation. Do not leave a compatibility path or comment describing behavior that no longer exists. Preserve intentional upstream comments unless the new design makes them incorrect.

### 9. Treat remediation patterns as candidates

The established patterns later in this plan are a vocabulary, not a lookup table. Choose among per-call parameters, immutable values, scoped bindings, cloning, CoroutineContext, factories, explicit ownership, static reset, or resource teardown only after proving the real lifetime and owner.

### 10. Reject speculative complexity

Record low-confidence concerns under rejected or unresolved analysis. Do not implement them. Surface every evidence-backed, meaningful non-defect improvement to the owner with its benefit, cost, and alternatives, then stop for explicit approval. This requirement exists to keep worthwhile opportunities visible, not to discourage finding them.

## Findings and final decisions

| ID | Category / severity | Final decision |
|---|---|---|
| `socialite-01` | Security defect / Major | Send both Bitbucket user and email tokens in `Authorization: Bearer`; never place them in URLs. |
| `socialite-02` | Upstream defect / Minor | Port current LinkedIn `StillImage` handling: hoist the optional node to `[]` and terminate width reads with `?? null`. |
| `socialite-03` | Current parity improvement | Port OAuth2 `Two\User::fake()` and current focused tests/docs; OAuth1 remains unsupported. |
| `socialite-04` | Provider/security defect / Major | Use GitLab `/api/v4/user` and Bearer auth. This also corrects current Laravel Socialite behavior. |
| `socialite-05` | JWT validation defect / Major | Accept exactly Google's bare and HTTPS issuer forms; use the package's named issuer/audience exceptions without flattening their cause. |
| `socialite-06` | Security defect / Major | Send generic OIDC UserInfo tokens through Bearer auth while retaining the JSON accept header. |
| `socialite-07` | Dead code / Minor | Delete unused `appendOIDCPayload()`. |
| `socialite-08` | Rejected metadata concern | Keep `hypervel/collections`; Socialite directly uses its `Arr` and `value()` symbols. |
| `socialite-09` | Divergence/docs defect / Minor | Record OAuth1 and legacy Twitter omission, the `x` replacement, and the final first-party extension surface in README/source/test/docs; remove obsolete `formatConfig()`. |
| `socialite-10` | Worker-lifecycle footgun / Major | Mark `withConfig()` boot-only and document `setConfig()` as the request/coroutine override that must be applied independently on redirect and callback requests. |
| `socialite-11` | API cleanup / Minor | Move `HasProviderContext` to `Concerns`, make raw context methods protected, and expose no generic application state API. |
| `socialite-12` | Config-key defect / Minor | Delegate all keys, including null and `"0"`, directly to `Arr::get()`. |
| `socialite-13` | Type/API defect / Minor | Return `Contracts\Provider` from Factory/manager/fake, `Two\AbstractProvider` from the generic builder, and arrays from token/user-response boundaries. Remove false nullable results; rely on native return types rather than a duplicate `instanceof` guard. |
| `socialite-14` | Facade metadata defect / Major | Generate the facade only from `SocialiteManager`; do not advertise direct provider calls that cannot select a default driver. |
| `socialite-15` | Documentation defect / Minor | Explain that `stateless()` disables OAuth state checks but X PKCE and generic OIDC nonce validation still require session continuity. |
| `socialite-16` | Extension documentation gap / Minor | Document `OpenIdProvider`, custom OAuth2 providers, parser hooks, request access, full token responses, and provider registration in Laravel-docs prose. |
| `socialite-17` | Secret-handling defect / Major | Apply `#[SensitiveParameter]` to every active client-secret, token, code, ID-token, secret config, and token-response frame. Keep user profiles, client IDs, state, nonce, and scopes diagnostic, and derive reflection coverage from the provider surface rather than maintaining a duplicate method inventory. |
| `socialite-18` | Container identity defect / Major | Make `SocialiteManager` the canonical auto-singleton and alias Factory to it. Preserve Factory-only facade fake swaps and delete duplicate manager overrides. |
| `socialite-19` | Tenant-correctness/performance/availability defect / Major | Key generic OIDC discovery by its exact URL and use one bounded exact-URL parsed-JWKS concern for generic OIDC, Google, and Facebook, including cache directives and one throttled rotation retry. Remove manual Facebook RSA construction and `phpseclib`. |
| `socialite-20` | OAuth response defect / Major | Preserve the submitted refresh token when rotation omits one; add four protected response parsers and a whole-response user-mapping seam, use nullable exact expiry parsing, and remove redundant Google/Twitch/OIDC orchestration. |
| `socialite-21` | OIDC validation defect / Major | Accept scalar/list audiences, require this client ID, reject additional audiences unless listed in `trusted_audiences`, and do not universally require `azp`. Apply to generic OIDC, Google, and Facebook. |
| `socialite-22` | Coroutine context identity defect / Major | Replace recyclable object-ID namespaces with a lazy monotonic process-lifetime sequence that is intentionally never reset. |
| `socialite-23` | Request ownership defect / Major | Remove the retained Request property, seed context in construction, refresh it on cached-driver resolution, and fail clearly when a provider is used without request context. |
| `socialite-24` | Extension/coroutine-safety defect / Major | Expose the complete access-token response on `Two\User` by passing the current response directly through user construction; retain no response state on the worker provider. |
| `socialite-25` | OIDC lifecycle/diagnostic defect / Minor | Consume nonce once, preserve discovery failures as previous exceptions, and classify discovered metadata failures as runtime errors rather than bad caller arguments. |
| `socialite-26` | OIDC validation defect / Major | Require and validate a nonce only for providers whose flow enables nonce protection. |
| `socialite-27` | Coroutine memoization defect / Major | Publish the authenticated user only after response parsing and every setter succeed, so a caught exchange failure cannot leave a partial cached user. |
| `support-34` | Cross-package manager defect / Major | `Support\Manager::setContainer()` must refresh both container and configuration references; Socialite deletes its workaround. |
| `object-pool-04` | Completed-package state-owner defect / Major | Alias concrete `PoolManager`/`PoolRecycler` to Factory/Recycler so each worker has one pool registry and one timer owner. |
| `reverb-40` | Completed-package state-owner defect / Major | Alias `ArrayChannelManager` to `ChannelManager` under the existing custom-binding guard so concrete and contract users share one channel repository. |

Fortify response bindings and Database's entity resolver were inspected and remain ordinary bindings: those concretes are stateless, so distinct instances do not split ownership. Facebook Graph profile lookup retains query parameters because Meta documents `access_token` and `appsecret_proof` there and does not document an equivalent Bearer request for that endpoint.

## Implementation

### 1. Make provider context identity and request ownership exact

Move the concern to `src/socialite/src/Concerns/HasProviderContext.php`. Use one non-yielding increment per provider instance rather than a recyclable object handle:

```php
protected static int $nextContextNamespace = 0;

protected function getContextKey(string $key): string
{
    $namespace = $this->contextNamespace
        ??= '__socialite.providers.' . ++self::$nextContextNamespace;

    return $namespace . '.' . $key;
}
```

The sequence has process lifetime and no `flushState()`: resetting it while non-coroutine context entries remain would recreate the collision. Keep a short declaration comment that `Socialite\AbstractProvider` must remain the concern's only root user; an unrelated second root user would own a separate trait static counter while sharing the fixed key prefix. No lock is needed because increment and assignment do not yield. Add protected `getContext()`, `setContext()`, `getOrSetContext()`, and `forgetContext()` only.

The provider constructor seeds the current request into context and retains no Request property:

```php
public function __construct(Request $request, protected array $guzzle = [])
{
    $this->setRequest($request);
}

protected function getRequest(): Request
{
    $request = $this->getContext('request');

    if (! $request instanceof Request) {
        throw new LogicException(
            'No request is available for this provider. Resolve it through Socialite::driver() or call setRequest().'
        );
    }

    return $request;
}
```

`SocialiteManager::driver()` continues refreshing cached `AbstractProvider` instances from the current request binding. Direct construction works in the constructor's coroutine; reuse in another coroutine requires `setRequest()` and otherwise fails instead of reading a stale request.

### 2. Give each stateful service one container identity

Use Hypervel's established concrete-auto-singleton alias shape:

```php
// Socialite
$this->app->alias(SocialiteManager::class, Factory::class);

// Object Pool
$this->app->alias(PoolManager::class, Factory::class);
$this->app->alias(PoolRecycler::class, Recycler::class);

// Reverb, inside the existing custom-binding guard
$this->app->alias(ArrayChannelManager::class, ChannelManager::class);
```

Do not explicitly singleton-bind these concrete classes. `Container::bind()` and `instance()` drop a stale alias, so later application bindings and facade swaps still win. `Socialite::fake()` intentionally swaps only Factory; direct concrete resolution remains the real manager.

Fix the shared manager mutation and delete Socialite's duplicate methods:

```php
public function setContainer(Container $container): static
{
    $this->container = $container;
    $this->config = $container->make('config');

    return $this;
}
```

Update the base method's tests-only warning so it states that both the container and configuration references are swapped.

### 3. Make provider types and OAuth response parsing truthful

Use native domain types throughout:

```php
interface Factory
{
    public function driver(UnitEnum|string|null $driver = null): Provider;
}

/**
 * @template TProvider of Two\AbstractProvider
 * @param class-string<TProvider> $provider
 * @return TProvider
 */
public function buildOAuth2Provider(string $provider, #[SensitiveParameter] ?array $config): Two\AbstractProvider;
```

`with()` and fake/manager `driver()` return `Provider`. Token exchange and user lookup boundaries return `array`; generic OIDC methods are non-nullable. Regenerate the facade from the manager only.

Add four protected parsers used by both login and refresh:

```php
protected function parseAccessToken(#[SensitiveParameter] array $response): string
{
    return Arr::get($response, 'access_token');
}

protected function parseRefreshToken(#[SensitiveParameter] array $response): ?string
{
    return Arr::get($response, 'refresh_token');
}

protected function parseExpiresIn(#[SensitiveParameter] array $response): ?int
{
    $expiresIn = Arr::get($response, 'expires_in');

    if (is_int($expiresIn)) {
        return $expiresIn >= 0 ? $expiresIn : null;
    }

    if (! is_string($expiresIn) || ! ctype_digit($expiresIn)) {
        return null;
    }

    $normalized = ltrim($expiresIn, '0');
    $parsed = filter_var($normalized === '' ? '0' : $normalized, FILTER_VALIDATE_INT);

    return $parsed === false ? null : $parsed;
}

protected function parseApprovedScopes(#[SensitiveParameter] array $response): array
{
    $scopes = Arr::get($response, 'scope');

    return is_array($scopes)
        ? $scopes
        : (is_string($scopes) && $scopes !== '' ? explode($this->scopeSeparator, $scopes) : []);
}
```

This accepts non-negative integer and bounded digit-string expiry values, including zero-padded strings allowed by RFC 6749's `1*DIGIT` grammar, preserves zero, and maps absent/malformed advisory expiry to null. Normalize leading zeroes before `FILTER_VALIDATE_INT`; keep this local to the parser rather than coupling it to the independent JWKS cache parser. `parseAccessToken(): string` intentionally fails at the parser with a native `TypeError` when a successful token response has no string access token, instead of widening the hook and passing an invalid value deeper into `getUserByToken(string $token)`. `Token::$expiresIn` becomes `?int`; its refresh token remains `string`, using the submitted value when the response omits rotation. Delete `GoogleProvider::refreshToken()` and Twitch's `userInstance()` / `refreshToken()` overrides because the base parsers now preserve their behavior. Keep Slack's response-shape handling, X's transport overrides, Facebook's expiry-key translation, and every other genuinely provider-specific seam.

Add a whole-response user lookup seam to the base provider:

```php
protected function getUserByTokenResponse(#[SensitiveParameter] array $response): array
{
    return $this->getUserByToken($this->parseAccessToken($response));
}
```

Delete `OpenIdProvider::user()` and retain only its non-nullable one-line override of this seam, returning `getUserByOIDCToken($response['id_token'])`. Its direct required-key access is deliberate: a successful OIDC token response without an ID token raises the missing-key diagnostic and native string-boundary `TypeError` instead of widening the hook or passing an invalid value into JWT decoding. This preserves OIDC behavior while making the shared orchestration and cleanup authoritative for every provider, including custom providers that map users from the complete token response.

Add `Two\User::$accessTokenResponseBody = []` and its fluent setter. Pass the current response directly through the shared orchestration and retain the cached-user and invalid-state guards in their current order:

```php
public function user(): User
{
    if ($user = $this->getUser()) {
        return $user;
    }

    if ($this->hasInvalidState()) {
        throw new InvalidStateException;
    }

    $response = $this->getAccessTokenResponse($this->getCode());

    return $this->userInstance($response, $this->getUserByTokenResponse($response));
}
```

`userInstance()` copies its response argument to the returned user, fully decorates the local object, and calls `setUser()` only after every parser and setter succeeds. `userFromToken()` leaves the returned user's response body at its empty default because no token exchange occurred. The provider retains no response property, context slot, accessor, cleanup branch, or response pipeline.

```php
protected function userInstance(#[SensitiveParameter] array $response, array $user): User
{
    $instance = $this->mapUserToObject($user);

    $instance->setToken($this->parseAccessToken($response))
        ->setRefreshToken($this->parseRefreshToken($response))
        ->setExpiresIn($this->parseExpiresIn($response))
        ->setApprovedScopes($this->parseApprovedScopes($response))
        ->setAccessTokenResponseBody($response);

    $this->setUser($instance);

    return $instance;
}
```

Port `Two\User::fake(array $attributes = [])` from current upstream, adapted to strict Hypervel types. Include `accessTokenResponseBody` with a default empty array, pass an override through its setter, and cover both behaviors.

### 4. Secure provider transport and OIDC validation

Apply the exact request corrections in Bitbucket, GitLab, and generic OIDC. Port current LinkedIn optional-image mapping. Preserve Facebook's documented query-token profile request.

Centralize audience validation on `Two\AbstractProvider`:

```php
protected function validateAudience(mixed $audience): void
{
    $audiences = is_array($audience) ? $audience : [$audience];

    $trustedAudiences = Arr::wrap($this->getConfig('trusted_audiences', []));
    $trusted = [$this->getClientId(), ...$trustedAudiences];

    if (! in_array($this->getClientId(), $audiences, true)) {
        throw new InvalidAudienceException;
    }

    foreach ($audiences as $candidate) {
        if (! is_string($candidate) || ! in_array($candidate, $trusted, true)) {
            throw new InvalidAudienceException;
        }
    }
}
```

Use it in generic OIDC, Google, and Facebook. A single trusted audience may be configured as a string, matching the package's existing string-or-array scope convention. Reject missing/invalid audiences and untrusted extras; ignore `azp` rather than imposing an extension-specific rule. Google accepts only `accounts.google.com` and `https://accounts.google.com`; delete its catch-all verification wrapper so named JWT/issuer/audience failures retain their type and cause. Facebook and generic OIDC retain their exact issuer rules.

Consume OIDC nonce with `session()->pull('nonce')`. Wrap discovery failures with `previous: $exception`. `ConfigurationFetchingException` and `InvalidUserInfoUrlException` extend `RuntimeException`; issuer/audience/nonce/state validation remains in the invalid-argument family.

### 5. Share one bounded JWKS implementation

First make generic discovery tenant-correct with one atomically assigned URL/config entry:

```php
/** @var null|array{url: string, config: array} */
protected ?array $openidConfig = null;

protected function getOpenIdConfig(bool $refresh = false): array
{
    $url = $this->getOpenIdConfigUrl();

    if (! $refresh && ($this->openidConfig['url'] ?? null) === $url) {
        return $this->openidConfig['config'];
    }

    try {
        $response = $this->getHttpClient()->get($url);
        $config = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($config) || array_is_list($config)) {
            throw new UnexpectedValueException('The OIDC configuration response must be a JSON object with named fields.');
        }
    } catch (Throwable $exception) {
        throw new ConfigurationFetchingException(
            'Unable to get the OIDC configuration from ' . $url . ': ' . $exception->getMessage(),
            previous: $exception,
        );
    }

    $this->openidConfig = ['url' => $url, 'config' => $config];

    return $this->openidConfig['config'];
}
```

The implementation must derive the current URL before reuse, fetch/decode into a local, preserve the previous throwable, and publish URL plus config in one assignment. Reject the empty decoded object deliberately: `json_decode('{}', true)` produces `[]`, which is list-shaped and contains none of the required named metadata. A concurrent tenant switch can cause a later refetch but can never return configuration for the wrong URL. Forced JWKS refresh must still call `getJwksUri(true)` and therefore refresh discovery once the cooldown permits network work.

Add `src/socialite/src/Two/Concerns/InteractsWithJwks.php` and use it from generic OIDC, Google, and Facebook. The concern owns:

```php
/** @var null|array{url: string, keys: array, expiresAt: ?int} */
protected ?array $jwks = null;

/** @var null|array{url: string, attemptedAt: int} */
protected ?array $jwksRefreshAttempt = null;

protected int $jwksRefreshCooldownSeconds = 10;
```

Keep the concern's key-fetch/cache helpers private. The only per-provider override is `protected function getJwksUri(bool $refresh = false): string`: generic OIDC refreshes discovery when requested, while Google and Facebook return fixed URLs. The concern also owns one shared protected decode boundary:

```php
protected function decodeUsingJwks(#[SensitiveParameter] string $token): array
```

It fetches the parsed keys, calls `JWT::decode()`, and performs the single throttled retry after `SignatureInvalidException` or when the firebase/php-jwt message contains `"kid" invalid`. Do not retry the sibling `"kid" empty` failure: refreshing keys cannot repair a token with no key identifier. Generic OIDC, Google, and Facebook call the shared boundary once and retain only their own payload validation/mapping; Facebook keeps its no-`kid` fallback to ordinary access-token lookup. Replace `getGoogleJwks()`, `getPublicKeyOfOIDCToken()`, the old generic cache, and all provider-local retry catches without wrappers.

The fetch algorithm must:

1. derive the lookup URL with `getJwksUri(false)` before consulting the one-entry cache;
2. on an ordinary read, return matching keys while `expiresAt === null` or the current `time()` is before expiry;
3. on a forced read, return matching cached keys when the refresh-attempt entry matches the lookup URL and remains inside the cooldown, without calling `getJwksUri(true)` or performing any HTTP request;
4. otherwise stamp the lookup URL with one captured `time()` before refresh I/O, call `getJwksUri(true)`, and replace the stamp's URL with the refreshed URL before the JWKS request if discovery changed it;
5. fetch, throwing-decode, and `JWK::parseKeySet()` into locals, then atomically assign the complete refreshed URL/keys/expiry entry;
6. let `decodeUsingJwks()` retry JWT decoding once through the forced path.

Replacing the stamp URL aligns a successful newly discovered key set with later cooldown checks. Stamping before I/O also throttles a same-URL discovery or JWKS failure when matching cached keys remain. A cold failure, or a changed-URL failure with no matching keys, deliberately retries on the next login because the cooldown has no correct keys it can return; do not add a second failure-only timestamp or suppression path.

Parse every `Cache-Control` header value and every comma-separated directive case-insensitively. `no-cache` or `no-store` anywhere wins and makes the entry immediately stale. Accept `max-age=N` only when `N` is a non-negative decimal integer no greater than `PHP_INT_MAX - $now`, including zero-padded values allowed by HTTP's `1*DIGIT` grammar; when repeated valid values exist, use the smallest. Normalize leading zeroes locally before `FILTER_VALIDATE_INT`. Ignore malformed, negative, and out-of-range max-age values. If no usable directive remains, retain the entry without expiry, preserving generic OIDC's current cache-until-failure behavior. Deliberately ignore `Expires`.

Use `time()` for both expiry and cooldown. A wall-clock jump may shorten or extend reuse, but signature/kid failure still forces refresh; mixing `hrtime()` with protocol timestamps would add complexity without improving the contract. Headerless retention is safe only because forced refresh lands in the same change.

Facebook now decodes through the shared boundary, so unknown kids produce the library's authentication failure instead of a null dereference. Remove `phpseclib/phpseclib` from the Socialite split package and, because no other package uses it, remove the root direct dependency and update the lock through Composer.

### 6. Mark the complete secret-bearing call chain

Import `SensitiveParameter` and mark parameters on:

- manager provider construction/redirect formatting where the config contains `client_secret`;
- base and OAuth2 constructors/config setters for secret config and `clientSecret`;
- every public/protected built-in-provider frame accepting access tokens, refresh tokens, ID tokens, authorization codes, or token-response arrays;
- the four response parsers and `userInstance()` response input;
- `Two\Token` token/refresh-token constructor inputs;
- `Two\User` token, refresh-token, and complete-response setters.

Add one reflection regression that derives the built-in provider classes from `SocialiteManager`'s `create*Driver()` return types, includes the root/OAuth2/OIDC bases plus `Token` and `User`, and checks semantic parameter names and token-response method inputs rather than enumerating class/method/parameter tuples. It must automatically cover new first-party providers and their overrides. Treat `clientSecret`, access/refresh/ID token, authorization-code, secret-bearing provider config, and the response inputs to the four parsers, `getUserByTokenResponse()`, and `userInstance()` as sensitive. Do not mark user profile arrays, client IDs, state, nonce, scopes, or non-secret identifiers. Redacting a complete token response reduces stack-argument diagnostics, but Guzzle exceptions retain the HTTP response at the correct transport boundary; credential secrecy takes precedence.

### 7. Finish the first-party extension and documentation surface

Delete OAuth1-only `SocialiteManager::formatConfig()`, its facade line, and the guide paragraph. Delete dead `appendOIDCPayload()` and duplicate manager overrides. Regenerate the facade docblock from `SocialiteManager` and run the facade-documenter lint.

Update `src/socialite/README.md` using the package README convention:

1. package heading;
2. `Documentation: https://hypervel.org/docs/socialite`;
3. concise `Differences From Laravel` covering no OAuth1/legacy Twitter, the `x` driver, `buildOAuth2Provider`, dynamic config/request access, removed OAuth1-only `formatConfig`, trusted audiences, and session-dependent stateless limitations;
4. `Ported from: https://github.com/laravel/socialite`.

Update `src/boost/docs/socialite.md` in Laravel-docs prose:

- use `User::fake()` in testing examples;
- register custom providers and call `withConfig()` during provider boot;
- explain that `setConfig()` is coroutine-local and must be reapplied independently on redirect and callback requests;
- show OAuth2 parser hooks, `getUserByTokenResponse()`, `accessTokenResponseBody`, and `getRequest()` for ports that currently override `user()` or read `$this->request`;
- document generic `OpenIdProvider`, `trusted_audiences`, Bearer UserInfo, nonce/session behavior, and JWKS rotation without exposing internal cache mechanics;
- clarify Factory-only fake replacement and the PKCE/OIDC limits of `stateless()`.

Add concise source and matching test `REMOVED:` markers at the natural Laravel OAuth1/Twitter synchronization points. Do not document internal coroutine implementation as a Laravel difference.

Apply native `: void` to the existing Socialite test methods and type nullable Client fixture properties while editing those files; do not create a separate test abstraction.

### 8. Update durable audit records

After implementation and review:

- add one Socialite work-unit entry to the audit ledger with findings `socialite-01` through `socialite-27`, rejected concerns, implementation, validation, performance, and Laravel-facing result;
- add `support-34` to the completed Support entry;
- add `object-pool-04` to the completed Object Pool entry;
- add `reverb-40` to the completed Reverb entry;
- add dependency-index rows for those three cross-package findings and revalidate `support-02`;
- record `socialite-04` as a current Laravel Socialite GitLab defect and preserve the focused source/test delta needed for an upstream correction;
- mark Socialite complete and clear/update the active routing entry only after all gates, self-review, code review, owner checkpoint, and bookkeeping are complete.

## Regression plan

Run each changed test file immediately. The final focused coverage must include:

- `SocialiteManagerTest`: one custom creator visible through Factory and concrete manager; direct manager remains real after Factory facade fake; builder/config types; request refresh and missing-context failure;
- `AbstractProviderTest` / `OAuthTwoTest`: key `"0"`, boot/request config boundaries, recycled-object-ID isolation, parser matrices including zero-padded expiry, intentional missing-token failure, refresh-token retention, direct complete-response publication, whole-response user mapping, transactional user memoization, and strict token results;
- `SocialiteFakeTest`: current OAuth2 `User::fake()` defaults/overrides and enum routing;
- `BitbucketProviderTest`, `GitlabProviderTest`, and `OpenIdProviderTest`: exact Bearer request shapes with no token query;
- `LinkedInProviderTest`: missing `StillImage` on each searched size without warnings;
- `GoogleProviderIdTokenTest`, `FacebookProviderTest`, and `OpenIdProviderTest`: scalar/list/string-configured trusted audiences, issuer classes without Google's catch-all flattening, required OIDC ID-token failure, signature/kid rotation recovery, OIDC complete-response publication, and provider-specific JWKS use;
- generic OIDC discovery coverage: request-local base-URL changes never reuse another tenant's discovery document, refresh reaches the network, and malformed/failing data does not replace a valid entry;
- shared JWKS coverage: reuse before `max-age`, refetch after expiry, repeated/comma-separated directives, zero-padded and malformed max-age handling, immediate staleness for `no-cache` and `no-store`, indefinite headerless reuse plus failure refresh, exact-URL switching, same-URL failed-refresh cooldown with no discovery request, cold/changed-URL failure retry, refreshed discovery changing the JWKS URL, malformed response not published, local-before-atomic-assignment behavior, no refresh for a token without `kid`, and rotation recovery for generic OIDC, Google, and Facebook where each rotated key causes exactly one refetch and a successful second decode;
- OIDC tests: enabled and disabled nonce validation, nonce consumption, previous discovery failure, runtime exception taxonomy, and non-null user response;
- derived reflection coverage for every sensitive parameter, new provider overrides without inventory changes, and corrected native return contracts;
- `tests/Support/ManagerTest.php`: `setContainer()` refreshes config as well as container;
- Object Pool provider coverage: pool created through concrete manager is visible through Factory; interval set through concrete recycler is visible through Recycler;
- Reverb provider coverage: channel created through `ArrayChannelManager` is visible through `ChannelManager`, while an application binding before or after registration still wins;
- split/root dependency validation and facade-documenter lint.

Then run focused Socialite, Support, Object Pool, and Reverb suites; `composer validate --strict` and the Socialite split validation; documentation link/navigation checks; stale-symbol/import scans; and one authoritative `composer fix` checkpoint. After review amendments, rerun affected focused tests and repeat the full gate only when the changes can affect it.

## Performance, compatibility, and complexity gates

- Ordinary non-Socialite requests are unchanged.
- Provider construction pays one local integer increment; each instance caches the resulting namespace.
- Callback paths add only bounded array/string/type checks beside unavoidable network and JWT work.
- JWKS caching removes repeated Google/Facebook network calls and preserves generic OIDC headerless reuse; it retains exactly one key set and one refresh timestamp per cached provider.
- A throttled forced JWKS retry returns the matching cached keys before refreshed discovery, so the cooldown path performs no HTTP work.
- No lock, yield, timer, background job, framework cache, unbounded tenant map, LRU, registry, clone, container lookup, or new ordinary network round trip is added.
- `#[SensitiveParameter]` and native type metadata add no meaningful normal-path work.

Laravel-facing OAuth2 APIs remain unless a cleaner Hypervel design has an explicit owner gate. Before source implementation, obtain approval for: public raw context helpers becoming protected; the retained Request property becoming `getRequest()` context access; the three protected JWKS methods becoming the concern's `getJwksUri()` / `decodeUsingJwks()` surface; removal of documented OAuth1-only `formatConfig()`; and the additive `User::fake()`, parser/whole-response mapping, full-response, and `trusted_audiences` surfaces. These changes improve coroutine safety or first-party extensibility without compatibility shims. Exception-base changes affect Hypervel-original classes only. `Token::$expiresIn` widens safely to `?int`; supported named arguments and useful Laravel OAuth2 behavior remain intact.

## Explicit rejections

Do not add provider/event registries, a mutable config DTO/retriever, provider allowlists, OAuth1 compatibility, a shared mutable Guzzle client, provider/per-coroutine clones, UUID/WeakMap context identity, state/nonce rollback transactions, overlapping-flow registries, encrypted stateless transport, locks/singleflight, maps/LRU eviction, timers/background refresh, framework/PSR cache adapters, Firebase `CachedKeySet`, configurable token transport, a response pipeline, a new exception hierarchy, a creator-result guard, or dual fake swaps. Do not rewrite stateless container bindings for symmetry. Harmless duplicate cold JWKS fetches are accepted.

## Completion review

Freshly trace every changed caller/callee and same-family override after implementation. Confirm exact request/coroutine/worker ownership, nested and exceptional cleanup, cache identity/expiry/rotation, HTTP request shapes, JWT failure classes, facade generation, named arguments, ecosystem-port guidance, package metadata, the GitLab upstream handoff, and completed-package records. Search for stale `HasProviderContext`, `$this->request`, `OpenIdProvider::user()`, `formatConfig`, `appendOIDCPayload`, old JWKS helpers, Google's `Failed to verify Google JWT token` wrapper/import, phpseclib, query-token sites, broad `mixed`/nullable response types, false facade methods, and obsolete docs. Reject any new complexity without a demonstrated job and remove every superseded path before code review.
