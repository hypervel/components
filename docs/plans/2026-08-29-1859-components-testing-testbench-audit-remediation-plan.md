# Testing and Testbench Audit Remediation

## Goal

Resolve audit findings 127, 128, 130, and 146–152 plus the related Testing TODOs. The result must keep Laravel-facing assertion APIs intact, make Testbench bootstrap and parallel-worker ownership deterministic, isolate supported databases correctly, remove dead paths, and complete meaningful upstream test coverage without adding production machinery.

## Invariants

- Laravel-compatible public APIs remain available. The only signature widening is `array|string|null` for Laravel's JSON validation assertions so invalid empty input reaches the normal assertion diagnostic instead of PHP type checking.
- Testbench owns one disposable runtime skeleton per process. Re-entering bootstrap must reload configuration after centralized per-test state cleanup but must not recopy the active runtime or register another shutdown cleanup.
- `TEST_TOKEN` is runner-owned process identity. Filesystem names use one raw-superglobal sanitizer; the mutable `ParallelTesting::token()` resolver remains the callback identity used by lifecycle hooks and tests.
- Parallel database management must never leave a worker on a shared persistent database. URL-only connections are normalized before suffixing. Endpoint-specific read/write database identities fail clearly; endpoint blocks that only override hosts, ports, or credentials remain supported.
- In-memory SQLite remains process-local and unmanaged. SQLite URIs remain unsupported because safely rewriting arbitrary URI forms requires semantics the framework cannot infer.
- Test-only changes add no application hot-path work. No registry, lock, retry loop, token callback, reflection seam, or package-wide validation layer is introduced.

## 1. Policy-query assertion ordering (#127)

`AssertsPolicyQueryConsistency::assertWithCanMatchesPolicy()` currently compares two maps in their insertion order. SQL does not promise row order without an explicit `ORDER BY`, so correct policy results can fail strict comparison.

Sort both collections by their existing model keys before converting them to arrays:

```php
$expected = $models
    ->mapWithKeys(...)
    ->sortKeys()
    ->all();

$actual = $query
    ->withCan(...)
    ->get()
    ->mapWithKeys(...)
    ->sortKeys()
    ->all();
```

Do not normalize or cast keys. `sortKeys()` preserves the map's real key/value contract while removing ordering noise. Extend `AssertsPolicyQueryConsistencyTest` with reversed/unordered string and integer model keys and a genuine value mismatch.

## 2. JSON validation assertion input (#128)

Widen these `TestResponse` methods from `array|string` to `array|string|null`:

- `assertJsonValidationErrors()`
- `assertOnlyJsonValidationErrors()`

Their callers already accept nullable error input, and Laravel deliberately routes `null` to the clean `No validation errors were provided.` assertion failure. The sole in-repo subclass does not override either method, so widening does not create a variance conflict. Preserve all existing message/key handling. Add public-path coverage through `assertInvalid()` and `assertOnlyInvalid()` proving null fails with the assertion diagnostic rather than a `TypeError`.

## 3. ParallelTesting service lifetime (#130)

Delete `ParallelTestingServiceProvider::register()`. The same-class singleton binding duplicates Hypervel's unbound-concrete auto-singleton behavior. Keep `boot()` unchanged. Update `TestingServiceProviderTest` to prove the class is not explicitly bound before first resolution and repeated resolutions return the same worker instance.

Do not loosen `TestViews::parallelSafeCompiledViewPath()` to tolerate missing or non-string `view.compiled` values. `FoundationServiceProvider` requires that string during provider registration, before parallel-testing callbacks can run, and the sibling cache hook enforces its required string identically. The existing empty-string guard remains reachable and sufficient.

## 4. Workbench discovery caches and model fallback (#146–147)

### Nullable caches

