# Passport consent and OAuth server enhancements

Status: consent, authorization events/interactions, response handling and provider inference are implemented. Next implement shared client authentication, registration, PAR and server endpoints, then resource indicators, DPoP and cross-feature verification. The deliberate route, authorization-hook, consent, token-claim and schema changes are approved. DPoP's additional verification and replay-store cost is approved for requests using that opt-in feature.

## 1. Purpose and boundaries

Make Passport a complete, extensible OAuth authorization server for ordinary applications and independently configured identity servers. Keep existing token creation, guards, scopes, models, client repositories and named routes familiar. Protocol features belong in Passport when they govern OAuth credentials or authorization; applications own login UI, consent UI, business policy and account administration.

Implement durable consent, partial approval, native account selection and reauthentication, query/form-post responses, public server metadata/JWKS, client assertions, token revocation/introspection, pushed authorization requests (PAR), dynamic client registration (DCR), resource indicators and opt-in DPoP. Keep implicit/password grants removed. Do not introduce an authorization framework beside League, another signing-key store, another token cache or a generic plugin pipeline.

Preserve the existing atomic issuance, refresh-family replay detection, writer reads for credential correctness, cache invalidation and worker key retention. New feature state belongs in the current operation or browser session, never request-derived singleton properties or global config mutations. Services remain worker-safe; mutable response/event instances are created per operation. Register mutable response bindings with fresh lifetimes, and extend static test cleanup only for genuinely static registrations.

### Compatibility changes

| Surface | Final behavior and reason |
|---|---|
| Authorization routes | GET and POST `/oauth/authorize` start authorization. POST/DELETE consent moves to `/oauth/authorize/consent`; existing route names remain. This separates protocol input from CSRF-protected user decisions. |
| Authorization callbacks | Replace `resolvePromptsUsing()` and `authorizationAttributesUsing()` with synchronous events described below. Multiple integrations can contribute without capturing and wrapping one another's callbacks. Remove superseded properties, resets, tests and documentation. |
| Remembered consent | Independent of active token expiry. Forgetting approval and revoking an authorization are explicit operations. |
| Consent pre-check | Protected `AuthorizationController::hasGrantedScopes()` gains optional `array $permissions = []`. Overrides must accept it; returning true cannot bypass the final locked consent check. Use `Client::skipsAuthorization()` for trusted automatic approval or a custom consent model's `covers()` for existing approval coverage. |
| Fresh authentication | `prompt=login` requests confirmation without logout. There is no configuration switch restoring logout-based reauthentication. |
| Prompt validation | Reject `none` combined with other requested prompt values. |
| Access-token claims | Always emit protected `client_id`; reserve `client_id` and `cnf`. `aud` remains the client ID when resources are absent. |
| Token responses | Report actual granted `scope`; DPoP-bound responses use `token_type=DPoP`. |
| Model provider inference | `HasApiTokens::getProviderName()` throws when multiple configured providers match, rather than silently issuing/listing credentials under the first one. Explicit overrides and unambiguous configurations retain their behavior. |

Preserve other Laravel signatures, named arguments and protected extension points. A custom League response implementation remains valid; the new Passport response base is recommended, not compulsory. No legacy access-token decoding fallback is needed for this unreleased package.

## 2. Durable consent

### Models and schema

Add root `Consent` and `ConsentRepository`, a factory, and `Passport::useConsentModel()`, `consentModel()` and `consent()` following existing model customization. Add `HasApiTokens::oauthConsents()` and `Client::consents()`. The user-facing name matches `oauthApps()` and avoids collisions with application privacy/marketing consent relations.

`oauth_consents` contains:

- UUID primary `id`; UUID `client_id`; non-null string `provider`; `user_id` with the existing token user-ID type.
- `jsonb('scopes')`, `jsonb('permissions')`, nullable `dateTime('remembered_until')`, and timestamps.
- Unique `(client_id, provider, user_id)` and an index supporting provider/user account listings. Applications replacing the model/migration include their ownership column in these indexes.

Keep `getApprovingProvider(): ?string`. Normalize null to `''` only at consent identity construction so SQL uniqueness works without forbidding providerless custom approving guards. Such guards can issue OAuth tokens for external resource servers; the stock local user guard still requires a configured provider. Do not invent a second guard-namespace registry.

Add nullable, indexed UUID `consent_id` to auth codes, device codes, access tokens, refresh tokens and refresh families. No credential-to-consent FK: revoked credentials and refresh replay evidence survive consent deletion. Consent and credentials share their connection; clients may retain their existing separate connection. PAT and machine grants have no consent.

Ownership follows the configured models/scopes. Settings revisions and key rotations do not change consent identity. Do not add a generic partition resolver or separate issuer comparison: selecting server settings alone does not partition credential ownership. Applications requiring independent ownership apply that boundary to their credential and consent models together.

