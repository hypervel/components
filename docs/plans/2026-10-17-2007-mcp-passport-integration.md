# MCP–Passport integration

## Outcome and boundaries

Integrate MCP's OAuth support with Passport's consent, registration, discovery, resource indicators and authentication challenges. Applications must be able to use one Passport server for MCP and other APIs without conflicting routes, duplicated issuer configuration or weaker anonymous-registration policy.

The completed [Passport enhancement plan](2026-10-16-1620-passport-enhancements.md) and [MCP port plan](2026-10-16-mcp-port.md) describe their respective implementations. Leave those historical records unchanged. This plan owns the reconciliation described below; preserve their other supported behavior.

Use existing Passport services, models, caches and server selection. MCP continues to own protected-resource discovery and its specialized public-client registration policy. Do not introduce another OAuth server, registration framework, challenge parser, global resource registry or compatibility layer. Client ID Metadata Documents are not implemented or advertised by this integration; their addition requires a separate feature decision.

### Approved compatibility changes

| Surface | Final behavior |
| --- | --- |
| Client trust | Keep one `third_party` flag. Replace the unreleased `dynamically_registered` field and all its consumers. |
| Authorization-server discovery | Passport's `passport.metadata` route serves the authoritative document. Remove MCP's `mcp.oauth.authorization-server` and `.nested` routes and metadata builder. |
| Registration name | MCP's local registration route uses `passport.clients.register`, replacing `mcp.oauth.register`; its configured URL prefix remains supported. |
| Registration ownership | Applications choose MCP registration, native Passport registration, or their own endpoint. Enabling both built-in registration implementations fails clearly instead of silently replacing one. |
| Issuer | Local OAuth uses the exact selected Passport issuer, configured explicitly as HTTPS. `mcp.authorization_server` remains an explicit external-server override. Remove the request-URL fallback. |
| Anonymous scopes | MCP defaults to `mcp:use` plus Passport's configured anonymous allowances. Neither configuration nor ordinary model defaults may confer `*` or `passport:register`. |
| Wildcard callbacks | `mcp.redirect_domains = ['*']` allows HTTPS or HTTP loopback callbacks, not arbitrary remote HTTP callbacks. Explicit administrator allowances remain supported. |
| Optional Passport | Without Passport, omit local registration instead of exposing an endpoint that always fails. Explicit external-server discovery remains supported. |

Other Laravel public/protected signatures and named arguments remain intact. In particular, retain `Mcp::oauthRoutes(string $oauthPrefix = 'oauth', bool $registration = true)`, the MCP registration controller's existing policy methods, configured custom callback schemes, repository overrides and custom client connections. Any further API departure requires approval before implementation.

## 1. Shared ownership and server selection

| Concern | Source of truth |
| --- | --- |
| Local issuer, keys, protocol capabilities and current server | Passport's selected `ServerSettings` and `ServerFactory` |
| Token issuance, consent, validation, revocation and caches | Existing Passport services |
| Resource identifiers and audience validation | Passport's resource catalog and `CheckTokenAudience` |
| General anonymous-registration allowance | `passport.registration.scopes` |
| MCP registration policy | Existing MCP controller, redirect settings and `mcp:use` scope |
| External authorization server | Explicit `mcp.authorization_server` |
| Protected-resource metadata and its URL | MCP's existing protected-resource routes |

Resolve the local issuer when producing protected-resource metadata, not while registering routes. Capture only worker-safe services in callbacks, never selected settings, users, issuers, URLs or requests. The same worker must follow the current server selection across successive and concurrent requests. Applications supplying independent server settings continue to own their resource catalogs and client model policies; never change global configuration or append request-derived resources at runtime.

Explicit external authorization-server configuration does not require installing or resolving Passport. If that setting is absent, resolve the current Passport issuer when Passport is available. If neither source supplies an issuer, throw a clear `LogicException` explaining the required configuration. Do not silently use `app.url`, an incoming Host header or an invented default issuer. Preserve the issuer string exactly; discovery clients compare it with the authorization-server document.

## 2. Client trust and registration policy

### One trust field

Keep the existing `third_party` boolean column, default false, after the owner morphs in `oauth_clients`. Remove the branch-only `dynamically_registered` column, cast and property documentation. There is no migration for deployed data from that unreleased field.

`Client::firstParty()` returns true only when `owner_id === null` and `third_party` is false or absent. Both dynamic-registration endpoints set `third_party=true` independently of submitted metadata. Native `ClientMetadataValidator` treats it as a protected attribute that extension metadata cannot replace. Owner ID zero remains an owned client, not a first-party client.