`Workbench::$cachedNamespaces` and `$cachedCoreBindings` deliberately cache null misses. Replace `isset()` presence checks with `array_key_exists()` so a miss is read once instead of reopening and reparsing metadata on every lookup. Preserve force-refresh behavior and the upstream-compatible public visibility of `$cachedCoreBindings`; its documented array shape remains the caller contract, and rejecting malformed external writes does not justify breaking that surface or adding validation machinery. A caller that omits required structural keys will now produce an undefined-key warning instead of being silently treated as a cache miss. The test configuration escalates that invalid shape to a failure, and no in-repo caller relies on it.

### Monorepo and split-package paths

Derive the expected Composer path with `workbench_relative_path($type)` rather than assuming `workbench/{$type}`. Normalize separators and surrounding slashes on both the expected path and Composer entries before comparison. This supports:

- components root: `src/testbench/workbench/...`;
- split Testbench package: `workbench/...`;
- custom matching PSR-4 entries.

Test negative lookup memoization, force refresh, root-monorepo discovery, split layout, and nullable core-binding memoization. Exercise the split layout through the real `dogfood/testbench-package` suite as well as focused unit fixtures.

### Skeleton User model

Change the fallback probe from `base_path('Models/User.php')` to `base_path('app/Models/User.php')`. Preserve precedence: explicit `AUTH_MODEL`, workbench model, skeleton `App\Models\User`, then no model. Cover each branch.

The standalone package regression writes and deletes only `app/Models/User.php`; the shared runtime skeleton owns the containing directory and its tracked placeholder.

## 5. Sync command cleanup ownership (#148)

Move `TerminatingConsole::flush()` from `SyncSkeletonCommand::configure()` to the first line of `handle()`.

Symfony constructs/configures commands for list, help, and completion without executing them. Those read-only actions must not delete cleanup callbacks owned by other commands. Executing `package:sync-skeleton` intentionally starts a durable sync operation and clears earlier restoration callbacks. Cover construction/list/help preserving callbacks and real execution flushing them.

## 6. Environment loading cleanup (#149)

Delete Testbench's unused `Bootstrap\LoadEnvironmentVariables` subclass and its one-byte fallback fixture. `CreatesApplication` and Testbench's Foundation application both intentionally use the framework loader; package environment files are copied into the disposable runtime by `Bootstrapper` before application loading.

Importing the dead subclass would create a second environment owner. Its raw Dotenv load would bypass `DotenvManager`'s cached key/value ownership, preventing centralized test cleanup from knowing which process-global variables to restore. Preserve the current Foundation loader and its environment-array overlay. Update affected tests to pin package/custom/no-environment-file behavior through the real runtime-copy path.

## 7. One framework configuration path owner (#150)

Add a public static owner accessor to `Foundation\Bootstrap\LoadConfiguration`:

```php
public static function frameworkConfigPath(): string
{
    $path = realpath(dirname(__DIR__, 2) . '/config');

    if ($path === false) {
        throw new RuntimeException('Unable to locate the framework configuration directory.');
    }

    return $path;
}
```

Use it in all three consumers:

1. `LoadConfiguration::getBaseConfiguration()`;
2. Testbench's `UsesFrameworkConfiguration` attribute;
3. `ConfigPublishCommandWithoutMergedConfigurationTest::getExpectedConfigFiles()`.

The owner performs the only existence check, so every consumer fails with the same clear error in monorepo and split-package layouts. Remove reflection and `package_path()` derivations. Test the resolved directory, the attribute path, and config publishing. Do not add a production seam merely to make the fixed package directory disappear in-process; the guard is for a corrupt installation and its branch is direct.

## 8. Idempotent Testbench bootstrap (#151)

### Bootstrap sequence

Restructure `Bootstrapper::bootstrap()` in this order:

1. Resolve and define `TESTBENCH_WORKING_PATH` when absent.
2. Load the active YAML configuration through `resolveConfigurationPath()`.
3. Define `SWOOLE_HOOK_FLAGS` when absent.
4. If `BASE_PATH` is already defined, return.
5. Resolve the configured/default skeleton source, purge stale copies, create the disposable runtime, define `BASE_PATH`, and register its cleanup once.