Fix model-side provider inference used by `tokens()`, `createToken()` and `oauthConsents()`: gather distinct matching providers used by Passport guards and return the sole match. Preserve the existing no-match exception; multiple matches throw a clear `LogicException` directing the application to override `getProviderName()`. Multiple guards using one provider are not ambiguous. Do not infer a model's provider from whichever request guard happens to be active. Authorization flows continue using their actual approving provider.

### Remembering and partial approval

Replace `AuthorizationController::hasGrantedScopes()` token aggregation with one indexed consent lookup and in-memory coverage check, passing trusted permission sets through its optional fourth argument. Its docblock explains that this is a pre-check; the finalizer checks coverage again under the row lock. No persistent consent cache: this is an authorization-only query, and caching would complicate revocation races without improving the bearer hot path.

Expose `Passport::rememberConsentFor(DateTimeInterface|DateInterval|null $duration = null): ?DateInterval` following Passport's lifetime APIs, converting dates to intervals and using `func_num_args()` to distinguish a read from explicitly setting null. Setting is boot-only; the default null means indefinite and `PT0S` means never. `AuthorizationUserResolved::$rememberConsentFor` starts from this default and listeners may replace it per request. Save that trusted value with the pending authorization. Do not add a three-state override to `ServerSettings`.

The optional form field `remember` is boolean; missing uses the configured remember policy. False or an explicitly empty form value (including middleware-normalized null) records `remembered_until=now`; otherwise apply the trusted duration, with null meaning forever. Document a hidden false value before a remember checkbox so leaving it unchecked is explicit. Never accept a submitted duration. Expiry controls reuse of the approval, not validity of issued credentials.

Optional submitted `scopes[]` selects a subset of the saved request. An absent scopes key preserves existing Allow behavior; a present empty array, null or empty form value means approve none. Document the hidden empty `scopes` form input before the checkbox array so unchecking everything remains explicit, including after ConvertEmptyStringsToNull. Reject other scalar shapes and injected scopes/permission names. Device approval persists selected scopes as well as its user and attributes.

Consent permission data has one owning format:

```php
[
    'resources' => [
        'https://api.example.com/calendar' => ['calendar.read'],
    ],
    'extension.namespace' => ['permission-name'],
]
```

Flat `scopes` covers requests without resources. With resources, every target's stored scope set must cover that request's scopes. Extension namespaces contain string sets that Passport compares without interpreting. Core owns `resources`; extensions cannot overwrite core permission fields. The view receives the presented scopes/permissions, and approval is validated against that saved presentation.

For each affected scope/permission set, merge under the row lock:

```text
next = (previous - (presented - approved)) union approved
```

This preserves unrelated approvals while honoring explicit deselection. Do not independently union resource and scope lists: approving write/A and read/B must not produce write/B. Credentials capture their final scopes/resources; extensions capture their own final namespace decisions in grant attributes through `AuthorizationApproved`. Later consent changes do not rewrite those credentials. A newly remembered decision does not revive permissions from an expired/forgotten approval that were not presented: start remembered coverage from empty when the previous row is no longer remembered. The latest explicit remember choice applies to the resulting row.

Remembered reuse never extends `remembered_until`. First-party `skipsAuthorization()` remains a trusted application policy: record its grant linkage but do not turn it into user-remembered consent. A new row created by a skip has `remembered_until=now`; an existing row's remember state and user-approved coverage are not widened by an automatic skip. Device approval continues to show its confirmation screen.

### Forgetting, revocation and races

`Consent::forget()` expires remembered approval without revoking credentials. `Consent::revoke()` revokes the complete associated authorization and deletes the row. Instance `delete()` follows that revocation path. Mass-query deletes/raw writes remain documented low-level bypasses, consistent with Eloquent; do not add interception machinery for them.

Revocation uses one credential transaction and this lock order:

1. Lock consent.
2. Revoke linked auth codes, then device codes.
3. Mark linked refresh families revoked; revoke refresh tokens.
4. Read access-token IDs with locking, bounded queries; revoke tokens and invalidate their caches/dispatch `AccessTokenRevoked` after commit.
5. Delete consent. A new authorization gets a new UUID.

Reuse the family-revocation token batching through a shared owning helper, preserving bounded memory and existing cache failure semantics. No queue for correctness-critical revocation. Use current reads (`UPDATE` and `lockForUpdate`) throughout: a plain MySQL REPEATABLE READ snapshot taken before waiting for exchange may miss the newly committed access token.

Initial grant attachment—explicit approval, remembered reuse, first-party skip and device approval—locks consent within issuance and rechecks coverage/expiry. For creation paths, probe on the writer before beginning the issuance transaction, then lock a found row or use locking `createOrFirst` on a miss. This avoids InnoDB missing-row gap locks and preserves savepoint cleanup of duplicate-key locks. The probe is only a hint; approval uses the locked result. Remembered reuse needs only the locking lookup. If remembered consent disappeared, re-prompt; if explicit approval finds it gone, create a new row from that approval. Reject observer-canceled creation or remember writes before issuing credentials. Never issue against a deleted consent ID.