Update native registration/model tests and docs together. Preserve the incoming first-party consent-policy integration test, adapting its obsolete `authRequest` session assertion to the current pending-authorization response. Consolidate overlapping custom model cases into one dataset, retaining missing/false/true flags and owned-client coverage.

### Keep MCP's specialized registration controller

MCP's registration policy deliberately differs from Passport's general endpoint: it creates public clients, accepts configured desktop schemes and loopback callbacks, applies redirect-domain allowances, supports the legacy name fallback, honors `ClientRepository` overrides, and persists optional metadata only when the custom model has those columns. Forcing it through the general validator would require multiple hooks and subclasses while changing established behavior. Preserve these responsibilities in `OAuthRegisterController` rather than adding those mechanisms.

Retain the transaction on the selected client model's connection. Registration fails and rolls back if an observer cancels any required save. Preserve cancellation identity and existing reporting of ordinary persistence failures. Keep setting and saving `third_party` at the controller's owning boundary, outside the overridable `grantMcpScope()` method; an existing scope-policy override must not accidentally omit trust classification. The separate saves are acceptable registration-only work inside the existing transaction.

Revise `grantMcpScope()`:

1. Refresh the saved client as today so database/model defaults are available.
2. If scopes are null, start from `passport.registration.scopes`; otherwise retain the application's existing scope array.
3. Add `mcp:use`, remove duplicates and exclude `*` and `passport:register`. Reindex the result with `array_values()` so the stored JSON remains a list after filtering.
4. Persist the resulting explicit ceiling, treating an observer-canceled save as registration failure.

Keep this policy overridable through the existing method. Raw model writes or an application replacing that policy remain deliberate application responsibilities; do not build interception machinery for them. Custom client schemas must include Passport's scopes column; update test fixtures that previously omitted it rather than adding schema-dependent production fallbacks.

Validate `passport.registration.scopes` at Passport startup: reserved wildcard/provisioning scopes are configuration errors. Remove the redundant reserved-scope intersection from the native anonymous-registration request path; its existing comparison against the validated configured ceiling still rejects requested privileged scopes. Keep its grant restrictions and authenticated provisioning behavior unchanged. MCP still excludes reserved scopes from its final list because model defaults are another source.

Return `client_name` from the saved client's `name` and space-separated `scope` from its stored ceiling, rather than always reporting only `mcp:use`. If an application overrides the scope policy and deliberately retains null (unrestricted), omit the response's `scope` instead of claiming a false ceiling. Preserve an explicit empty array as an empty scope string. Preserve client ID, effective grants, response types, redirects, `token_endpoint_auth_method=none`, and supported optional metadata. Add no-store to success and error responses produced by this controller, without adding a new global response middleware or changing unrelated exception rendering.

### Callback validation

Preserve existing fragment/userinfo rejection, exact redirect values, domain/path matching, lookalike-host rejection and explicit custom-scheme configuration. Tighten the wildcard branch so non-loopback HTTP is rejected unless an administrator explicitly allowed that callback domain. Do not let a wildcard shortcut skip the transport check. Keep HTTP loopback handling for `localhost`, `127.0.0.1` and `[::1]`, and existing configured desktop schemes such as `cursor://...`.

Explicit non-loopback HTTP allowances are an administrator-controlled exception, not the secure default. Document that distinction. Do not broaden Passport's separate general metadata validator to accept MCP-specific callback policy.

## 3. Routes and discovery

In `Registrar::oauthRoutes()`:

- Keep adding the MCP scope and the existing root/nested protected-resource metadata routes. Retain `hasGetRoute()` for preserving an application-owned exact protected-resource route.
- Remove both MCP authorization-server metadata routes and their now-unused `authorizationServerMetadata()` method. Passport's optional issuer-path route handles local authorization-server discovery with its existing issuer/path checks and ETag/cache policy.
- If registration is requested and Passport is installed, reject simultaneous native DCR opt-in with a clear `LogicException` directing the application to choose one implementation. Native opt-in already belongs in provider `register()`, before routes are built; do not add a boot-order coordinator.
- Register the existing MCP controller at the supplied prefix, named `passport.clients.register`, with `throttle:passport`. This gives Passport's existing metadata builder the selected registration endpoint without teaching Passport MCP route names.
- With `registration:false`, leave registration entirely to native Passport or an application route using `passport.clients.register`. With Passport absent, do not register the local endpoint or retain its unreachable request-time missing-Passport response.

Document the native alternative explicitly: enable Passport DCR before route registration, allow `mcp:use` in `passport.registration.scopes` when anonymous clients need it, and call `Mcp::oauthRoutes(registration: false)`. The general endpoint keeps its own authentication-method and metadata policy; selecting it must not silently inherit MCP's permissive desktop callback rules.

