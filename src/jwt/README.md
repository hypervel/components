JWT for Hypervel
===

Documentation: https://hypervel.org/docs/jwt

## Differences From php-open-source-saver/jwt-auth

- Hypervel uses array payloads instead of upstream `Payload`, `Token`, and claim DTO objects.
- Hypervel keeps the `Jwt` facade mapped to the array-based `JwtManager`, but does not include upstream `JwtAuth`, `JwtFactory`, or `JwtProvider` facades. Use `Jwt::blacklist()` for the blacklist and `Jwt::driver()` for the JWT provider.
- The guard does not forward unknown methods to an upstream `JWT` object. Token methods such as `fromUser`, `payload`, `refresh` and `invalidate` are defined on the guard, `check()` reports an authenticated user rather than a valid token, and manager features are available through the `Jwt` facade.
- The guard's `id()` loads the user like Laravel's guards, while upstream returns the token subject without loading the user. Use `getUserId()` for that.
- The JWT provider is chosen with the `driver` option and custom drivers are registered with `Jwt::extend()`, following Laravel's driver managers, instead of upstream's `providers.jwt` class setting.
- Custom claims may set registered claims such as `exp`, `iss` and `jti`, but not `sub` or `prv`, which always come from the user and its provider. Customize the subject with `JwtSubject::getJwtIdentifier()`.
- Hypervel adds a default `iss` only when `jwt.issuer` is set, rather than using the request URL, and a default `jti` only when the blacklist is enabled.
- Only the `Authorization` header is read by default, while upstream also reads the query string, request input, route parameters and cookies. Add `InputSource` to `jwt.parser` to read the query string and request body, where the body wins when both contain a token, or `Cookie` to read the cookie named by `cookie_key_name`.
- Upstream's separate query-string, route-parameter and Lumen parsers are not included. `InputSource` reads query input, and other sources such as route parameters use a custom `TokenExtractor`. The parser chain is set in `jwt.parser` instead of with upstream's `setChain()` and `addParser()`, because one parser is shared by every request.
- The `decrypt_cookies` option is not included. Upstream decrypts the cookie itself, which can't read cookies written by the `EncryptCookies` middleware, so that middleware decrypts the token cookie instead.
- Upstream's middleware is not included. Use `auth:api` instead of `jwt.auth`, and call `Auth::guard('api')->user()` on routes that allow guests instead of using `jwt.check`. Instead of the sliding refresh middleware (`jwt.refresh` and `jwt.renew`), use an explicit refresh endpoint that calls `Auth::guard(...)->refresh()`.
- The Namshi provider is not included. Lcobucci supports the same HMAC, RSA and ECDSA algorithms, and `tymondesigns/jwt-auth` no longer ships Namshi.
- Lumen integrations are not included.
- Clearing the blacklist requires a cache store that supports tags. On other stores upstream flushes the whole cache, so Hypervel throws the cache's tag error instead and never removes other cache entries.
- The `show_black_list_exception` option is not included. Upstream's option skips the blacklist check when disabled, so revoked tokens are accepted. Guards already treat blacklisted tokens as unauthenticated, and the exception handler's `dontReport` method keeps JWT exceptions out of logs.

Ported from: https://github.com/PHP-Open-Source-Saver/jwt-auth
