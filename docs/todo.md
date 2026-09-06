# Source Implementation Gaps

## Authentication

- Create hypervel/react-starter-kit. Include the standard skeleton pieces that currently only exist as follow-ups: a `composer dev` script for running the Hypervel development server and frontend asset watcher together, plus explicit Hypervel Vite refresh paths instead of the Laravel plugin's `refresh: true` shortcut.
- Port Passport package
- Replace permission package fake Passport client-credentials coverage with real Passport tests once Passport is ported. The current tests use a local fake guard/client so the permission package can keep Passport middleware parity without depending on a package that does not exist yet.

## Artisan

- Add a `composer dev` script to the `hypervel/hypervel` application skeleton. The script should start the Hypervel development server and frontend asset watcher together using the package manager tools already included with the skeleton, so new applications have a simple one-command local development workflow.

## Boost

- Implement Hypervel Boost's interactive installer and supporting AI tools, consuming the existing Wayfinder and Horizon skill templates where appropriate. Once the package ships working functionality, add and verify its installation documentation.

## Wayfinder

- Fix `@laravel/vite-plugin-wayfinder` generation scheduling upstream. Each plugin instance should capture its own hook context, normalize Windows path separators, parse a documented multiword command into an argument vector without shell expansion, serialize its own runs, collapse a burst into one follow-up run, recover after failure, and remain isolated from other plugin instances. Components should not carry a scheduler or shell workaround for plugin-owned behavior.

## Framework-wide

- Resolve the production dependency cycle between `hypervel/foundation` and `hypervel/testing`, which currently causes test-only classes, PHPUnit integration, and Mockery to propagate through `hypervel/support` into every package that depends on Support. Decide whether Foundation's testing namespace moves to `hypervel/testing` or Foundation changes its dependency to development-only while preserving split-package installation and public namespaces.
- Design a connection-owned service identity and capability API for external backends that packages can query without repeated hot-path probes. Redis/Valkey and database connections already expose fragments of this information in different forms; prefer lazy detection cached for the current connection or pool generation, with invalidation on reconnect and purge, over an eager process-global startup registry that performs unused I/O or survives a backend change. Start with concrete consumers and capability checks rather than a universal version-comparison abstraction.
- Find a clean, simple framework-wide solution for configuration-dependent services resolved before worker configuration reload. `server:reload` refreshes the existing configuration repository, but objects that have already copied configuration into their own state remain stale. For example, `SentryServiceProvider` eagerly resolves a worker-lifetime Hub and client during boot, so DSN, environment, and sampling changes are not applied until a full restart; resolving `Cache::store('some-store')` from a service provider populates `CacheManager`'s store cache before reload, so changes to that store's driver, connection, prefix, or other captured configuration are likewise not applied. Define the reload contract, audit framework-owned eager resolutions and manager caches, and solve the lifecycle at their shared owning boundary instead of adding package-specific refresh hooks or application workarounds.
- Investigate where requiring and directly using a PHP extension would make framework code significantly faster than its current pure-PHP implementation. The framework already declares bundled extensions it depends on, so the question is which hot paths are doing in PHP what a C extension does natively. The worked example is `ext-gmp` for identifier encoding: UUID and ULID string conversion and any base32/base58/base62 short-id work exceed 64 bits, so `ramsey/uuid` and `symfony/uid` convert them digit by digit in PHP, while `gmp_init()`/`gmp_strval()` do arbitrary-base conversion natively — a hand-rolled base-36 UUID conversion measured 14.6 µs against 0.5 µs for the GMP equivalent with byte-identical output. Anything that fits in a 64-bit int (snowflakes, timestamps, counters) needs no extension, and hashing, encryption, and signatures are already C. Measure `Str::uuid()`/`Str::ulid()` and the other candidates before adding a requirement, and weigh each new extension against installation cost.
- Convert untyped `$config->get()` calls across `src/` to the typed getters (`string()`, `integer()`, `float()`, `boolean()`, `array()`) without call-site defaults, for every key that isn't genuinely nullable. Defaults live in the merged config files — declare any key currently defaulted only at a call site in its package's config file as part of the conversion. Typed getters throw `InvalidArgumentException` naming the key on misconfiguration instead of letting a wrong type propagate silently, and give phpstan real return types. Bootstrap code that runs before config merging keeps its call-site defaults. Approved modernization per the Porting Packages policy in `AGENTS.md`; new code already follows the rule.
- Audit unmatched PHPStan inline ignores and global patterns with `reportUnmatchedIgnoredErrors` enabled — currently 196 unmatched inline ignores across 99 files plus 5 unmatched global patterns. Remove only suppressions that no longer match after tracing the underlying code; do not replace correct source with runtime branches or wider types merely to keep static analysis green. Decide as part of the work whether `phpstan.neon.dist` should then set `reportUnmatchedIgnoredErrors: true` permanently, since leaving it `false` lets the suppressions rot again.
- Add PHPStan Eloquent extensions that preserve `Eloquent\Builder<TModel>` for non-passthrough methods forwarded to `Query\Builder`, and expose model named scopes on Eloquent builders and relations. The query-builder mixin currently gives fluent calls the wrong builder type, while named scopes are treated as nonexistent methods; these gaps force scopes to split mutation from return and leave `HasDatabaseNotifications` with `method.notFound` suppressions. This will be the repository's first PHPStan extension, so use Larastan as prior art and wire the extensions into `phpstan.neon.dist` without maintaining duplicate query-method or scope lists in `@method` annotations.
- Audit typed input accessors across `InteractsWithData`, Support `DataObject`, and Hypervel Data. Define the accepted integer, float, and boolean forms for each public contract. Extract a neutral Support conversion primitive only if `InteractsWithData` and `DataObject` converge on exactly the same strict semantics, and decide separately whether Hypervel Data should adopt those semantics rather than changing its tested permissive casts as a side effect.