Protected-resource metadata uses the issuer selection in §1. Keep its resource identifier based on the requested protected-resource location and existing URL/proxy handling. Never replace it with the authorization-server issuer or client ID. External-server mode uses that external server's discovery document; it does not synthesize a local copy. Document `Mcp::oauthRoutes(registration: false)` for external-server deployments, including when Passport is installed for other application APIs, so they do not expose an unused local registration endpoint.

Preserve custom prefixes, route groups, domain parameters, standalone package installation and existing route-cache behavior. MCP still loads its route definitions for local server/client setup; resolver registration must not be placed behind a new routes-cached gate. Require normal deployment route-cache rebuilding after changing routes.

## 4. Authentication challenge composition

Currently Passport supplies `WWW-Authenticate` before MCP's response middleware runs. MCP preserves existing challenges, so it cannot add its protected-resource discovery URL. Fix composition where Passport constructs its own challenge; do not parse and rewrite arbitrary application headers or emit competing challenges for the same scheme.

Add boot-only `Passport::resolveResourceMetadataUsing(?Closure $callback): void`, with the callback typed in PHPDoc as `Closure(Request): ?string`. Store only that callback and clear it through existing `Passport::flushState()`. Use the package's established resolver conventions and worker-lifetime warning. No request attributes or new context state are needed.

`AuthenticationChallenge::header()` calls the resolver and incorporates the returned URL, when non-null, into its Bearer and enabled DPoP challenges. Escape quotes and backslashes as for scope parameters. Put `resource_metadata` first in the Bearer challenge so clients see a parameterized challenge before any optional second scheme. Retain current scheme-specific errors, DPoP algorithm advertisement and insufficient-scope details.

Defer header construction inside the existing `forGuard()` response callback until the request, status, exception and failed guard match. An unauthenticated guard lookup can occur on a successful optional-auth request or before another guard succeeds; registering that callback is not itself a failed response. Likewise, construct `forException()`'s header only after its response checks match. This keeps resolver and middleware inspection work off successful requests without adding state or another hook.

```http
WWW-Authenticate: Bearer resource_metadata="https://app.example.com/.well-known/oauth-protected-resource/mcp/weather", error="invalid_token"
```

Extract `AddWwwAuthenticateHeader::resourceMetadataUrl(Request): ?string` as an instance method sharing the existing route-presence, effective-middleware, exclusion and Sanctum-only checks. Have the existing non-Passport 401 fallback call it too. In `oauthRoutes()`, when Passport is installed, resolve the worker-safe middleware once and capture it in the Passport callback:

```php
$challenges = app(AddWwwAuthenticateHeader::class);

Passport::resolveResourceMetadataUsing(
    static fn (Request $request): ?string => $challenges->resourceMetadataUrl($request),
);
```

Read the request's actual route when the callback runs; authentication may be ordered ahead of route-level MCP middleware. Return null for unrelated routes, registration routes, excluded MCP middleware and Sanctum-only authentication. Preserve the existing non-Passport fallback and application-supplied challenges. Passport's authentication failures and scope failures gain discovery metadata through their existing 401/403 response hooks; do not add challenges indiscriminately to all application 403 responses.

All new resolution, route inspection and formatting occurs on failure responses. Successful API calls retain their existing guard, signature verification and cache behavior.

## 5. Resource audiences and consent

MCP clients already send the resource identifier during authorization, exchange and refresh. Passport now validates it against its catalog, so the documented setup must define that resource and enforce its audience. Reuse `Passport::tokensCanAccess()` or the selected server's `ServerSettings::$resources`; do not add a second catalog or infer authority from an incoming host.

For a single-server application, define the identifier once in application configuration:

```php
// config/services.php
'weather_mcp' => ['resource' => 'https://app.example.com/mcp/weather'],
```

Add it to the application's single catalog definition in `AppServiceProvider::boot()`, alongside all other API resources:

```php
use Hypervel\Passport\Passport;

Passport::tokensCanAccess([
    config()->string('services.weather_mcp.resource') => 'Weather MCP server',
]);
```

Read that same value when defining the MCP route in `routes/ai.php`:

```php
use App\Mcp\Servers\WeatherServer;
use Hypervel\Mcp\Facades\Mcp;
use Hypervel\Passport\Http\Middleware\CheckToken;
use Hypervel\Passport\Http\Middleware\CheckTokenAudience;

Mcp::oauthRoutes();

Mcp::web('/mcp/weather', WeatherServer::class)->middleware([
    'auth:passport',
    CheckTokenAudience::using(config()->string('services.weather_mcp.resource')),
    CheckToken::using('mcp:use'),
]);
```