Exchange/refresh performs no new consent lookup. Code/device claims already hold the grant row lock through commit; refresh already locks its family. Revocation either waits for issuance and then revokes its products, or wins and makes the exchange fail. Copy `consent_id` as a protected internal attribute alongside provider, including explicit family assignment; family creation currently copies only application-configured inherited attributes.

## 3. Authorization interactions and responses

### Events and pending authorization

Add `Events/AuthorizationRequestValidated` for code authorization after League validates client/redirect/state, before login. It carries the HTTP request, authoritative normalized authorization parameters, validated League authorization request and mutable additional prompts. Validate the client's supplied prompt combination separately: an extension requiring login during `prompt=none` causes `login_required`, not a malformed-request error.

Add `Events/AuthorizationUserResolved` for code and device authorization after authentication and before consent decisions. It carries request, authoritative normalized authorization parameters, user, actual approving provider/guard, client, scopes, the validated authorization/device entity, mutable `attributes`, namespaced `permissions`, and `rememberConsentFor`. These are synchronous security-policy events; queued listeners cannot participate. Guard dispatch with `hasListeners()`. Write protected provider/consent attributes after extension contributions; never accept their values from form input. Policy listeners read the normalized parameters, so PAR and resume requests cannot replace them with browser form input.

Add `Events/AuthorizationApproved` to the shared finalization path for explicit approval, remembered reuse, first-party skip and device approval. It carries the HTTP request, authoritative parameters, actual user/provider/guard/client, grant type, read-only final scopes/permissions and mutable extension attributes. Lock or create consent through `ConsentRepository`, which starts the issuance transaction after its writer probe as described in §2, and recheck coverage before dispatching once ahead of League completion. Apply protected provider/consent/resource/scope fields after listener contributions, then complete code issuance or device approval. Do not dispatch from repository persistence methods, which may be retried for identifier collisions. Guard dispatch and event construction with `hasListeners()`.

Expose read-only `AuthorizationApproved::$approval` using `Enums/AuthorizationApproval` with cases `Prompt`, `Remembered` and `Policy`. The finalizer sets it from the actual decision path: submitted consent, remembered coverage, or the trusted `skipsAuthorization()` policy respectively. Unless prompt=consent forces interaction, evaluate `skipsAuthorization()` before remembered coverage, as today, so `Policy` wins when both apply. Device approval is always `Prompt`. This is operation context, not another database field or browser-supplied value. Extensions can distinguish explicit approval from remembered reuse without repeating Passport's policy checks.

Approval listeners run while consent is locked: perform local checks and writes on the credential connection, not HTTP or remote-document fetching. Failure rolls back consent, extension writes and credential issuance together. Side effects needed only after successful commit use normal after-commit/queued mechanisms. The earlier validation/user-resolution events remain outside this finalization transaction.

Listeners reject protocol requests by throwing a League `OAuthServerException`; they need not construct redirect details. The outer authorization boundary catches both League exceptions and Passport's `OAuthServerException`, extracting the wrapped League exception with `getPrevious()` for the latter. After rollback, render with the already-validated redirect URI, state and response mode rather than using the wrapper's eagerly built response. Keep loginRequired()/consentRequired() signatures and their standalone query-response behavior. This also gives prompt=none errors the requested form-post transport. For League rejection before an authorization object exists, the resolver revalidates the active client and callback only on failure. Errors redirect only to that validated destination; malformed raw parameters, invalid clients and unvalidated callbacks remain local. Absent or unsupported response modes fall back to query. Device approval keeps its device error response; other throwables propagate after rollback.

Temporarily correct League's authorization dispatch error at the existing bridge boundary: translate only `unsupported_grant_type` to `unsupported_response_type`, or `invalid_request` for a missing client ID. Preserve other errors and token-endpoint behavior. Link [League #1216](https://github.com/thephpleague/oauth2-server/issues/1216) in an `@TODO` requiring removal when the supported dependency contains the fix. Keep the public authorization regression enabled through removal; no skipped duplicate dependency test or `docs/todo.md` entry.

Retain pending authorizations in a bounded browser-session map keyed by unguessable one-time decision tokens. Store compact JSON-compatible normalized parameters and identifiers, the presented permission sets, trusted extension attributes, response mode and remember policy; no serialized League entities or user/client models. Preserve calendar/relative remember durations and apply them at approval time. Keep original parameters unchanged for listeners; capture the resolved redirect URI and scope IDs separately. Once permissions and remember policy have been presented, keep them fixed when reopening that interaction, while rerunning policy checks and capturing current attributes such as fresh authentication evidence. Changing defaults must not let an older form approve more than it presented. Default to ten pending flows with a ten-minute lifetime; prune expired entries and evict the oldest at the limit. Completion, denial and rendered authorization errors consume only their own entry, including resolver failures; ordinary login/application redirects retain it. Resume URLs and forms identify that entry, including after PAR consumption. Revalidate with the saved resolved redirect URI and scope IDs, then restore an originally omitted redirect URI and originally empty scope set; device decisions reload their saved device-code identifier. Preserve League's treatment of empty redirect parameters as omitted. Recheck current user/provider, client grant permission and presented scopes on approval. Revalidate protocol interaction conditions on resume rather than treating a redirect as proof of authentication. Remove the superseded entity serialization and unserialize allowlists.

