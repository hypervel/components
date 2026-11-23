# Framework-wide PhpRedis 6.3 minimum

## Status and purpose

Deferred; obtain authorization before implementing. Development and tests already require PhpRedis `^6.3` in the components root manifest. Published packages continue supporting their existing client versions, including the separate requirements for hash-field expiration.

Adopt one PhpRedis minimum across installed applications to remove obsolete client-version branches. Hash-field expiration is used by Redis cache tagging and user-session tracking; the native test doubles also need the methods declared by PhpRedis 6.3. Do not replace those native methods with test fixtures or add compatibility shims.

Redis remains optional for applications that do not use it. A Composer conflict rejects an installed extension below 6.3 even when that application does not use Redis; users must upgrade or unload it. Document PIE as an installation option, not a framework dependency. Before implementation, check current package availability and the current code against this plan.

## Metadata and installation

- Keep root `composer.json` at `ext-redis: ^6.3`; update `src/horizon/composer.json` to the same requirement.
- In `src/redis/composer.json`, retain the optional extension suggestion and add `"conflict": {"ext-redis": "<6.3"}`. Framework, Foundation, Cache, Session and Queue already require this package, so do not duplicate the conflict in their manifests or require Redis for every application.
- Update the Redis, Cache and Session extension suggestions to one `^6.3` requirement, removing feature-specific client clauses.
- Use Composer commands for root dependency changes. Split-package manifests have no lockfiles and are edited directly. Never commit the components root lockfile.
- Composer's conflict behavior was verified with a disposable local package: an absent extension and version 6.3 resolve; version 6.2 fails. Repeat this focused check against the final metadata rather than building a permanent Composer test harness.

## Shared requirement and diagnostics

In `src/redis/src/RedisRequirements.php`:

- Set `MINIMUM_PHPREDIS_VERSION` to `6.3.0` and remove its separate-feature qualification.
- Remove `HASH_FIELD_EXPIRATION_PHPREDIS_VERSION` and `hashFieldExpirationClientError()`.
- Add `clientError(string|false $version): ?string`, accepting `phpversion('redis')` directly. Return null for supported clients; report the required and installed versions otherwise. For false, say the extension is not installed rather than presenting a fictitious version zero. Include `pie install phpredis/phpredis` as the installation/upgrade instruction.
- Preserve the Redis and Valkey server constants and `hashFieldExpirationServerError()`.

Use the shared client check in both diagnostics:

- `src/cache/src/Redis/Console/Doctor/Checks/PhpRedisCheck.php`: remove the tag-mode constructor parameter and private version-selection method. Update construction in `DoctorCommand.php`. Retain missing-extension detection and useful install/upgrade instructions. Use the spelling `PhpRedis` consistently in the touched output and matching tests.
- `src/doctor/src/Diagnostics/RedisSupportsConfiguredFeatures.php`: check the client once, then check servers only for configured features requiring hash-field expiration. Remove the second, feature-specific client check and the `?: '0'` conversion. Retain inactive-service skipping, real connection failures and cancellation handling.

Do not add production test seams to diagnostics. Test version decisions through the existing plain-input requirement test and retain existing diagnostic integration coverage.

## Remove obsolete client branches

- In `RedisConnection::setOptions()`, remove the pre-6.2 `OPT_PACK_IGNORE_NUMBERS` availability guard. Keep the option mapping and normal invalid-option validation. Remove the stale PhpRedis 6.0 qualification from `pack()`.
- In `Foundation/Testing/Concerns/RequiresHashFieldExpiration`, remove the client branch and `detectedPhpredisVersion()`. Keep server detection, memoization, skip reasons and `flushHashFieldExpirationState()`.
- Remove corresponding old-client test cases and version-dependent skips. Preserve supported behavior assertions and server-capability tests.

### Boundaries that stay

- Ordinary Redis server 6.2+ and Valkey 7.2+ remain supported. Features using hash-field expiration still require Redis 8+ or Valkey 9+.
- Keep cache doctor's `RedisVersionCheck` and `HashFieldExpirationCheck`, including the actual command probe. A supported PHP client does not establish server or command availability.
- Keep optional serializer/compression build checks and Cluster-specific limitations such as TCP keepalive behavior.
- Keep service opt-in skips in reusable integration-test traits. A configured but unusable Redis connection must still fail.
- The hash-expiry call-site audit covers native calls and Lua in AnyTag `Add`, `Put`, `PutMany`, `Touch`, `Increment`, `Decrement` and `Forever`, plus `RedisSessionHandler` and cache doctor checks. Their remaining branches select topology, TTL, configured tag mode, tracking or metadata behavior; they are not old-client fallbacks and must not be removed for this bump. Facade annotations and telemetry command names need no change.

