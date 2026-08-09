# Testing correctness, parallel ownership, and current parity

**Status:** Complete.

## Objective

Complete the Testing audit by correcting verified parallel-runner ownership, teardown exhaustion,
test-command resource publication, assertion behavior, current Laravel API gaps, console command
isolation, JSON roots, registrar validation, split metadata, and test fixture ownership. Preserve
Hypervel's centralized static cleanup, coroutine-per-test model, worker-local Testbench clone,
ParaTest 7 runner contract, live token/config behavior, streamed binary responses, and HEAD producer
suppression.

These paths run in test workers, assertions, or `artisan test`; no application request path changes.
The design adds no production registry, retry, lock, coroutine context, cache, middleware, process
supervisor, resource stack, scalar assertion hierarchy, or compatibility wrapper.

## Evidence baseline

- Hypervel branch baseline: `0.4` at `dae01c405705a5a87606b6b9abb6217cac46884f`.
- Current Laravel framework reference: `examples/laravel/framework` `13.x` at
  `8df67f9d176d1d0375a866d8c6780be95ce0336e`.
- Installed test dependencies: PHPUnit `13.x` and ParaTest `7.24`; ParaTest 8 is not claimed.
- The `.tmp/audit-findings/testing.md` report is discovery evidence only. Every accepted item was
  rechecked against current source, tests, callers, dependency ownership, and upstream history.
- Originating Laravel changes were used to discover the complete changed-file surface; current
  Laravel source/tests remain the porting reference:

| PR | Current result used here |
|---|---|
| `#59140`, `#59161`, `#60090`, `#60128` | HTML assertion APIs, normalization, diagnostics, tests |
| `#59829`, `#60225`, `#59970` | Bulk JSON-path and missing flashed-input assertions |
| `#60816` | Forbidden output keyed by `"0"`; Hypervel also restores string keys before strict matcher calls |
| `#56838`, `#56849` | `PendingCommand::dd()` API; its two upstream defects are corrected |
| `#51725` | Logged exception, redirect-error, JSON-error assertion context |
| `#58946` | Grouped ordinary session-value diagnostics |
| `#60745`, `#59452` | Fluent matching and recursive-trait allocation cleanup |
| `#60423`, `#60514` | Truthful `JsonException` metadata |
| `#60929` | Per-response decoded JSON memoization |

