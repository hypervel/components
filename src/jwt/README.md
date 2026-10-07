JWT for Hypervel
===

Documentation: https://hypervel.org/docs/jwt

## Differences From jwt-auth

Hypervel JWT takes behavior, fixes and tests from both jwt-auth repositories below, with its own design: one guard and one manager serve every request in a worker, so per-request state lives in coroutine context, payloads are plain arrays, and settings come from configuration rather than setters on shared services.

- Payloads are arrays instead of upstream `Payload`, `Token` and claim objects. Read claims with array access, such as `payload()['sub']`, in place of `getClaim()` and the `Payload` methods.
- The `JWTAuth`, `JWTFactory` and `JWTProvider` facades are not included. Use the guard's methods, such as `Auth::guard('api')->fromUser($user)` or `login($user)`, to issue tokens for users. The `Jwt` facade's `encode()` signs the given payload without the claims the guard adds, except for a `jti` when the blacklist is enabled and the payload has none. `decode()` verifies a token and returns its claims. `Jwt::blacklist()` returns the blacklist and `Jwt::driver()` the JWT provider.
- Any `Authenticatable` user can receive tokens. Implement `JwtSubject`, upstream's `JWTSubject`, only to change the `sub` claim or add custom claims.
- The guard does not forward unknown methods to an upstream `JWT` object. In place of `JWTAuth::check()`, which reports whether the token is valid, use the guard's `check()` for an authenticated user or `Jwt::decode()` to validate a token.
- The guard always reads the current request, so it has no `setRequest()`; use `setToken()` to authenticate a specific token. Runtime setters such as `lockSubject()`, `setBlacklistEnabled()`, `setPersistentClaims()` and `setRefreshIat()` are not included because the guard and manager are shared by every request; set these options in `config/jwt.php`.
- Without a token, the guard's `refresh()` returns `null` and `payload()` returns an empty array, while upstream throws.
- The guard's `id()` loads the user like Laravel's guards and the original repository, while the PHP-Open-Source-Saver fork returns the token subject without loading the user. Use `getUserId()` for that.
- The JWT provider is chosen with the `driver` option and custom drivers are registered with `Jwt::extend()`, following Laravel's driver managers, instead of upstream's `providers.jwt` class setting.
- Custom claims may set registered claims such as `exp`, `iss` and `jti`, but not `sub` or `prv`, which always come from the user and its provider. Customize the subject with `JwtSubject::getJwtIdentifier()`.
- Hypervel adds a default `iss` only when `jwt.issuer` is set, rather than using the request URL, and a default `jti` only when the blacklist is enabled.
- Only `iat` and `sub` are required claims by default, because `iss`, `exp` and `jti` are only added when an issuer, a TTL or the blacklist is configured. The default `ttl` is 120 minutes rather than 60.
- Subject locking hashes the user provider's model class. While it is enabled, a guard whose provider exposes its model rejects tokens without a matching `prv` claim, including tokens that have none, and disabling it turns the check off. Upstream hashes the user's class, accepts tokens without `prv`, and still checks a `prv` claim while locking is disabled.
- Only the `Authorization` header is read by default, while upstream also reads the query string, request input, route parameters and cookies. Add `InputSource` to `jwt.parser` to read the query string and request body, where the body wins when both contain a token, or `Cookie` to read the cookie named by `cookie_key_name`.
- Upstream's separate query-string, route-parameter and Lumen parsers are not included. `InputSource` reads query input, and other sources such as route parameters use a custom `TokenExtractor`. The parser chain is set in `jwt.parser` instead of with upstream's `setChain()` and `addParser()`, because one parser is shared by every request.
- The `decrypt_cookies` option is not included. Upstream decrypts the cookie itself, which can't read cookies written by the `EncryptCookies` middleware, so that middleware decrypts the token cookie instead.
- Upstream's middleware is not included. Use `auth:api` instead of `jwt.auth`, and call `Auth::guard('api')->user()` on routes that allow guests instead of using `jwt.check`. Instead of the sliding refresh middleware (`jwt.refresh` and `jwt.renew`), use an explicit refresh endpoint that calls `Auth::guard(...)->refresh()`.
- The Namshi provider is not included. Lcobucci supports the same HMAC, RSA and ECDSA algorithms, and `tymondesigns/jwt-auth` no longer ships Namshi.
- Lumen integrations are not included.
- Clearing the blacklist requires a cache store that supports tags. On other stores upstream flushes the whole cache, so Hypervel throws the cache's tag error instead and never removes other cache entries.
- The `show_black_list_exception` option is not included. Upstream's option skips the blacklist check when disabled, so revoked tokens are accepted. Guards already treat blacklisted tokens as unauthenticated, and the exception handler's `dontReport` method keeps JWT exceptions out of logs.

Ported from:

- https://github.com/PHP-Open-Source-Saver/jwt-auth
- https://github.com/tymondesigns/jwt-auth