`BASE_PATH` is the existing ownership fact; do not add another static boot flag. Re-entry reloads YAML-backed state after `AfterEachTestSubscriber` flushes `Bootstrapper` and `Config`, but never overlays the active clone.

### Callers

- Remove `TestCase::$hasBootstrappedTestbench`.
- Call `Bootstrapper::bootstrap()` before `parent::setUp()` for every framework-booting Testbench test. The bootstrapper itself owns idempotence.
- Make Testbench `ParallelRunner`'s default application resolver call the bootstrapper unconditionally. Preserve a user-supplied application resolver exactly.
- Delete `default_skeleton_path()`'s unreachable second `BASE_PATH` check.

Keep `default_skeleton_path()`'s first `BASE_PATH` guard. The helper is used by every base-path resolution, so calling `Bootstrapper::bootstrap()` unconditionally there would repeat configuration-file probes on ordinary path lookups. The direct TestCase and runner callers bootstrap once at their real lifecycle boundary instead.

### Configuration fallback

Make `Bootstrapper::resolveConfigurationPath()` public and call it from `Workbench::configuration()` with `package_path()`, the same active package root Bootstrapper records as `TESTBENCH_WORKING_PATH`. This prevents Workbench from loading the components package's bundled `testbench.yaml` when the active standalone package has no local YAML file. Keep the method focused on path ownership; do not add another configuration resolver abstraction. Use focused tests for the missing-local-file fallback and the real `dogfood/testbench-package` suite for standalone package selection.

Cover direct repeated bootstrap, helper-before-TestCase, TestCase bootstrap ordering, default ParallelRunner, custom resolver bypass, predefined `BASE_PATH`, fallback path selection, no clone overlay, one cleanup registration, and retained runtime mutations.

## 9. Process identity, environment maps, and migration cleanup (#152)

### One filesystem token sanitizer

Add an `@internal` public `ParallelTesting::processToken(): ?string`, reading `$_SERVER['TEST_TOKEN'] ?? $_ENV['TEST_TOKEN'] ?? null` directly. It is public only because Testbench consumes it across the split-package boundary. Return null for unset or non-scalar values; otherwise cast to string, return null only when `$token === ''`, and replace characters outside `[A-Za-z0-9_.-]` with `_`. This preserves the existing safe interpolation of integer and other scalar runner values while centralizing sanitization.

Use it only for filesystem/process artifacts:

- `ParallelTesting::tempDir()`;
- Testbench runtime-copy directory names;
- profile output filenames;
- `CreatesApplication::paraTestWorkerToken()`, retained as a thin two-caller delegate for route/cache filename setup.

Do not route service database numbers, Redis prefixes, lifecycle callback tokens, or `ParallelTesting::token()` through it. An unset token retains the current `default` filesystem segment; an explicitly empty token deliberately changes from an empty segment to `default`, avoiding ambiguous artifact names. Test traversal/punctuation, integer and other scalar values, non-scalar rejection, `$_SERVER` precedence, both empty and unset fallback, and every consumer. Pin the strict empty check explicitly: integer or string zero resolves to `0`, while boolean false casts to `''` and resolves to `default`.

In the same class, cast a scalar direct `$_SERVER['TEST_TOKEN']` fallback in `ParallelTesting::token()` to its declared string type while preserving `false` for absence and treating a non-scalar superglobal value as absent. Keep resolver results strict: a custom `resolveTokenUsing()` callback still owns the documented `string|false` contract and is not silently coerced. Cover integer fallback, absent/non-scalar fallback, and unchanged resolver behavior.

Do not advertise `processToken()` on the generated `Hypervel\Support\Facades\ParallelTesting` surface. The facade's resolver-aware `token()` remains the public lifecycle identity, while the documenter already excludes `@internal` methods. Run the facade docblock lint test to prove the generated surface stays current.

### Subprocess environment map

Build `defined_environment_variables()` from the union `$_ENV + $_SERVER` to obtain real keys without `array_merge()` renumbering numeric entries. Filter the key collection to strings before `mapWithKeys()`. For each retained key, preserve current value precedence:

```php
$_ENV[$key] ?? $_SERVER[$key] ?? null
```

Keep only scalar or null values. Numeric keys are omitted because the declared Symfony environment map accepts string keys. Preserve the working-path insertion. Cover overlapping sources, null fallback, false/zero/empty-string values, array/object rejection, and numeric-key omission.

`BatchableTransactionTest` and `QueueTransactionTest` must unset `DB_URL`, `DATABASE_URL`, and `DB_POOLED_URL` in their remote-process environment with `false`, matching Symfony Process semantics, so host/database test settings cannot be silently overridden by ambient URLs.

### Migration paths and options

- In `WithHypervelMigrations`, return when the install flag is false before resolving `default_migration_path()`. Remove the unreachable `is_dir()` check; the path helper already resolves the supported fixture path.
- In `InteractsWithMigrations::loadHypervelMigrations()`, resolve migration options once, add `--path` and `--realpath`, and pass that same array to the processor.
- Keep the teardown migration cache as its declared array-owned state. Do not add the master plan's stale nullable fallback.

Also reject the master plan's first-caller bootstrap descriptor/assertion and `ConfigContract` expansion. Supported boot paths establish the process-owned configuration consistently, and `Bootstrapper` intentionally owns the concrete YAML-backed `Config`; neither additional state nor a wider unsupported abstraction improves that contract.

Cover disabled migration loading without path resolution, enabled registration/loading paths, option preservation, and one option-resolution call.

## 10. URL-safe parallel database isolation

This completes the existing URL-awareness TODO and replaces `TestDatabases::switchToDatabase()`'s regex.

### Normalize before suffixing

Across both database lifecycle owners:

- Foundation's `configureParallelDatabaseName()` before providers boot and its trait-owned `ensureParallelDatabaseExists()` fallback;
- generic Testing `TestDatabases::switchToDatabase()` after the application exists;

read the declared default connection record and pass it through `Hypervel\Database\ConfigurationUrlParser::parseConfiguration()`, matching `ConnectionFactory`. This preserves driver aliases, credentials, ports, percent-decoding, and URL query options without rebuilding a URL string. At the two suffixing owners — Foundation's early configuration and generic Testing's switch — store the normalized explicit record with `url` removed and replace only its `database` value with the per-worker name. Foundation's later ensure method never becomes a third suffixing owner; it stores the parsed record with its current database only when that managed record changed.

Within the Foundation trait, use one private helper from both lifecycle methods to normalize the declared record, validate its topology, and return it for classification without mutating configuration. Only then classify the driver/database with `shouldManageParallelDatabase()`. On a managed path, persist the normalized record and its current or newly suffixed database in one write; skip the write when the record is already explicit and unchanged. This order makes URL-only records visible to both the guard and the normal suffixing path, avoids mutating invalid or unmanaged configuration, and stays idempotent when an earlier call already removed `url`.

For the normal lifecycle where `setUpTheTestEnvironment()` creates the application, the generic Testing boundary runs inside `callSetUpTestCaseCallbacks()`, performing normalization, switching, and database creation before database traits run in `setUpTraits()` and call `ensureParallelDatabaseExists()`. Testbench's earlier Foundation boundary runs before providers boot, and every later pass stays idempotent. If a non-Testbench application already exists or disables Testing package discovery, the provider-owned suffixing callback does not run; Foundation can still validate topology and operate on a parsed database during its probe/recovery, but it does not provide per-worker isolation. Do not add a third suffixing owner to support an application that opted out of parallel database management.

Keep `ensureParallelDatabaseExists()`'s `QueryException` recovery self-sufficient. Replace the existing suffix regex with a small private Foundation-trait helper that strips only the exact `_test_{$token}` suffix; use it both when computing the test database and when deriving the unsuffixed recovery database. This matches Testing's existing token-aware suffix rule and preserves base names containing `_test_`. Delete `InteractsWithParallelDatabase::$originalDatabaseName` entirely: per-input suffix stripping replaces its only remaining purpose, avoids stale cross-test/database state, and removes the corresponding manual test resets. Rename the existing repeat-call test around same-token suffix stripping. Cover recovery without any pre-seeded state and independent calls for different base databases.

