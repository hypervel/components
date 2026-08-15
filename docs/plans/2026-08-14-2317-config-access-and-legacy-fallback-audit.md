# Config Access and Legacy Fallback Audit

## Goal

Audit the `contrib/hypervel/components` repository so configuration reads state their real type, fail loudly when a required shipped key is missing or misspelled, preserve intentional null and mixed behavior, and remove Laravel compatibility fallbacks that Hypervel does not need. The finished code should use one default at the owning config file, with source-level fallbacks only for genuinely optional settings inside replaceable or dynamic config structures.

## Ground Rules

- Audit production PHP, route/config files, migrations, and Blade views. Distinguish configuration repositories from unrelated `get()` methods.
- Use `string()`, `integer()`, `float()`, `boolean()`, `array()`, or `collection()` when the consumed value has one required type. Use `collection()` when a required config array is immediately wrapped in a `Collection`. Helper-based code uses `config()->{type}()`; ported code keeps its helper/facade/injection structure.
- `Arr::get()` treats a present null key as present because `Arr::exists()` uses `array_key_exists()`. A call-site fallback for a key present in loaded config is therefore dead even when the configured value is null.
- Remove a call-site default only after confirming the exact key exists in the shipped framework or package config and that the owning config has loaded before the read. Package defaults merged in `register()` are unavailable to `isEnabled()`, and nested members of wholesale-replaced arrays may still need call-site fallbacks. A missing required key must raise the config repository's native `InvalidArgumentException`.
- Keep `get()` or an appropriate fallback when the read has meaningful null, union, mixed, dynamic, or deliberately optional nested behavior, or when it targets config owned by an optional package that may not be installed. Add or retain a functional test for meaningful null behavior.
- Named config groups in `LoadConfiguration::mergeableOptions()` merge entry by entry. Other nested arrays replace the default array. Retain a nested-member fallback only when both conditions hold: the application can replace the enclosing array wholesale, and omission of that member is supported behavior rather than broken configuration. For ported packages, compare each replaceable array with current upstream config: a Hypervel-only member added to an otherwise compatible upstream block must remain optional at the call site because an unmodified upstream config is valid. This does not apply when Hypervel intentionally rewrote the block into a different contract, such as `permission.cache`. Replaceability alone does not make required class names, cache keys, or other required members optional.
- Remove old Laravel key names, env names, and config shapes only when upstream history confirms that the branch exists for older applications rather than current functionality. Record public porting consequences concisely.
- Do not add wrappers, compatibility aliases, normalization layers, custom exceptions, or new configuration machinery.

## Final Configuration Contract

### Current-only keys and shapes

1. Scheduling mutex cache configuration is `cache.schedule_store`, backed by `SCHEDULE_CACHE_STORE`. A null value selects the cache manager's default store. Adding this top-level key makes the old kernel fallback unreachable even when its value is null; remove the dead direct env chain so `SCHEDULE_CACHE_DRIVER` is unsupported by design.
2. `database.migrations` is the current array shape. `table` is required and consumers read `database.migrations.table` as a string. `update_date_on_publish` remains optional for application arrays that replace `migrations` wholesale, so `publishesMigrations()` retains its `false` fallback. The old scalar table-name shape is rejected instead of normalized at each caller.
3. `logging.deprecations` is the current array shape. Exception bootstrappers read it as an array; its nested `channel` remains nullable because null disables a deprecation logger. The old scalar channel shape is rejected.
4. Sentry logging uses `SENTRY_LOG_LEVEL`, falling back to the general `LOG_LEVEL`. Upstream deliberately renamed `SENTRY_LOGS_LEVEL` and retained it only as a backwards-compatibility alias; Hypervel removes that alias.
5. Socialite's `services.x` / `services.x-oauth-2` lookup remains. Both keys were introduced together for the single current X provider, and the undeprecated upstream alternate key is not a renamed-key shim or a separate driver.
6. Dynamic connection inheritance, generic package merge defaults, and current per-driver configuration such as `concurrency.driver.{name}` remain unchanged.