Use existing `Route::block()` on browser routes that read/write pending state: authorization GET, resume, consent approve/deny, account continue/switch and device authorize/approve/deny. One-time decisions/challenges and concurrent-tab safety require server-side session storage plus a shared lock-capable block store. Document the existing cookie-session limitation: older client-carried snapshots can replay pending entries, and parallel tabs can lose entries; a lock cannot refresh an incoming cookie. Do not add a new runtime ban on that driver or another pending-request store. Supported concurrency tests use server-side sessions. No session locks are added to token or API authentication requests.

### Login, confirmation and account choice

Guests use the existing login redirection. A synchronous Login listener confirms unbound pending login interactions for the event's guard and binds their user/provider only after explicit authentication; remember-cookie restoration never satisfies `prompt=login`. An already-authenticated user's confirmation challenge retains the `Store::passwordConfirmed($guard)` contract. `Contracts/ReauthenticationResponse` defaults to the normal `password.confirm` route with an intended return URL; apps may bind passkey, MFA or other confirmation responses. Both Fortify and Passkeys already write the guard confirmation value. Never log out or invalidate the session just to reauthenticate. Recommend the default SameSite=Lax session policy for cross-site authorization: Strict can withhold the session through the redirect chain and lose the pending interaction.

The default confirmation response uses the framework's single intended-return slot; simultaneous confirmations can return to the latest pending interaction. Each interaction remains independently resumable by its own URL and rechecks its identity/challenge. Do not introduce a parallel application redirect manager.

At challenge start clear only that guard's prior confirmation, bind the challenge to the pending authorization/user/guard, and consume it once after a new confirmation. Timestamps use whole seconds, so a strict “greater than start” check alone is incorrect. Clearing the old value permits legitimate same-second confirmation without accepting an earlier confirmation. A changed user cannot approve the old user's request. Document that starting confirmation also expires the application's previous password-confirmation window for that guard.

Add `Contracts/AccountSelectionViewResponse` and `Passport::accountSelectionView()`, using existing view-response conventions. Register account selection explicitly; preserve `viewPrefix()`'s existing view names and return `account_selection_required` before login or confirmation when no selection response is configured. Account-selection and confirmation responses receive the validated client alongside their interaction parameters. Validate client/provider compatibility before interaction errors, login or confirmation. The screen offers continuing as the current user or selecting another account. Continue records a one-use choice bound to the pending authorization and its current user; choosing another account performs real logout/login and discards the previous user's captured attributes, permissions and remember policy. Keep this distinct from reauthentication. Use CSRF-protected interaction submissions and FrameGuard. This is not a multi-account session manager.

Fix `SessionGuard::login()` to clear stale `viaRemember` before emitting explicit-login events. Remember-cookie restoration continues to report recall. Extensions can distinguish fresh authentication without reading stale request-local guard state.

### HTTP boundary

Register protocol POST `/authorize` outside `web`: accept form input and send a stateless 303 to GET without starting/replacing the browser session. This preserves an existing SameSite=Lax cookie on cross-site POST. Preserve repeated `resource` values; reject duplicate scalar protocol parameters. Bound request input; use PAR for large/sensitive requests. Consent POST/DELETE retains web/auth/CSRF middleware at its new URL.

Add one authorization response renderer for query and `form_post`, shared by success and redirectable errors. Both immediate and resumed completion render to the saved, validated destination with the exact original state from the authoritative parameters, including whitespace; preserve existing callback query parameters. Extract only the generated code from League's completed response without rebuilding or signing it twice. Code-authorization denial revalidates and consumes the pending request, then renders `access_denied` directly; it does not start token issuance. Device denial still persists its decision. Store validated response mode with the pending request. Never redirect an invalid client/redirect URI error. Form-post output escapes fields, uses no-store and a restrictive CSP with an explicit submit target; provide a manual submit fallback.

Add `Bridge/BearerTokenResponse` as Passport's default League response subclass. It reports final scope and exposes protected accessors for the saved access-token model, redeemed grant and pinned signing key. Keep these backed by the current `TokenIssuance`, not retained on the worker's response prototype. Extensions hash the memoized access-token string when needed. Non-default response types remain responsible for the protocols they implement.

### Route and configuration names

Paths below are relative to `passport.path` (default `oauth`), except the well-known metadata route. Preserve the existing token/device routes and names.

| Method / path | Route name |
|---|---|
| GET `/authorize` | `passport.authorizations.authorize` |
| POST `/authorize` | `passport.authorizations.store` |
| GET `/authorize/resume/{authorization}` | `passport.authorizations.resume` |
| POST / DELETE `/authorize/consent` | `passport.authorizations.approve` / `passport.authorizations.deny` |
| POST `/authorize/account/continue` | `passport.authorizations.account.continue` |
| POST `/authorize/account/switch` | `passport.authorizations.account.switch` |
| GET `/jwks` | `passport.jwks` |
| GET `/.well-known/oauth-authorization-server{issuer-path}` | `passport.metadata` |
| POST `/register` / `/par` | `passport.clients.register` / `passport.par` |
| POST `/revoke` / `/introspect` | `passport.token.revoke` / `passport.token.introspect` |