## Tests to update

- `tests/Composer/PackageManifestConsistencyTest.php`: remove the `ext-redis` subset exception and its unused imports and parser; exact equality applies again once the development and published requirements match.
- `tests/Redis/RedisRequirementsTest.php`: move existing client cases to `clientError()`, use false for the missing extension, and retain older/prerelease/minimum/newer version cases and all server cases.
- `tests/Redis/PackageMetadataTest.php`: preserve the optional-extension assertions; add the conflict assertion to that existing test.
- `tests/Session/PackageMetadataTest.php`: update the exact extension-suggestion assertion. Horizon's metadata test already checks the dependency's presence.
- `tests/Foundation/Testing/Concerns/RequiresHashFieldExpirationTest.php`: remove the old-client skip case, version stub property, assignments and override; retain server failures and memoization coverage.
- `tests/Cache/Redis/Console/DoctorCommandTest.php`: replace the tag-mode client-version dataset with the single-baseline check; remove the old-client skip from the zero-named-store case and update changed diagnostic text.
- `tests/Doctor/Feature/Diagnostics/RedisSupportsConfiguredFeaturesTest.php`: remove obsolete version attributes and the unsupported-client-only integration test; the shared requirement test covers that decision.
- Remove `OPT_PACK_IGNORE_NUMBERS` skips in `tests/Redis/RedisConnectionTest.php`, `tests/Redis/PhpRedisClusterConnectionTest.php`, `tests/Integration/Cache/Redis/RedisStoreTest.php` and `tests/Integration/Cache/Redis/SerializationIntegrationTest.php`. Delete the standalone test expecting the old-client availability exception.
- Remove redundant Redis extension-presence attributes from `tests/Integration/Horizon/Feature/SupervisorTest.php` and `tests/Integration/Queue/Redis/{RedisQueueDriverTest,RedisQueueTest,ThrottlesExceptionsRedisStoreTest,RateLimitedRedisStoreTest}.php`: the development root requires the extension. Remove unused imports; do not remove service configuration checks.

## Documentation and CI

- Update the root README and `src/docs/installation.md` minimum. State 6.3 and show `pie install phpredis/phpredis` in `src/docs/redis.md` installation guidance.
- Amend the existing Redis sentence in `src/docs/porting-from-laravel.md` with the minimum and an installation link; do not add a duplicate section.
- Remove redundant client-version qualifiers from `src/docs/cache.md`, `src/docs/session.md`, the `pack_ignore_numbers` documentation, and `RedisStore::setTagMode()`. Preserve server requirements and explain Redis **or** Valkey, not both together.
- Update foundation `config/cache.php` and `config/session.php`, plus both application skeleton mirrors, to describe only the additional server requirements for the relevant features.
- Update stale client-skip comments in `tests/Integration/Cache/Redis/RedisCacheIntegrationTestCase.php` and `tests/Integration/Auth/Redis/EloquentUserProviderCacheTagsTest.php`.
- Remove the two feature-specific PhpRedis comments in `.github/workflows/redis.yml`. The CI Dockerfile already installs and validates 6.3: retain those build checks and change the validation error's rationale from integration coverage to the framework minimum. No new installation workflow, installer dependency or manually pinned binary is needed.
- Leave historical plans and the factual INCREX note in `docs/todo.md` unchanged. Do not update consumer lockfiles merely to replace suggestion text.

## Verification and completion

Search all source, tests, manifests, configuration, CI and docs for the removed symbols and client-version requirements, including raw hash-expiry commands in Lua. Check private package/application consumers under the normal consumer-check rules; none referenced the removed helpers at investigation time.

Run each edited test file immediately. Exercise existing cache hash-expiry and Redis session-tracking integration coverage with an isolated Redis service; rely on CI for the full Redis/Valkey/Cluster matrix. Validate Composer metadata and optional-extension conflict behavior, run source analysis and formatting, then self-review and obtain code review before committing and publishing.

The final change must contain no obsolete client branches, test stubs, skips or explanatory text. Preserve the server/build/topology checks above. This is a requirement and maintenance improvement, with no new hot-path work or changes to application-facing Redis operations.