Remove the migration-shape normalization from `DatabaseServiceProvider`, `DumpCommand`, and `DatabaseTruncation`; remove the deprecation-shape branches from the Foundation and Testbench `HandleExceptions` bootstrappers; remove direct env access from the Foundation console kernel; and remove the renamed Sentry env alias from its package config.

### Missing shipped keys

Add defaults at the owning config surface so consumers do not carry hidden defaults:

- `cache.schedule_store => env('SCHEDULE_CACHE_STORE')`
- `fortify.limiters.verification => '6,1'` in both package config and publishable stub; retain the same fallback at its typed route read because an existing published `limiters` array replaces the package array wholesale
- `sanctum.routes => true`
- `sanctum.prefix => 'sanctum'`

### Passkey config loading with nullable application settings

`app.url` and `app.key` can be null for supported application behavior. PHP eagerly evaluates function arguments, so the current `env(..., parse_url(config('app.url'), ...))` expressions can fail even when explicit passkey env values are set, and the Fortify bridge's nested typed fallback reads `app.key` even when a dedicated passkey secret is configured.

In the Passkeys config, Fortify config, and Fortify stub:

```php
$appUrl = config('app.url');
$defaultRelyingPartyId = $appUrl === null ? null : parse_url($appUrl, PHP_URL_HOST);
$defaultAllowedOrigins = $appUrl === null ? [] : [$appUrl];
```

Use those derived defaults in the returned array. Keep `config('app.key')` as the default because a null app key is valid until a secret-requiring passkey operation runs. In `FortifyServiceProvider`, remove the local typed `app.url` read and its `parse_url($appUrl, ...)` / `[$appUrl]` bridge defaults; the Fortify config has already derived them safely. Copy nullable `fortify.passkeys.relying_party_id`, `allowed_origins`, and `user_handle_secret` with `get()` while reading timeout with `integer(..., 60000)` because the enclosing application array may replace the package defaults. In `Passkeys`, read those three nullable values with `get()` and let the existing domain guards reject null, wrong-type, empty ID/origin collections, and empty secrets with their purpose-built `RuntimeException` messages. Extend the secret guard to reject non-strings as well as the empty string.

## Access Conversion

Apply the following classifications to every matching production caller. Before editing each package, re-read its README, shipped config, relevant source, and relevant tests.

