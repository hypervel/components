# Tinker Correctness, PsySH Lifecycles, and Current Parity

## Status

Complete. The implementation, current `0.4` merge, PsySH `dev-main` update, project-trust correction, focused validation, load-bearing counterfactuals, final self-review, and independent code review are complete. Replace `dev-main` with the first compatible stable PsySH release containing the required behavior before Hypervel 0.4 is released.

## Scope

Correct the verified Tinker findings without turning this targeted maintenance unit into a second package-wide audit. Preserve Hypervel's coroutine-aware Console execution, prohibition on PsySH process forking, upstream Tinker APIs and configuration, Database/Process presentation, and operation-local shell/alias-loader ownership.

References checked:

- current Hypervel Components `0.4`, including all Tinker source/tests and connected Console behavior;
- current Laravel Tinker `3.x` and Laravel documentation;
- current PsySH `main`.

This plan is the post-compaction implementation reference. It reproduces the core plan's "What this audit is not" section and principles 7–10 verbatim below.

## What this audit is not

This audit is not permission to add defensive machinery for every imaginable failure. Do not add an abstraction, state machine, retry loop, configurable timeout, registry, mutex, context slot, cache, or compatibility API merely because it sounds robust.

Complexity must pay for itself with at least one of:

- a demonstrated failure;
- a complete source trace proving a realistic vulnerable schedule;
- a clear general capability with real consumers and owner approval;
- deletion of greater or riskier complexity elsewhere.

Typical Laravel lifecycle semantics define the supported contract. A package that intentionally relies on model events, middleware, listeners, transactions, or another documented mechanism is not defective merely because userland can explicitly bypass that mechanism. Do not build a parallel enforcement path for `withoutEvents()`, raw database writes, disabled middleware, direct transport access, or comparable deliberate bypasses unless the public contract explicitly promises behavior through that bypass.

Underengineering is equally a failure. Fix every verified defect completely at its lowest owning boundary, never with a partial fix or a local patch over a broken shared contract, and always surface meaningful evidence-backed improvements rather than dropping them to avoid effort. Restraint applies to speculative machinery and cosmetic change, not to complete fixes or worthwhile opportunities.

Do not treat an upstream difference as a bug without tracing it. Do not treat upstream parity as proof of correctness. A real Hypervel defect remains a defect when Laravel, Hyperf, Symfony, or an SDK has the same hole.

The audit categories are discovery lenses, not boundaries around what may be corrected. Any genuine issue discovered while auditing, implementing, testing, or reviewing must be investigated, assigned to its lowest owning boundary, and taken through the applicable consensus, implementation, validation, review, and approval workflow—even when it is outside the current package, initial taxonomy, or changed diff. Do not dismiss a verified issue as unrelated or defer it merely to preserve package order. This rule applies only after the evidence threshold is met; it does not turn speculative concerns, deliberate bypasses, unsupported use, or contract violations into work.

### 7. Preserve hot-path quality

For every fix, inspect:

- additional allocations;
- container or facade resolutions;
- locking and atomics;
- hashing and serialization;
- new yields or sleeps;
- retries and polling;
- logging or exception construction;
- retained worker memory;
- cache invalidation and eviction.

A correctness guard on a cold failure path has a different cost from a new lock or resolver on every request. State the difference explicitly.

Any proposed change with a measured or source-proven hot-path regression requires explicit owner approval before implementation, even when it fixes a defect. Present the expected frequency and magnitude, the evidence, and the viable alternatives. Do not hide an unavoidable tradeoff inside a general correctness claim.

Performance improvements must provide a meaningful practical benefit after accounting for code complexity and divergence from upstream. Measure representative behavior where practical. Always surface an evidence-backed opportunity to the owner, but do not implement it without approval; a micro-optimization within measurement noise is neither a reason to diverge nor an actionable finding.

### 8. Remove superseded design completely

When a fix changes the owning model, delete obsolete helpers, callbacks, properties, config keys, comments, tests, and documentation. Do not leave a compatibility path or comment describing behavior that no longer exists. Preserve intentional upstream comments unless the new design makes them incorrect.

### 9. Treat remediation patterns as candidates

The established patterns later in this plan are a vocabulary, not a lookup table. Choose among per-call parameters, immutable values, scoped bindings, cloning, CoroutineContext, factories, explicit ownership, static reset, or resource teardown only after proving the real lifetime and owner.