Explain that `tokensCanAccess()` replaces the global catalog. Applications with multiple independent server contexts define their resources in the selected settings instead; an example must not append request-derived values to global state. Resource URLs must match the externally visible MCP location, including trusted proxy prefixes. Configure the local issuer once through Passport, for example `PASSPORT_ISSUER=https://app.example.com`; no matching MCP issuer setting is required. Local testing uses HTTPS for that authorization server too.

The scope middleware remains necessary: partial consent can retain an audience while granting no `mcp:use` scope. Audience enforcement adds an in-memory comparison using the already validated token, not another database read or signature verification.

Pass the saved, validated `redirectUri` alongside existing parameters to Passport's code-consent view. Use the pending entry's resolved callback so PAR, resume and omitted-redirect requests display the actual destination. MCP's view displays the escaped host for HTTP/HTTPS callbacks and the escaped complete URI for other schemes, so the scheme identifies the receiving application. Client names/logos are self-asserted and cannot replace this destination display.

Render requested resources alongside scopes. Remove stale hidden `state` and `client_id` fields; retain CSRF and `auth_token`, and keep using named approval/denial routes so the new consent URL is followed automatically. Preserve full approval by omission of subset fields; this work does not add another consent-selection UI.

Let the client callback own completion and window closing: remove consent-page close timers and URL polling, which can interrupt callback delivery. Disable both decision buttons after either form is submitted, retaining the approval loading indicator and native form submission. Guard against reintroducing the timers in the existing rendered-view test, without a separate JavaScript harness.

## 6. Regression tests and verification

Extend the tests that own each behavior. Keep applicable upstream coverage; adapt approved differences without weakening assertions. Consolidate custom duplicates. Use real models/HTTP boundaries where the regression depends on integration, rather than mocking Passport routes and missing the interaction again.

| Area | Required evidence |
| --- | --- |
| Trust | Missing/false third-party flag plus null owner remains first-party; true flag and owned clients do not. Add `third_party:false` to an existing MCP registration request and retain its persisted-true assertion; native metadata extensions cannot override it. The documented first-party skip policy still presents consent for external clients. |
| Registration | MCP scope defaults, configured additional scopes and model defaults yield explicit ceilings; reserved-scope filtering round-trips to the exact expected list on a freshly loaded model; returned scope/name match storage. A deliberate null-scopes override omits the response scope. Observer cancellations roll back trust/scope/metadata writes. Existing custom-model, connection, callback and repository cases remain valid. No-store covers success and protocol/persistence failures. |
| Native configuration | Reserved anonymous allowances fail startup. Retain endpoint rejection of privileged requested scopes/grants under valid config; remove the test's artificial post-startup invalid-config override. |
| Routes/discovery | Simultaneous native/MCP registration fails clearly. A real integration request serves Passport metadata with the MCP registration endpoint. Preserve registration:false with native/custom registration, custom prefix, explicit external AS without Passport, missing-issuer error and actual selected issuer including a path. No separate provider-order/cache-mode matrix unless implementation exposes a concrete dependency on it. |
| Redirect transport | Wildcard rejects non-loopback HTTP, permits HTTPS and HTTP loopback; explicit administrator HTTP allowance remains valid. Retain configured custom-scheme and lookalike/fragment tests. |
| Challenge composition | Real Passport failures with absent/invalid tokens include resource_metadata and retain errors/DPoP advertisement. Scope failure includes metadata and scope details. Non-MCP Passport, Sanctum, excluded middleware and explicit application challenges retain their behavior. Successful optional-auth guest requests and multi-guard requests must not invoke the resolver after an unsuccessful Passport guard lookup. |
| Complete MCP OAuth flow | Real public registration → consent → PKCE exchange with resource → authenticated MCP operation succeeds using actual provider wiring. A wrong-audience token fails. Exercise missing mcp:use through the actual scope middleware without duplicating every Passport grant case. |
| Consent display | Saved HTTP/HTTPS callback host, complete native callback URI and resource identifiers render escaped; valid approval/denial still works through named routes. Extend existing PAR/resume coverage to verify the new view parameter instead of creating another complete protocol matrix. |
| Selected-server isolation | Two sequential requests reuse the services with different selected settings and verify the correct issuer each time. This detects accidental issuer capture without another overlapping-coroutine or stress test. |

Update `tests/Mcp/Unit/Server/RegistrarTest.php` fixtures to use the current required client schema. New cross-package behavior belongs in a focused MCP feature test with real Passport wiring. Preserve existing native registration, consent, resource and challenge tests. Adapt upstream tests by the behavior they protect:

- Change the null-scopes registration case to assert the explicit ceiling; remove the obsolete no-scopes-column case.
- Adapt registration-disabled omission, custom registration prefix and custom registration-route advertising cases to real `passport.metadata` with a configured issuer and the canonical registration name.
- Remove assertions/tests specifically requiring MCP-owned AS documents, including arbitrary nested AS aliases and the MCP setting overriding the local issuer. Retain protected-resource assertions from tests that previously exercised both documents.
- Remove the obsolete existing-exact-AS-route test and missing-Passport registration response case. Retain exact protected-resource route preservation.
- Place concise `REMOVED:` explanations at omitted upstream test locations and the natural source insertion points, as required for intentional omissions; do not retain dead test bodies.

During implementation, run each changed test file immediately. At completion run formatting, full static analysis and focused affected tests sequentially; use ParaTest for MCP and Passport suites. Include affected HTTP destination and Request-facade regressions when verifying the incoming shared changes against Passport's consumers. Do not run the entire repository suite merely because the merge introduces many already reviewed MCP files; expand checks only for changes that actually affect the wider framework.

Trace the final caller/callee paths and check API compatibility, selected-server ownership, cache behavior, security, meaningful failures and unnecessary work. A successful authenticated request must not gain a new database lookup, remote fetch, cryptographic verification, shared cache or lock. Cold registration and error responses may perform the bounded work described above. Do not add timing-sensitive CI thresholds or speculative microbenchmarks.

## 7. Documentation and cleanup

Update canonical `src/docs/mcp.md` and `src/docs/passport.md` in their existing prose style. Cover one local issuer, explicit external override, selected registration endpoint, anonymous scope policy, resource/audience/scope setup, callback security and consent display. Keep examples internally consistent and distinguish MCP's public-client profile from Passport's general registration capabilities.

Document `redirectUri` in Passport's authorization-view parameter description and Inertia example as the saved, validated callback. Recommend displaying its HTTP/HTTPS host or complete native callback URI on third-party consent screens because client names and logos are self-asserted; custom views need this guidance as well as MCP's bundled view. Ported published MCP views must adopt the destination/resource display and remove window-closing timers.

Update the MCP and Passport README public-contract differences and `src/docs/porting-from-laravel.md` only for changes requiring developer action, linking to canonical explanations. Name the required nullable JSONB `scopes` column alongside the boolean `third_party` column for imported client schemas; MCP now persists its ceiling unconditionally. Remove obsolete AS-route names, unrestricted anonymous-scope claims, missing-Passport endpoint promises and request-derived issuer defaults from active source/docs/tests. Preserve incoming credits and unrelated valid changes. Do not alter completed historical plans to match later behavior.

Keep useful comments at trust, issuer and challenge ownership boundaries; do not narrate the merge or duplicate obvious code. No stale duplicate field, dead metadata builder, obsolete form fields or compatibility wrapper remains. No changes to instruction files are part of this work.

## Research and source anchors

- [RFC 8414 §3.3](https://www.rfc-editor.org/rfc/rfc8414.html#section-3.3): clients validate the issuer against the expected identifier; Passport already owns issuer-path discovery and actual capability advertisement.
- [RFC 9728 §§3.3 and 5.1](https://www.rfc-editor.org/rfc/rfc9728.html): protected-resource identity and resource_metadata in authentication challenges, including authentication schemes beyond Bearer. Resource discovery and authorization-server discovery have separate owners.
- [MCP authorization](https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization): protected-resource discovery, resource parameters/audience checks, HTTPS and callback/consent security. These requirements guide interoperability; route names are framework conventions, not protocol requirements.
- [RFC 7591 §§3.2.1 and 5](https://www.rfc-editor.org/rfc/rfc7591.html): effective registration metadata and security considerations for self-asserted client information. MCP's policy is a specialized registration profile, not a replacement authorization server.
- [RFC 8252 §7](https://www.rfc-editor.org/rfc/rfc8252.html#section-7): native redirects and loopback callbacks. Preserve explicit desktop integration allowances rather than silently applying the general web-client policy to them.
- Source boundaries: MCP `Server/Registrar`, `Server/Http/Controllers/OAuthRegisterController`, `Server/Middleware/AddWwwAuthenticateHeader`, `Client/OAuth/OAuthClient`, config and consent view; Passport `Client`, `ClientMetadataValidator`, `ClientRegistrationController`, `AuthorizationController`, `ServerMetadata`, `ServerMetadataController`, `AuthenticationChallenge`, `PassportServiceProvider`, `ResourceRepository`, `CheckTokenAudience` and `CheckToken`.