| Area | Convert to typed access and remove duplicate defaults | Keep untyped because behavior is not one required type |
|---|---|---|
| Core app/foundation | required app environment, timezone, locale, name, debug flag, providers, aliases, faker locale, filesystem links, view paths/compiled path, database default, session path, and consumers that require a non-null app URL/key | package consumers of `app.url`, `app.asset_url`, `app.editor`, `app.key`, logging default, and session domain enumerated below |
| Auth, bus, queue, database | batching table, required failed-job settings, required defaults, email-verification expiry, and current migration fields | nullable batching database, dynamic password-broker expiry, optional connection queue/migration connection, dynamic auth provider/model keys, and database connection URL |
| Cache, hashing, rate limiter, signal | cache store collections/prefix and benchmark environment, hashing driver/options, rate-limiter settings, signal handlers | `cache.serializable_classes`, optional cache store members, and null schedule store |
| Reverb, server, gRPC | server collections, enabled flags, required route path and server settings | nullable gRPC compression and application/provider fields whose package contract allows null |
| Horizon | environment/default arrays, silenced arrays, prefix/path/use, memory and fast-termination settings, and layout name reads after boot normalization | nullable name during boot normalization, domain/watch, dynamic waits, environment fallback chain, optional queue names, and fallbacks inside replaced `trim` / `metrics` arrays |
| Inertia | page flags and arrays, history flag, SSR timeouts/backoff/enabled/runtime/URL/validation flags | nullable bundle and hot URL |
| JWT | algorithm, provider/storage classes, keys/parser/claims/validation settings, blacklist flags and numeric settings | nullable secret/TTL/refresh/issuer |
| Mail and notifications | required app name and top-level mail settings | optional `services.*` credentials and Markdown-member fallbacks inside the replaced `markdown` array |
| Passkeys and Fortify | required redirect, feature arrays, route view flag, middleware array, and configured non-null limiter strings | nullable relying-party ID/origins/secret, guard/domain/redirect/pipelines/limiter entries, passkey throttle, and timeout/verification fallbacks inside replaced arrays |
| Permission | top-level flags/resolver settings, migration arrays/booleans, required role/permission models, team foreign key, and cache settings | nullable team/default models, nullable pivot keys, and dynamic guard providers |
| Sanctum | cache enabled flag, token prefix, stateful domains, last-used flag, routes and prefix | nullable expiration/cache store/cache timings and nullable middleware entries; preserve class fallbacks for optional members of a replaced middleware array |
| Scout | after-commit/soft-delete flags, prefix, required chunk sizes, `meilisearch.host`, and required Typesense settings | nullable job options, optional Algolia timeouts/key/index settings, and fallbacks for Hypervel-only Meilisearch retry options inside the otherwise upstream-compatible replaced block |
| Sentry | pool/features/root options, log/channel/cache arrays, log level, and shipped tracing/breadcrumb flags | genuinely nullable SDK options and dynamic option-array reads |
| Telescope | enabled/defer flags, ignore/only arrays, watcher collection, middleware array, storage chunk, and required driver/connection values, including `Storage/EntryModel.php` | nullable path/domain/queue connection/queue/delay and polymorphic watcher definitions |
| Tinker | command/alias/dont-alias arrays | casters and `trust_project`, which accept their upstream union behavior |
| Testbench/testing | required app URL, providers, aliases, view paths, cache prefix, and database default | application/test overrides that deliberately allow absent app key, auth model, or connection URL; the timezone override method remains nullable for subclasses |

Specific call-site defaults already confirmed to duplicate loaded config include Reverb `server.servers`; Sentry pool/features/root/log-channel/cache-store values; Horizon defaults and silenced arrays; Scout top-level flags/prefix; Permission top-level flags/team resolver; Hashing driver/options; Foundation providers/aliases/links; Tinker command/alias/dont-alias arrays; and the `app.name` fallbacks in `foundation/src/resources/health-up.blade.php:7` and `foundation/resources/exceptions/renderer/components/layout.blade.php:8`. Remove these dead defaults rather than preserving two apparent sources of truth. Retain the nested-member fallbacks listed below only where omission is supported behavior.

### Authoritative retained-access inventory

The following production reads deliberately remain untyped or retain a fallback. Line numbers refer to the pre-implementation tree; update this inventory if edits move or change a retained read. When a cited line contains multiple config reads, the Config surface column names only the retained read; every other read on that line follows the conversion table.