Use `Passport::enableDynamicClientRegistration()` as the boot-time registration opt-in. Config names are `passport.par.lifetime` (90 seconds), `passport.authorization.lifetime` (600 seconds), `passport.authorization.max_pending` (10), `passport.registration.scopes` (explicit anonymous-client scope ceiling, default empty), and `passport.dpop.enabled` (false). `Passport::dpopTokensExpireIn(DateTimeInterface|DateInterval|null $date = null): DateInterval` follows existing lifetime getter/setters, defaulting to five minutes. Document the shared replay-cache store selection as `passport.replay.store`; it must provide atomic, shared additions retained for the full acceptance window.

## 4. Client authentication and registration

### One client-authentication service

Add `ClientAuthenticator` accepting the actual PSR request and endpoint purpose. Validate client authentication once per operation, enforce the registered method and reject conflicting credentials. Return the authenticated client/entity and retain the result only in the operation. Preserve authentication-failed events and grant-type checks.

Supported methods are `none`, `client_secret_basic`, `client_secret_post` and `private_key_jwt`. Existing repository-created secret clients retain basic/post compatibility when no explicit method is registered; explicitly registered clients accept only their method. Existing public clients retain `none`; a JWT-authenticated client is confidential even without a stored secret. Update `Client::confidential()` and bridge construction accordingly without changing their signatures.

League's `AbstractGrant::validateClient()` requires a secret for confidential clients. Use its protected override point in Passport bridge grants, sharing a small concern which delegates to `ClientAuthenticator` and preserves `getClientEntityOrFail()` grant checks. Add thin refresh/client-credentials bridge subclasses as necessary; do not inject fake secrets, change the PSR body to trick League, or copy complete grant implementations. The internal personal-access grant remains an internal flow.

Add client columns for `token_endpoint_auth_method`, registered assertion algorithm, `jwks`, `jwks_uri`, DPoP requirement and `application_type` (default `web`, alternatively `native`). Use JSONB for key sets and semantic types. Validate mutually exclusive inline/remote key declarations and supported algorithm/key combinations; registered JWKS contain public keys only, never private key material. Existing SHA-256 secret storage remains; do not advertise `client_secret_jwt`.

For `private_key_jwt`, validate signature, algorithm, client identity in iss/sub, exp/iat/nbf and a bounded assertion lifetime; accept the configured issuer or canonical token endpoint as audience, also at PAR/revocation/introspection. No host-derived aliases. Use atomic shared-cache `add` for client-scoped jti replay until the full accepted expiry, including leeway. Never mutate Firebase's static timestamp/leeway per request.

One `ReplayCache` service owns replay-store validation and atomic consumption for assertions and DPoP. Accept direct RedisStore or DatabaseStore backends; reject local/file/null/session stores, stack and failover wrappers. StackStore has no atomic add, and failover can switch to a different replay history. Resolve/validate the configured store when this service is used; ordinary bearer requests do not pay this cost. Fail closed on storage errors. For DatabaseStore, reject consumption inside an active transaction on that store's connection so rollback cannot erase a consumed marker. A dedicated cache connection is recommended when applications wrap requests in transactions, not separately enforced. Document the per-proof database write and recommend Redis for high volume.

Replay entries must survive their entire acceptance window. Require Redis `noeviction`; both volatile-* and allkeys-* policies can discard replay evidence. This is a server/instance policy, so choosing a separate logical Redis database alone is insufficient. Do not reset/flush the security store during accepted token/proof lifetimes; operational recovery must preserve replay history or stop accepting affected credentials until their window expires.

Add public `RemoteDocumentFetcher::fetch(string $url): array` using the existing HTTP client for bounded JSON documents. It enforces HTTPS, destination policy, timeouts, streamed size limits and response cleanup, including redirects. `PublicDestinationPolicy` alone permits HTTP, so enforce HTTPS explicitly. Remote JWKS and extension metadata documents share this small service; their owning validators check JSON shape. Cache remote JWKS by client/key-metadata revision with a finite TTL; unknown-kid refresh is bounded and coordinated, not one unbounded network request per bad assertion. No new outbound HTTP stack.

### Dynamic registration

Add disabled-by-default `POST /oauth/register`, named `passport.clients.register`, using the Passport throttle and a shared `ClientMetadataValidator` also usable by application management code. Dispatch `Events/ClientMetadataValidated` after core validation on both create and update, guarded by `hasListeners()`. Carry read-only submitted/core-validated metadata, optional existing client and authenticated provisioning token, plus one mutable array of validated extension metadata keyed by metadata name. Synchronous listeners validate their fields or reject provisioning. Core and privileged internal fields remain protected; unknown submitted members are ignored unless their extension validates them. Persist validated extension keys as matching client attributes. Direct model writes bypass this validator and remain the application's responsibility.