For SQLite, keep `:memory:` and supported memory forms process-local. Continue rejecting URI filenames with the existing two-sentence diagnostic.

### Reject only endpoint-owned identities

Call the Foundation normalization/topology helper after each lifecycle method's without-databases, missing-token, and missing-connection returns but before database classification or probing. The second caller keeps topology validation active and gives Foundation's probe/recovery a parsed database when the provider callback did not run; it does not suffix that database. At the later Testing boundary, `ParallelTesting::whenRunningInParallel()` already owns the serial/token gate; inside `whenNotUsingInMemoryDatabase()`, inspect the declared topology from the configuration repository immediately after its `without_databases` return and reject unsupported shapes before either `DB::getConfig()` call materializes the default pooled connection. Do not duplicate runner-state checks inside that method. Reject a `read` or `write` endpoint only when any associative endpoint or any entry in its list form declares `database` or `url`.

Host-, port-, and credential-only endpoint blocks inherit the normalized top-level database and remain supported. At each local guard, reject direct `database` or `url` keys and scan nested endpoint records only when `isset($endpoints[0])`, exactly matching `ConnectionFactory`'s list-form rule without inspecting unrelated nested option maps. The later boundary must deliberately parse the declared record before validation so query-string options that introduce read/write topology cannot bypass the guard, then use:

- the configuration repository for declared read/write topology, because the materialized connection has already removed those keys;
- `DB::getConfig('database')` for the current effective database name.

Keep the two checks local to their owning packages. A shared constant/helper would deepen the existing `foundation`/`testing` package cycle for two setup-only guards. Use matching diagnostics:

> Read/write connections with endpoint-specific databases or URLs cannot be automatically managed during parallel testing. Configure a single database identity or run with --without-databases.

### Normalize endpoint URLs at their merge boundary

`ConnectionFactory::configForRead()` already promises nested read URL parsing, but the normal read/write connection path merges both endpoint records without parsing them. The same read URL therefore works for a dedicated read pool and is silently ignored by the base pool; nested write URLs are also ignored.

Make `getReadConfig()` and `getWriteConfig()` parse their merged endpoint record with `parseConfig(..., $config['name'] ?? null)`. Keep `configForRead()`'s outer parse so direct public calls remain self-sufficient, then rely on `getReadConfig()` for the endpoint parse rather than parsing its result again. This single owning boundary covers normal connections, dedicated read pools, and shared-PDO SQLite entry points. Use associative endpoint fixtures so tests do not pin `Arr::random()` selection for list-form endpoints.

Tests must cover URL-only MySQL/PostgreSQL settings, percent-encoded credentials, slash-containing and overriding query options, URL fields overriding matching discrete fields, discrete fields absent from the URL remaining intact, host-only URLs preserving the discrete database before suffixing, SQLite URL relative/absolute path semantics matching `ConnectionFactory`, file SQLite names containing dots/dashes, memory SQLite, rejected URI SQLite, safe read/write host lists, rejected associative and list endpoint database/URL overrides through both Foundation callers and the Testing boundary, query-derived endpoint topology, escape hatches, and the original setup diagnostic surviving the teardown pass. Add factory-level coverage proving nested read and write URLs are parsed consistently while plain partial endpoint blocks retain their existing merge behavior.

Update `src/docs/testing.md`'s parallel-database section to state that URL-configured primary connections are normalized and isolated. Beside the existing SQLite URI limitation, document that read/write endpoints with their own `database` or `url` identities cannot be isolated automatically and require one inherited database identity or `--without-databases`. Update `src/docs/database.md` beside read/write connection configuration to state that either side may define its own URL, which overrides matching values merged from the primary connection. These are runtime configuration details users can encounter, not porting-guide divergences.

## 11. Complete current Testing coverage