| Location | Config surface | Reason |
|---|---|---|
| `src/auth/src/AuthManager.php:130,170`; `CreatesUserProviders.php:58,82`; `Passwords/PasswordBrokerManager.php:119` | `app.key`, dynamic guards/providers/brokers | null / dynamic |
| `src/broadcasting/src/BroadcastManager.php:462`; `src/cache/src/CacheManager.php:422`; `src/filesystem/src/FilesystemManager.php:701`; `src/log/src/LogManager.php:562`; `src/queue/src/QueueManager.php:353` | named driver configuration | dynamic; missing names feed manager-specific errors |
| `src/bus/src/BusServiceProvider.php:68`; `src/horizon/src/Http/Controllers/BatchesController.php:59` | `queue.batching.database` | null selects the default database connection |
| `src/cache/src/CacheManager.php:65` | `cache.serializable_classes` | `false|array|null|true` union |
| `src/cache/src/SwooleTableManager.php:109`; `src/rate-limiter/src/RateLimiter.php:142`; `src/rate-limiter/src/Swoole/TableManager.php:66` | named table/store config | dynamic plus package validation |
| `src/container/src/Attributes/Config.php:26`; `Attributes/Context.php:31`; `ContextualBindingBuilder.php:65`; `src/foundation/src/helpers.php:335` | caller-supplied keys/defaults | generic API |
| `src/console/src/GeneratorCommand.php:478-480`; `src/foundation/src/Console/PolicyMakeCommand.php:81-92` | dynamic auth provider/model lookup | dynamic / null |
| `src/database/src/Migrations/Migrator.php:617-625` | effective default and per-connection migration route | bootstrap / null / dynamic fallback |
| `src/encryption/src/Commands/KeyGenerateCommand.php:76,123`; `src/testbench/src/Foundation/Process/RemoteCommand.php:61`; `src/passkeys/config/passkeys.php:42`; `src/fortify/config/fortify.php:76`; `src/fortify/stubs/fortify.php:157` | `app.key` | null is the ungenerated-key state |
| `src/foundation/src/Bootstrap/HandleExceptions.php:123,135,145`; `src/testbench/src/Bootstrap/HandleExceptions.php:44` | dynamically created logging channels | bootstrap / dynamic |
| `src/foundation/src/Console/ConfigShowCommand.php:45`; `src/scout/src/EngineManager.php:264` | caller-supplied key/default | generic API |
| `src/foundation/src/Console/Kernel.php:285,293` | `app.schedule_timezone`, `cache.schedule_store` | null selects app timezone/default cache |
| `src/foundation/src/Concerns/ResolvesSourceHref.php:49`; `src/foundation/resources/exceptions/renderer/components/file-with-line.blade.php:13` | `app.editor` | null disables editor links |
| `src/routing/src/RoutingServiceProvider.php:61,91`; `src/inertia/src/Middleware.php:70-71` | `app.asset_url` | null disables the asset root/version input |
| `src/sanctum/src/Sanctum.php:54`; `src/wayfinder/src/Route.php:351`; `src/passkeys/config/passkeys.php:17,30`; `src/fortify/config/fortify.php:74-75`; `src/fortify/stubs/fortify.php:155-156` | `app.url` | null has explicit package behavior |
| `src/mail/resources/views/html/message.blade.php:4`; `src/mail/resources/views/text/message.blade.php:4` | `app.url` | null renders the mail header URL as empty |
| `src/foundation/src/Http/MaintenanceModeBypassCookie.php:22` | `session.domain` | null creates a host-only cookie |
| `src/grpc/src/GrpcServiceProvider.php:179` | `grpc.server.compression` | null disables compression |
| `src/horizon/src/HorizonServiceProvider.php:46,75` | `horizon.name` before normalization, `horizon.domain` | null/empty derives the application name or omits the domain |
| `src/horizon/src/Console/ListenCommand.php:36`; `HorizonCommand.php:37`; `MasterSupervisorController.php:28` | `horizon.watch`, `horizon.env` | absent optional override / dynamic fallback; the `app.env` reads on the latter two lines convert to typed access |
| `src/horizon/src/Listeners/MonitorWaitTimes.php:44-45` | `horizon.waits.{queue}` | dynamic; absent queue uses 60 seconds |
| `src/queue/src/Console/WorkCommand.php:363`; `ListenCommand.php:83`; `ClearCommand.php:74`; `src/horizon/src/Console/SupervisorCommand.php:144`; `ClearCommand.php:68`; `SupervisorOptions.php:78` | `queue.connections.{name}.queue` | retained fallback; queue is an optional member of a dynamic connection entry |
| `src/inertia/src/Commands/StartSsr.php:39`; `Ssr/BundleDetector.php:41`; `Ssr/HttpGateway.php:296` | SSR bundle/hot URL | null selects discovery or disables hot URL |
| `src/jwt/src/ClaimFactory.php:50`; `JwtManager.php:70,151,223`; `JwtServiceProvider.php:69,137` | secret, issuer, TTL, refresh TTL | null/union token behavior |
| `src/log/src/LogManager.php:570`; `src/foundation/src/Console/AboutCommand.php:176` | `logging.default` | nullable default channel |
| `src/mail/src/MailManager.php:314-332,662,674`; `src/notifications/src/Channels/SlackWebApiChannel.php:76,89,94` | mailer/address and `services.*` data | dynamic / optional credentials |
| `src/fortify/src/FortifyServiceProvider.php:126-129,185`; `AuthenticatedSessionController.php:56-57`; `src/fortify/routes/routes.php:30,47-50` | passkey bridge, domain, pipeline, guard, limiters | nullable bridge values / retained optional-member fallbacks / dynamic |
| `src/passkeys/src/Passkeys.php:50,91,291`; `src/passkeys/routes/routes.php:11,21` | relying party, origins, secret, guard, throttle | null is validated at use or disables middleware |
| `src/permission/src/PermissionRegistrar.php:242,260-261`; `src/permission/src/Guard.php:184` | team model, pivot keys, guard provider | null / dynamic fallback |
| `src/queue/src/Console/WorkCommand.php:330-333` | `queue.output_timezone` | null uses application timezone |
| `src/saloon/src/Console/Commands/MakeCommand.php:103`; `SaloonManager.php:460,562` | namespace and cache/limiter stores | null disables/selects defaults |
| `src/sanctum/src/Http/Middleware/EnsureFrontendRequestsAreStateful.php:54-58`; `SanctumServiceProvider.php:126-138,221`; `PersonalAccessToken.php:171,201,261,285,329`; `Console/Commands/PruneExpired.php:50` | middleware, expiration, cache store/timings | null removes middleware or selects supported package behavior; class fallbacks protect replaced middleware arrays |
| `src/scout/src/Traits/ConfiguresJobOptions.php:42-53`; `ScoutServiceProvider.php:110-116,156`; `Console/IndexCommand.php:67-68` | job options, Algolia timeouts, Meilisearch key, index settings | null / dynamic |
| `src/session/src/SessionManager.php:96,108,199,246` | connection, auth provider, block store | null / dynamic |
| `src/socialite/src/SocialiteManager.php:60-192` | provider credentials, including X alternate key | optional dynamic service config |
| `src/telescope/src/Telescope.php:194` | `horizon.path` | retained fallback; Horizon is an optional package and its config may not be loaded |
| `src/telescope/src/Telescope.php:197-198,625-628,823`; `TelescopeServiceProvider.php:68,70,203`; `Jobs/ProcessPendingUpdates.php:41,49,51`; `Http/Controllers/EntryController.php:63` | Telescope path/domain/queue/watcher | null / mixed / dynamic |
| `src/foundation/src/Testing/Concerns/InteractsWithParallelDatabase.php:58-106`; `InteractsWithRedis.php:242`; `src/testing/src/Concerns/TestDatabases.php:172-174`; `src/testbench/src/Bootstrap/LoadConfiguration.php:28,90-107`; `Concerns/HandlesDatabases.php:78-81`; `Foundation/Concerns/HandlesDatabaseConnections.php:50,75`; `Factories/UserFactory.php:59` | mutable test connection/model config | test harness / dynamic / null |
| `src/testbench/src/Concerns/CreatesApplication.php:128` | application timezone | nullable subclass extension point |
| `src/tinker/src/Console/TinkerCommand.php:54,150` | `trust_project`, casters | union / optional package config |
| `src/auth/src/Notifications/ResetPassword.php:97` | `auth.passwords.{broker}.expire` | retained fallback; the dynamic broker may have no expiry member |
| `src/support/src/ServiceProvider.php:325` | `database.migrations.update_date_on_publish` | retained fallback; intentionally optional member of a replaced array |
| `src/horizon/src/Repositories/RedisJobRepository.php:67-72`; `RedisMetricsRepository.php:231,256`; `src/horizon/src/Listeners/StoreTagsForFailedJob.php:32`; `TrimMonitoredJobs.php:30`; `TrimFailedJobs.php:30`; `src/horizon/src/Http/Controllers/DashboardStatsController.php:25`; `src/horizon/src/Console/SnapshotCommand.php:30` | `horizon.trim.*`, `horizon.metrics.*` | retained fallbacks; intentionally optional members of replaced arrays |
| `src/mail/src/MailServiceProvider.php:67-69`; `src/notifications/src/Channels/MailChannel.php:114` | `mail.markdown.*` | retained fallbacks; applications may provide a partial Markdown options array |
| `src/scout/src/ScoutServiceProvider.php:141-142` | `scout.meilisearch.retries`, `initial_retry_delay_ms` | retained fallbacks; Hypervel-only options in an otherwise upstream-compatible replaced block |
| `src/cache/src/CacheManager.php:410` | named store `prefix` | retained inheritance; an omitted per-store prefix uses `cache.prefix` |