## Testing

- Replace PHPUnit 13's [soft-deprecated `expectExceptionMessage()`](https://github.com/sebastianbergmann/phpunit/issues/6560) calls across the test suite. Preserve intended matching semantics: use `expectExceptionObject()` for combined class/message/code expectations, `expectExceptionMessageIs()` for exact messages, and `expectExceptionMessageIsOrContains()` for substring matching. Audit each assertion's intent and run its owning test file as it is changed.

## HTTP Server

- Remove trailer-stream one-chunk lookahead once the minimum supported Swoole release includes [swoole-src#6124](https://github.com/swoole/swoole-src/pull/6124). Current releases send an empty `END_STREAM` DATA frame before trailer HEADERS when `end()` receives no body after `write()`, so `ResponseBridge` retains the final chunk for `end($chunk)` and delays delivery by one chunk. Once fixed, raise the `ext-swoole` constraint, write every chunk immediately, emit trailers, call bare `end()`, invert the deterministic bridge ordering tests, and add real gRPC incremental-delivery coverage.

## Routing

- Correct `CompiledRouteCollection`'s 405 method aggregation when cached routes and routes added at runtime share a path but allow different methods. If the compiled matcher rejects the request method, the dynamic collection's `MethodNotAllowedHttpException` currently replaces the compiled matcher's allowed-method set, so the response's `Allow` header omits methods supplied by the cached routes. Laravel has the same catch structure, but Hypervel should retain, merge, and de-duplicate both method sets before producing the 405 response. Add focused coverage with cached and dynamic methods in both registration directions, including GET/HEAD behavior.
- Handle pathless absolute-form request targets in `RequestBridge`. Symfony leaves `http://example.com` as the request URI and derives `/http://example.com` as its path; with a query it can also append the query twice. The absolute-form grammar permits an empty path and servers must accept absolute-form requests ([RFC 9112 section 3.2.2](https://www.rfc-editor.org/rfc/rfc9112.html#section-3.2.2)), while an empty HTTP(S) path is normally equivalent to `/` except for OPTIONS ([RFC 9110 section 4.2.3](https://www.rfc-editor.org/rfc/rfc9110.html#section-4.2.3)). Normalize pathless HTTP(S) targets before Symfony derives the path, preserve the query exactly once, and add explicit GET and OPTIONS coverage alongside host, port, and query variants.

## Documentation

- Publish reproducible Hypervel 0.4 benchmarks on a dedicated documentation page before linking them from the introduction. Record the framework, PHP, Swoole, and dependency versions; use the same hardware and load-generation conditions for every runtime; publish the benchmark applications and configuration; and include the raw results, collection date, and limitations. Do not reuse the Hypervel 0.3 results as current data. Once the page is published, add it to `src/docs/documentation.md` and link to it from the introduction.
- When the Hypervel 0.4 documentation is published, replace the versioned GitHub source links in both the `hypervel/components` and `hypervel/hypervel` READMEs with the corresponding hypervel.org documentation URLs. The documentation's `{{version}}` cross-links only resolve on the published site, so readers who follow the current links land on pages whose internal navigation is broken.

## Data

- Reconcile `hypervel/data` with the final `spatie/laravel-data` v5 release before Hypervel 0.4 is released. Compare the released source, tests, and documentation, and adopt worthwhile API, behavior, coverage, and documentation changes without replacing Hypervel's fixed creation engine, coroutine-safe state model, first-party framework integrations, or measured performance improvements with Laravel-specific machinery.

## TypeScript

- Port `spatie/typescript-transformer` as a general first-party Hypervel package, then add an optional Hypervel Data adapter that recognizes data classes and their existing mapping, Optional, lazy, and collection metadata. Keep TypeScript generation outside `hypervel/data`: the transformer must also support ordinary PHP classes and enums without making runtime data construction depend on filesystem discovery or code generation.

## Redis

- Revisit the rate limiter's portable fixed-window Lua script once native bounded increment-with-expiry support is mature across the supported Redis-compatible ecosystem. Redis 8.8's `INCREX` can atomically reject increments above an upper bound and set expiry only for a new window, but Redis 8.6 and Valkey 9 do not provide it, [Valkey #3253](https://github.com/valkey-io/valkey/pull/3253) is still an open related proposal rather than equivalent `INCREX` support, and phpredis 6.3 exposes no typed `INCREX` method (while `rawCommand()` bypasses key prefixing and has different Redis Cluster routing semantics). Re-benchmark and switch only when Redis and Valkey expose equivalent semantics and phpredis has prefix-aware, cluster-aware client support; keep the corresponding focused `@TODO` beside the Lua script until then.

## Validation

- Adopt Brick Math 0.20's bounded parsing API for untrusted numeric validation inputs once the supported dependency graph permits it. Ramsey UUID currently limits Brick Math to 0.18, which has neither bounded parsing nor 0.20's parser-backtracking fix, so an intermediate upgrade would not address this issue. Preserve the configurable exponent-range behavior and cover delegated and compiled rules, oversized mantissas and exponents, malformed input, and `multiple_of`; do not add a local parsing workaround or disguise an incompatible Brick version with a Composer alias.

## Notifications

- Design a provider-agnostic first-party SMS notification API before adding an SMS provider. Keep Horizon's existing `Horizon::routeSmsNotificationsTo(...)`, but have Horizon target the generic channel and message contract rather than a vendor class; provider packages should adapt that contract to Vonage or other services. Decide routing, provider selection, message construction, per-message client overrides, and failure reporting, then implement the first adapter and update the notification and Horizon documentation, stubs, and Boost references. Keep mutable third-party SDK clients isolated per send—Vonage's client caches resources that mutate request and response state around yielding HTTP calls—while reusing only immutable configuration and the coroutine-safe transport. Add standalone package, provider, direct-construction, routing, failure, Horizon mail/Slack/SMS, and deterministic concurrent-send coverage. Do not add obsolete Nexmo names or compatibility aliases.

## Sentinel

- Port `laravel/sentinel` as `hypervel/sentinel`, add direct Horizon and Telescope dependencies, and prepend `SentinelMiddleware:horizon` and `SentinelMiddleware:telescope` while preserving configured middleware. Remove Horizon's temporary `REMOVED:` source comment and cover both dashboards' security integration.