### EventFake

Copy current Laravel `tests/Support/SupportTestingEventFakeTest.php` to the matching `tests/Support/SupportTestingEventFakeTest.php` path and port it through the normal test workflow. Keep the existing database-backed `tests/Integration/Events/EventFakeTest.php` suite unchanged except for any correction directly exposed by the port; it tests a different integration boundary. Use `Hypervel\Tests\TestCase`, the repository's Mockery alias and strict types, and type the fixture as `protected EventFake $fake`. Preserve upstream's safe `try`/`catch (ExpectationFailedException)` assertion pattern: `fail()` throws its parent `AssertionFailedError`, so the catch cannot swallow a false positive.

Restore Laravel's dispatcher-contract constructor and public property types on `EventFake`. Hypervel previously narrowed both to the concrete dispatcher only to make PHPStan accept `assertListening()`'s `getListeners()` call, rejecting valid contract implementations such as `NullDispatcher`. Keep the upstream implicit listener-introspection seam and use one identifier-scoped PHPStan suppression with a short reason; do not widen the dispatcher contract or add a runtime type guard.

### TestResponse

Update `tests/Testing/TestResponseTest.php` through the repository's incremental upstream workflow:

1. identify the upstream pull requests that introduced each missing current test/API behavior;
2. inspect every file in those changes;
3. compare current Laravel source/tests, not only historical diffs;
4. merge missing supported tests into Hypervel while preserving stricter types and Hypervel-specific cases;
5. stop and report any unapproved unsupported surface or source defect rather than weakening tests.

Symfony returns `false` from `getContent()` for streamed and binary-file responses. `TestResponse` currently forwards that sentinel through `__call()`, while its content assertions, JSON failure decorator, dump helper, and no-content assertion all require the captured body string. This masks streamed JSON assertion failures with a `TypeError`, breaks the text assertions and dump helper, and lets `assertNoContent()` pass for non-empty streams.

Add an explicit `TestResponse::getContent(): string` beside `streamedContent()`. Return the base content when it is a string; otherwise delegate to the existing streamed/binary capture and memoization owner. Collapse `decodeResponseJson()` to consume that method, and do not add local guards, another accessor, MIME matching changes, or recovery for responses sent outside the supported test harness. Cover one streamed callback across content/text assertions and repeated access, proving it is invoked once; make binary-file content flow through the same getter before the existing streamed assertions; preserve the streamed JSON mismatch as an ordinary assertion failure; and prove `assertNoContent()` accepts an empty stream but rejects a non-empty one.

### TestView

Laravel has no matching complete TestView suite. Complete focused coverage for:

- `assertViewHas()`, `assertViewHasAll()`, `assertViewMissing()`, and `assertViewEmpty()`;
- escape-aware `assertSee()`, `assertSeeInOrder()`, `assertSeeText()`, `assertSeeTextInOrder()`, `assertDontSee()`, and `assertDontSeeText()`;
- raw-HTML `assertSeeHtml()`, `assertSeeHtmlInOrder()`, and `assertDontSeeHtml()`, which deliberately have no `$escape` parameter;
- `__toString()`.

Preserve the existing model/collection identity and malformed-UTF-8 cases. Test public success and meaningful failure branches without manufacturing private implementation tests.

Delete the stale `docs/todo.md` item asking to convert remaining raw PHPUnit tests: the only reference is the framework-owned `tests/TestCase.php` base class itself. Remove the URL-aware database TODO when §10 is complete, then remove the two Testing coverage TODOs when §11 is complete. Delete the empty `## Testing` heading after those entries are gone, and remove the pre-existing empty `## Collections` heading while editing the same file.

## 12. Record the package ownership cycle

Add one concise actionable item under `docs/todo.md`'s `## Framework-wide` section: `hypervel/foundation` and `hypervel/testing` require each other in production metadata, causing test-only classes and PHPUnit integration to ship with every Foundation install. Resolving it requires deciding whether Foundation's testing namespace moves to `hypervel/testing` or Foundation changes its dependency to development-only while deliberately preserving split-package installation and public namespaces.

