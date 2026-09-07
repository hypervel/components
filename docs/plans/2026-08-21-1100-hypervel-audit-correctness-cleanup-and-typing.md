# Hypervel Audit Correctness, Cleanup, and Typing

## Goal

Resolve every verified finding from the supplied framework audit, including the broader local inconsistencies exposed by each sampled finding. Fix the two adjacent command/validation bugs found while tracing the code. Leave no compatibility shim, dead branch, stale comment, false success, or half-typed family behind.

This is framework bug-fix and source-quality work. Laravel remains the public API reference for the Laravel-ported packages; Hypervel-specific Redis console code keeps its stronger concrete requirements and Swoole-aware design. Implementation remains serial and file-by-file. Re-read this plan and `AGENTS.md` after compaction, and re-read the relevant package source before each implementation slice.

## Confirmed design

### Correctness fixes

1. `BenchmarkCommand` must clean up after success, `BenchmarkMemoryException`, and every other throwable from a benchmark suite. Cleanup failure must be visible and make a successful run fail, but it must never replace an active suite exception. Its best-effort cache-service display must also report a failure without aborting the benchmark.
2. `DoctorCommand` must keep cleanup best-effort across every tag, pattern, and registry operation while reporting each thrown cleanup failure. A failed silent preflight does not stop the functional diagnostics, but it makes the command fail because stale state can affect their reliability. Functional assertion totals and cleanup health remain separate. Its best-effort system-information display must catch every throwable and retain its visible connection-failure result.
3. `Rules\Email` must let its nested validator reject non-string values. The current early return leaves its message list empty, so `Validator::validateUsingCustomRule()` falls back to the rule class name. `ValidatesAttributes::validateEmail()` already rejects the value safely and supplies the translated email message.
4. Nested class-string command calls must execute a fresh clone. `Command::resolveCommand()` currently clones name-resolved commands and supplied command objects, but executes a container-resolved class string directly. Hypervel auto-singletons that unbound concrete, so repeated or concurrent `$this->call(CommandClass::class)` invocations otherwise share mutable command state.

### Type and source-quality fixes

- `BenchmarkContext::getStore()` returns the concrete `Hypervel\Cache\Repository`. Every benchmark scenario obtains its repository here, and the tagged scenarios plus `cleanup()` call concrete-only `tags()`, while `Hypervel\Contracts\Cache\Repository` does not declare that method. Keep the local `@var Repository` refinement because `CacheContract::store()` is contract-typed; remove the contradictory `@return` and fully qualified native type.
- Add all missing native types that the audited call paths prove: 22 array-backed `Container` properties, the two known arrow-function parameters, the View bound-attribute parameter, affected test methods/providers/callbacks, and precise array shapes that native PHP cannot express.
- Add Laravel-style imperative method documentation to the complete audited families, not only the sampled files.
- Remove unused imports and catch bindings. Correct ordinary all-caps emphasis in Cache Redis comments while preserving real identifiers, acronyms, boolean operators, and intentional console banners.
- Correct Telescope's protected `countExceptionOccurences()` spelling cleanly, with no alias or deprecated wrapper. The owner explicitly chose correct Hypervel code over retaining the upstream typo.

### Verified non-findings and guardrails

- `Repository::rememberForever()` has one `@template TCacheValue`; no source docblock contains a duplicate template name. Do not edit it.
- gRPC regeneration already runs `type-generated-constants.php`, rejects surviving untyped constants, formats the result, and only then replaces the generated health classes. Do not add another generator check or test.
- Keep `Container::$environmentResolver` docblock-only. PHP forbids `callable` as a property type, and narrowing the public `?callable` resolver API to `?Closure` would be wrong. `Container::$instance`, `$sharedResolutions`, `$sharedResolutionWaits`, and `$buildRecipes` are already correctly typed.
- Keep `BenchmarkContext::cleanup()` fail-fast. Twelve scenario call sites rely on cleanup failure aborting meaningless benchmark work; aggregation belongs only in the command's final cleanup boundary.
- Keep `DoctorCommand::cleanup()` as `void`. The runtime failure list records thrown failures only. Existing `CleanupVerificationCheck` owns silent incompleteness by scanning cache-value patterns, tag-storage patterns, and any-mode registry orphans after cleanup.
- Do not reset Doctor counters or failure lists in `handle()`. The lazy loader and auto-singleton container intentionally cache command prototypes. `Application::freshCommandForRun()` already clones top-level CLI and `Artisan::call()` executions, and section 5 closes the omitted clone in nested class-string `$this->call()` execution. Invocation state belongs on those per-run clones, not behind command-specific reset machinery.
- Do not add validation cases for an absent attribute or an empty string here. Those paths are outer `Validator` applicability rules, and the current helper always creates a present attribute. The explicit `nullable` case is the relevant contrast for the Email rule change.
- Do not add a Telescope upgrade-guide entry. `src/docs/upgrade.md` is the completed 0.3-to-0.4 guide, and this narrow protected spelling correction does not belong in the Laravel porting guide. No user documentation changes are needed.