The registration response includes all effective registered metadata, including defaults and validated extension fields, rather than echoing input or returning an unrestricted model array. Return a newly generated secret once, never its stored digest or other internal fields. Add `Exceptions/InvalidClientMetadataException` with `invalidClientMetadata(string $description)` and `invalidRedirectUri(string $description)` factories. Core validation and listeners throw it; the endpoint renders the RFC7591 400 JSON error, while console/admin callers can catch the same exception without constructing an HTTP response.

Open registration can create authorization-code/refresh clients using any supported authentication method. Omitted `token_endpoint_auth_method` defaults to `client_secret_basic`, as RFC7591 requires. It cannot provision client-credentials authority. Set an explicit server-approved scope ceiling; omitted Client.scopes currently grants all scopes. Exclude reserved `passport:register`, wildcard scopes, owner/provider fields, first-party consent bypass and equivalent privileges from anonymous provisioning.

Authenticated registration requires a real Bearer/DPoP token explicitly containing `passport:register`; browser cookies and wildcard-only scope grants do not confer this authority. Such a token can provision enabled public grants, including client credentials; internal personal-access grants remain excluded. Associate a user-owned registration with its authenticated owner; a machine-issued registration is unowned. Additional business restrictions use `ClientMetadataValidated`, rather than a second policy hook. Validate token audience where resource restrictions apply, using the canonical registration endpoint as the resource identifier.

Validate exact redirect URIs using web/native-client rules (HTTPS, loopback HTTP exceptions, reverse-domain private-use schemes); no wildcards. Explicitly registered native metadata is required for native exceptions. Generate and return a secret once whenever the selected registration method requires it; persist only its normal digest. RFC7592 registration-management credentials/endpoints are not part of this design: applications manage clients through ordinary owner relations/repositories and their own authorized admin API.

## 5. PAR, resources, revocation and introspection

### Pushed authorization requests

Add `POST /oauth/par` (`passport.par`) with the shared authenticator and validation rules. Return an unguessable standard request_uri and expires_in. Store its digest, client_id, normalized parameters and indexed expiry in configurable `PushedAuthorizationRequest` storage on the credential connection; use a replaceable Eloquent model and default 90-second lifetime. Add purge support to the existing maintenance command.

Authorization accepts request_uri plus matching client identity, ignores other front-channel authorization parameters, checks owner/expiry, and atomically claims the record once. The pushed parameters are authoritative. Preserve the validated request in its keyed pending browser authorization so login/confirmation resumes the same flow without redeeming PAR again or disclosing parameters in a URL. Another browser/session cannot replay the URI. Raw GET/form requests and pushed requests share validation and response rendering. Preserve selected server context; no global pending-request state.

### Resource indicators

Expose a boot-time resource catalog plus `ServerSettings` override, mapping absolute URI identifiers to descriptions. Reject unknown/malformed targets with invalid_target. Keep this separate from scopes; an app can apply additional resource/client policy. Normalize repeated resource parameters from raw query/form input at the owning OAuth boundary, including PAR and device requests.

Persist original authorized `resources` on auth codes, device codes and refresh tokens; persist selected `audience` on access tokens. The family has no unused resources copy. Exchange may select a subset for its access token while the refresh token retains the original authorized set for later resource selection. Requests cannot add resources beyond the grant; a refresh token's stored scope limits remain effective. Keep these protected protocol fields out of generic inherited attributes: repositories explicitly persist their owning fields from issuance state/the redeemed grant. Renaming columns alone is insufficient because the current repositories spread generic attributes into several different models. Keep ordinary extension-attribute inheritance unchanged.

Emit resource audiences when supplied, otherwise keep aud=client ID. Always emit a separate client_id, and change `BearerTokenValidator` to derive oauth_client_id from it instead of aud[0]. Expose validated audiences to `CheckTokenAudience`, which rejects cookie/transient credentials and requires the configured target. It adds no DB lookup. External resource servers must likewise enforce audience. Introspection returns aud and client_id separately.

### Revocation and introspection

Add `POST /oauth/revoke` and `/oauth/introspect` using the route names above, throttling and shared client authentication. Neither uses browser session authentication. Reuse one owning JWT validation/credential lookup path; introspection of DPoP-bound tokens reports their binding without requiring a resource-request DPoP proof.

Revocation authenticates the caller and checks credential ownership. Invalid, expired or already-revoked tokens produce the RFC7009 success response without disclosing other clients' credentials. Refresh revocation revokes its family and linked access tokens; access-token revocation invalidates that token. Consent-level revocation remains the explicit whole-authorization operation. Decode encrypted refresh credentials through the same encryption format/key as issuance, with no alternate token store.

Introspection returns active=false for invalid/inaccessible credentials. For active tokens return authorized metadata only: client, subject when present, scope, type, audience, issuer and times; include cnf for DPoP. Default access is the issuing client. Add `Passport::authorizeIntrospectionUsing()` for resource-server clients that need to inspect another client's token, preserving tenant/model ownership policy before application permission. Do not expose secrets, browser state or refresh payloads. Responses are no-store.