- PHP's current installation contract makes JSON always available from PHP 8.0 and Hash always
  available from PHP 7.4; Filter remains disableable. The official references are
  [JSON installation](https://www.php.net/manual/en/json.installation.php),
  [Hash installation](https://www.php.net/manual/en/hash.installation.php), and
  [Filter installation](https://www.php.net/manual/en/filter.installation.php). Other declared
  extensions also remain real optional constraints.
- Completed routed work remains authoritative: `http-01`, `reflection-04`, `config-02`, `bus-17`,
  `http-server-03`, `http-server-05`, `database-08`, `nested-set-13`, `view-37`, `testing-01`, and
  `testing-02`, plus Testing's side of `database-15`, are revalidated, not replaced.

## Anti-overengineering rules

The following wording is retained verbatim from the core audit plan. Its principle numbering is
also retained; principles 1–6 remain in the core operating plan. In principle 9, “later in this
plan” refers to the core plan's
[Established remediation vocabulary](2026-07-12-0900-framework-coroutine-state-lifecycle-audit.md#established-remediation-vocabulary)
section.

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

## Retained architecture and API boundaries

- Normal tests remain isolated by the inherited coroutine-enabled base cases; no test gains its own
  coroutine trait or a new per-package base class.
- `AfterEachTestSubscriber` remains the one framework-static cleanup registry. Package tests own
  only files, processes, external resources, and process-global fixtures they create.
- `PhpHandler` remains before parallel setup because it applies PHPUnit configuration, including
  `HYPERVEL_PARALLEL_TESTING`, before callbacks inspect parallel state.
- `RunsInParallel::forEachProcess()` remains the protected setup-loop extension point; its default
  implementation delegates each token to the new protected single-process owner. Teardown uses
  that owner directly over the exact tokens whose setup callback was entered, including a custom
  token set produced by an override.
- Every parent-coordinator process callback still receives a fresh application and a string token
  in ascending configured process order. Testbench's application resolver remains honored.
- Process setup remains fail-fast; only teardown becomes exhaustive. Test-case and process setup
  callback semantics do not change.
- ParaTest's installed `RunnerInterface::run(): int` contract remains the runner boundary.
  Laravel's public `getExitCode()` compatibility method remains intentionally omitted because the
  supported ParaTest interface has no such method and already returns the final integer directly.
- Testbench's disposable application clone remains worker-local and shared across that worker's
  tests, so tests still restore every file they mutate within it.
- `TestResponse` keeps binary streamed-response handling, cached streamed content, and HEAD
  producer suppression. New JSON memoization sits above those existing boundaries.
- `TestView`/`TestComponent` macro cleanup and recent exact-object/stored-model identity behavior
  remain intact.
- Supported Laravel public APIs, method order, and named arguments remain compatible except for
  the recorded ParaTest-only `getExitCode()` omission. `forEachProcess()` retains its protected
  name/signature and setup role, but teardown deliberately uses the exact attempted-token owner
  rather than invoking the overridable loop a second time. Deliberate upstream-defect corrections
  are limited to safe/one-shot `dd()` output, unsupported or empty exception-context containment,
  rendered-text string-zero assertions, malformed-encoding normalization, textless expected-value
  failures, correctly formatted constraint messages, and keyed-null session assertions retaining
  their presence-and-not-null contract. PendingCommand's numeric-string cast is a Hypervel
  strict-types correction, not an upstream defect.

## Findings and final decisions

Final IDs continue after existing `testing-01` and `testing-02`.

| Final ID | Audit source | Final decision |
|---|---|---|
| `testing-03` | `testing-audit-01` | Track setup-entered tokens locally; teardown every owned token and preserve first/primary failure. |
| `testing-04` | `testing-audit-02` | Exhaust process/test-case teardown callbacks in registration order; retain the first error. |
| `testing-05` | `testing-audit-03` | Give `artisan test` one resource boundary and require complete file publication. |
| `testing-06` | `testing-audit-04` | Port one normalized HTML-text constraint and consistent Response/View/Component APIs; correct string-zero, malformed-encoding, textless-value, and diagnostic defects in the shared constraints. |
| `testing-07` | `testing-audit-05` | Restore current bulk JSON-path and missing flashed-input APIs. |
| `testing-08` | `testing-audit-06` | Correct forbidden output, failure cleanup, and `dd()` output/one-shot execution. |
| `testing-09` | `testing-audit-07` | Restore failure-context precedence and contain unsupported logged context values. |
| `testing-10` | `testing-audit-08`, Laravel `#60929` | Accept every valid JSON root and memoize one decoded wrapper per response. |
| `testing-11` | `testing-audit-09` | Require an explicitly defined callable public static registrar method. |
| `testing-12` | `testing-audit-10` | Port four bounded current-parity cleanups and preserve keyed-null session semantics. |
| `testing-13` | `testing-audit-11` | Correct Testing split dependencies, suggestions, metadata test, and provenance. |
| `testing-14` | `testing-audit-12` | Add required `void` types to remaining direct/touched test methods, file by file. |
| `testing-15` | `testing-audit-13` | Restore exact environment and worker-clone file state after every direct test path. |
| `testing-16` | Owner-approved metadata correction | Remove vacuous `ext-json`/`ext-hash` constraints from every active manifest and stale plan. |

## Implementation

Work one file at a time. Do not use scripts, search-and-replace, `sed`, `awk`, or loops to modify
the remaining test signatures or manifests. Run every changed/new test file immediately.

### 1. Make parent process ownership transactional (`testing-03`)

In `src/testing/src/Concerns/RunsInParallel.php`:

- remove unused `$output`;
- import `ApplicationContract`, `RunnerInterface`, and `WrapperRunner` and replace the file's three
  remaining fully qualified class references;
- keep `PhpHandler` first;
- retain `forEachProcess()` as the protected loop extension point and have its default
  implementation delegate each token to one protected `forProcess()` owner, which overrides can
  also reuse. That owner constructs one application, applies one string-token resolver, invokes
  its callback, then resets the resolver before independently flushing that application while
  preserving callback-first failure precedence. The resolver reset is a plain assignment and
  needs no impossible-failure guard;
- in setup, append the token to a local list as the callback's first statement. A partially failing
  setup is owned; application/token failures before callback entry are not;
- skip the runner after setup failure;
- teardown setup-entered tokens in ascending order with fresh applications, continuing after every
  failure;
- add a concise WHY above teardown explaining that rerunning the overridable setup loop cannot
  guarantee the same owned token set;
- preserve the setup/runner error over cleanup errors; otherwise throw the first teardown error;
- deliberately throw a teardown-only failure after an otherwise successful runner.

The controlling shape is:

```php
$attemptedTokens = [];
$exception = null;
$exitCode = RunnerInterface::EXCEPTION_EXIT;

try {
    $this->forEachProcess(function () use (&$attemptedTokens): void {
        $attemptedTokens[] = (string) ParallelTesting::token();
        ParallelTesting::callSetUpProcessCallbacks();
    });

    $exitCode = $this->runner->run();
} catch (Throwable $throwable) {
    $exception = $throwable;
}

foreach ($attemptedTokens as $token) {
    try {
        $this->forProcess($token, fn () => ParallelTesting::callTearDownProcessCallbacks());
    } catch (Throwable $throwable) {
        $exception ??= $throwable;
    }
}

if ($exception !== null) {
    throw $exception;
}

return $exitCode;
```

At the matching upstream position, add a concise source comment explaining that Laravel's
`getExitCode()` fallback has no applicable method on supported ParaTest 7. Retain fresh application
counts, direct resolver reset, and guarded application flush inside `forProcess()`. No
matching Laravel Testing regression covers this method, so no test is skipped and no `REMOVED:`
marker applies.

Update `tests/Testing/ParallelRunnerTest.php` through `execute()`, using a stub runner and queued
application fakes. Prove callback entry, partial ownership, unattempted exclusion, runner
suppression, all-token teardown, both failure-precedence rules, resolver/flush exhaustion, string
token ordering, fresh applications, and teardown-only propagation. A subclass regression must
prove an overridden `forEachProcess()` drives setup and the attempted token set while teardown
uses the default `forProcess()` owner over exactly those tokens. Snapshot and restore:

- `$_SERVER['HYPERVEL_PARALLEL_TESTING']`;
- `$_ENV`/process values changed by `PhpHandler` (`COLUMNS`, `LINES`);
- ambient `TEST_TOKEN` and resolver callbacks;
- the global Container instance, restored only by class teardown;
- static application/runner resolvers.

### 2. Exhaust only teardown callback lists (`testing-04`)

In `src/testing/src/ParallelTesting.php`, route only
`callTearDownProcessCallbacks()` and `callTearDownTestCaseCallbacks()` through a private helper:

```php
private function callTearDownCallbacks(array $callbacks, array $parameters): void
{
    $exception = null;

    foreach ($callbacks as $callback) {
        try {
            $this->container->call($callback, $parameters);
        } catch (Throwable $throwable) {
            $exception ??= $throwable;
        }
    }

    if ($exception !== null) {
        throw $exception;
    }
}
```

Call it inside the existing `whenRunningInParallel()` boundary with the same named parameters.
Do not alter setup callback loops. Extend `tests/Testing/ParallelTestingTest.php` for both teardown
lists, registration order, multiple errors/first-error propagation, and a setup fail-fast control.

### 3. Give `artisan test` one resource boundary (`testing-05`)

In `src/testing/src/Console/TestCommandBase.php`:

- leave no-resource preflight exits before ownership;
- begin the owner before profile directory allocation and argument construction;
- include profile allocation, temporary PHPUnit config creation, Process construction/run,
  reporting, and coverage reporting;
- retain current SIGINT swallowing and propagate non-SIGINT signals;
- after every path, independently attempt temporary-config cleanup, allocated coverage cleanup,
  then profile-directory cleanup;
- preserve the operation error, otherwise the first cleanup error.

Use direct `try/catch/finally` code in `handle()`, not a generic resource stack. Cleanup order and
precedence must remain obvious in that method.

Require temporary configuration publication before returning its path:

```php
$written = @$document->save($this->temporaryConfigurationFile);

if ($written === false) {
    throw new RuntimeException(sprintf(
        'Unable to write temporary PHPUnit configuration [%s].',
        $this->temporaryConfigurationFile,
    ));
}
```

The suppression is narrow: the checked return becomes the contextual exception and avoids an
extra native warning for the same failure.

In `src/testing/src/Profile/ExecutionFinishedSubscriber.php`, encode once and require a full write:

```php
$encoded = json_encode($slowTests, JSON_THROW_ON_ERROR);
$written = @file_put_contents($path, $encoded);

if ($written !== strlen($encoded)) {
    throw new RuntimeException(sprintf('Unable to write test profile [%s].', $path));
}
```

Both write suppressions are narrowly paired with exact checked results and contextual exceptions;
they do not swallow a failure.

Extend `tests/Testing/Console/TestCommandTest.php` with existing-method harness failures for
argument construction, non-SIGINT process failure, reporting, coverage cleanup, and competing
cleanup errors. Verify every owned file/directory is removed and exact primary/first-cleanup
precedence. Skip the permission-based temporary-configuration publication regression when running
as root, where permission checks are unreliable. Add
`tests/Testing/Profile/ExecutionFinishedSubscriberTest.php`; a local test stream
wrapper may deterministically return false/short writes without adding a production seam. Register
and unregister it in `try/finally`.

### 4. Normalize rendered HTML assertions once (`testing-06`)

Copy current Laravel `Constraints/SeeInHtml.php`, then apply Hypervel strict types and correct its
verified assertion defects. Normalize valid Unicode and retain byte-wise behavior for malformed
encodings that current Hypervel assertions accept:

```php
protected function normalize(string $value): string
{
    $value = trim(html_entity_decode(strip_tags($value), ENT_QUOTES, 'UTF-8'));
    $normalized = preg_replace('/\s+/u', ' ', $value);

    if ($normalized !== null) {
        return $normalized;
    }

    /** @var string $normalized */
    $normalized = preg_replace('/\s+/', ' ', $value);

    return $normalized;
}
```

Use it for ordinary, ordered, and negative rendered-text assertions in `TestResponse`, `TestView`,
and `TestComponent`. In both constraints, replace `empty($value)` with `$value === ''` so the valid
string `"0"` is asserted rather than skipped. In `SeeInHtml` only, fail before searching when a
non-empty raw expectation normalizes to empty; re-normalize `$failedValue` in the cold
`failureDescription()` path to select the distinct `the expected value "..." contains visible
text` diagnostic. Keep raw empty-string skipping unchanged.

Preserve `SeeInOrder` for raw content and Mailable assertions. It needs no normalized-empty branch
because entity decoding cannot turn a non-empty value into an empty string. Remove the duplicated
`Failed asserting that` prefix and every terminal period from both constraints' failure fragments;
PHPUnit supplies the prefix and final period. Assert complete rendered messages so doubled
punctuation cannot pass unnoticed. Delete now-dead `TestResponse::decodedResponseText()`.

Retain current Laravel's non-redundant `string|list<string>` and `list<string>` parameter PHPDocs
across the complete assertion family on Response/View/Component. The native `array|string` and
`array` types cannot express element types. For View/Component, the resulting API includes:

```php
public function assertSee(array|string $value, bool $escape = true): static;
public function assertSeeHtml(array|string $value): static;
public function assertSeeHtmlInOrder(array $values): static;
public function assertSeeText(array|string $value, bool $escape = true): static;
public function assertSeeTextInOrder(array $values, bool $escape = true): static;
public function assertDontSee(array|string $value, bool $escape = true): static;
public function assertDontSeeHtml(array|string $value): static;
public function assertDontSeeText(array|string $value, bool $escape = true): static;
```

Add `tests/Testing/SeeInHtmlTest.php` from current upstream and extend
`tests/Testing/TestResponseTest.php` plus `tests/Testing/TestViewTest.php` for array APIs, raw HTML,
entity/tag/ASCII and Unicode whitespace normalization, ordering, negation, and unchanged model
identity behavior. Cover `TestComponent` in the same focused file with an inline component/view
fixture; do not add a new base class. Add counterfactual coverage for string zero, whitespace-only
expectations, raw markup-only expectations with `escape: false`, malformed UTF-8 matching and
non-matching, and exact PHPUnit diagnostics. Because upstream's constraint tests usually invert
the production operands, include one production-oriented assertion that requires constructor
content normalization.

Add Hypervel-owned `list<string>` PHPDocs to Mailable's two ordered assertion methods and prove
string-zero behavior through both public callers in `tests/Mail/MailMailableAssertionsTest.php`.
Each caller must reject an absent zero and pin zero as the failed ordered value; a present-zero
success case alone is not counterfactual when the broken constraint skips zero. Restore the three
touched plain-text assertion destructures to upstream's `[, $text]` form. Run targeted PHPStan on
both constraint files. Do not share the two intentionally different normalizers or extract a
value-filter helper.

Update both duplicated public surfaces: `src/boost/docs/http-tests.md` and
`src/boost/docs/views.md`. Document the expanded `TestComponent` surface beside its example.

### 5. Restore bounded TestResponse APIs (`testing-07`)

Insert current methods in upstream relative order, using existing single-item assertions only:

```php
public function assertJsonPaths(array $paths): static
{
    foreach ($paths as $path => $expected) {
        $this->assertJsonPath($path, $expected);
    }

    return $this;
}

public function assertJsonPathsCanonicalizing(array $paths): static
{
    foreach ($paths as $path => $expected) {
        $this->assertJsonPathCanonicalizing($path, $expected);
    }

    return $this;
}

public function assertJsonMissingPaths(array $paths): static
{
    foreach ($paths as $path) {
        $this->assertJsonMissingPath($path);
    }

    return $this;
}
```

`assertSessionMissingInput(array|string $key): static` recurses over arrays and otherwise asserts
`session()->hasOldInput($key) === false` with Laravel's diagnostic. Port current regressions into
`tests/Testing/TestResponseTest.php`. Update `src/boost/docs/http-tests.md`, including one concise
canonicalizing example; add no matcher or registry.

### 6. Correct PendingCommand isolation and debug execution (`testing-08`)

In `src/testing/src/PendingCommand.php`:

- use `array_search(...) !== false` for exact and substring forbidden output;
- normalize each forbidden-output array key back to `string` once before constructing its exact or
  substring matcher, then capture that normalized key for write-back. PHP converts canonical
  numeric-string associative keys to integers; add one concise WHY and do not replace the public
  arrays or Laravel's shared Mockery scalar matching;
- use `===` for string question text;
- after `mockConsoleOutput()` succeeds, put execution, translation, exit assertions, and
  verification in `try/finally`;
- first reset the shared `expectsOutput` scalar to `null`, then clear every expectation array and
  remove the `OutputStyle` binding in that `finally`;
- preserve the original thrown failure;
- port `dd(): never` between `run()` and `verifyExpectations()`, but correct both upstream defects.
- remove the dead public `expectedTables` property from `InteractsWithConsole` and its flush line;
  `expectsTable()` lowers tables into ordinary output expectations, and neither framework has any
  reader or writer for that property.

The final debug method is:

```php
public function dd(): never
{
    $this->hasExecuted = true;

    $output = new BufferedOutput;
    $consoleOutput = new OutputStyle(new ArrayInput($this->parameters), $output);
    $exitCode = $this->app
        ->make(KernelContract::class)
        ->call($this->command, $this->parameters, $consoleOutput);

    dd([
        'exitCode' => $exitCode,
        'output' => $output->fetch(),
    ]);
}
```

`BufferedOutput` intentionally captures plain text. It neither reads nor closes process `STDOUT`.
Setting `hasExecuted` before the call prevents `__destruct()` from running the command again after
`dd()` exits.

Extend `tests/Console/ArtisanCommandTest.php` for exact and substring forbidden key `"0"` and
caught command/exit/verification failures. Immediately assert `expectsOutput` is `null`, every
expectation array is empty, and the `OutputStyle` binding is absent, then run a clean second command
as end-to-end proof. Avoid required Mockery invocations on deliberately failing paths. Add
counterfactual sequential-command regressions for both no-argument states: after
`doesntExpectOutput()`, a second command's explicit line matcher must still run; after
`expectsOutput()`, a silent second command with no output matcher must remain valid. Let normal
Mockery teardown expose either stale state. Restore Laravel's named
`verifyMockeryExpectationsNow()` test helper for the four tests that deliberately force immediate
Mockery verification; retain the bounded `ignoringMockOnceExceptions()` helper and call sites.
Add a non-discovered
`tests/Console/Fixtures/PendingCommandDdFixture.php` and invoke it in a subprocess: assert command
text appears in dumped `output`, the real command exit code appears in the dump, the dump reaches
stdout, the expected nonzero dump exit occurs, and a temporary side-effect counter proves one
command execution. Explain the fixture's inherited-scope `$APP_BASE_PATH` handoff with one concise
WHY. Delete the temporary counter file in `finally`; the committed fixture remains. Preserve
Laravel's `Debug the command.` method title.
Type every test method in the heavily modified file `: void`; retain purposeful Mockery-verification helpers rather than
adding or blindly removing `m::close()` calls.

Update `src/boost/docs/console-tests.md` beside existing command debugging guidance.

### 7. Restore assertion failure context safely (`testing-09`)

In `src/testing/src/TestResponseAssert.php`, port Laravel's precedence:

1. last collected logged exception;
2. redirect-session validation errors;
3. JSON errors.

Foundation's producer stays unchanged because supported log context includes strings. At the
failure-only consumer boundary:

```php
$lastException = $this->response->exceptions->last();

if ($lastException instanceof Throwable || (is_string($lastException) && $lastException !== '')) {
    return $this->appendExceptionToException($lastException, $exception);
}
```

Type `appendExceptionToException(Throwable|string $exceptionToAppend, ...)` and remove Laravel's
contradictory `@param Throwable`. Unsupported mixed values and the empty string fall through to
redirect/JSON context instead of causing a secondary error or empty diagnostic that hides the
original assertion failure. Containing unsupported mixed values is the approved upstream-defect
correction; empty-string fallthrough restores Laravel's truthy-guard behavior. Do not scan backward
or normalize arbitrary log context.

Test throwable/non-empty-string context, unsupported mixed values, empty-string fallthrough,
redirect errors, JSON errors, and exact precedence in `tests/Testing/TestResponseTest.php`.
Revalidate existing Foundation construction
coverage in `tests/Foundation/Testing/Concerns/MakesHttpRequestsTest.php` without changing the
producer.

### 8. Accept every valid JSON root and parse once (`testing-10`)

In `src/testing/src/AssertableJsonString.php`, change only decoded storage to `mixed`; the accepted
input union remains unchanged. In `TestResponse`, add:

```php
protected ?AssertableJsonString $decodedResponseJson = null;
```

Capture streamed/ordinary content once, build one wrapper, and validate before publishing it:

```php
if ($this->decodedResponseJson !== null) {
    return $this->decodedResponseJson;
}

$content = $this->isStreamedResponse()
    ? $this->streamedContent()
    : $this->getContent();
$testJson = new AssertableJsonString($content);
$decodedResponse = $testJson->json();

// JSON permits only space, tab, line feed, and carriage return around a value.
if ($decodedResponse === null && trim($content, " \t\n\r") !== 'null') {
    if ($this->exception) {
        throw $this->exception;
    }

    PHPUnit::withResponse($this)->fail('Invalid JSON was returned from the route.');
}

return $this->decodedResponseJson = $testJson;
```

`false` is valid and must not enter the invalid branch. Do not call `json_validate()`, decode
twice, accept NUL/vertical-tab as whitespace, or assign the cache before validation.

Extend `tests/Testing/TestResponseTest.php` for `false`, `true`, integer, string, `null`, valid JSON
whitespace, invalid null-like bytes, stored response-exception precedence, streamed content,
existing arrays/objects, and strict wrapper identity across repeated decodes. Represent the root
cases as a list of content/expected pairs so PHP cannot coerce JSON text into colliding array keys.

### 9. Validate test-state registrar callability (`testing-11`)

Keep class-string and `class_exists` validation, then require both explicit definition and
callability:

```php
if (! method_exists($class, 'register') || ! is_callable($class . '::register')) {
    throw new RuntimeException(
        "Test-state registrar [{$class}] declared by [{$source}] must define a public static register method."
    );
}
```

Extend `tests/Testing/PHPUnit/TestStateRegistrarsTest.php` with public-instance,
protected/private-static, abstract-static, magic-only negatives and inherited-public-static
positive coverage. Do not introduce reflection, an interface, instantiation, deduplication, or a
discovery cache.

### 10. Apply bounded current parity and preserve keyed-null semantics (`testing-12`)

In `TestResponse::assertSessionHasAll()`, keep integer keys, Closures, and keyed null expectations
on their existing paths, but group ordinary non-null values. Current Laravel accidentally compares
a missing key's null result with the null sentinel that means “present and not null”, allowing a
missing key to pass:

```php
$actual = [];
$expected = [];

// Populate both only for ordinary named, non-null values. A null expectation delegates to
// assertSessionHas() because it asserts that the key is present and not null.

if ($expected !== []) {
    PHPUnit::withResponse($this)->assertEquals($expected, $actual);
}
```

Test missing and wrong ordinary values so the combined equality diagnostic is pinned. Also prove
that a keyed null expectation fails for an absent key and a present-null key, but passes for a
present non-null key.

In `Fluent/Concerns/Matching.php`, replace the intermediate
`whereInstanceOf('Closure')->isNotEmpty()` allocation with the current direct
`contains(fn ($value) => $value instanceof Closure)` check. This also removes the class name
encoded as an unqualified string. In
`Concerns/TestDatabases.php`, consume `class_uses_recursive()` directly without `array_flip()`.
Add truthful `@throws JsonException` to `assertStreamedJsonContent()` and
`assertSessionHasNoErrors()`. Run their existing focused tests after each file.

### 11. Correct Testing split metadata and provenance (`testing-13`)

Update `src/testing/composer.json`:

```json
"require": {
    "ext-dom": "*",
    "ext-mbstring": "*",
    "hypervel/di": "^0.4",
    "nesbot/carbon": "^3.13.1"
},
"suggest": {
    "brianium/paratest": "Required to run tests in parallel (^7.24).",
    "phpunit/phpunit": "Required to use Hypervel's testing assertions and PHPUnit integration (^13.0.3)."
}
```

The excerpt shows additions/wording, not the complete manifest. Retain all existing dependencies.
`hypervel/di` is direct because the unconditional centralized subscriber calls its AOP/class-map
owners. Carbon and mbstring are direct runtime boundaries. The existing Foundation↔Testing cycle
is inspected and retained: both packages already expose broad public cross-package behavior, and
moving it would change ownership/namespaces without fixing a defect.

Add `tests/Testing/PackageMetadataTest.php` matching root constraints, provider discovery,
required direct packages/extensions, suggestion ranges, and absence of guaranteed-core extension
requirements. Keep the `src/testing/README.md` package header and prevailing DeepWiki badge, then
add the applicable entries in standard package order:

```md
Documentation: https://hypervel.org/docs/testing

## Differences From Laravel

- `ParallelRunner` does not expose Laravel's `getExitCode()` method. ParaTest 7 returns the final
  exit code directly from `RunnerInterface::run()`, and Hypervel's `execute()` method returns it.

Ported from: https://github.com/laravel/framework/tree/13.x/src/Illuminate/Testing
```

### 12. Remove guaranteed-core extension metadata everywhere (`testing-16`)

The durable rule is: Composer platform requirements express facilities a supported runtime may
lack; they do not inventory every extension/function used. Remove `ext-json` and `ext-hash` because
PHP `^8.4` guarantees both. Keep `ext-filter`, `ext-ctype`, and every other declared extension that
PHP can omit.

Use `composer remove ext-json ext-hash --no-update` for root `composer.json`, then
`composer update --lock` to refresh the local untracked lock. Edit these split manifests one at a
time:

```text
src/auth/composer.json
src/broadcasting/composer.json
src/encryption/composer.json
src/filesystem/composer.json
src/fortify/composer.json
src/horizon/composer.json
src/passkeys/composer.json
src/routing/composer.json
src/sanctum/composer.json
src/socialite/composer.json
src/telescope/composer.json
```

Remove `require` entries and the two misleading `suggest` entries in Broadcasting/Filesystem.
Update and immediately run:

```text
tests/Auth/PackageMetadataTest.php
tests/Horizon/PackageMetadataTest.php
tests/Passkeys/PackageMetadataTest.php
tests/Routing/PackageMetadataTest.php
tests/Sanctum/PackageMetadataTest.php
tests/Telescope/PackageMetadataTest.php
```

Remove stale extension declarations/claims from:

```text
docs/plans/2026-07-01-0915-fortify-passkeys-port.md
docs/plans/2026-08-05-1615-auth-correctness-lifecycle-and-current-parity.md
docs/plans/2026-08-05-2352-routing-correctness-current-parity-and-cache-lifecycles.md
docs/plans/2026-08-07-1302-sanctum-correctness-cache-settlement-and-current-parity.md
docs/plans/2026-08-07-2205-passkeys-correctness-security-and-maintenance.md
docs/plans/2026-08-08-1443-telescope-correctness-current-parity-and-watcher-lifecycles.md
```

Have `tests/Testing/PackageMetadataTest.php` scan the root and every active split manifest so
reintroducing either guaranteed-core extension as a requirement or suggestion fails directly.
Do not edit `_archive/`: it is a parked historical snapshot, not an active/published package.
Afterward grep all active manifests/tests/plans for both names, run `composer validate` as an
explicit metadata check, and run the normal final gate separately.

### 13. Finish test typing and fixture ownership (`testing-14`, `testing-15`)

Add `: void` manually to remaining test methods in:

```text
tests/Testing/AssertTest.php
tests/Testing/Concerns/InteractsWithDeprecationHandlingTest.php
tests/Testing/Concerns/TestCachesTest.php
tests/Testing/Concerns/TestDatabasesTest.php
tests/Testing/Concerns/TestViewsTest.php
tests/Testing/Fluent/AssertTest.php
tests/Testing/ParallelConsoleOutputTest.php
tests/Testing/ParallelTestingTest.php
tests/Console/ArtisanCommandTest.php
```

Do not change providers or inherited special methods. Run each file before moving to the next.

In `TestCachesTest`, distinguish key absence from a present falsey/string value and restore the
exact original state:

```php
$hadValue = array_key_exists('HYPERVEL_PARALLEL_TESTING_WITHOUT_CACHE', $_SERVER);
$original = $_SERVER['HYPERVEL_PARALLEL_TESTING_WITHOUT_CACHE'] ?? null;

try {
    $_SERVER['HYPERVEL_PARALLEL_TESTING_WITHOUT_CACHE'] = '1';
    // assertion
} finally {
    if ($hadValue) {
        $_SERVER['HYPERVEL_PARALLEL_TESTING_WITHOUT_CACHE'] = $original;
    } else {
        unset($_SERVER['HYPERVEL_PARALLEL_TESTING_WITHOUT_CACHE']);
    }
}
```

In `TestCommandTest`, snapshot presence and exact bytes for worker-clone `phpunit.xml` and
`custom-phpunit.xml`; restore old bytes or delete newly created files in teardown. Delete every
`.hypervel-phpunit-profile-*.xml` created by the class, including the second generated path. Keep
the standalone profile project cleanup and one class-level exact `argv` restoration; do not
duplicate that ownership inside individual tests. Do not add a production reset hook, blanket
environment snapshot, filesystem transaction, or global fixture manager.

### 14. Update audit records

Route Testing as the active package and name this detail plan. Required revalidation entries are
the completed IDs listed in the evidence baseline.

After implementation and review:

- recheck every proposed `testing-*` ID against latest `0.4` and renumber collisions before use;
- add one compact ledger work unit named `Complete Testing correctness, parallel ownership, and
  current parity`, covering `testing-03` through `testing-16`, approved upstream-defect
  corrections, rejected designs, routed revalidation, API/performance effects, and validation;
- add `testing-16` to the cross-package dependency index for Auth, Broadcasting, Encryption,
  Filesystem, Fortify, Horizon, Passkeys, Routing, Sanctum, Socialite, and Telescope, all
  revalidated by their exact metadata tests/manifest review in this work unit;
- route `testing-06` to Mail in the dependency index and cross-reference it from Mail's completed
  ledger entry; the two public Mailable ordered assertions are revalidated by their exact test;
- remove Testing's pending wording from every carried row revalidated here;
- mark `testing` complete in the package checklist;
- preserve every carried pending revalidation and completed record from latest `0.4` while
  advancing the active routing lines to Testing;
- do not copy review history, branch/commit details, test counts, or `.tmp` prose into tracked
  records.

## Planned file inventory

| Area | Files |
|---|---|
| Parallel ownership | `RunsInParallel.php`, `ParallelTesting.php`, `ParallelRunnerTest.php`, `ParallelTestingTest.php` |
| Command resources | `TestCommandBase.php`, profile subscriber, `TestCommandTest.php`, new profile subscriber test |
| HTML/assertions | new `SeeInHtml.php`/test, `SeeInOrder.php`, `TestResponse.php`, `TestView.php`, `TestComponent.php`, `Mailable.php`, and focused Testing/Mail tests |
| Console assertions | `PendingCommand.php`, `InteractsWithConsole.php`, `ArtisanCommandTest.php`, new non-discovered `dd()` fixture, console-test docs |
| Response diagnostics/JSON | `TestResponseAssert.php`, `AssertableJsonString.php`, `TestResponse.php`, focused response tests, and Foundation's MakesHttpRequests test |
| Registrar/parity | `TestStateRegistrars.php`/test, `Matching.php`/test, `TestDatabases.php`/test |
| Testing metadata/docs | Testing manifest, README, new metadata test, HTTP/View docs |
| Core-extension metadata | root plus 11 split manifests, six metadata tests, six current plan documents |
| Direct typing/fixtures | eight direct Testing files; TestCaches/TestCommand ownership paths |
| Records | this detail plan, core audit plan, companion ledger |

The inventory is a verification boundary, not permission for incidental rewrites. If an unexpected
defect appears while touching a file, follow the required investigation/consensus process.

## Test and validation plan

| Surface | Counterfactual proof |
|---|---|
| Parent runner | Partial setup owns cleanup; unattempted tokens do not; runner skip/order/fresh apps and failure precedence are exact. |
| Callback lists | Later teardown callbacks run after one/multiple failures; first failure wins; setup still stops. |
| Test command | Every allocation/failure/signal/report/cleanup path releases all owned resources without replacing the primary error. |
| Profile publication | JSON encodes once; false/short write fails with the named path; no partial publication is accepted. |
| HTML helpers | Entity/tag/ASCII/Unicode whitespace, malformed bytes, string zero, textless values, arrays, order, negation, Mail callers, and exact diagnostics behave consistently. |
| Response APIs | Bulk paths, canonicalized paths, missing paths/input, grouped session diagnostics, and existing identity assertions pass. |
| Pending command | Key `"0"`, caught failure reuse, binding cleanup, stdout dump, captured output, and exactly one execution are pinned. |
| Failure context | Throwable/string/redirect/JSON precedence works; unsupported mixed context cannot hide the original assertion failure. |
| JSON roots | Object, array, every scalar, literal null, invalid bytes, stored exception, streaming, and memoized identity are exact. |
| Registrar | Every callable/non-callable method shape yields the intended registration or contextual error. |
| Metadata | Testing's direct dependencies/suggestions/provenance are exact; guaranteed-core entries are absent from every active manifest. |
| Fixtures/types | Exact prior globals/files survive failures; each manually typed test file remains behaviorally green. |

Run each changed test file immediately. After coherent slices, run focused Testing, Console,
Foundation response-context, Testbench, and affected package metadata tests. Run `composer validate`
after manifest work. At the final checkpoint run `composer fix` once; if it fails, correct with
targeted checks and then run the failed plus remaining gate entries per `AGENTS.md`.

After gates pass, perform a fresh full-diff review: trace every caller/callee, token/application
owner, cleanup edge, test interleaving, file/global restoration, API/doc parity, retained state,
dead helper/comment, hot-path effect, and new complexity. Then request independent code review and
loop to signoff before final records and owner pre-commit review.

## API, performance, and complexity assessment

- Laravel APIs are additive or restored except for the existing recorded `getExitCode()` omission.
  `forEachProcess()` remains protected and controls setup, while exact attempted-token teardown is
  intentionally no longer routed through an override. Hypervel-specific streamed, coroutine,
  identity, and parallel behavior otherwise remain.
- Approved divergences fix upstream defects only: `dd()` captures output without closing stdout
  and marks execution before shutdown; response diagnostics contain unsupported context; text
  constraints retain string zero and malformed bytes, reject textless values, and emit one
  correctly punctuated PHPUnit message; grouped session diagnostics preserve keyed null's
  presence-and-not-null semantics. PendingCommand additionally restores numeric-string array keys
  before strict string operations and removes the behaviorally inert `expectedTables` property.
- No application request, worker loop, database, Redis, network, or production coroutine path is
  changed.
- Successful parallel startup performs the same number/order of application creations and runner
  calls. The local attempted-token list is bounded by the number of setup callbacks the
  overridable loop enters.
- Exhaustive work is added only after failures. Setup remains fail-fast.
- JSON memoization reduces repeated parsing; Matching and TestDatabases remove small test-only
  allocations. HTML normalization and its malformed-byte fallback run only when a test assertion
  is made.
- File-publication checks compare already-produced bytes and add no encoding or I/O pass.
- Metadata, docs, test typing, and fixture restoration have no runtime cost.
- The implementation uses direct loops, `try/finally`, `BufferedOutput`, exact type guards, and
  existing single-item assertion APIs. No new general-purpose framework mechanism is introduced.

## Rejected designs and verified non-findings

- No persistent token/lifecycle registry, retry, rollback callback API, concurrency, or parallel
  teardown scheduler; one local attempted-token list owns this one coordinator call.
- No aggregate exception or teardown priority system; preserve one primary/first failure.
- No per-`PendingCommand` expectation-state redesign. Sequential commands are the supported path,
  and resetting the shared scalar and arrays after each run completely fixes that path without a
  large public divergence from Laravel.
- No prefixed-key, tuple, or entry-object storage for forbidden output; one loop-local cast restores
  the declared string contract without changing public expectation arrays.
- No generic resource stack, atomic-file layer, shutdown hook, process supervisor, or production
  failure-injection seam.
- No DOM parser/sanitizer beyond the upstream assertion constraint, no three divergent
  Response/View/Component implementations, and no shared normalizer or value-filter helper for
  the two constraints' intentionally different behavior.
- No bulk assertion matcher/registry and no scalar JSON assertion hierarchy.
- No Foundation logger filtering: Laravel deliberately supports string exception context, and the
  public response collection can contain mixed values.
- No second JSON parse or `json_validate()` pass.
- No registrar interface, reflection hierarchy, instantiation, magic-only acceptance,
  deduplication, or cache.
- No ParaTest 8 API claim; installed ParaTest 7 returns the exit code directly.
- No broad dependency expansion for every class-gated optional cleanup target. Only direct
  unconditional Testing requirements are added.
- No attempt to unwind the existing Foundation/Testing package cycle; doing so would move public
  ownership without correcting behavior.
- No `ext-json`/`ext-hash` usage inventory in Composer. Guaranteed facilities are removed; real
  optional extension constraints remain.
- No edits under `_archive/`; it intentionally preserves parked historical source.
- No duplicate static cleanup, per-test coroutine traits, test-local global fixture manager, or
  production reset API.
- Existing cache/database/view token suffixing, SQLite classification, migration memoization,
  Testbench application resolution, central cleanup, binary streaming, HEAD suppression, and
  exact-model identity remain correct and are revalidated.
