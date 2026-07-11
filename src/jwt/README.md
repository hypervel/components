JWT for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/jwt)

Ported from: https://github.com/PHP-Open-Source-Saver/jwt-auth

This package provides stateless JWT authentication for Hypervel applications, adapted for long-lived Swoole workers and coroutine-safe request state.

## Differences From "php-open-source-saver/jwt-auth"

- Hypervel uses array payloads instead of upstream `Payload`, `Token`, and claim DTO objects.
- Hypervel keeps the `Jwt` facade mapped to the array-based `JwtManager`, but does not include upstream `JwtAuth`, `JwtFactory`, or `JwtProvider` facades.
- Hypervel's parser chain is stateless and receives the request for each parse so coroutine requests cannot leak through singleton services.
- Cookie token parsing is available but not enabled by default.
- Upstream route-parameter and Lumen parser shortcuts are not included.
- Upstream sliding refresh middleware is not included; use an explicit refresh endpoint that calls `Auth::guard(...)->refresh()`.
- Namshi and Lumen integrations are not included.
- The `show_black_list_exception` option is not included; JWT exceptions fail normally.

Full usage docs are available in `src/boost/docs/jwt.md`.