## 6. DPoP

Provide opt-in DPoP support through `passport.dpop.enabled`; ordinary bearer requests incur no proof-signature verification or replay-cache write. Require a shared atomic replay store when enabled. Support and advertise RS256 and ES256. Firebase's PS256 implementation requires phpseclib, so it is outside this dependency profile. Client metadata can require DPoP. A malformed supplied proof is rejected, never ignored as a fallback to bearer issuance.

`DpopProofValidator` validates typ, public embedded JWK, algorithm/signature, htm, externally visible trusted htu (excluding query/fragment), iat window and jti. Do not fetch proof keys from arbitrary headers/URLs. Bound input sizes; cache keys include proof-key thumbprint, method, URL and hashed jti. Insert atomically after validation and retain through the whole acceptance window. Cache failure fails authentication. Request-local validation reuse prevents double consumption within one request, not replay across requests.

Token issuance adds protected cnf.jkt and uses token_type=DPoP. Persist the binding on the refresh family; refresh cannot strip or replace an existing binding. Bind public-client refresh credentials whenever issued with DPoP. Honor dpop_jkt on authorization; PAR supports both dpop_jkt and a DPoP proof, checking equality when both occur. This is required composition, not a separate authentication method.

All TokenGuard entry points share DPoP-aware credential extraction. Resource requests require the proof key to match cnf, and validate ath as well as method/URL/replay. Reject a bound token presented as ordinary Bearer and do not fall back to a browser cookie after a failed supplied credential. Reuse the existing access-token signature verification and token/client cache.

Preserve BearerRequestFactory's header-only fast path for ordinary bearer tokens. For DPoP it must carry the proof and trusted external method/URL, which the current empty-URI request intentionally omits. Extend ServerFactory's request-validation fingerprint to cover those DPoP inputs; it currently hashes Authorization alone. Within one request, repeated guard/middleware validation shares the validated proof instead of consuming replay protection twice, including the guard's uncached credential-validation entry point. A changed proof/method/URL cannot reuse an earlier result.

No server nonces in this profile. Apply the `dpopTokensExpireIn()` cap and take the shorter of it and the ordinary grant lifetime. Apply it to the operation's token entity before persistence/signing, not by mutating the worker-shared grant TTL. Short tokens limit exposure to pre-generated proofs; iat/jti alone does not prevent their pre-generation. Keep ordinary bearer TTLs unchanged. Document the real extra signature/cache operation and benchmark it separately from ordinary bearer authentication.

## 7. Metadata, keys and extension integration

Add public JWKS and an RFC8414 metadata builder/controller. Use `ServerFactory::jwks()` and selected immutable settings; do not parse keys again or retain another unbounded server map. Resolve endpoint URLs through named routes and the configured prefix, including proxies and path parameters. Require a configured canonical issuer to publish metadata; never derive issuer identity from an untrusted Host header.

RFC8414 uses `/.well-known/oauth-authorization-server` with the issuer path appended after that well-known segment. This differs from OpenID discovery's issuer-path placement. Keep discovery routing separate from passport.path; document application-owned routes for custom issuer layouts. Publish standardized metadata for capabilities actually enabled, authentication methods/algorithms, scopes, DPoP, registration and PAR. Document the resource catalog in feature documentation rather than inventing a resource-support discovery flag. Do not claim unsupported profiles such as FAPI or JAR.

JWKS/discovery cache lifetimes must fit signing-key publication delay after accounting for server-settings staleness. Include max-age and ETag only with a safe budget; default conservatively for custom resolvers whose staleness isn't known. Normal key rotation keeps existing retention/publication behavior. Token-response extensions use `ServerFactory::signingKey($lifetime)` / issuance retention for their own signed artifacts.

## 8. Verification and documentation

Implement coherent groups: authorization/events/consent; authentication/metadata/endpoints; resources/DPoP; final cross-feature verification. Keep tightly coupled issuance changes together. Follow repository checks in order: formatting, static analysis, focused tests, then a fresh self-review. Run new/changed test files immediately during implementation. Use the full suite only when shared framework changes justify it.

Fix shared transaction cleanup: a failed savepoint rollback must disconnect and clear the unusable transaction without replacing the original error, preserving cancellation. Only a transaction entered at the outermost level may retry, so nested issuance cannot replay part of an application's transaction. Cover real deadlocks, rollback cleanup and callback ownership in the database tests, with one nested consent-approval integration case.