### 10. Reject speculative complexity

Record low-confidence concerns under rejected or unresolved analysis. Do not implement them. Surface every evidence-backed, meaningful non-defect improvement to the owner with its benefit, cost, and alternatives, then stop for explicit approval. This requirement exists to keep worthwhile opportunities visible, not to discourage finding them.

## Contracts and performance budget

- Keep `--execute`, positional `include`, `commands`, `alias`, `dont_alias`, and `casters` Laravel-shaped. Keep the `trust_project` name, accepted values, and semantics while defaulting to PsySH's safer `prompt` mode instead of Laravel Tinker's `always`.
- Keep PsySH process forking disabled before shell construction. Local `.psysh.php` configuration cannot re-enable `ProcessForker`: listeners are constructed before local config is loaded and are never rebuilt.
- Use PsySH's normal `Shell`; current `dev-main` owns include loading and direct-execution signal cleanup without a Hypervel subclass.
- Interactive Tinker retains Ctrl-C handling. One-shot execution must not leave process-global signal or error-handler state behind.
- User casters keep overriding defaults. Database model, Process result, and Foundation Application casters remain built in; Foundation brings Database and Process as hard transitive dependencies.
- No HTTP/request path changes. All added comparisons, filtering, and loading occur only while starting or running the developer command. There is no lock, yield, retry, cache, static registry, coroutine context, retained worker allocation, or repeated filesystem I/O beyond includes explicitly requested by the caller.

## Final findings

| ID | Defect | Final treatment |
|---|---|---|
| `tinker-01` | Direct execution invoked PsySH's `SignalHandler::onExecute()` without matching cleanup, replacing process-global SIGINT state in surviving programmatic/ParaTest processes. | Use PsySH's paired execution cleanup through its normal `Shell`; do not retain a local listener filter. |
| `tinker-02` | Truthy option checks send valid `--execute=0` and `--execute=''` values to the REPL branch. | Treat every non-null `--execute` value as direct execution. |
| `tinker-03` | `setIncludes()` configured files, but direct `Shell::execute()` did not load them. | Depend temporarily on PsySH `dev-main`, which loads configured includes at the outermost `run()` or `execute()` boundary while keeping the loader private. |
| `tinker-04` | PsySH caught only `Exception` while loading includes and restored its error handler only normally, so `ParseError` aborted later includes and left PsySH's process-global handler installed. | Use the corrected `dev-main` include lifecycle, which restores the handler in `finally` and contains each `Throwable`. |
| `tinker-05` | `execute($code, true)` rethrows `BreakException`; Tinker's broad catch renders `exit(3)` as an error and returns 1. | Return the embedded exit code without error rendering. |
| `tinker-06` | Raw prefixes make `App\Nova` also match `App\NovaThing` and make `/app/vendor-local/...` look like `/app/vendor/...`. | Match normalized aliases and vendor directories on semantic boundaries. |
| `tinker-07` | One Application presentation getter throwing `Error` or `TypeError` escapes the per-property `Exception` boundary and aborts the dump. | Contain `Throwable` from each getter. |
| `tinker-08` | Symfony returns `null` for a disabled configured command, which PsySH forwards to its `callable\|Command` parameter and rejects with `TypeError`. | Omit disabled command results. |
| `tinker-09` | Split metadata declares unused Contracts and a misleading Database suggestion, lacks durable dependency coverage, and omits upstream provenance. | Correct dependencies/provenance and add focused metadata coverage. |
| `tinker-10` | Public guidance omits execute/alias/caster/trust behavior and incorrectly says all PCNTL support is disabled. | Complete the concise Tinker guide in Laravel-docs prose. |
| `tinker-11` | Tinker redundantly writes the Kernel-cached Console application's exception policy and can leave a caller's explicit setting changed. | Remove the mutation. |
| `tinker-12` | Laravel Tinker's `always` project-trust default silently executes `.psysh.php` from the current working directory, opting out of PsySH's protection against untrusted project configuration. | Use PsySH's native `prompt` mode by default; retain explicit `always` and `never` configuration. |

## Implementation

### 1. Merge current `0.4` and consume PsySH `dev-main`

