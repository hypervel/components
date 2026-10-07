JWT for Hypervel
===

Documentation: https://hypervel.org/docs/jwt

## Differences From php-open-source-saver/jwt-auth

- Hypervel uses array payloads instead of upstream `Payload`, `Token`, and claim DTO objects.
- Hypervel keeps the `Jwt` facade mapped to the array-based `JwtManager`, but does not include upstream `JwtAuth`, `JwtFactory`, or `JwtProvider` facades.
- Custom claims may set registered claims such as `exp`, `iss` and `jti`, but not `sub` or `prv`, which always come from the user and its provider. Customize the subject with `JwtSubject::getJwtIdentifier()`.
- Hypervel adds a default `iss` only when `jwt.issuer` is set, rather than using the request URL, and a default `jti` only when the blacklist is enabled.
- Cookie token parsing is available but not enabled by default.
- Upstream route-parameter and Lumen parser shortcuts are not included.
- Upstream sliding refresh middleware is not included; use an explicit refresh endpoint that calls `Auth::guard(...)->refresh()`.
- The Namshi provider is not included. Lcobucci supports the same HMAC, RSA and ECDSA algorithms, and `tymondesigns/jwt-auth` no longer ships Namshi.
- Lumen integrations are not included.
- Clearing the blacklist requires a cache store that supports tags. On other stores upstream flushes the whole cache, so Hypervel throws the cache's tag error instead and never removes other cache entries.
- The `show_black_list_exception` option is not included; JWT exceptions fail normally.

Ported from: https://github.com/PHP-Open-Source-Saver/jwt-auth
