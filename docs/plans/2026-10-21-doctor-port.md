# Hypervel Doctor port

## Status and authorization

Target branch: `feature/doctor`, based on `0.4`. All paths below are relative to the components repository unless otherwise stated.

This is a new package port plus the framework corrections described below. Implementation, the timezone architectural adaptation, and the shared Redis requirements API are authorized. API-compatible correctness fixes and their supporting refactors do not need separate approval, including those in other framework packages. This plan alone does not authorize staging, committing, or pushing.

Implementation and verification are complete.

Follow the repository's `AGENTS.md` and other applicable instructions; this plan does not override them.

## Approved architectural adaptation

| # | Approved decision | Reason |
|---|---|---|
| 1 | Omit public `ApplicationTimezoneIsValid` and the command's timezone termination workaround. | Invalid application timezone fails during framework boot, before Doctor. A bypass would complicate bootstrap for no useful diagnostic benefit. |

## Doctor enhancements

These are diagnostic coverage/design additions within the port, not Laravel API removals. The shared cross-package Redis API is approved:

| Enhancement | Reason |
|---|---|
| Check installed dependencies' extension requirements, versions, and providers/replacements. | Read framework requirements such as the Swoole minimum from Composer metadata rather than duplicating constants. |
| Check installed dependencies' PHP constraints. | Composer's generated runtime check does not enforce upper bounds and can be disabled. |
| Warn about degraded failover capacity. | Report unavailable alternatives even when another cache/queue backend remains usable. Detecting total failure is a correctness fix. |
| Add conditional Redis feature checks using shared Redis-owned requirements. | Diagnose only the capabilities used by active services, without hot-path network work. |
| Check required Mercure broadcaster settings. | Cover Hypervel's first-party broadcaster through the active-driver configuration diagnostic. |

The remaining changes follow the porting policy: modern types, coroutine safety, supported Hypervel equivalents, and verified correctness fixes. This includes production environment-file handling, actual Git protection checks, effective cache-table repairs, the Redis-session fallback, migration isolation, and shared constants supporting those fixes. Any additional public/protected API departure, capability removal, schema change, or material performance tradeoff discovered during implementation must return to the owner. Do not silently substitute a workaround when approval is needed.

## References and package structure