After conversion, use broad greps across `src/` to inspect every remaining direct `config('...')`, `Config::get()`, config-repository `get()`, cast around config access, typed getter with a fallback, repository ArrayAccess (`$config[...]` / `config()[...]`), and `has()`-then-`get()` pair. Each remaining occurrence must match this inventory or a documented generic repository implementation. Update the inventory when a final decision changes; do not silently add exceptions.

## Regression Coverage

Add tests at the public behavior boundary rather than tests that merely assert which repository method was called.

1. **Scheduling:** in the Foundation console kernel tests, prove a null `app.schedule_timezone` makes scheduled events inherit `app.timezone`; a null `cache.schedule_store` leaves both `CacheEventMutex` and `CacheSchedulingMutex` on the default store; and a configured store selects it for both mutexes. Prove `SCHEDULE_CACHE_DRIVER` no longer affects configuration while `SCHEDULE_CACHE_STORE` does through the config-loading surface.
2. **Sessions:** extend `StartSessionTest` so a null `session.block_store` reaches `CacheFactory::store(null)` and the blocking request still uses the cache lock path.
3. **Mail:** render both HTML and text Markdown message views with `app.url = null`; prove the message body and application name still render and the header URL is empty rather than causing a typed-access failure.
4. **JWT:** extend `JwtManagerTest` with RS256 and the existing asymmetric-key fixtures while `jwt.secret` is null; encode and decode a token to prove null is valid for asymmetric signing.
5. **Passkeys:** extend route tests with `passkeys.throttle = null` and assert login and management routes omit throttle middleware. With `app.url = null`, prove explicit relying-party/origin env values load without eager fallback failure; without those env values, prove config still loads and `relyingPartyId()` / `allowedOrigins()` raise their domain-specific errors when used. With `app.key = null`, cover both an explicit passkey secret and the domain-specific missing-secret error at use.
6. **Fortify:** cover the same explicit and absent passkey env/secret cases through provider registration, proving nullable values can cross the Fortify-to-Passkeys bridge without breaking application boot and required values still fail when a WebAuthn operation requests them. Confirm the package config and published stub expose the verification limiter default while an existing replacement `limiters` array still receives the route fallback.
7. **Telescope:** extend HTTP route tests with `telescope.path = null` and prove dashboard/API routes register and respond without a prefix.
8. **Permission:** extend registrar tests so null role/permission pivot keys resolve to `role_id`/`permission_id`; extend assigned-model behavior so a null default model falls back to the authenticated guard model for raw IDs.
9. **Legacy shapes:** add focused tests proving the current custom migration-table array works and the old scalar `database.migrations` value fails; prove an array `logging.deprecations` with null channel works and the old scalar form fails.
10. **Sentry:** add env/config coverage proving `SENTRY_LOG_LEVEL` wins, `LOG_LEVEL` is its fallback, and `SENTRY_LOGS_LEVEL` is ignored.