Do not add a shared helper across that cycle as part of this remediation. The TODO records a verified packaging problem without coupling the current correctness fixes to a package-boundary redesign.

## File map

Expected source edits:

- `src/foundation/src/Bootstrap/LoadConfiguration.php`
- `src/foundation/src/Testing/Concerns/InteractsWithParallelDatabase.php`
- `src/database/src/Connectors/ConnectionFactory.php`
- `src/testbench/src/Attributes/UsesFrameworkConfiguration.php`
- `src/testbench/src/Bootstrapper.php`
- `src/testbench/src/Concerns/CreatesApplication.php`
- `src/testbench/src/Concerns/InteractsWithMigrations.php`
- `src/testbench/src/Concerns/WithHypervelMigrations.php`
- `src/testbench/src/Features/ParallelRunner.php`
- `src/testbench/src/Foundation/Console/SyncSkeletonCommand.php`
- `src/testbench/src/TestCase.php`
- `src/testbench/src/Workbench/Workbench.php`
- `src/testbench/src/functions.php`
- `src/testing/src/Concerns/AssertsPolicyQueryConsistency.php`
- `src/testing/src/Concerns/TestDatabases.php`
- `src/testing/src/ParallelTesting.php`
- `src/testing/src/ParallelTestingServiceProvider.php`
- `src/testing/src/Profile/ExecutionFinishedSubscriber.php`
- `src/testing/src/TestResponse.php`
- `src/support/src/Testing/Fakes/EventFake.php`

Expected deletion:

- `src/testbench/src/Bootstrap/LoadEnvironmentVariables.php`
- `src/testbench/src/Bootstrap/Fixtures/.env.testbench` and its now-empty `Fixtures` directory, after confirming no remaining consumer

Tests will be changed or added beside the existing owning suites under `tests/Foundation`, `tests/Integration/Foundation`, `tests/Testbench`, `tests/Testing`, the new upstream-mirrored `tests/Support/SupportTestingEventFakeTest.php`, and `tests/Integration/Database/Queue`. The exact TestResponse inventory is determined by the required incremental-update comparison before each ported group.

Expected documentation edits:

- `docs/todo.md`
- `src/docs/database.md`
- `src/docs/testing.md`

These fixes do not change a Laravel API or require a porting-guide entry.

## Verification

1. Before each edit, reread the owning source, callers, tests, package README, and current upstream files where parity defines the contract.
2. Edit one file at a time. Run every changed test file immediately with `./vendor/bin/phpunit --no-progress <file>`.
3. Run the focused Testbench package contract suite after Testbench bootstrap/package-mode changes: `composer test:testbench`.
4. Run focused Foundation/Testing suites and queue subprocess tests after their coherent slices.
5. Run `tests/FacadeDocumenter/FacadeDocblocksTest.php` so the internal process-token helper cannot drift onto the advertised static API.
6. Run `composer fix` once at the completed checkpoint. It owns formatting, PHPStan, the parallel suite, Testbench package tests, and the standalone `dogfood/testbench-package` suite.
7. Review the final diff through all callers and callees, checking Laravel API parity, bootstrap ownership, package and split layouts, token/environment cleanup, database isolation, worker/static state, dead code, comments, and avoidable test/runtime cost.

## Simplicity and performance check

- Production request handling is untouched.
- Sorting is confined to an explicit test assertion.
- URL parsing and topology checks run only during parallel-test setup/switching.
- Token sanitization is linear in a small runner-controlled string and replaces duplicate regex logic.
- After centralized cleanup, the next test parses YAML once; repeated bootstrap calls within that state reuse `Config::cacheFromYaml()`. This test-only parse is required to restore the configuration the subscriber intentionally cleared.
- Nullable discovery misses become cheaper because they are cached correctly.
- Deleting the redundant binding, dead loader, reflection path derivation, URL rewrite regex, duplicate migration resolution, and stale guards reduces code and failure modes.