## Implementation

### 1. Make Benchmark cleanup exception-safe

Files:

- `tests/Cache/Redis/Console/BenchmarkCommandTest.php`
- `src/cache/src/Redis/Console/BenchmarkCommand.php`
- `src/cache/src/Redis/Console/Benchmark/BenchmarkContext.php`

First extend `BenchmarkCommandTest` with a subclass that retains the production `handle()`. Its `createContext()` override returns an injected Mockery `BenchmarkContext`; its `runComparison()` override either returns normally or throws an injected prebuilt `Throwable`. Do not reuse `TestableBenchmarkCommand`, whose `handle()` tests setup only, and do not construct a real `BenchmarkContext` for this control-flow test. Run the command with explicit `--scale=small`, `--runs=1`, `--compare-tag-modes`, and `--force` options. The comparison branch reaches the real try/catch/finally boundary without calling `BenchmarkContext::getStoreInstance()`; the context double's complete production expectation is therefore one `cleanup()` call, configured to return or throw for the matrix row.

Give these full-handle tests their own cache mock builder. Do not relax the existing `mockCacheStore()` helper: its exact two-call expectation correctly protects the setup-only tests. The new builder expects `CacheContract::store()` exactly three times (twice in `setup()` and once in `displaySystemInfo()`), `Repository::getStore()` twice, and `Repository::get('test')` once; it also supplies the Redis context/info and tag mode needed by the successful display path. This keeps both seams strict and prevents the first full-handle test from failing on an unrelated Mockery count.

Cover these paths:

| Suite path | Cleanup path | Required result |
|---|---|---|
| returns | returns | `SUCCESS`, cleanup once |
| returns | throws | class and message reported, `FAILURE` |
| throws `BenchmarkMemoryException` | returns | memory guidance shown, cleanup once, `FAILURE` |
| throws `BenchmarkMemoryException` | throws | both failures reported, `FAILURE` |
| throws another prebuilt exception | throws | cleanup reported and the exact suite exception remains top-level |

Then wrap the existing suite branch as follows:

```php
$cleanupFailed = false;

try {
    // Existing comparison or selected-mode suite.
} catch (BenchmarkMemoryException $exception) {
    $this->displayMemoryError($exception);

    return self::FAILURE;
} finally {
    $this->newLine();
    $this->info('Cleaning up benchmark data...');

    try {
        $context->cleanup();
    } catch (Throwable $exception) {
        $cleanupFailed = true;
        $this->error('Benchmark cleanup failed (' . $exception::class . '): ' . $exception->getMessage());
    }
}

return $cleanupFailed ? self::FAILURE : self::SUCCESS;
```

The inner catch stays `Throwable`: a cleanup `Error` or `TypeError` must not displace an active suite exception. Do not change `BenchmarkContext::cleanup()` or any scenario caller.

Also import `Throwable`, replace `displaySystemInfo()`'s silent `Exception` catch with `catch (Throwable $exception)`, and emit a non-fatal diagnostic containing `Cache Service: Connection failed`, the throwable class, and its message. Add a real-handle regression in which the third `CacheContract::store()` call returns the repository but the repository's second `getStore()` call throws an `Error`; setup's first `getStore()` call still succeeds, so the strict 3/2/1 fixture counts remain valid. Assert the diagnostic and prove the controlled comparison suite still runs. This is a best-effort header path, so inability to retrieve the optional service/version details must be visible but must not prevent the actual benchmark. Keep the `Exception` import because mandatory setup still catches ordinary connection exceptions separately.