Merge current `0.4` into this branch before further source changes. Resolve overlaps by preserving all newer Tinker behavior from `0.4`, including lazy command resolution, optional command and alias lists, the configured caster map, model appends through `getAppends()`, and documentation at `src/docs/artisan.md`. Combine those changes with the audit fixes; do not restore the old Boost documentation path, eager command resolution, direct model-property access, or narrower caster failure boundary.

Use Composer to change the root `psy/psysh` requirement to `dev-main`, set the split package requirement in `src/tinker/composer.json` to the same constraint, and update the installed dependency. Current PsySH `main` provides all behavior Hypervel needs:

- configured includes load once at the outermost `run()` or `execute()` boundary;
- each include `Throwable` is reported without stopping later includes, and the caller's error handler is restored;
- execution callbacks are paired, and `SignalHandler` restores the exact SIGINT handler and async-signal setting it replaced.

Replace `dev-main` with the first compatible stable release containing these behaviors before Hypervel 0.4 is released. Do not copy or reflect into PsySH internals, expose its include loader, add a version branch, or keep a local shell subclass.

### 2. Make one-shot execution exact

Resolve the option once and select the shell once:

```php
/** @var ?string $code */
$code = $this->option('execute');

if ($code !== null) {
    $config->setRawOutput(true);
}

$shell = new Shell($config);
```

Use the same PsySH `Shell` for direct and interactive execution. Current PsySH `main` owns direct-execution signal cleanup. Keep `setUsePcntl(false)` before shell construction because `ProcessForker` remains incompatible with Swoole; do not add a local shell subclass or listener filter.

The direct branch becomes:

```php
if ($code !== null) {
    try {
        $shell->setOutput($this->output);
        $shell->execute($code, true);
    } catch (BreakException $e) {
        return $e->getCode();
    } catch (Throwable $e) {
        $shell->writeException($e);

        return 1;
    } finally {
        $loader->unregister();
    }

    return 0;
}
```

Keep the existing `$shell->setIncludes($this->argument('include'))` call before alias-loader registration and before either execution branch. PsySH's `execute()` boots before loading includes because project `.psysh.php` may contribute `defaultIncludes`. Keep `setOutput()` before execution because include failures use the configured output.

PsySH reports include failures per file and continues loading. Preserve that contract: a malformed include does not itself change Tinker's exit status; the status reflects the subsequently executed code. Do not reintroduce the rejected abort-on-first-failure divergence.

Delete `$this->getApplication()->setCatchExceptions(false)`: Console already sets this policy, programmatic dispatch bypasses Symfony's wrapper, and Tinker must not mutate shared application state. Keep loader cleanup in both paths.

In `handle()`, keep scalar/null trust configuration on `get()` and use typed array retrieval for alias configuration:

```php
$config->setTrustProject($appConfig->get('tinker.trust_project'));

$loader = ClassAliasAutoloader::register(
    $shell,
    $path,
    $appConfig->array('tinker.alias', []),
    $appConfig->array('tinker.dont_alias', []),
);
```

In `getCommands()`, the local `$config` is Hypervel's Config Repository. Use its typed array accessor and omit disabled configured commands:

```php
$config = $this->getHypervel()->make('config');

foreach ($config->array('tinker.commands', []) as $command) {
    if (($command = $this->getApplication()->addCommand(
        $this->getHypervel()->make($command),
    )) !== null) {
        $commands[] = $command;
    }
}
```

Do not extract a shell factory or command registry. The branch and null check are the whole required policy.

### 3. Correct alias boundaries

Normalize configured class/namespace names once in the constructor. Preserve Collection matching and explicit-include precedence:

```php
$this->includedAliases = collect($includedAliases)
    ->map(static fn (string $alias): string => trim($alias, '\\'));
$this->excludedAliases = collect($excludedAliases)
    ->map(static fn (string $alias): string => trim($alias, '\\'));
```

Match exact classes or namespace descendants only:

```php
private static function matchesAlias(string $class, string $alias): bool
{
    return $class === $alias || Str::startsWith($class, $alias . '\\');
}
```

Use the matcher for both included and excluded aliases. A Composer classmap value is a file path, so vendor exclusion needs only the directory-child boundary:

```php
if (Str::startsWith($path, $this->vendorPath . DIRECTORY_SEPARATOR)) {
    return false;
}
```

Do not canonicalize paths, inspect the filesystem per class, cache the classmap across invocations, or add an alias index.

### 4. Keep presentation failures local

In `TinkerCaster::castApplication()`, import and catch `Throwable` around each getter, without an unused catch variable, so one failing optional virtual property does not suppress later ones:

```php
foreach (self::$appProperties as $property) {
    try {
        $value = $app->{$property}();

        if ($value !== null) {
            $results[Caster::PREFIX_VIRTUAL . $property] = $value;
        }
    } catch (Throwable) {
    }
}
```

Register the Foundation Application, Database model, and Process result casters unconditionally. `hypervel/foundation` is a direct hard dependency, Foundation directly requires Database, and Foundation's Concurrency dependency requires Process. Symfony stores caster class-string keys without resolving them, so conditional registration would not protect a runtime boundary even if a class were absent.

### 5. Correct metadata, provenance, and documentation

In `src/tinker/composer.json`:

- remove unused `hypervel/contracts`;
- keep root-consistent `symfony/console:^8.1` and `symfony/var-dumper:^8.1`;
- require PsySH `dev-main` as described in section 1;
- omit `suggest`: Database and Process are already hard transitive dependencies.

Add `tests/Tinker/PackageMetadataTest.php` to pin direct dependency/root-constraint agreement, the absent Contracts dependency and `suggest` section, and provider discovery. Add upstream provenance and concise `Differences From Laravel` notes about the user-visible no-fork behavior and safer project-trust default to the README.

Default `trust_project` to `prompt`, using PsySH's existing trust implementation. Interactive Tinker asks before loading an unfamiliar local `.psysh.php`; noninteractive execution skips untrusted project configuration without blocking. Keep `always`, `never`, boolean, and null values available. Do not add path allowlists, change working directories, or expose PsySH's `--trust-project` options: Hypervel's command does not define those options, and the existing environment variable is sufficient for one-run automation.

Update only the Tinker section of `src/docs/artisan.md`, following the surrounding Laravel-docs prose. Document:

- `--execute` and its zero/non-zero exit-status behavior, including that a reported include failure does not alter the status produced by the executed code;
- positional includes before direct execution;
- that Hypervel disables process forking, not all PCNTL support;
- `tinker.alias` vendor opt-in and `dont_alias` exclusions;
- custom `tinker.casters`;
- the `prompt` project-trust default, interactive confirmation, noninteractive skip, and working remedies: answer the prompt, configure `trust_project`, or set `TINKER_TRUST_PROJECT=always` for one trusted run.

Keep the guide concise: no exhaustive config reference, internal listener discussion, or default-caster listing.

Add one concise porting-guide entry explaining that Laravel applications which rely on implicit `.psysh.php` loading must explicitly select `always` in a trusted environment.

### 6. Update durable records

Add one compact Tinker ledger section covering `tinker-01` through `tinker-12`, the temporary PsySH `dev-main` constraint, Console revalidation, final API/performance result, and rejected designs. Route the core Tinker line to this work unit. Preserve every newer `0.4` record while resolving the audit-plan and ledger conflicts. Check the core package checklist only after current `0.4` is merged, `dev-main` is installed, and implementation, validation, self-review, and code review are complete.

## Tests and validation

Run changed test files after each coherent source slice. Touch test methods with `: void`; make `ClassAliasAutoloaderTest::$loader` nullable and conditionally unregister it so setup failures remain primary. Use `ParallelTesting::tempDir()` and exception-safe cleanup instead of global `tempnam()`.

Required Hypervel regressions:

1. Successful and failing direct execution preserve a sentinel SIGINT handler; test cleanup restores the sentinel even after assertion failure. Do not assert async-signal mode at the Hypervel boundary because Symfony Console owns additional signal state.
2. A bounded subprocess runs the disposable runtime clone's own `artisan` at `BASE_PATH` to prove `--execute=0` and `--execute=''` select direct execution. The clone does not discover the root package, so temporarily add `TinkerServiceProvider` to its `bootstrap/providers.php` through the existing provider-file API and restore the original file in `finally`. Pass `COMPOSER_VENDOR_DIR` and `HYPERVEL_AUTOLOAD_PATH` to the child; `TESTBENCH_BASE_PATH` is not involved because the clone's entry point already owns `BASE_PATH`. Give the child an open stdin pipe that is deliberately not closed while awaiting it: the wrong REPL branch sees piped input and blocks in `getInput(false)`, while the direct branch returns immediately. Use a ten-second failure budget, treat timeout as test failure, and close every pipe in `finally`; do not require a PTY, invent another bootstrap, or add a production shell factory.
3. One integration test changes into an isolated temporary project, explicitly selects `always`, and proves that a positional include and trusted local `.psysh.php` include both share variables with directly executed code. A second uses unique include paths, disables mocked console output, and proves that a malformed positional include reports `ParseError`, a later include still loads, the prior error handler remains installed, and successful executed code still returns 0. Inspect and rebalance the handler stack before any assertion so a regression cannot contaminate later tests.
4. A direct-execution test changes into an isolated temporary project under the shipped `prompt` default and proves that an untrusted `.psysh.php` cannot create its sentinel file. Do not assert PsySH's warning text.
5. `exit(3)` returns 3 without evaluation-error output; ordinary throwables still return 1.
6. Direct `getCommands()` coverage proves that an enabled configured command is retained after the whitelist while a disabled configured command is omitted.
7. The public `isAliasable()` matrix covers exact class, namespace child, common-prefix sibling, trailing separator, exclusion, real vendor child, and vendor-prefix sibling. The loader exclusion test invokes `aliasClass()` directly and relies on its shell mock because PHP class aliases are permanent and make `class_exists()` order-dependent.
8. An Application getter throwing `Error` is omitted while later virtual properties remain.
9. Metadata/provenance, nullable project-trust configuration, and existing coroutine execution remain correct.

Validation order:

1. Run each changed Tinker test file, the alias-loader file in reverse order, then the complete `tests/Tinker` group.
2. Validate both Composer manifests and confirm the installed PsySH source is current `dev-main`.
3. Confirm the include tests are load-bearing by temporarily removing the positional `setIncludes()` call and moving direct-execution `setOutput()` after `execute()`, running the matching test after each change, and reverting immediately.
4. Confirm the project-trust regression is load-bearing by running the negative trust regression, positive include test, and default-pinning config test with `TINKER_TRUST_PROJECT=always`: the negative and default-pinning tests must fail while the positive include test still passes through its explicit `always` setting.
5. Run `composer lint:fix`, `composer analyse`, and the complete `tests/Tinker` group in that order.
6. Perform a fresh caller/callee, process-global state, terminal/signal, public API, cold-path performance, retained-memory, stale-code, and overengineering review.
7. Apply review corrections, rerun affected focused tests, and repeat the complete gate when changes warrant it.

## Rejected designs and non-findings

- No local shell subclass, listener filter, public/protected include loader, nested include reloading, generated `require_once` source, private-method reflection, copied include loop, switch to PsySH's noninteractive runner, or version-specific compatibility path.
- No signal/error-handler snapshot around yielding Hypervel code, process isolation, lock, listener registry, mode router, or coroutine context.
- No removal of interactive signal handling. Keep the existing `setUsePcntl(false)` invariant; do not add a `ProcessForker` listener filter.
- No class-alias registry, unalias attempt, path canonicalization, classmap cache, or concurrency machinery. PHP has no coroutine-local class table, and concurrent REPLs in one worker are unsupported.
- Keep `ClassAliasAutoloader::__destruct()`: while registered, the autoload callback retains the object; normal `finally` cleanup unregisters it first, and destruction remains an idempotent fallback.
- Keep the shell's command set invocation-local and preserve existing caster precedence. Configured command registration on the Kernel-cached Console application follows upstream Tinker behavior.
- Keep the null guard around dynamic Application getter results; only its failure boundary widens.
- Do not add default caster config, exhaustive docs, suggestions for packages already required transitively, or tests that merely mirror trivial mappings.
- Do not add `--trust-project` or `--no-trust-project` to Tinker. Supporting them would add two options and reorder trust configuration to duplicate the existing environment-variable control.
- `setTrustProject()` would override trust flags parsed by PsySH, but neither Laravel nor Hypervel defines those flags on the Tinker command. This unreachable shared behavior is not a defect.

## Expected result

Tinker preserves its Laravel-facing API and Hypervel's coroutine/no-fork adaptations while direct execution becomes exact for falsey code, includes, exit status, disabled commands, process-global cleanup, and untrusted project configuration. Alias discovery respects semantic boundaries; presentation degrades per property; metadata and docs describe the real package. All work remains cold developer-console work, with no application hot-path or high-scale footprint. No accepted defect, workaround, stale branch, compatibility shim, TODO, or speculative machinery remains in the completed Hypervel package.
