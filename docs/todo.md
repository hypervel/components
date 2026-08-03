# Source Implementation Gaps

## Authentication

- Create hypervel/react-starter-kit. Include the standard skeleton pieces that currently only exist as follow-ups: a `composer dev` script for running the Hypervel development server and frontend asset watcher together, plus explicit Hypervel Vite refresh paths instead of the Laravel plugin's `refresh: true` shortcut.
- Port Fortify package
- Port Passport package
- Replace permission package fake Passport client-credentials coverage with real Passport tests once Passport is ported. The current tests use a local fake guard/client so the permission package can keep Passport middleware parity without depending on a package that does not exist yet.

## Artisan

- Add a `composer dev` script to the `hypervel/hypervel` application skeleton. The script should start the Hypervel development server and frontend asset watcher together using the package manager tools already included with the skeleton, so new applications have a simple one-command local development workflow.

## Boost

- Implement Hypervel Boost's installation flow and revisit the Boost section of `installation.md` once the implementation is complete. The current docs describe the intended `composer require hypervel/boost --dev` and `php artisan boost:install` workflow, but `src/boost` currently contains the documentation package only. Correct fix: add the interactive installer command and supporting tools, then update the installation docs for any differences from Laravel Boost.

## Framework-wide

- Convert the remaining tests that extend `PHPUnit\Framework\TestCase` to `Hypervel\Tests\TestCase` as required by `AGENTS.md`, verifying each file individually under coroutine execution and opting out only when the test explicitly exercises coroutine transitions.
- Find a clean, simple framework-wide solution for configuration-dependent services resolved before worker configuration reload. `server:reload` refreshes the existing configuration repository, but objects that have already copied configuration into their own state remain stale. For example, `SentryServiceProvider` eagerly resolves a worker-lifetime Hub and client during boot, so DSN, environment, and sampling changes are not applied until a full restart; resolving `Cache::store('some-store')` from a service provider populates `CacheManager`'s store cache before reload, so changes to that store's driver, connection, prefix, or other captured configuration are likewise not applied. Define the reload contract, audit framework-owned eager resolutions and manager caches, and solve the lifecycle at their shared owning boundary instead of adding package-specific refresh hooks or application workarounds.
- Convert container array access to `make()` across `src/`. About 40 files use `$app['...']` (e.g. `LogManager`, `ViewServiceProvider`, `TranslationServiceProvider`), carried over from upstream Laravel. `offsetGet()` always returns `mixed`, while `make()` has class-string generics phpstan can follow, so the conversion makes static analysis strictly more useful. Approved modernization per the Porting Packages policy in `AGENTS.md`; new code already follows the rule.
- Investigate where requiring and directly using a PHP extension would make framework code significantly faster than its current pure-PHP implementation. The framework already declares bundled extensions it depends on, so the question is which hot paths are doing in PHP what a C extension does natively. The worked example is `ext-gmp` for identifier encoding: UUID and ULID string conversion and any base32/base58/base62 short-id work exceed 64 bits, so `ramsey/uuid` and `symfony/uid` convert them digit by digit in PHP, while `gmp_init()`/`gmp_strval()` do arbitrary-base conversion natively — a hand-rolled base-36 UUID conversion measured 14.6 µs against 0.5 µs for the GMP equivalent with byte-identical output. Anything that fits in a 64-bit int (snowflakes, timestamps, counters) needs no extension, and hashing, encryption, and signatures are already C. Measure `Str::uuid()`/`Str::ulid()` and the other candidates before adding a requirement, and weigh each new extension against installation cost.
- Convert untyped `$config->get()` calls across `src/` to the typed getters (`string()`, `integer()`, `float()`, `boolean()`, `array()`) without call-site defaults, for every key that isn't genuinely nullable. Defaults live in the merged config files — declare any key currently defaulted only at a call site in its package's config file as part of the conversion. Typed getters throw `InvalidArgumentException` naming the key on misconfiguration instead of letting a wrong type propagate silently, and give phpstan real return types. Bootstrap code that runs before config merging keeps its call-site defaults. Approved modernization per the Porting Packages policy in `AGENTS.md`; new code already follows the rule.
- Audit unmatched PHPStan inline ignores and global patterns with `reportUnmatchedIgnoredErrors` enabled — currently 196 unmatched inline ignores across 99 files plus 5 unmatched global patterns. Remove only suppressions that no longer match after tracing the underlying code; do not replace correct source with runtime branches or wider types merely to keep static analysis green. Decide as part of the work whether `phpstan.neon.dist` should then set `reportUnmatchedIgnoredErrors: true` permanently, since leaving it `false` lets the suppressions rot again.

## Testing

- Complete Testing assertion coverage: port the remaining current Laravel `TestResponseTest` cases through the incremental upstream-update workflow, and add focused coverage for `TestView`'s public assertion and string surface where Laravel has no equivalent suite.
- Add the repository-required `: void` return type to the remaining untyped HTTP test methods: 176 in `tests/Http/HttpClientTest.php`, 30 in `tests/Http/HttpRequestTrustedStateTest.php`, and 4 in `tests/Http/HttpRequestTrustedStateCoroutineTest.php`. Verify each file after the mechanical conversion.

## Image

- Port the complete first-party Image component through the dedicated [Image package handoff](notes/image-package.md). The HTTP integration must add `Request::image(string $key): ?Image`, port `testImageMethod` and `testImageMethodReturnsNullForMissingKey`, and add the `hypervel/image` suggestion to `src/http/composer.json` with its package-metadata regression.

## HTTP Server

- Remove trailer-stream one-chunk lookahead once the minimum supported Swoole release includes [swoole-src#6124](https://github.com/swoole/swoole-src/pull/6124). Current releases send an empty `END_STREAM` DATA frame before trailer HEADERS when `end()` receives no body after `write()`, so `ResponseBridge` retains the final chunk for `end($chunk)` and delays delivery by one chunk. Once fixed, raise the `ext-swoole` constraint, write every chunk immediately, emit trailers, call bare `end()`, invert the deterministic bridge ordering tests, and add real gRPC incremental-delivery coverage.

## Documentation

- Re-run the introduction benchmarks against Hypervel 0.4 before publishing externally. The benchmark tables currently preserve the 0.3 results so the comparison is not lost during the docs port, but Hypervel 0.4's decoupled runtime should have fresh measurements before those numbers are treated as current.

## Redis

- Audit transformed Redis command wrapper return types against serializer-configured phpredis connections. For example, `RedisConnection::callGet(): ?string` can receive unserialized non-string values from phpredis when a serializer is enabled under `strict_types`; check the other `call*` wrappers for the same mismatch and update signatures/tests to match real client behavior.

## Collections

## Horizon

- Port Laravel's first-party `laravel/vonage-notification-channel` as `hypervel/vonage-notification-channel`, then wire Horizon long-wait SMS notifications through the current `vonage` channel and `VonageMessage`. Keep `Horizon::routeSmsNotificationsTo(...)`, add the package prerequisite and functional mail/Slack/SMS coverage, and do not port deprecated Nexmo aliases or fallbacks.