In `BenchmarkContext`, change `getStore()` to the imported concrete `Repository`. While editing the cleanup method's comments, type its connection callback as `RedisConnection`, rename its registry filter parameter to `string $member`, and type both callback returns. In `BenchmarkCommand`, rename local catch variables and the protected `displayMemoryError(BenchmarkMemoryException $exception)` parameter consistently; update its positional test-subclass call as needed. Replace the now-false `Cleanup skipped to avoid further memory exhaustion.` guidance with wording that automatic cleanup runs next and the listed commands are fallback recovery if benchmark keys remain. There is no in-tree named-argument caller to preserve.

### 2. Make Doctor cleanup truthful and independently reportable

Files:

- `tests/Cache/Redis/Console/DoctorCommandTest.php`
- `src/cache/src/Redis/Console/DoctorCommand.php`

Add focused regressions through the real cleanup and handle flow:

- the first cache-store lookup, inside system-information display, throws an `Error` and prints the existing `Service: Connection failed` result; the second lookup returns the controlled Redis repository so store validation and diagnostics demonstrably continue;
- a thrown failure in each cleanup category is reported with its operation, class, and message, and later cleanup work is still attempted;
- silent preflight failure is visible, functional checks still run, and the eventual command status is `FAILURE` even when all functional assertions pass;
- the summary presents functional failures and cleanup failures as separate groups and never counts cleanup as a test assertion;
- a cleanup `Throwable` during an active prebuilt functional exception is reported without replacing that exact functional exception;
- `Cleanup complete.` appears only when that cleanup invocation had no thrown failure.

Add the runtime-only state and one shared reporter because four cleanup branches call it:

```php
/** @var list<string> */
private array $cleanupFailures = [];

/**
 * Record and report a cleanup failure.
 */
private function recordCleanupFailure(string $operation, Throwable $exception): void
{
    $failure = $operation . ' failed (' . $exception::class . '): ' . $exception->getMessage();

    $this->cleanupFailures[] = $failure;
    $this->error($failure);
}
```

Catch `Throwable` around each known-tag flush, cache-value pattern, tag-storage pattern, and any-mode registry cleanup. Derive `$phase = $silent ? 'Preflight cleanup' : 'Cleanup'` once at method entry, then begin every reported operation with that phase and include its tag or pattern, for example `Preflight cleanup: flush tag '_doctor:test:products'`. This makes otherwise identical preflight and final failures distinguishable both inline and in the summary without adding state or a phase abstraction. Track the failure-list count at method entry so a non-silent invocation only prints `Cleanup complete.` when that invocation added no failure. Keep reporting active in silent mode so a preflight problem is never hidden, and assert the phase distinction in the cleanup regressions.

Widen `displaySystemInformation()`'s remaining `catch (Exception)` to `catch (Throwable $exception)`. Once the four cleanup catches are widened too, replace the now-unused `Exception` import with `Throwable`. Report the throwable class and message after `Service: Connection failed`: this optional display step must identify the cause and allow the command's substantive checks to continue even when the failure is an `Error` or `TypeError`.

`displaySummary()` keeps assertion counts unchanged and adds its own cleanup-failures heading/list when needed. The callback returns success only when both conditions hold:

```php
return $this->testsFailed === 0 && $this->cleanupFailures === []
    ? self::SUCCESS
    : self::FAILURE;
```

Import the concrete Cache `Repository` for the local `@var` in `handle()`. Rename the cleanup registry filter parameter to `string $member` and type its return as `bool`. Do not create cleanup result objects, registries, retries, or return-value protocols.

### 3. Complete Cache Redis method documentation

Add imperative title docblocks to every undocumented named method in the concrete Doctor checks: 68 additions across 22 classes.