| Area | Meaningful coverage |
|---|---|
| Consent | Partial code/device selection, omitted versus explicit empty selection, injected scopes/permissions, expiry/forget/revoke, expired remembered coverage, skip paths, providerless identity, no resurrection, rollback/cache/event behavior. |
| Provider inference | Multiple providers sharing one model fail clearly; multiple guards sharing one provider remain valid; explicit overrides issue/list the selected provider's credentials and consents. |
| Extension finalization | Final selected scopes/permissions and actual approval source reach listeners; trusted skip wins over existing remembered coverage unless prompt=consent forces interaction; protected fields cannot be overwritten; listener rejection preserves redirect/state/query or form-post transport and rolls back consent, extension writes and grants. |
| Races | Revoke versus exchange on MySQL and PostgreSQL; remembered reuse/revoke, explicit approval/deletion, refresh/revoke and concurrent approvals on a real integration database. Coordinate operations; do not rely on sleeps alone. |
| Interactions | Same-second fresh confirmation, stale confirmation, changed user/guard, guest login, explicit one-use account choice, simultaneous pending tabs and expiry/limits, prompt none conflicts, real logout versus reauth, remember restoration followed by explicit login. |
| HTTP | Cross-site POST preserves session cookie, consent remains CSRF-protected, valid query/form-post errors including prompt=none login_required/consent_required, invalid redirects, path prefixes/proxies, repeated resource parsing and PAR resume/replay/expiry. Reconstructed requests preserve redirect omission through exchange, explicit redirect requirements, PKCE/state and empty scopes despite changed defaults; prepending a registered callback cannot change the saved destination, removal rejects it, and existing callback query parameters survive either response mode. Reject form overrides; concurrent session decisions cannot reuse one entry. |
| Client auth/DCR | Methods enforced across grants/endpoints, invalid algorithm/key/aud/expiry, concurrent assertion replay, bounded key refresh, open registration cannot obtain privileged scopes or machine grants, metadata and native redirects; create/update extension validation, response defaults/extension output and protocol errors without internal-field leakage. |
| Resource/DPoP | Client identity distinct from audience; A/B grant selection across refresh; consent does not invent cross-resource rights; proof replay/mismatch/expiry/method/URL/ath, PAR binding, refresh binding, no cookie downgrade. |
| Replay storage | Concurrent consumption permits one winner; local/stack/failover stores are rejected; database transaction rollback cannot erase an accepted claim; backend failure denies authentication. |
| Introspection/revoke | Own versus authorized resource client, inaccessible/unknown tokens, idempotence, family revocation, cnf reporting without a resource proof. |
| Performance/isolation | Warm ordinary bearer query counts remain unchanged and do not read consent; concurrent server/user settings never leak; cold versus warm key loads; bounded caches; DPoP counted separately. |

Keep applicable upstream tests, adapting approved behavior instead of weakening assertions. Extend current tests for changed shared paths; do not duplicate the whole suite to prove unchanged OAuth-only behavior.

Update `src/docs/passport.md` in its existing Laravel-style prose, README public-contract differences, `src/docs/porting-from-laravel.md` for actual adaptations, and relevant route/config examples. Explain hardcoded consent URL changes, confirmation response integration, explicit forget/revoke semantics, client auth metadata, audience enforcement and cache requirements. Update sync.yaml notes only for mechanical source mappings. Add useful comments at non-obvious lock/ownership boundaries; remove obsolete callback, token-derived consent and logout-based reauthentication code/comments rather than leaving compatibility wrappers.

## 9. Research and source anchors

- OAuth core/security: [RFC6749](https://www.rfc-editor.org/rfc/rfc6749), [RFC9700](https://www.rfc-editor.org/rfc/rfc9700), [native apps](https://www.rfc-editor.org/rfc/rfc8252).
- Endpoints: [revocation](https://www.rfc-editor.org/rfc/rfc7009), [introspection](https://www.rfc-editor.org/rfc/rfc7662), [PAR](https://www.rfc-editor.org/rfc/rfc9126), [registration](https://www.rfc-editor.org/rfc/rfc7591), [server metadata](https://www.rfc-editor.org/rfc/rfc8414).
- [Resource indicators §2.2](https://www.rfc-editor.org/rfc/rfc8707#section-2.2) distinguishes the full authorization from a token's resource subset. [DPoP](https://www.rfc-editor.org/rfc/rfc9449) defines proof binding, PAR composition and nonce/pre-generation limits. [JWT client assertions](https://www.rfc-editor.org/rfc/rfc7523) permits the token endpoint audience.
- [OIDC authorization requests](https://openid.net/specs/openid-connect-core-1_0.html#AuthRequest) supplies prompt/reauthentication semantics; [form-post mode](https://openid.net/specs/oauth-v2-form-post-response-mode-1_0.html) defines response transport. These do not require logout before authentication.
- Established consent ownership: [Ory login/consent](https://www.ory.com/docs/oauth2-oidc/custom-login-consent/flow), [authentik consent](https://docs.goauthentik.io/add-secure-apps/flows-stages/stages/consent/). Exact endpoint paths vary; discovery publishes them.
- Current source anchors: `AuthorizationController`, `ResolvesApprovingGuard`, `TokenIssuance`, `RefreshTokenRepository`, `BearerTokenValidator`, `ServerFactory`, `PassportCache`, `ClientRelation`/`TokenRelation`, `SessionGuard`, `PasswordConfirmation`, `PasskeyConfirmationController`, `PublicDestinationPolicy`. League's protected grant validation is the client-authentication integration boundary.