Reference package: [Laravel Doctor](https://github.com/laravel/doctor), using the current checked-out upstream source. Preserve its class names, method/parameter names, protected extension points, file layout, selection semantics, result structures, rendering formats, and fix callbacks unless this plan explicitly identifies a difference for approval.

Create `src/doctor`, namespace `Hypervel\Doctor`, package `hypervel/doctor`. Use `src/cache/{composer.json,LICENSE.md,README.md}` for the first-party package skeleton. Copy upstream files with `cp`, then read and edit each copy individually. Do not recreate ported files from selected fragments or run bulk rewrite scripts.

Wire both Composer manifests:

- Split package PSR-4 namespace, dependencies, branch alias, and `extra.hypervel.providers`.
- Root PSR-4 mapping, `replace`, and provider discovery entry. Add optional Doctor through discovery, not `DefaultProviders` or early bootstrap.
- Keep the facade and upstream provider registrations. Remove only the redundant concrete `ComposerJson` singleton binding; keep the `Doctor` factory that installs the default diagnostics.
- Use Hypervel console, contracts, process, support, prompts, database, cache, session, filesystem, Redis and other directly used split packages. Verify the final direct imports/helpers against their owning packages; the development root's broad dependencies are not evidence that the split package is complete.
- Retain `composer-runtime-api` and `composer/semver`. Add `laravel/agent-detector` as a standalone dependency, preserving its actual namespace. Check Packagist for the latest compatible stable release immediately before `composer require`; do not invent a Hypervel port of this independent package.
- Do not add `composer/composer`, Pest, a dependency solver, or a generic subprocess adapter.
- Root dependency changes use Composer commands. Root `composer.lock` stays untracked. Update split manifest requirements directly, as permitted for unlocked monorepo packages.
- Audit touched framework manifests too: the extracted Redis requirements become a direct Foundation dependency; declare `hypervel/redis` there rather than relying only on Session's transitive requirement. Database already requires Context for the migration state change.

Copy `config/doctor.php`, retaining `only`, `except`, and environment-mode behavior. Convert framework names and examples. Keep the diagnostic generator and both stubs, with strict types and modern Hypervel signatures.

User documentation belongs in `src/docs/doctor.md`, adapted from upstream README. Add its navigation entry in `src/docs/documentation.md`. Keep the package README minimal with documentation/upstream links and only deliberate public differences. Record the approved timezone omission in README, a source comment at its registration/workaround locations, and a test omission note naming the upstream file in the shared TestCase. Add a concise porting-guide entry for existing Laravel Doctor usage that needs adaptation, including `EnvironmentMode::fromHypervelEnvironment()` alongside the namespace change; do not turn that guide into a bug-fix inventory.

## Lifetimes, cancellation, and command behavior

| State | Ownership and implementation |
|---|---|
| Registered diagnostic classes | Worker-lived `Doctor` registry. Add `Boot-only.` warnings to its registration methods. |
| Diagnostic invocation | In `Doctor::selected()`, resolve through `makeTransient($class)`. This reproduces Laravel's fresh implicit resolutions while respecting explicit application bindings. |
| Pending run and result objects | Construct per invocation as upstream does. Do not add blanket `Transient` markers. |
| Root `ComposerJson` contents | Keep existing instance memoization. Bundled fixes do not modify root composer.json. |
| Installed dependency metadata | Fresh snapshot once per diagnostic check, including the post-fix pass. Never worker-cache health verdicts or the installed graph. |
| Diagnostic source attribution | Keep upstream class-keyed metadata cache, naturally bounded by application classes. Add `flushState()` and optional-package cleanup in `AfterEachTestSubscriber`. |
| CLI renderers | Retain command-owned rendering state. Hypervel already clones command prototypes for normal and nested runs; do not add another command lifecycle layer. |

Derive DiagnosticSource's built-in diagnostic namespace as `__NAMESPACE__ . '\\Diagnostics\\'` rather than another literal namespace.

`Doctor::check()` and `fix()` must rethrow `Swoole\Coroutine\CanceledException` unchanged before converting ordinary failures into results. Apply this to the following diagnostic I/O catches, where swallowing cancellation would continue work or produce a misleading result:

- `CacheStoreIsReachable`: check probe, fix probe, `fixOptions`, and `cacheTableIsMissing`.
- `DatabaseConnectionIsReachable`, `FilesystemDisksAreReachable`, `MigrationsAreUpToDate`, `QueueConnectionIsReachable`, `RedisConnectionsAreReachable`, and `SessionDriverIsReachable`.
- New failover child probes and `RedisSupportsConfiguredFeatures`.

Keep owned probe cleanup in `finally`; cleanup errors must not replace an existing failure or cancellation. Do not blanket-edit non-I/O reflection catches or the separate manual `cache:redis-doctor` command's cancellation behavior.

Use `Hypervel\Console\CommandInput` and remove the Laravel-version compatibility TODO: `boolean()` for flags, `array()` for selectors, and `string()->toString()` for format/exit-threshold strings. `string()` returns a Stringable object, not a native string. Preserve option validation and explicit-format detection. Hypervel Prompts supports coroutine task animation; remove the `pcntl_fork` requirement. Keep interactive/noninteractive and JSON/GitHub restrictions. Correct `fixDecision()` before any prompt path:

```php
if ($this->input()->boolean('fix')) {
    return ! $outcome->fixRequiresOption();
}
```

Thus `--fix` applies unambiguous fixes and skips choice-based fixes even with interactive input; an explicit interactive run can still choose an option. Do not select a store on the user's behalf.

Keep upstream repairs and scope restrictions, translating commands to Hypervel. `make:cache-table` is canonical. Scheduler advice uses Hypervel's long-running `schedule:run`; do not recommend nonexistent `schedule:work` or an every-minute launcher for the long-running command. Background/deferred queues pass the asynchronous diagnostic in both environment modes with in-process coroutine wording and no worker advice; ordinary external queues retain worker advice. Apply this to the first concrete failover child as well.

Honor the queue manager's built-in `null` connection without requiring a configuration record. Reachability can resolve it normally; the asynchronous diagnostic skips with an explicit explanation that null queues discard jobs, including named null drivers and null failover children.

`Link::docs()` uses ordinary `https://hypervel.org/docs/...` URLs in all renderers. Hypervel's `.md` endpoints are not available. Verify every translated link and anchor against `src/docs`.

Programmatic checks remain supported. Repairs that write deployment files or mutate global configuration are maintenance operations; document that they must not run concurrently with application traffic. Do not add a subprocess repair framework or weaken the existing fix API. Existing Process execution already returns idle DB connections before waiting; do not duplicate pool-release calls throughout Doctor.

## Infrastructure diagnostics

### Database and SQLite

Resolve the registered `db.factory`, preserving `DB::extend()` custom factories. Use its public `parseConfig($configuration, $name)` for normalized driver detection, SQLite handling and timeout defaults; this owns database URL parsing and name/prefix defaults. Build the standalone probe with that factory's `make()`. Do not duplicate normalization with Doctor-owned parser calls or purge/disconnect the application's cached connection.

```php
$connection = app('db.factory')->make($configuration, $name);

try {
    $connection->getServerVersion();
} finally {
    $connection->disconnect();
}
```

Use the framework's driver-neutral API: `getPdo()` exists only on PDO connections; `getServerVersion()` belongs to the driver-neutral connection contract. Preserve an explicitly configured PDO timeout and upstream's default probe timeout where applicable; that option does not bound custom transports, which own their timeouts. Do not invent a driver registry or cast a custom connection to PDO. Remove SQL Server-specific probing.

Identify drivers from normalized configuration, not connection names. A connection named `analytics` can be SQLite. Use `SQLiteDatabase::isInMemory()` and `isUri()` so `:memory:` and supported `file:` URI forms never become bogus local files. A URI is handled by the SQLite connector, not by Doctor's plain-file creation repair. Keep schema probes cheap (`hasTable`), without counting application tables or reading application rows.

### Migrations: fix the owning state first

`DatabaseServiceProvider` registers both `migrator` and `migration.repository` as singletons. Their connection fields currently override the coroutine default. A verified interleaving is A `usingConnection('tenant_a')`, B `usingConnection('tenant_b')`, then A querying the repository: A's context is `tenant_a` but its repository resolves `tenant_b`. Restoration can also leave stale connection state. Concurrent `queue:work --concurrency` provisioning jobs calling migration commands exercise this path. `migrate:install` directly changes repository source as well.

Implement the migration correctness fix as follows:

- Add `Migrations/MigratorState` with nullable `connection` and `OutputStyle` fields. Implement `ReplicableContext::replicate()` by cloning the state, so `Coroutine::fork()` inherits values without sharing subsequent field mutation.
- Preserve `Migrator::$connection` and `$output` as protected typed properties through PHP 8.4 hooks. Reads use `currentState()?->...` without allocating state; setters initialize state when needed.
- Store state in `CoroutineContext` under a private/protected owning key prefix plus `spl_object_id($this)`. Outside coroutines use a private instance state field. Follow the existing `ClientManager` property-hook pattern, without its client-resource cleanup machinery.
- Make `DatabaseMigrationRepository::$connection` a corresponding hooked nullable property. A scalar context value plus non-coroutine instance field is sufficient; no second state class is needed.
- Preserve `usingConnection()`'s independent stored-connection and default-context snapshots/restoration. Keep the repository interface and custom bindings; custom implementations own their own coroutine safety.
- Keep migration paths, resolver callbacks, exclusions and immutable file metadata worker-lived. Do not scope the entire migrator, which would lose boot-registered paths. Do not lock all migrations or clone repositories inside Doctor.
- Correct the existing docblock that incorrectly claims no worker-global mutation. Update any touched public worker configuration mutator warnings.

`InstallCommand` needs no extra restoration mechanism: its repository source now belongs only to the calling coroutine. Retain its existing same-coroutine behavior.

Property shape:

```php
protected ?string $connection {
    get => $this->currentState()?->connection;
    set { $this->state()->connection = $value; }
}
```

Doctor then obtains migration files from registered paths plus `database_path('migrations')`, and inspects the repository inside `usingConnection(null, ...)`. This applies `migrations_connection` consistently with `migrate:status`, without changing ambient context afterward. Preserve the local-only subprocess migration repair and its error output.

### Active services and wrappers

Extend existing `Support/ActiveDrivers`, alongside `logChannels()` and `mailers()`, with `cacheStores(string $store, array $seen = []): array`, `queueConnections(string $connection, array $seen = []): array`, and `redisConnections(): array`, returning ordered lists of names. Keep the existing static-helper style. Walk only selected services, not every configured tenant/inactive store. Use path-local cycle detection and deduplicate names while preserving configured order. These discovery lists do not replace wrapper-aware reachability verdicts.

- Cache `stack` accepts numeric store names and named entries with per-layer options; follow `CacheManager::createStackDriver()`'s actual shape.
- Failover cache uses configured child stores; failover queue uses child connections. Redis discovery unwraps both wrapper types, and required service-configuration checks follow active failover queue branches.
- A stack round-trip already reaches its layers and propagates false/exception failure. Do not repeat those layer probes just to reproduce the wrapper's work.
- Probe the selected cache repository, including wrappers, and every failover child (primary included). Probe a child stack as a whole; traverse ordinary stack layers only to discover nested failovers. Deduplicate supplemental probes by name within the check. A false write, wrong read, or thrown probe/cleanup error fails. Successful writes/reads with unhealthy failover children warn, including when wrapper cleanup returns false; explain that cleanup could not be confirmed and list child failures separately, without claiming they were its only cause. Cleanup false without unhealthy failover children fails. Queue failover retains all-healthy/pass, partly-healthy/warn, all-unhealthy/fail verdicts using its non-writing probes.
- Judge async configuration by the first concrete child of a failover queue; fallback children are checked for reachability. Resolve the configured driver rather than treating the connection name as the driver.
- Include each active Redis cache store's effective lock connection (`lock_connection ?? connection`) as well as its data connection. Lock-only connections need reachability/base client support, not hash-field feature requirements. Deduplicate names within each check. Reachability and feature compatibility are separate diagnostics; do not add a global probe-result cache to couple them.

Do not misreport an empty/malformed wrapper discovery as verified healthy. Let actual supported manager resolution expose invalid required configuration; avoid a second framework configuration validator.

Reject recursive built-in wrapper configurations at construction in their owning managers. Cache stack construction recurses immediately; lazy cache/queue failover cycles can exhaust process memory during an operation. Use local configuration walks, with no worker state or per-operation checks:

- `CacheManager`: validate the actual supplied root record in `createStackDriver()` and `createFailoverDriver()`, following named children through `getConfig()`. Start with an empty named path: an on-demand build's label is not a named store identity. Stop at custom creators and non-wrapper records. Follow numeric and named stack entries exactly as the factory does.
- `QueueManager`: keep connector construction inside the existing construction callback. If its connector is a `FailoverConnector`, walk children whose driver matches that confirmed root driver. Do not construct child connectors for inspection; custom drivers own their configuration semantics and factory failures must remain lazy.
- Follow only usable wrapper child arrays; leave missing backups and other resolution failures deferred to normal failover. Track paths per branch, so shared children are valid. Throw `InvalidArgumentException` with the repeating chain and the existing Mail-style wording: `Circular cache store definition detected: a -> b -> a.` (or `queue connection`).
- Doctor's discovery helpers retain explicit path-local cycle errors because some consumers never resolve the selected manager. Cache reachability's supplemental failover discovery may stop at repeats: its actual root probe owns the failure. Remove discarded discovery calls from repair-candidate probes once manager validation owns this protection.

### Cache round-trip and repair

Resolve the chosen repository once. Use a unique short-lived probe key, require successful `put`, verify that `get` returns the value, and call `forget` in `finally`. Preserve cleanup's false return separately from thrown failures so the degraded failover rule above can apply; alternative-store repair candidates still require a completely successful probe. Preserve the primary exception if cleanup also fails; cancellation takes precedence and propagates. The `null` driver intentionally stores nothing: skip with an explicit explanation. Exclude null children from supplemental failover probes, while still requiring the selected wrapper's actual round-trip to succeed. The session cache driver supports an in-memory CLI round-trip and needs no bypass.

Retain upstream's fixed `file`, `database`, and `redis` repair candidates, removing Memcached. A candidate must be configured and use a different driver from the failing store. Do not enumerate every configured store. Require the candidate probe to pass except for the supported database-table creation repair:

- `make:cache-table` generates separate `cache` and `cache_locks` migrations using the default schema connection. It does not consume arbitrary cache table/connection settings.
- Creation is eligible only for the generated table names and effective default data/lock connections. Use the same predicate when presenting and executing the repair. A custom layout is still selectable if its existing store passes the probe.
- Add `DatabaseStore::DEFAULT_LOCK_TABLE = 'cache_locks'`; use it in the constructor default, `CacheManager`, the generator, and Doctor. Add `CacheTableCommand::TABLE = 'cache'`: use it in both `handle()`'s table map and `migrationTableName()`, with `DEFAULT_LOCK_TABLE` for the other map key. Doctor compares against those actual generator constants. Keep `cache.stores.*.table` required, without a fallback.
- Keep shipped config values concrete/readable and test agreement with their owning constants. Treat omitted/null optional lock settings exactly as the manager does.
- The option label states that it creates cache tables and runs all pending migrations. An existing but unrun generated migration is valid: the generator warns and returns success, then `migrate --force` applies it.
- Re-probe before updating the selected store. Do not claim success merely because generation/migration commands exited successfully.

### Sessions and Redis feature requirements

Add public `SessionManager::DEFAULT_REDIS_CONNECTION = 'session'` and use it in the factory and both Doctor consumers. Do not change database-session connection fallback semantics or interpret the string `session` as the database default. Read database-session and database-queue table names as required settings, matching `SessionManager` and `DatabaseConnector`; remove upstream's invented `sessions` and `jobs` fallbacks and misleading fallback names in errors.

Add `Hypervel\Redis\RedisRequirements` with owning constants:

```php
public const string MINIMUM_PHPREDIS_VERSION = '6.1.0';
public const string HASH_FIELD_EXPIRATION_PHPREDIS_VERSION = '6.3.0';
public const string HASH_FIELD_EXPIRATION_REDIS_VERSION = '8.0.0';
public const string HASH_FIELD_EXPIRATION_VALKEY_VERSION = '9.0.0';
```

Expose two pure methods returning an unsupported reason or `null`:

```php
public static function hashFieldExpirationClientError(string $version): ?string;
public static function hashFieldExpirationServerError(array $info): ?string;
```

Keep client detection and network access at callers. Preserve Valkey-version precedence over its Redis compatibility version. Missing server version means support could not be established. Copy the primary existing implementation before extracting it; remove replaced constants/logic from the original locations.

Consumers:

- `cache/src/Redis/Console/Doctor/Checks/PhpRedisCheck.php`: base or feature client requirement, with existing presentation.
- `RedisVersionCheck.php`: server requirement with its existing separate verdict and command behavior.
- `HashFieldExpirationCheck.php`: remediation uses shared constants; retain its functional checks and stress behavior.
- `foundation/src/Testing/Concerns/RequiresHashFieldExpiration.php`: keep detection overrides and memoization, reject old clients before fetching server info; unknown server versions no longer return true.
- New Doctor `Diagnostics/RedisSupportsConfiguredFeatures.php`, group `cache`, registered beside Redis reachability.

The new diagnostic skips when no Redis connection is active. Otherwise check the base phpredis minimum. Hash-field minimums apply only to active Redis cache stores with effective `tag_mode = any` or Redis sessions with `session.track_user_sessions`. Query `INFO server` only for those feature-bearing connections, after client support passes, once per distinct connection. Ordinary Redis usage must not require Redis 8/Valkey 9/phpredis 6.3. An active Redis connection without those features still receives the base client result, not a skip. Cluster `info()` checks the default node; do not describe it as an audit of every cluster node. No changes to application request/job hot paths.

## Paths, configuration, and environment

Use application-owned path APIs: `config_path`, `storage_path`, `database_path`, and selected environment path/file. Avoid reconstructing them from `base_path`.

- `ConfigurationFilesCanBeLoaded`: recursively inspect nested PHP config files as `LoadConfiguration` does; preserve file attribution. Do not instantiate a second application or duplicate the loader.
- `ConfigurationCanBeCached`: validate `eval('return ' . var_export($configuration, true) . ';')` instead of writing and requiring a unique temporary file. This preserves `__set_state` validation while removing filesystem work and scripts retained by CLI OPcache across programmatic runs. Preserve detailed serialization errors; remove the obsolete temp-file helper, imports and cleanup. The framework's cache-building command already uses eval for per-value error diagnosis; its main validation runs in a fresh subprocess, so it does not need this worker-lifetime fix.
- `Configured`: generic getters are intentional where the diagnostic must recognize missing/malformed/union values. Use typed getters for genuinely fixed-type configuration, not to bypass a diagnostic's error reporting.
- `RequiredConfigurationValuesAreSet`: add active Mercure broadcaster requirements for `url`, effective publishing credentials (`publish_secret` or `secret`), and effective subscription credentials (`subscribe_secret` or `secret`). Match the factory's truthy fallback precedence rather than Postmark's non-null precedence. Do not instantiate a hub or duplicate its algorithm/key validation merely to check required values.
- `StorageIsWritable`: include `storage/framework` itself, the standard required storage directories, and deduplicated parent directories of `getCachedConfigPath`, `getCachedRoutesPath`, `getCachedEventsPath`, and `getCachedPackagesPath`. Honor relocated configured session/view/file-cache paths where those services are active. Display actual paths. In `fix()`, recompute failures and create/chmod only failing paths, then recheck; leave healthy directories' permissions unchanged. In particular, a configured shared directory such as `/tmp` must not have its sticky bit removed by a repair for another path.
- `BootstrapCacheMatchesEnvironment`: report config, routes and events separately. Remove all compiled-view scanning; on-demand compiled views prove neither deployment optimization nor stale bootstrap config. Production: warn when none are cached; report a partial cache as a notice identifying cached/uncached types; pass when all are cached. Local: pass when none are cached, otherwise notice naming cached types. Missing optional optimizations are advice, not an application failure. Keep one upstream diagnostic/result rather than inventing nested diagnostic results.
- `EnvironmentFileExists`: inspect exactly `environmentFilePath()`, never `.env*`. Fix that selected file, using `.env.example` under `environmentPath()` when available. Never overwrite an existing selected file. In production, missing file skips; existing files are still checked by applicable diagnostics.
- `PublicStorageLinkExists`: use the configured public disk root and matching configured links as described below. Treat `.gitkeep` and `.gitignore` as placeholders; do not alter Testbench's skeleton or delete its placeholder to make tests pass.
- Retain configured filesystem links, disk probes, and local repair restrictions. Tenant-driver exceptions remain errors when a caller deliberately selects a tenant resource without context.

### Public storage links

The diagnostic and its repair must inspect the same configuration. Skip a non-local public disk with the existing `not-default-local-path` code and a message describing the local-disk requirement. For a local disk, read its configured `root` and select the `filesystems.links` entries whose normalized target equals that root. Reuse upstream's realpath-or-trailing-separator normalization; no hardcoded public link or storage root is needed. If no configured links target that disk, skip with a new `not-configured` code. Inspect the configured root lazily, stopping at the first entry other than `.gitignore`, `.gitkeep`, or dot entries; do not list an entire uploads directory to establish usage.

Evaluate each expected link in this order:

| State | Result |
|---|---|
| `realpath($link)` equals the normalized target | Valid, including relative links and symlink chains. |
| Neither `file_exists($link)` nor `is_link($link)` | Missing; eligible for the existing repair. |
| Existing symlink that is dangling or resolves elsewhere | Failure; no automatic repair. Explain explicit `storage:link --force` remediation. |
| Other conflicting file/directory | Failure; no automatic repair. Explain moving the conflict aside before `storage:link`; never remove it. |

Keep one diagnostic result with path-specific details. Mark it fixable only when every failing expected link is missing. Preserve `linked`, `missing`, `unused`, `created`, and `creation-failed`; add distinct message codes for wrong symlinks and conflicting paths. Do not add per-link result objects, inode/bind-mount detection, or a generic link-management layer.

Run plain `storage:link` in the existing subprocess for repair, without `--force` or `--relative`, then evaluate the expected links again using the same inspection routine. Return `created` only when the command succeeds and every expected link resolves correctly. Otherwise return `creation-failed` with useful path details and process output. A zero exit status alone is insufficient: `StorageLinkCommand` intentionally reports existing-path conflicts while remaining successful for idempotent deployment calls. Keep that command unchanged, including its rule that replacing a dangling symlink requires explicit `--force`. Forcing the command would also replace other configured links beyond this diagnostic's scope.

### Git tracking and ignoring

Use argument-array Process commands with bounded execution, the selected environment file's directory as working directory, and `LC_ALL=C`. Preserve inherited ignore rules and application subdirectories within a larger repository.

1. Establish applicable work-tree membership with `git rev-parse --is-inside-work-tree`. A missing executable surfaces from the array Process command as exit 127, not an exception; report the missing-Git skip. Recognize “not a repository” only from exit 128 plus the C-locale `not a git repository` stderr message, with its own skip explanation. Exit 128 alone also covers ownership/permission failures; those remain diagnostic errors. A false work-tree result is not a work tree.
2. `git --literal-pathspecs ls-files --error-unmatch -- <selected-file>`: success means tracked and therefore unfixable failure; explain manual `git rm --cached` remediation. Literal pathspecs prevent special characters in the selected filename from matching other files. Apply this flag only to `ls-files`, never through shared `GIT_LITERAL_PATHSPECS`, which `check-ignore` rejects. Exit 1 means untracked; other failures remain errors.
3. `git check-ignore --no-index -q -- <selected-file>`: success passes, exit 1 offers the local ignore repair, other failures are errors. The preceding literal index check owns tracking detection; `--no-index` prevents this command's separate index/pathspec matching from treating a wildcard filename as a different tracked file.
4. Repair only within the applicable work tree, in `.gitignore` beside the selected environment file. Append an anchored, correctly escaped literal basename, retaining existing content/newline handling. A rule in the application directory cannot ignore a file outside it. Do not create ignore rules in unrelated repositories or untrack files.
5. Recheck effective ignoring after repair. Report failure if the selected file is still unprotected. Use the selected path in all messages.

## Platform requirements and one source of truth

Startup guards already run before command dispatch (`Application::checkEnvironment`, foundation timezone boot). Do not add a startup bypass or duplicate unreachable extension/build-flag checks. The Swoole minimum belongs to installed Composer requirements, not a new Doctor or foundation version constant.

Create non-final, stateless `Support/InstalledPackages`, container-resolved like `ComposerJson`. One call per diagnostic produces a fresh snapshot:

```php
/**
 * @return array<string, array{
 *     requirements: array<string, string>,
 *     providers: array<string, string>
 * }>
 */
public function platformRequirements(ComposerJson $composer): array;
```

Pass each diagnostic's existing injected `ComposerJson` to the snapshot helper, preserving its constructor and supplied root metadata. Read installed.json once per call, not once per extension/provider. Resolve `Hypervel\Foundation\PackageManifest` and use its public `vendorPath`, sharing the framework's `COMPOSER_VENDOR_DIR`/application-vendor rule and Testbench binding. Do not scan Composer loaders or add a second vendor-directory policy. A protected `vendorPath()` supports focused fixture substitution without a public testing-only API. Declare Doctor's direct Foundation dependency. Read root version metadata from that directory's `composer/installed.php` when needed for `self.version`.

Build the snapshot from:

- Root composer.json `require`, even if matching installed metadata is unavailable.
- Root `require-dev` only when installed metadata positively records dev mode.
- Each installed package's `require`, never its `require-dev`.
- Root and installed package `provide`/`replace` links for relevant platform packages. Resolve `self.version` using that package's available version; root installed metadata supplies its version. Do not turn an unknown root self-version into an invented constraint.

Missing installed metadata removes only the installed contribution. It does not suppress available root checks. Keep the existing public `ComposerJson` methods. Recommended extensions remain root suggestions rather than interpreting every dependency suggestion as a framework requirement. Both extension diagnostics use `InstalledPackages::loadedExtensions()` for the map of Composer-normalized names to runtime versions; do not cache it or duplicate normalization.

For required extensions, follow Composer's platform normalization:

- Map loaded names to `ext-` plus lowercase name with spaces replaced by hyphens; omit synthetic `Core`/`standard` entries.
- Use current-process `phpversion($extension) ?: '0'`. Normalize via `VersionParser`; if necessary use the leading numeric three/four-component version, else `0`, matching Composer's fallback.
- A real loaded version is one candidate; each provider/replacement is another. Compare real versions with `Semver::satisfies()`. Provider/replacement links are ranges, not versions: parse both link and requirement with `VersionParser::parseConstraints()` and compare with `matches()`, as Composer does. Resolve `self.version` to the providing package's exact version constraint. One candidate must match every requiring constraint for that platform package. Do not demand a physical extension when a valid Composer provider satisfies the graph.
- Report the loaded version or absence, requiring package names, and unmet constraints. Do not compare against duplicated Swoole literals.

`PhpVersionSatisfiesComposerRequirement` uses the same snapshot for all `php` constraints, including upper bounds. Check actual `PHP_VERSION`, not `config.platform` emulation. Skip only if no applicable PHP requirement exists. Preserve message codes but update summaries/details to name requiring packages and constraints. Recommend a compatible PHP or dependency version; do not tell users to edit the root constraint to resolve a dependency's requirement. No Composer subprocess or dependency solver is needed.

Fresh metadata matters: `ComposerLockIsFresh` can install/update packages, and the post-fix check must see that new graph even when root composer.json is unchanged. Do not add a worker cache plus invalidation mechanism for this small per-run read.

## Extensibility and tenancy

Keep the upstream extension model: subclass `Diagnostic`, implement `check(): DiagnosticResult`, optionally implement `Fixable`, register classes through `Doctor::diagnostic()`/`diagnostics()` during provider boot, and select by class/group/package. Keep `make:diagnostic`, its fixable stub, message/remediation/link APIs and programmatic runs. No new plugin registry or tenancy hook is required.

Doctor checks selected application resources. A tenant-scoped driver that requires tenant context must retain that guard; do not swallow its exception or automatically iterate tenants. Applications and packages can register specialized diagnostics through the existing extension API. No framework tenancy hooks are needed for this port.

## Testing and verification

Port upstream tests into `tests/Doctor/`, preserving placement and converting Pest to PHPUnit with the repository base classes. Keep the shared provider/environment setup and restore every changed process environment variable. Preserve all applicable upstream coverage; the timezone exclusion is approved.

After each test file is created/changed, run that file immediately with a suitable command timeout. Use `Hypervel\Tests\TestCase` for units and `Hypervel\Testbench\TestCase` for app/container/file writes. Configure app setup in `defineEnvironment`. Use Testbench's disposable app and `ParallelTesting::tempDir`; restore files, selected env paths, environment variables and process state. Never write into the committed skeleton.

Keep all applicable upstream coverage. Add regressions to the corresponding upstream test file where possible; only new helpers/diagnostics need new files. Use data providers for meaningful variants, not one near-identical test per I/O catch.

| Area | Required behavioral coverage |
|---|---|
| Core/CLI | Fresh diagnostic on rerun; original callback/result semantics; cancellation identity from check/fix plus a representative probe; interactive `--fix` never prompts for a choice; normal interactive choices still work; JSON/GitHub formats and exit statuses; generated strict-typed stubs. |
| Source attribution | Actual root resolves to `hypervel/components`; a real vendor class resolves to the deeper vendor package path. Do not mutate global InstalledVersions, test the test-cleanup hook itself, or invent split-package attribution in the development root. |
| Database | Aliased and URL-configured drivers; registered factory extension and driver-neutral probe; connection cleanup after success/failure; SQLite memory/URI/file repair distinction. |
| Migrations | Existing routing tests remain intact. New `tests/Database/MigratorCoroutineIsolationTest.php` follows the existing flat migration test placement and uses real migrator/repository with a mocked resolver: overlapping selected connections, independent restoration, output isolation, fork inheritance without parent mutation, independent live instances, and non-coroutine behavior. Doctor checks the configured migrations target and restores ambient context. |
| Cache/wrappers | False put, mismatched read, plain cleanup failure and preserved primary cancellation/error; null skip; session round-trip; stack named/numeric entries; failover healthy/degraded/failed; dead primary/backup and nested failover with successful write/read but false cleanup warn; false-writing primary fails while a false-writing backup warns; nested wrappers and cycles; duplicate names produce useful unduplicated diagnostics; eligible/ineligible creation repair and honest label; already-generated pending migrations; re-probe before switching. |
| Manager cycles | Extend existing CacheManagerTest/QueueManagerTest: self/indirect cycles reject with their chain; valid nested/shared children work; cache on-demand labels do not create false cycles; custom creators/connectors remain authoritative; missing backups and throwing custom queue connector factories stay lazy. Preserve existing pooled connector behavior. |
| Redis/session | Correct session fallback; missing required database session/queue tables fail; active Redis data/lock names are included and inactive stores excluded; base client and conditional feature minimums; Redis versus Valkey precedence; unknown server version; no INFO required for lock-only/inactive features or unsupported clients. Existing manual Redis checks retain their command behavior. Extend existing trait/check tests and add focused RedisRequirements/Doctor tests. |
| Files/config | Custom storage/config/database/bootstrap/session/view roots; repairing a failing directory preserves healthy directory modes; nested config failures with filenames; config export round-trip and detailed serialization errors, including `__set_state`; Mercure effective publishing/subscription credential alternatives; partial bootstrap states; no view-directory scanning; exact selected env file and production omission. |
| Public storage links | Extend the upstream test file: data providers for `.gitignore`/`.gitkeep` placeholders and wrong/dangling links; preserve conflicting real files/directories; configured nonstandard links and custom public roots pass and repair; relative links pass; mixed missing/conflicting links are not fixable; zero-exit repair without a correct link fails. Keep source-command conflict semantics intact. |
| Git | Actual isolated repositories: tracked even if matching an ignore rule; ancestor ignore rules; negation; selected filename/path; untracked repair and effective recheck; no repository/missing Git/real process error distinctions, including missing executable exit 127 and fatal safe-directory ownership errors. Use Process fakes for command-error branches. |
| Platform | Root-only operation, installed dependencies, dev versus no-dev, exclusion of dependency require-dev, providers/replacements including self.version, actual version constraints and normalization, PHP upper bounds, missing metadata, nondefault vendor path, and changed installed metadata on the post-fix pass. Use fixture installed.json/installed.php through protected vendorPath override. |
| Shared constants | Constructor/factory/generator/config agree with the owning cache/session defaults. Extend existing behavioral tests rather than asserting every literal at every caller. |

Inspect existing coverage before adding files. Do not retest `makeTransient` binding semantics already covered by container tests, add production test seams, or assert incidental helper call counts. Network-call avoidance is verified through inactive/unsupported cases that must succeed without a server, not by mirroring the implementation's loop counts.

Use fakes for ordinary external-service failure paths. If an integration test needs a real service, put it in the appropriate `tests/Integration/...` directory, use its service isolation trait, and update CI discovery only if a new directory requires it. No benchmark without fresh owner confirmation that the machine is idle.

Final checks run sequentially from the worktree root:

1. Review the diff against upstream inventory and this plan. Check declared dependencies against final imports, public/protected API parity, named arguments, extension hooks, and every approved omission. Check all touched code for stale comments, old framework names, dead branches, obsolete TODOs, unused imports and redundant tests.
2. Run focused affected suites after coherent changes. Use ParaTest for multiple files and a process count supported by configured Redis database isolation.
3. Run targeted Doctor source PHPStan after porting, resolving actual type problems without blanket ignores. Tests are not PHPStan targets.
4. Because this design touches database/cache/session/foundation/testing behavior, run `composer fix` for final formatting, analysis and full test verification. Do not run its full component checks redundantly beforehand. Bound execution and keep verbose logs in a task-specific temporary directory.
5. Correct failures at their owner, rerun the failed check and remaining checks, then request independent code review with results. Review performance/scalability and Laravel ergonomics explicitly: no redundant I/O, unbounded worker caches, new runtime hot-path work, or generic machinery without a concrete need.
6. Remove disposable investigation files, preserve the durable worktree, and report completion plus any approval-dependent remaining work accurately. Commit only with explicit authorization.