- Functional checks: `AddOperationsCheck`, `BasicOperationsCheck`, `BulkOperationsCheck`, `CleanupVerificationCheck`, `ConcurrencyCheck`, `EdgeCasesCheck`, `ExpirationCheck`, `FlushBehaviorCheck`, `ForeverStorageCheck`, `HashStructuresCheck`, `IncrementDecrementCheck`, `LargeDatasetCheck`, `MemoryLeakPreventionCheck`, `MultipleTagsCheck`, `SequentialOperationsCheck`, `SharedTagFlushCheck`, `TaggedOperationsCheck`, and `TaggedRememberCheck`.
- Environment checks: `CacheStoreCheck`, `HashFieldExpirationCheck`, `PhpRedisCheck`, and `RedisVersionCheck`.
- Document all 48 `name()` / `run()` / `getFixInstructions()` implementations and the constructors of `CacheStoreCheck`, `HashFieldExpirationCheck`, and `RedisVersionCheck`.
- Document the 17 currently bare helpers: `ConcurrencyCheck::setOutput()`, `testAtomicAdd()`, `testConcurrentFlush()`; `ExpirationCheck::setOutput()`, `testAnyModeExpiration()`, `testAllModeExpiration()`; both mode helpers on `FlushBehaviorCheck`, `ForeverStorageCheck`, `IncrementDecrementCheck`, `MemoryLeakPreventionCheck`, and `TaggedOperationsCheck`; plus `SharedTagFlushCheck::testAnyMode()`.
- Add missing title lines to the existing type-only docblocks on both `MultipleTagsCheck` mode helpers and `SharedTagFlushCheck::testAllMode()`. Keep their useful array annotations.
- While these methods are open, complete the proven local callback typing: type the five atomic-add closures and two concurrent-flush closures as returning `bool`; change the existing `array_filter` arrow to `fn (bool $succeeded): bool => $succeeded === true`; change `CleanupVerificationCheck`'s registry filter to `fn (string $member): bool => ...`; rename the three local `Throwable $e` bindings in `ConcurrencyCheck` and `RedisVersionCheck` to `$exception`. These are local variables only and do not change an extension signature.
- Replace `ExpirationCheck`'s raw `sleep(2)` with `Sleep::sleep(2)` so the source delay uses the framework's fakeable timing primitive while retaining the same real delay by default.
- Remove `ForeverStorageCheck::testAllMode()`'s unused private `$key` parameter and its sole call-site argument.

Use short titles that state the method's purpose; do not repeat signatures, inventory class members, or add bodies where the code is clear. The new Doctor cleanup reporter also receives its title docblock as shown above.

Add constructor titles to the complete 15-file Cache Redis gap:

- `Operations/AllTag/{Add,AddEntry,Decrement,Flush,FlushStale,Forever,GetEntries,Increment,Put,PutMany}.php`
- `Operations/AllTagOperations.php`
- `Operations/AnyTagOperations.php`
- `Support/MonitoringDetector.php`
- `Support/StoreContext.php`
- `Support/TagKeyBuilder.php`

Match the established sibling wording, such as `Create a new touch operation instance.` Complete the directly proven callback returns in these files: `void` for `AddEntry`, `Flush`, and `FlushStale`; `int|false` for `Decrement` and `Increment`; `bool` for `Forever`, `Put`, and `PutMany`; `Generator` for `GetEntries`' lazy source; and `string` for `StoreContext::optPrefix()`. Replace `TagKeyBuilder`'s historical move narration with its current architectural role so the comment reads as designed this way from the start.

### 4. Finish Cache Redis comment, import, and callback cleanup

Normalize every ordinary all-caps emphasis found in the scoped Cache Redis scan:

| File group | Ordinary emphasis to normalize |
|---|---|
| `Support/Serialization.php` | `IMPORTANT`, `NOT`, `AND` |
| `Console/Doctor/DoctorContext.php` | `ANY`, `ALL`, two `BOTH` occurrences |
| `Console/DoctorCommand.php` | `BOTH` |
| Doctor check interface/classes | `BEFORE`, `AFTER`, `ANY`, `ALL`, and two `NOT` occurrences |
| `Console/Benchmark/BenchmarkContext.php` | three `BOTH` occurrences and `NOT` before `OPT_PREFIX` |
| `AnyTagSet.php` | `ANY` |
| `Operations/AllTag/PutMany.php` | two `ONE` occurrences |
| `Operations/AnyTag/Forever.php` | `WITHOUT` |
| `Operations/Flush.php` | `ALL` |

Preserve `OR` and `AND` in `FlushBehaviorCheck` where they name boolean operators. Preserve identifiers/acronyms such as `ARGV`, `OPT_PREFIX`, `ZSET`, `SCAN`, `KEYS`, `TTL`, and Redis command names. Preserve intentional console banners and warnings. Keep useful performance/ownership explanations; rewrite `IMPORTANT:` as a clear sentence-case ownership lead rather than deleting it.

Also:

- remove the unused global `Redis` import from `Operations/AllTag/Prune.php`;
- type `AnyTag/GetTagItems::fetchValues()`'s `array_map` key as `string` and its connection callback return as `array`;
- rename `Serialization`'s abbreviated `RedisConnection $conn` parameters to `$connection`; all in-tree calls are positional, and this Hypervel Redis helper is not a Laravel API;
- complete directly known operation callback returns and descriptive local names in the edited files, without changing command structure or result handling;
- correct `AnyTagSet`'s stale key-builder ownership comment to match the `StoreContext` → `TagKeyBuilder` call chain;
- retain every operation's behavior and network-round-trip structure.

### 5. Clone nested class-string commands per execution

Files:

- `tests/Integration/Console/CallCommandsTest.php`
- `src/console/src/Command.php`

Laravel's `Command::resolveCommand()` can execute a container-resolved class directly because its unbound concrete resolution is transient. Hypervel's container deliberately auto-singletons unbound concretes, so the ported class-string branch returns a worker-lived command prototype. The two sibling branches already clone a command resolved by name or supplied as an object, and top-level execution uses `Application::freshCommandForRun()` for the same input/output and coroutine-isolation reason.

Add an integration regression with a stateful child command whose typed instance counter increments and is printed by `handle()`. Have a parent command invoke `$this->call(StatefulChildCommand::class)` twice. Before the fix the output progresses from `1` to `2`; require two independent `1` results and no `2`, proving sequential class-string calls do not share the container prototype. Retaining per-instance state in the fixture also tests the behavior directly without relying on recyclable object IDs. While this test file is open, add `: void` to its existing test methods and to every no-return Artisan command closure; keep every command `handle()` return typed as `int`.

Then clone the container result in the existing class-string branch:

```php
$command = clone $this->hypervel->make($command);
```

Keep the shared post-resolution `setApplication()` and `setHypervel()` calls unchanged so the clone receives the current parent command's execution context. This is an intentional Hypervel adaptation of the Laravel source: Laravel does not need this clone under its transient unbound-resolution semantics. Do not reset individual command properties, opt commands into `SelfBuilding`, change container lifetime rules, or add a command run-state abstraction.

### 6. Complete safe framework source typing and constructor documentation

Apply these direct changes one file at a time:

- `src/console/src/Application.php`: add `Create a new console application instance.` above the constructor; type the `getDefaultInputDefinition()` tap callback as `function (InputDefinition $definition): void`; type `getEnvironmentOption(): InputOption` and remove its redundant `@return`. Native typing protected Laravel methods is an established approved Hypervel port adaptation throughout this class and package.
- `src/console/src/Commands/ScheduleListCommand.php`: type `handle(): void`; its only explicit return is bare and all other paths fall through.
- `src/console/src/Commands/ScheduleClearCacheCommand.php`: type `handle(Schedule $schedule): void`; the method has no return value. This completes the directly related scheduling-command family.
- `src/console/src/Commands/ScheduleTestCommand.php`: replace the live `return $this->info(...)` suppression with current Laravel's component-rendered info call followed by `return;`, type `handle(): void`, and type the directly known predicate/value callbacks. This deliberately makes the empty-schedule output consistent with the command's existing component-rendered no-match message.
- `tests/Integration/Console/Scheduling/ScheduleTestCommandTest.php`: add `: void` to the existing empty-schedule regression and run the test file immediately.
- `src/coordinator/src/Timer.php`: add `Create a new timer instance.` above the constructor.
- `src/core/src/Logger/StdoutLogger.php`: add `Create a new stdout logger instance.` above the constructor.
- `src/database/src/ConnectionResolver.php`: add `Create a new connection resolver instance.` above the constructor.
- `src/database/src/Pool/PooledConnection.php`: add `Create a new pooled connection instance.` above the constructor.
- `src/database/src/Eloquent/Concerns/PreventsCircularRecursion.php`: change the `tap()` arrow parameter to `array &$stack`.
- `src/view/src/Compilers/ComponentTagCompiler.php`: type `setBoundAttribute(string $attribute)`, remove its stale `mixed` annotation, and add `@return array<string, true>` to `getBoundAttributes()`.

In `src/container/src/Container.php`, add native `array` to these 22 properties while keeping PHPDocs that describe their elements:

`$resolved`, `$bindings`, `$methodBindings`, `$instances`, `$autoSingletons`, `$scopedInstances`, `$aliases`, `$abstractAliases`, `$extenders`, `$tags`, `$contextual`, `$contextualAttributes`, `$checkedForAttributeBindings`, `$checkedForSingletonOrScopedAttributes`, `$reboundCallbacks`, `$globalBeforeResolvingCallbacks`, `$globalResolvingCallbacks`, `$globalAfterResolvingCallbacks`, `$beforeResolvingCallbacks`, `$resolvingCallbacks`, `$afterResolvingCallbacks`, and `$afterResolvingAttributeCallbacks`.

All initialize to arrays, all mutation/reset paths preserve arrays, and the only in-tree production subclass (`Foundation\Application`) does not redeclare them. Do not touch the already typed properties or `$environmentResolver`.

### 7. Finish Database test typing and strict assertions

#### Eloquent timestamps

In `tests/Database/DatabaseEloquentTimestampsTest.php`:

- import `ConnectionInterface`, `Schema\Builder`, and `Schema\Blueprint`;
- type `createSchema(): void`, all seven test methods `: void`, `connection(): ConnectionInterface`, and `schema(): Builder`;
- type the three schema callbacks as `function (Blueprint $table): void` and every no-return timestamp-suppression callback as `: void`; retain the existing throwing callback's `: never`;
- remove the stale Illuminate return annotations now represented natively;
- replace the four exact timestamp-string `assertEquals()` calls with `assertSame()`;
- change `Setup the database schema.` to `Set up the database schema.`;
- remove the method-attached, contentless `Tests...` docblock;
- retain the useful fixture boundary, but convert the apparent class docblock into a normal `// Eloquent models.` section comment. It marks the three inline fixture models without attaching an inventory-style docblock to the first model class.

These comment edits are narrow applications of the explicit no-stale/dead-comment requirement, not a general precedent for stripping useful upstream comments.

#### MySQL and MariaDB connection tests

Make the two files structurally consistent:

- type test methods `: void` and callback returns `: void`;
- type float-comparison inputs as `float`, `string`, and `bool`;
- type JSON null inputs as `bool`, `string`, and `array`;
- type JSON-key count inputs as `int` and `string`;
- type every provider `: array`; add an imperative provider title and keep the precise shape beneath it:

```php
/**
 * Provide JSON float comparisons.
 *
 * @return list<array{0: float, 1: string, 2: bool}>
 */

/**
 * Provide JSON null comparisons.
 *
 * @return array<string, array{0: bool, 1: string, 2?: array<array-key, mixed>}>
 */

/**
 * Provide JSON paths and their expected match counts.
 *
 * @return array<string, array{0: int, 1: string}>
 */
```

The optional JSON payload uses `array-key`, not `string`, because the providers include list and explicitly integer-keyed arrays. Replace both float `assertEquals()` calls and MySQL's insert-ID `assertEquals()` with `assertSame()`. Replace MySQL's unnecessary static local `$callbackExecuted` with an ordinary local initialized to `false`, so a repeated invocation cannot inherit a passing listener state. This deliberately fixes the same static-local defect still present in the Laravel reference test; record the intentional upstream divergence in the implementation/commit context so a future re-port does not restore it as apparent parity. PDO's configured `ATTR_STRINGIFY_FETCHES => false` returns the tested float on both local engines, and `MySqlProcessor::processInsertGetId()` casts numeric IDs to `int`.

### 8. Correct Telescope spelling and unused catches

In `src/telescope/src/Storage/DatabaseEntriesRepository.php`:

- rename `countExceptionOccurences()` to `countExceptionOccurrences()` and update its sole in-tree call;
- change the title to `Count the occurrences of an exception.`;
- remove the unused binding from both `UniqueConstraintViolationException` catches and the `Throwable` catch in `loadMonitoredTags()`;
- retain both `JsonException $exception` bindings because their codes are used.

Do not retain the misspelled method as a shim and do not add migration prose. Run the repository storage test to protect the surrounding exception-family aggregation and tag behavior.

### 9. Restore Email validation messages and fully type its test

Files:

- `tests/Validation/ValidationEmailRuleTest.php`
- `src/validation/src/Rules/Email.php`

Make the regression fail first by changing integer expectations from `[Email::class]` to the translated Laravel-compatible email message and by making null cases execute. Then remove the redundant non-string guard from `Email::passes()`. The nested validator's existing email rule rejects integers and null, fills `$messages`, and returns `false`; the outer custom-rule path then uses that translated message instead of the class fallback.

