# Tinker Correctness, PsySH Lifecycles, and Current Parity

## Status

Independent Hypervel implementation and focused validation are complete. PsySH PRs [#951](https://github.com/bobthecow/psysh/pull/951), [#952](https://github.com/bobthecow/psysh/pull/952), and [#953](https://github.com/bobthecow/psysh/pull/953) contain the accepted upstream corrections. Tinker is complete only after a stable release containing #951 is consumed, the direct include integration is implemented, and the full validation and review workflow passes. #952 and #953 do not gate Hypervel completion.

## Scope

Correct the verified Tinker findings without turning this targeted maintenance unit into a second package-wide audit. Preserve Hypervel's coroutine-aware Console execution, prohibition on PsySH process forking, upstream Tinker APIs and configuration, optional Database/Process presentation, and operation-local shell/alias-loader ownership.

References checked:

- Hypervel Components `59442418c2e7cdf7dac9f532f34bf170580ae2d2`, including all Tinker source/tests and connected Console behavior;
- Laravel Tinker `a1fd59c74a05f93a8343d1ff002972aebc6aaa5e` (`3.x`);
- Laravel documentation `9c5a062c14069bab9054b558829e282f9593a065`;
- installed PsySH 0.12.24 (`ca0fdcf8a7617afa3adfdf1b5fef573dffb69ca1`);
- PsySH main `cd98f04e0e8d8611e4c619334e85e74f3096b24e`.

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

- Keep `--execute`, positional `include`, `commands`, `alias`, `dont_alias`, `casters`, and `trust_project` Laravel-shaped.
- Keep PsySH process forking disabled before shell construction. Local `.psysh.php` configuration cannot re-enable `ProcessForker`: listeners are constructed before local config is loaded and are never rebuilt.
- Interactive Tinker retains Ctrl-C handling. One-shot execution must not leave process-global signal or error-handler state behind.
- User casters keep overriding defaults. Database model and Process result casters remain optional; Foundation Application is a hard package dependency.
- No HTTP/request path changes. All added comparisons, filtering, and loading occur only while starting or running the developer command. There is no lock, yield, retry, cache, static registry, coroutine context, retained worker allocation, or repeated filesystem I/O beyond includes explicitly requested by the caller.

## Final findings

| ID | Defect | Final treatment |
|---|---|---|
| `tinker-01` | Direct execution invokes PsySH's `SignalHandler::onExecute()` without its balancing loop lifecycle, replacing process-global SIGINT state in surviving programmatic/ParaTest processes. | Use an execute-only shell without that listener unless a released PsySH source fully fixes direct execution settlement. |
| `tinker-02` | Truthy option checks send valid `--execute=0` and `--execute=''` values to the REPL branch. | Treat every non-null `--execute` value as direct execution. |
| `tinker-03` | `setIncludes()` configures files, but direct `Shell::execute()` never loads them. | Expose PsySH's real include lifecycle, consume its first stable release, and call it before direct execution. |
| `tinker-04` | PsySH catches only `Exception` while loading includes and restores its error handler only normally, so `ParseError` aborts later includes and leaves PsySH's process-global handler installed. | Restore the handler in the installing method's `finally` and contain `Throwable` per include. |
| `tinker-05` | `execute($code, true)` rethrows `BreakException`; Tinker's broad catch renders `exit(3)` as an error and returns 1. | Return the embedded exit code without error rendering. |
| `tinker-06` | Raw prefixes make `App\Nova` also match `App\NovaThing` and make `/app/vendor-local/...` look like `/app/vendor/...`. | Match normalized aliases and vendor directories on semantic boundaries. |
| `tinker-07` | One Application presentation getter throwing `Error` or `TypeError` escapes the per-property `Exception` boundary and aborts the dump. | Contain `Throwable` from each getter. |
| `tinker-08` | Symfony returns `null` for a disabled configured command, which PsySH forwards to its `callable|Command` parameter and rejects with `TypeError`. | Omit disabled command results. |
| `tinker-09` | Split metadata declares unused Contracts, lacks durable dependency coverage, and omits upstream provenance. | Correct dependencies/provenance and add focused metadata coverage. |
| `tinker-10` | Public guidance omits execute/alias/caster/trust behavior and incorrectly says all PCNTL support is disabled. | Complete the concise Boost guide in Laravel-docs prose. |
| `tinker-11` | Tinker redundantly writes the Kernel-cached Console application's exception policy and can leave a caller's explicit setting changed. | Remove the mutation. |
| `psysh-01` | Public direct/piped execution calls Shell/listener acquisition hooks without the balancing `afterLoop()` lifecycle. | Submit the non-gating owner correction, record its reference, and consume it opportunistically when its released source is complete. |

## Implementation

### 1. Fix and release PsySH include ownership

PsySH PR [#951](https://github.com/bobthecow/psysh/pull/951) makes `Shell::loadIncludes()` public. Preserve the scope-carrying closure and `include_once` semantics; widen only the failure boundary and make the installer own restoration:

```php
public function loadIncludes(): void
{
    $load = function (self $__psysh__): void {
        \set_error_handler([$__psysh__, 'handleError']);

        try {
            foreach ($__psysh__->getIncludes() as $__psysh_include__) {
                try {
                    include_once $__psysh_include__;
                } catch (\Throwable $_e) {
                    $__psysh__->writeException($_e);
                }
            }
        } finally {
            \restore_error_handler();
        }

        unset($__psysh_include__);

        // Override any new local variables with pre-defined scope variables
        \extract($__psysh__->getScopeVariables(false));

        // ... then add the whole mess of variables back.
        $__psysh__->setScopeVariables(\get_defined_vars());
    };

    $load($this);
}
```

The `Throwable` change restores the original intent lost when PsySH commit `6d3d2177` removed the separate `Error` catch without widening the remaining `Exception` catch. Add upstream tests proving a `ParseError` is reported, later includes still load, scope variables survive, and the caller's error handler is restored on success/failure.

Wait for a stable PsySH release containing the public method. Then update `psy/psysh` in both root `composer.json` and `src/tinker/composer.json` to that first release and run Composer update. Never call the method while the split constraint can resolve 0.12.22–0.12.24, where it is private. If upstream stalls or rejects the correction, stop and return the decision to the owner; do not add reflection, generated includes, copied dependency code, or a compatibility branch.

### 2. Make one-shot execution exact

Resolve the option once and select the shell once:

```php
/** @var ?string $code */
$code = $this->option('execute');

if ($code !== null) {
    $config->setRawOutput(true);
}

$shell = $code !== null
    ? new ExecuteShell($config)
    : new Shell($config);
```

`ExecuteShell` has no constructor or state. It overrides `getDefaultLoopListeners()`, calls the parent, and filters only `SignalHandler`:

```php
class ExecuteShell extends Shell
{
    protected function getDefaultLoopListeners(): array
    {
        return array_filter(
            parent::getDefaultLoopListeners(),
            static fn (object $listener): bool => ! $listener instanceof SignalHandler,
        );
    }
}
```

Do not filter `ProcessForker`: `setUsePcntl(false)` has already made it impossible at listener construction, including after local config loads.

Before adding `ExecuteShell`, inspect the released PsySH source. Omit the class and use `Shell` when—and only when—the release contains all three direct-execution corrections: complete `ExecutionClosure`/`afterLoop()` pairing, both terminal-signal mutations gated by an active run, and exact prior SIGINT/async-mode restoration. A partial fix does not supersede the filter. Never retain both a redundant filter and the complete upstream lifecycle.

The direct branch becomes:

```php
if ($code !== null) {
    try {
        $shell->setOutput($this->output);
        $shell->boot();
        $shell->loadIncludes();
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

Keep the existing `$shell->setIncludes($this->argument('include'))` call before alias-loader registration and before either execution branch. `boot()` must precede `loadIncludes()` because project `.psysh.php` is loaded during boot and may contribute `defaultIncludes`; `execute()` then observes the already-booted shell.

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

Register the Foundation Application caster unconditionally because `hypervel/foundation` is a direct hard dependency. Keep Database and Process class guards.

### 5. Correct metadata, provenance, and documentation

In `src/tinker/composer.json`:

- remove unused `hypervel/contracts`;
- keep root-consistent `symfony/console:^8.1` and `symfony/var-dumper:^8.1`;
- apply the released PsySH floor from section 1;
- retain only the Database suggestion. Do not add a Process suggestion solely for symmetry.

Add `tests/Tinker/PackageMetadataTest.php` to pin direct dependency/root-constraint agreement, the absent Contracts dependency, the Database suggestion, and provider discovery. Add `Ported from: https://github.com/laravel/tinker` to the README.

Update only the Tinker section of `src/boost/docs/artisan.md`, following the surrounding Laravel-docs prose. Document:

- `--execute` and its zero/non-zero exit-status behavior, including that a reported include failure does not alter the status produced by the executed code;
- positional includes before direct execution;
- that Hypervel disables process forking, not all PCNTL support;
- `tinker.alias` vendor opt-in and `dont_alias` exclusions;
- custom `tinker.casters`;
- `trust_project`.

Keep the guide concise: no exhaustive config reference, internal listener discussion, or default-caster listing.

### 6. Track the separate PsySH execution-settlement correction

This upstream defect does not gate Tinker completion because every Hypervel path where it both installs and survives is closed either by `ExecuteShell` or the complete released upstream correction; the piped CLI path exits immediately, and programmatic Tinker cannot obtain piped code from Symfony input. PsySH PR [#952](https://github.com/bobthecow/psysh/pull/952) contains the signal/listener correction, and stacked PR [#953](https://github.com/bobthecow/psysh/pull/953) contains the full-run and ProcessForker settlement correction.

The upstream design is:

1. Wrap the complete `ExecutionClosure` body in a new outer `try/finally` and call `Shell::afterLoop()` only after output-buffer completion and scope persistence, matching `ExecutionLoopClosure` ordering.
2. Add one Shell-owned run-active flag around the selected `doRun()` branch, acquired after boot/pending-code/autoloader setup and cleared in `finally`; expose the minimal query required by built-in listeners.
3. Gate both Shell-level and `SignalHandler` `stty isig` / `stty -isig` mutations on that active run. Direct `Shell::execute()` does not own terminal flags and performs neither mutation.
4. Snapshot and restore the exact prior SIGINT handler and `pcntl_async_signals()` mode in `SignalHandler`; add `pcntl_signal_get_handler` to its capability list.
5. Cover direct success/failure, piped noninteractive SignalHandler settlement, pcntl-enabled piped ProcessForker settlement, and unchanged interactive per-loop settlement.

ProcessForker's child gains a benign `afterLoop()` call and still hardcodes `SIG_DFL`. The `throw-up` path's terminal restoration is covered by #953 rather than separate machinery. If the full signal fix reaches the PsySH release consumed by Tinker, remove `ExecuteShell` as described in section 2. Doing so changes long-running `--execute` Ctrl-C from the ordinary one-shot default exit (130) to PsySH's rendered interruption/failure (1); either is valid, and the complete upstream lifecycle makes the filter otherwise needless.

### 7. Update durable records

Add one compact Tinker ledger section covering `tinker-01` through `tinker-11`, the PsySH include release/constraint, `psysh-01` and its upstream reference, Console revalidation, final API/performance result, and rejected designs. Route the core Tinker line to this work unit. Check the core package checklist only after the blocking PsySH include release is consumed and implementation, validation, self-review, and code review are complete.

## Tests and validation

Run changed test files after each coherent source slice. Touch test methods with `: void`; make `ClassAliasAutoloaderTest::$loader` nullable and conditionally unregister it so setup failures remain primary. Use `ParallelTesting::tempDir()` and exception-safe cleanup instead of global `tempnam()`.

Required Hypervel regressions:

1. Successful and failing direct execution preserve a sentinel SIGINT handler; test cleanup restores the sentinel even after assertion failure. Do not assert async-signal mode at the Hypervel boundary because Symfony Console owns additional signal state.
2. A bounded subprocess runs the disposable runtime clone's own `artisan` at `BASE_PATH` to prove `--execute=0` and `--execute=''` select direct execution. The clone does not discover the root package, so temporarily add `TinkerServiceProvider` to its `bootstrap/providers.php` through the existing provider-file API and restore the original file in `finally`. Pass `COMPOSER_VENDOR_DIR` and `HYPERVEL_AUTOLOAD_PATH` to the child; `TESTBENCH_BASE_PATH` is not involved because the clone's entry point already owns `BASE_PATH`. Give the child an open stdin pipe that is deliberately not closed while awaiting it: the wrong REPL branch sees piped input and blocks in `getInput(false)`, while the direct branch returns immediately. Use a ten-second failure budget, treat timeout as test failure, and close every pipe in `finally`; do not require a PTY, invent another bootstrap, or add a production shell factory.
3. Positional and project-configured default includes share variables with evaluated code; malformed includes are reported, later includes still load, the prior error handler remains installed, and a successful executed expression still returns 0 after the reported include failure.
4. `exit(3)` returns 3 without evaluation-error output; ordinary throwables still return 1.
5. A disabled configured command is omitted while enabled commands retain order.
6. The public `isAliasable()` matrix covers exact class, namespace child, common-prefix sibling, trailing separator, exclusion, real vendor child, and vendor-prefix sibling without creating irreversible class aliases.
7. An Application getter throwing `Error` is omitted while later virtual properties remain.
8. Metadata/provenance and existing coroutine execution remain correct.

Validation order:

1. Run each changed Tinker test file, then the complete `tests/Tinker` group.
2. Validate both Composer manifests and the installed PsySH floor.
3. Run `composer fix` once after implementation.
4. Perform a fresh caller/callee, process-global state, terminal/signal, public API, cold-path performance, retained-memory, stale-code, and overengineering review.
5. Apply review corrections, rerun affected focused tests, and repeat the complete gate when changes warrant it.

## Rejected designs and non-findings

- No generated `require_once` source, private-method reflection, copied include loop, switch to PsySH's noninteractive runner, or temporary compatibility API.
- No signal/error-handler snapshot around yielding Hypervel code, process isolation, lock, listener registry, mode router, or coroutine context.
- No removal of interactive signal handling and no `ProcessForker` filter beyond the existing `setUsePcntl(false)` invariant.
- No class-alias registry, unalias attempt, path canonicalization, classmap cache, or concurrency machinery. PHP has no coroutine-local class table, and concurrent REPLs in one worker are unsupported.
- Keep `ClassAliasAutoloader::__destruct()`: while registered, the autoload callback retains the object; normal `finally` cleanup unregisters it first, and destruction remains an idempotent fallback.
- Keep configured commands on the invocation-local shell and existing caster precedence. No mutable worker state is introduced.
- Keep the null guard around dynamic Application getter results; only its failure boundary widens.
- Do not add default caster config, exhaustive docs, Process metadata for symmetry, or tests that merely mirror trivial mappings.

## Expected result

Tinker preserves its Laravel-facing API and Hypervel's coroutine/no-fork adaptations while direct execution becomes exact for falsey code, includes, exit status, disabled commands, and process-global cleanup. Alias discovery respects semantic boundaries; presentation degrades per property; metadata and docs describe the real package. All work remains cold developer-console work, with no application hot-path or high-scale footprint. No accepted defect, workaround, stale branch, compatibility shim, TODO, or speculative machinery remains in the completed Hypervel package.
