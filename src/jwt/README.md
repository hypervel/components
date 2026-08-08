JWT for Hypervel
===

Documentation: https://hypervel.org/docs/jwt

## Differences From php-open-source-saver/jwt-auth

- Hypervel uses array payloads instead of upstream `Payload`, `Token`, and claim DTO objects.
- Hypervel keeps the `Jwt` facade mapped to the array-based `JwtManager`, but does not include upstream `JwtAuth`, `JwtFactory`, or `JwtProvider` facades.
- Cookie token parsing is available but not enabled by default.
- Upstream route-parameter and Lumen parser shortcuts are not included.
- Upstream sliding refresh middleware is not included; use an explicit refresh endpoint that calls `Auth::guard(...)->refresh()`.
- Namshi and Lumen integrations are not included.
- The `show_black_list_exception` option is not included; JWT exceptions fail normally.

Ported from: https://github.com/PHP-Open-Source-Saver/jwt-auth