Complete the test file's native typing in the same pass:

- add `: void` to every test and helper;
- type every `#[TestWith]` email argument as `string`;
- type the translator closure as `function (): Translator` and Email-producing macro/default callbacks as `function (): Email`;
- type helper rules as `Email`, values as `array|int|string|null`, expected messages as `array`, the expected result as `bool`, and custom messages as `?string`;
- retain PHPDocs only for useful list element shapes, with imperative titles on `fails()`, `assertValidationRules()`, and `passes()` rather than type-only docblocks;
- replace `Arr::wrap()` with `is_array($values) ? $values : [$values]` so scalar null becomes one exercised value, then remove the unused `Arr` import;
- replace the dead `is_object()` branch with `clone $rule`;
- rename local `$v` to `$validator`;
- use `var_export($value, true)` in assertion diagnostics so null and strings remain readable;
- change both bare present-null cases to failure expectations with the translated message;
- add one direct Validator case showing `['nullable', Rule::email()]` accepts present null without errors;
- restore Laravel's direct `strict()` alias regression, which was missing even though Hypervel exposes the method.

Do not add absent-key or empty-string cases to this Email-owned test.

### 10. Remove every stale PHPStan suppression

Keep `reportUnmatchedIgnoredErrors: true` temporarily while performing this audit. Full PHPStan 2.2.8 reports no ordinary source-analysis errors: all failures are stale suppression metadata. It reports 195 inline findings at 189 source locations—124 unmatched identifiers and 71 bare line ignores—plus five unmatched global patterns in `phpstan.neon.dist`.

Inspect each reported source location and remove only the unmatched directive or identifier. All six multi-identifier locations report every listed identifier as unmatched. Preserve an accompanying explanation only when it remains useful without the suppression; remove analyzer-only narration with the stale directive. Do not mechanically alter the 531 inline suppression lines that still match a real PHPStan error.

Remove these five unmatched global patterns:

- undefined method calls on `ColumnDefinition`;
- undefined method calls on `IndexDefinition`;
- undefined method calls on `ForeignKeyDefinition`;
- undefined method calls on the generic `TPivotModel`;
- the invariant `Collection` array-shape return pattern.

Run full PHPStan with unmatched-ignore reporting still enabled and require a clean result. Then restore `reportUnmatchedIgnoredErrors: false` and run full PHPStan again. Do not add a targeted config or alternate developer command: partial analyses otherwise report required global patterns belonging to packages outside the selected path, but making developers and agents remember two configurations adds permanent friction for a periodic maintenance check. Temporarily enable the setting again during future stale-suppression audits.

## API, performance, and complexity assessment

- No Laravel API behavior or named surface is removed. Email behavior moves back to Laravel's message contract; nested class-string commands retain the Laravel API while gaining the per-execution identity Hypervel's container semantics require; native types enforce proven existing shapes under Hypervel's approved porting adaptation, and the remaining Laravel-ported changes are grammar, test correctness, or unused-binding cleanup.
- The Telescope correction intentionally breaks callers/overrides of the misspelled Hypervel method, with no shim. Renaming `BenchmarkCommand::displayMemoryError()`'s protected `$e` parameter can likewise affect a Hypervel subclass that calls it by named argument. Both are Hypervel-only corrections, not Laravel API breaks. The concrete `BenchmarkContext::getStore()` return and typed protected View parameter also make existing real extension requirements explicit; neither class is a Laravel public surface.
- The cleanup changes add one local boolean to Benchmark and one runtime list plus one four-caller helper to Doctor. There is no target registry, result hierarchy, retry policy, cleanup protocol, run-state object, command-lifetime change, or extra verification layer.
- No application hot path gains network calls, loops, or shared state. Nested class-string command execution gains one shallow clone, matching the cloning already performed for top-level, name-resolved, and object-supplied executions. Command-only failure paths gain reporting. Invalid non-string Email values now run the existing nested validator to produce the correct message; valid Email validation is unchanged.
- Native property/parameter types and PHPDocs have no algorithmic cost. Cache operation pipelines, batching, and Redis round trips remain unchanged.
- Removing stale PHPStan directives and patterns has no runtime or API effect. Restoring unmatched-ignore reporting to `false` preserves one standard PHPStan command for full and targeted analysis.