Existing functional coverage remains the guard for known nullable behavior, including `app.asset_url`, `app.key`, `app.editor`, `app.url`, `cache.serializable_classes`, `logging.default`, `session.connection`, Horizon name/domain, gRPC compression, Inertia bundle/hot URL, JWT TTL/refresh/issuer, Fortify guard/redirects, Permission nullable models/keys, Sanctum expiration/store/middleware, Saloon nullable stores, Scout job options, and queue output timezone. Do not add duplicate tests where that behavior is already directly asserted.

Run every changed or new test file immediately from the repository root with `./vendor/bin/phpunit --no-progress <path>`. Fix straightforward mistakes immediately; stop and investigate any behavioral contradiction or non-trivial defect before changing the contract.

## Documentation

1. Update `src/docs/configuration.md` with repository, facade, and `config()->{type}()` examples. Explain that typed getters are for non-null settings, `get()`/direct helper reads are for meaningful null or mixed values, and defaults belong in shipped config rather than repeated at callers.
2. Update `src/docs/scheduling.md` to document `cache.schedule_store` / `SCHEDULE_CACHE_STORE` and that null selects the default cache store.
3. Update `src/docs/sentry.md` so `SENTRY_LOG_LEVEL` falls back directly to `LOG_LEVEL`; describe `SENTRY_LOGS_LEVEL` as the removed backwards-compatibility alias for the upstream rename.
4. Add one concise, action-focused entry to the Configuration section of `src/docs/porting-from-laravel.md`: Hypervel requires `database.migrations` to be an array with a required `table` member (`update_date_on_publish` remains optional), requires array-form `logging.deprecations`, uses `cache.schedule_store` / `SCHEDULE_CACHE_STORE`, and does not support Laravel's old `SCHEDULE_CACHE_DRIVER`.
5. In the Providers section of the same guide, change the post-merge Courier singleton example to `$app->make('config')->array('courier')`. Explain that `isEnabled()` runs before the provider's own `mergeConfigFrom()` and therefore may read only already-loaded application/framework config or an intentional fallback. Use `config()->boolean('courier.enabled', false)` in that example so the optional unpublished flag is typed without throwing.

