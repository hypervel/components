Passport for Hypervel
===

Documentation: https://hypervel.org/docs/passport

## Differences From Laravel

- Implicit and password grants are omitted in line with the OAuth security best current practice: they expose tokens in authorization responses or require applications to collect users' passwords. Use authorization code with PKCE or the device grant. PKCE requires `S256` so the authorization request does not expose the verifier.
- Deprecated JSON API routes, their controllers and validation rules, and `TokenRepository` are omitted. Define application-owned management routes using `ClientRepository::create*Client()`, `$user->oauthApps()` and `$user->tokens()`.
- `ClientRepository::findForUser()`, `forUser()`, `update()` and `delete()` are omitted. Use `$user->oauthApps()`, update client models directly, and revoke clients with `Client::revoke()`. `HasApiTokens::clients()` is replaced by `oauthApps()`, and `Client::user()` by `owner()`. Deprecated `Client::authCodes()`, `Token::user()` and `AuthCode::client()` relations are omitted. Use `Passport::defaultScopes()` instead of `setDefaultScope()`.
- Pre-13 client schemas, `Passport::$clientUuids` and `passport:hash` are omitted. Clients use UUIDs, `redirect_uris` and `grant_types`; migrate legacy data before importing it.
- Hypervel ships a `passport` guard. `actingAs()` and `actingAsClient()` default to it, and personal access clients without a provider use its provider. The API cookie is named `hypervel_token`; `POST /oauth/token`, `POST /oauth/device/code` and `GET /oauth/device/authorize` use the named `passport` rate limiter.
- Tokens record their user provider. Only a guard of that provider can authenticate the user, and a client's configured provider restricts who can authorize it. Use Hypervel's migrations and set the provider on imported token rows.
- Client secrets use SHA-256: generated secrets already have high entropy, so bcrypt adds CPU cost without useful protection against guessing. Regenerate Laravel's hashed client secrets when porting. Authorization codes and refresh tokens use a key derived from the app key, avoiding password stretching on every encryption and decryption. Laravel-issued codes and refresh tokens must be reissued. `encryptTokensUsing()` must return random key material that remains constant for the worker lifetime.
- Refresh-token reuse revokes the grant. Clients must not refresh concurrently with the same token. Rotation can only be disabled for confidential clients.
- File and environment signing keys are loaded once per worker. Changes require a full restart of processes that issue or verify tokens. The optional database key store supports rotation without restarts.
- Token redemption cannot run inside an application database transaction. Token, authorization-code, device-code, refresh-token, refresh-token-family and database signing-key models must share a connection; the client model may use another connection.
- Token events dispatch after issuance commits. Subsequent listener or cache invalidation failures are reported without withholding the issued tokens. Laravel dispatches token events immediately after saving each record, and listener exceptions fail the request.
- Guards read the coroutine's request; `TokenGuard::setRequest()` is omitted. Use `RequestContext::set()` or `validate(['request' => $request])`. Guard constructors and protected request helpers differ, as do `CreateFreshApiToken`'s protected methods, which receive the guard explicitly. `PassportServiceProvider::makeGuard()` takes `$name` before `$config`.
- `ApiTokenCookieFactory` takes `Config`, `ServerFactory`, `AuthManager` and `Container` instead of `Config` and `Encrypter`, so it uses the derived cookie key and resolves auth/request state per call. Its protected `createToken()` takes a required nullable provider after the expiration argument; update subclass overrides to carry that provider without falling back to another one. Existing two-argument `make()` calls still work, and an optional third argument selects the guard.
- Server-building extension methods live on `ServerFactory`, which selects the current request's settings while reusing configured servers and parsed keys across requests. Controllers, guards, middleware and personal access token factories resolve their current servers through it, so long-lived consumers follow settings changes and key rotation. Authorization controllers take `AuthManager` and resolve the approving guard per request. Custom controllers using `RetrievesAuthRequestFromSession` or `RetrievesDeviceCodeFromSession` also need `ResolvesApprovingGuard` and its `$auth`, `$config` and `$clients` dependencies.
- `Bridge\ClientRepository` no longer takes a hasher. `HandlesOAuthErrors::withErrorHandling()` and the `OAuthServerException` constructor no longer take the implicit grant's fragment argument.
- Token and client relations return `TokenRelation` and `ClientRelation`, subclasses of their upstream relation types. `personalAccessClient()` filters grant types in SQL; override that repository method if a custom model stores grant types differently.

See the [Passport porting guide](https://hypervel.org/docs/porting-from-laravel#passport) for migration guidance.

Ported from: https://github.com/laravel/passport