## Testing and verification

### Red/green bug cadence

Run each changed test file immediately after its test edit to confirm the regression, then again after its source fix:

```bash
./vendor/bin/phpunit --no-progress tests/Cache/Redis/Console/BenchmarkCommandTest.php
./vendor/bin/phpunit --no-progress tests/Cache/Redis/Console/DoctorCommandTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Console/CallCommandsTest.php
./vendor/bin/phpunit --no-progress tests/Validation/ValidationEmailRuleTest.php
```

Run each Database test file immediately after editing it:

```bash
./vendor/bin/phpunit --no-progress tests/Database/DatabaseEloquentTimestampsTest.php

DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=testing DB_USERNAME=root DB_PASSWORD=password \
  ./vendor/bin/phpunit --no-progress tests/Integration/Database/MySql/DatabaseMySqlConnectionTest.php

DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3307 DB_DATABASE=testing DB_USERNAME=root DB_PASSWORD=password \
  ./vendor/bin/phpunit --no-progress tests/Integration/Database/MariaDb/DatabaseMariaDbConnectionTest.php
```

### Focused source checks

After each related source slice, run:

```bash
./vendor/bin/phpunit --no-progress tests/Cache/Redis
./vendor/bin/phpunit --no-progress tests/Console/CommandTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Console/CallCommandsTest.php
./vendor/bin/phpunit --no-progress tests/Container/ContainerTest.php
./vendor/bin/phpunit --no-progress tests/Coordinator/TimerTest.php
./vendor/bin/phpunit --no-progress tests/Core/StdoutLoggerTest.php
./vendor/bin/phpunit --no-progress tests/Database/ConnectionResolverTest.php
./vendor/bin/phpunit --no-progress tests/Database/DatabaseConcernsPreventsCircularRecursionTest.php
./vendor/bin/phpunit --no-progress tests/Integration/Database/PooledConnectionTest.php
./vendor/bin/phpunit --no-progress tests/Telescope/Storage/DatabaseEntriesRepositoryTest.php
./vendor/bin/phpunit --no-progress tests/View/Blade/BladeComponentTagCompilerTest.php
```

### Search and review gates

- Confirm `countExceptionOccurences` has no source/test references and the corrected name has the intended definition/caller.
- Confirm the three Telescope catches have no unused binding and the used JSON catches retain theirs.
- Confirm all 22 listed Container properties are natively `array` and `$environmentResolver` is unchanged.
- Confirm every `Command::resolveCommand()` branch produces a per-execution command: clone the name-resolved command, clone the container result for an existing class string, and clone a supplied command object; keep both execution-context setters after those branches.
- Confirm `DoctorCommand` has no remaining `catch (Exception`, has removed `use Exception;`, and imports `Throwable`; confirm `BenchmarkCommand` imports both `Exception` for mandatory setup and `Throwable` for best-effort display/final cleanup.
- Confirm no `fn ($m)` remains in `DoctorCommand`, `CleanupVerificationCheck`, or `BenchmarkContext`, and each registry filter uses `string $member` with a `bool` return.
- Re-run the Doctor-method scan: no named method in the 22 concrete check classes may lack a docblock or have a type-only docblock without an imperative title.
- Re-run the Cache Redis ordinary-emphasis scan. Only real operators/identifiers and approved console banners may remain capitalized.
- Confirm the named Database files contain no `assertEquals()`, no untyped test/provider method, and no stale Illuminate return annotation.
- Confirm `ValidationEmailRuleTest` no longer imports or calls `Arr`, every null assertion executes, and the production guard is gone.
- Confirm `Repository::rememberForever()` and the gRPC generation workflow remain unchanged.
- With unmatched-ignore reporting temporarily enabled, confirm full PHPStan reports neither ordinary errors nor unmatched suppressions; then restore the setting to `false` and confirm the standard full run remains clean.
- Inspect every changed file for stale imports, dead branches/comments, unplanned API changes, avoidable hot-path work, and formatter-driven collateral edits.

At the final checkpoint, run `composer fix` once from the worktree root. If it fails, follow the repository workflow: fix with targeted checks, then run the failed check and every remaining `fix` script entry. Finish with `git diff --check`, `git status --short`, and a full diff review against this plan, Laravel references, and all caller/callee invariants above.