Do not duplicate these docs in package READMEs. The removed Sentry env alias is package-specific and does not change a normal Laravel porting decision, so it belongs only in the Sentry documentation.

## Implementation Order

1. Add or correct shipped config definitions and published stubs.
2. Remove the four confirmed Laravel compatibility branches: schedule env alias, scalar migration config, scalar deprecation config, and renamed Sentry env alias.
3. Add and run the regression tests for those contract changes and for the eager passkey-default defects.
4. Convert production access package by package, one file at a time. Update an existing test file only after its production slice is coherent, then run that test file immediately.
5. Add the missing functional null tests and run each file immediately.
6. Make targeted documentation edits.
7. Re-run the residual-access audit and inspect the diff for accidental API, typing, config, or upstream-mergeability changes.

## Final Verification and Review

1. Run focused package suites for every package with changed production behavior.
2. Run `composer fix` once. It covers formatting, PHPStan, the parallel suite, and Testbench package-mode tests.
3. If a full check fails, use targeted checks to fix it, then run the failed step and every later step from the `fix` script. Repeat the whole command only if the correction warrants it.
4. Review every changed file and trace each config value from definition through merge semantics to consumer and test. Confirm null branches remain reachable, required settings fail loudly, deprecated aliases are absent, current public behavior is covered, and no duplicate fallback, stale comment, dead normalization, or workaround remains.
5. Re-run broad residual greps and `git diff --check`, then report the final result and notify the user.
