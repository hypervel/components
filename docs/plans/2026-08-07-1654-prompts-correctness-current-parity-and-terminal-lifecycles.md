# Prompts Correctness, Current Parity, and Terminal Lifecycles

## Status

Implementation, validation, self-review, and independent code review are complete and signed off.

## Scope

Bring `hypervel/prompts` to the current Laravel Prompts surface while correcting the verified terminal, process, signal, stream, iterable, rendering, and metadata defects identified by the Prompts audit. Preserve Hypervel's coroutine-native execution and Collection support. Fix the Queue split dependency exposed by the same trace at its owning package boundary.

This is not a fresh package-wide audit. It revalidates and plans the accepted findings in `.tmp/audit-findings/prompts.md`, plus the adjacent Logger transport defect found while tracing Task settlement.

## References

- Repository rules: `AGENTS.md`
- Core audit plan: `docs/plans/2026-07-12-0900-framework-coroutine-state-lifecycle-audit.md`
- Prior findings: `.tmp/audit-findings/prompts.md` from the main Components worktree
- Laravel Prompts: `examples/laravel/prompts` at `08f025aa5b4d29c7dea2c781a6df64b5f1f4c75c`
- Laravel Prompts guide: `examples/laravel/docs/prompts.md` at `9c5a062c14069bab9054b558829e282f9593a065`
- Hypervel package: `src/prompts/`, `tests/Prompts/`, and `src/boost/docs/prompts.md`
- Cross-package consumers: Console prompt configuration, Testing cleanup, and Queue's Termwind call

## Existing contracts to preserve

- Public helpers, prompt classes, named arguments, validation, fallback behavior, and theme extension points remain Laravel-shaped.
- `Spinner` and `Task` use coroutine-native animation inside a Hypervel coroutine; callbacks continue in the caller's execution context.
- Decorated `Task` keeps its standalone process renderer when PCNTL and POSIX are available and its static fallback otherwise.
- Existing Collection-compatible inputs remain supported.
- In-process Task logging, coroutine-scoped prompt configuration, zero Task log limits, and existing prompt enhancements remain.
- Destructors remain idempotent emergency cleanup, but normal operation settlement becomes authoritative.
- Laravel's protected `Terminal::exec()`, `Task::receiveMessages()`, and `Task::resetTerminal()` extension points retain their current signatures.

No existing Laravel public API is removed or narrowed. Callout is additive current parity. The one intentional behavior beyond Laravel is clean undecorated Progress output.

## Final finding decisions

| Finding | Final treatment |
|---|---|
| `prompts-01` | Port current Callout, Elements, renderer, helper, tests, and guide from the current upstream tree. |
| `prompts-02` | Correct upstream Callout handling for empty key-value lists, sparse list keys, and numeric display keys. |
| `prompts-03` | Rebuild forked Task ownership as a transactional socket/process/signal operation with deterministic child exit and reap. |
| `prompts-04` | Restore cursor and TTY state in operation `finally` blocks; destructors become settled-state fallbacks. |
| `prompts-05` | Use static Spinner outside coroutines and clean static/direct rendering for undecorated Spinner, Task, Stream, and Progress. |
| `prompts-06` | Add the current successful kept-summary Task completion line without printing it on failure or alongside stable messages. |
| `prompts-07` | Keep the static FormBuilder revert slot, but clear it in a complete `try/finally`; remove inaccurate test-only wording. |
| `prompts-08` | Materialize only non-countable Traversables with `iterator_to_array($steps, false)` and reject totals `<= 0`. |
| `prompts-09` | Restore Progress's exact prior SIGINT handler and async mode; use a weak signal callback and idempotent settlement. |
| `prompts-10` | Make native Terminal EOF/read failure explicit while preserving transient empty non-EOF reads. |
| `prompts-11` | Bypass animation for undecorated Stream output and make `/dev/tty` probing warning-free and exception-safe. |
| `prompts-12` | Reject negative Task limits, preserve zero, and clamp terminal-derived limits to zero. |
| `prompts-13` | Render the valid zero-schema DataTable shape coherently in active and cancelled states. |
| `prompts-14` | Delete the unused, defective Prompts Termwind concern and its split dependency. |
| `prompts-15` | Keep Prompt and Terminal as separate Testing-subscriber reset entries; remove Terminal aggregation from `Prompt::flushState()`. |
| `prompts-16` | Make the README thin and complete; keep the already-correct split support/sort metadata unchanged. |
| `prompts-17` | Add accurate optional PCNTL/POSIX capability suggestions; do not suggest sockets. |
| `prompts-18` | Replace unsafe and divergent escape-stripping regexes with one pure internal helper. |
| `prompts-19` | Make Logger and Task process-socket writes complete, bounded, and observable without aborting user work. |
| `prompts-20` | Throw a descriptive exception when a custom prompt class has no renderer; do not add renderer inheritance. |
| `prompts-21` | Make `Prompt::fake()` simulate a decorated interactive terminal so existing renderer and animation assertions keep their meaning. |
| `queue-42` | Declare Queue's direct Termwind dependency in its split manifest. |
| `testbench-04` | Make runtime and purge shutdown callbacks owner-process-only so forked children cannot delete their PHPUnit worker's skeleton. |
| Cleanup | Initialize `Prompt::$cancelUsing` to `null`; no behavior-specific machinery or test is needed. |

## Implementation design

### 1. Current Callout parity and renderer corrections

Copy the current upstream files and preserve their order and structure:

- `Callout.php`;
- `Elements/ElementContract.php`, `Element.php`, `Heading.php`, `BulletedList.php`, `NumberedList.php`, `KeyValueList.php`, and `Link.php`;
- `Themes/Default/CalloutRenderer.php`;
- the `callout()` helper, default-theme registration, related renderer helpers, tests, and guide section.

Apply only standard Hypervel port adaptations: namespace, strict types, native types, strict comparisons, test base/types, and existing documentation conventions. Cast each fluent box-renderer match arm to string, matching every sibling strict-types renderer. New element list factories remain array-only like current upstream; do not widen them speculatively to Collection.

Correct three current-upstream defects in `CalloutRenderer`:

```php
if ($element->items === []) {
    return [];
}

$ordinal = 0;

foreach ($element->items as $item) {
    ++$ordinal;
    // Use the ordinal for list spacing or numbering, never the array key.
}

$key = (string) $key;
```

This accepts the factories' existing array shapes without validators, normalization objects, or readonly-array copies.

Declare key-value items as `array<int|string, string>` on both `Element::keyValueList()` and `KeyValueList::__construct()`. PHP converts numeric-string array keys to integers, and those rendered keys are part of the supported shape. Keep bulleted and numbered lists at `array<int, string>` because their sparse integer keys define neither content nor display numbering.

### 2. One correct decoration boundary

Add `Support\Utils::stripEscapeSequences()` as the single pure internal implementation used by:

- `Themes::renderTheme()` after wrapping, when output is undecorated;
- `Themes\Default\Concerns\InteractsWithStrings` for measurement/wrapping;
- `FakesInputOutput::strippedContent()`.

The helper must:

- strip complete CSI sequences with parameter bytes `0x30-0x3F`, intermediate bytes `0x20-0x2F`, and a final byte `0x40-0x7E`;
- strip BEL- or ST-terminated OSC control strings with `/\e\][^\x07\e]*(?:\x07|\e\\)/`;
- strip Symfony named and inline style tags needed by existing fake/measurement behavior.

OSC 8 labels remain visible because the label sits between the stripped opening and closing wrappers. The general OSC rule also removes `TitleRenderer`'s BEL-terminated OSC 0 title sequence.

Delete the current `/\e[^m]*m/` path because a non-SGR escape followed later by ordinary text containing `m` deletes visible content. Do not create another ANSI utility class, cache, parser object, or renderer registry.

When styled text is wrapped, `parseAnsiText()` must consume complete CSI sequences without discarding later text, update style state only for SGR, and accept both BEL and ST OSC terminators. Only OSC 8 changes hyperlink state, including parameterized openers. Wrapped links normalize their closing sequence to ST instead of retaining terminator variants. Unterminated CSI and OSC input remains literal text so parsing and measurement stay aligned.

`Cursor` and `Erase` become no-ops for undecorated output. The frame-level strip runs once, not inside every color helper. `Title` therefore emits no control bytes when undecorated. `Clear`'s CSI frame strips to empty while its existing two-newline output accounting remains unchanged. Task's completion line is the only new writer outside `renderTheme()` and must apply the same output-decoration gate explicitly.

`Prompt::fake()` must install `new BufferedConsoleOutput(decorated: true)`. The fake already forces interactive input and mocks a terminal, so decorated output is the coherent default and preserves the existing renderer assertions. Undecorated regressions must opt in explicitly by replacing the fake's output with a `BufferedConsoleOutput(decorated: false)`; do not add another public fake option or helper.

### 3. Rendering mode selection and operation cleanup

Use these execution choices:

| Component | Decorated in coroutine | Decorated outside coroutine | Undecorated |
|---|---|---|---|
| Spinner | existing coroutine animation | static | static, no terminal control |
| Task | existing coroutine animation | process renderer with PCNTL/POSIX, otherwise static | static, no terminal control |
| Stream | existing fade animation | existing fade animation | direct chunk writes |
| Progress | existing behavior | existing behavior | no initial/intermediate frames; one terminal frame |
| Interactive prompts | existing input/render loop | existing input/render loop | existing render cadence with plain frames and no terminal control |

Input interactivity and output decoration are independent. If input remains interactive while output is redirected, an interactive prompt continues to append one plain frame per render because cursor movement and erasure cannot operate on the redirected stream. This is accepted existing behavior minus terminal-control bytes; do not suppress frames or change prompt interactivity as part of the output correction.

Every completed Prompt, Spinner, Task, Stream, and Progress operation restores the cursor and any TTY mutation in `finally`. `hideCursor()` marks terminal state acquired after its decoration gate and before writing the control sequence; the interactive Prompt also marks acquisition before `setTty()` because capturing the original mode can succeed before applying the new mode fails. Destructors short-circuit once settled and remain best-effort fallbacks only. They must not reach a destroyed `CoroutineContext` or write cleanup bytes to a later operation's output.

Terminal cleanup marks the operation settled before attempting cursor and TTY restoration, attempts both independently, and reports the first failure. A failed cursor write releases the process-global cursor marker so another coroutine cannot send cleanup through a different output; this does not claim the physical cursor was restored. A failed TTY restore retains its captured mode because a later attempt still targets the same controlling terminal and may succeed. Prompt destructors swallow cleanup failures after making this best-effort attempt.

Operation failures remain primary over settlement failures in Prompt, Spinner, Task, Progress, and Stream. If operation and cleanup both fail, report only the original operation failure; PHP cannot attach a later cleanup failure as `previous`. Prompt and Stream keep their single settlement paths inline. Spinner uses one private helper for its two paths that attempts erase and terminal restoration independently. Progress uses one private helper that restores its construction-valid signal state first, then captures only the terminal-restoration failure. Do not add a shared base settlement abstraction.

Progress treats signal replacement, cursor hiding, and initial rendering as one acquisition transaction. If cursor or frame output fails after signal state was captured, settle immediately and rethrow the exact acquisition failure. Destructor cleanup is insufficient because a retained failed Progress would otherwise keep its replacement process-global SIGINT handler until destruction.

If interactive TTY setup fails, write its notice before enabling fallback. A notice-write failure rethrows the original TTY failure and does not enable or invoke the fallback through the same broken output. Progress error rendering cannot replace a callback failure, and its SIGINT handler always settles and exits even when cancellation rendering fails.

Retained instances start each operation with clean operation-owned render state. Spinner resets `static`, `count`, `state`, and `prevFrame`; Task resets `count`, `static`, `finished`, logs, stable messages, protocol buffers, `state`, and `prevFrame`. Configuration such as labels, hints, limits, and summary settings remains caller-controlled current state.

Task validates the caller-controlled public log limit at construction and again at operation entry, before resetting any operation state. It clamps that limit only for the current operation and restores the entry value in `run()`'s outer `finally`. The forked renderer exits before restoration and therefore keeps the clamped value it needs. `maxStableMessages` remains derived operation state and is recomputed on each run. Configuration is sampled at operation entry; mutation from inside the running callback is not a supported persistence mechanism and is discarded by restoration.

Do not join animation coroutines. Each Spinner operation and coroutine Task operation gives its animation loop a local completion flag, so a sleeping loop from an earlier operation cannot resume when the instance is reused. PHPStan cannot model the deferred closure execution, so annotate the flag as `bool` at its initializer rather than adding an ignore or runtime holder. No demonstrated final-render race justifies adding up to one interval of latency.

#### Undecorated Progress owner gate

For undecorated output, suppress both `start()`'s initial 0% frame and all intermediate `advance()` frames. The first output is the submit/error/cancel frame, rendered through the initial-frame path with an empty previous frame. An abandoned manual `start()` emits nothing instead of publishing an unfinished result.

This is an intentional observable improvement beyond current Laravel: redirected output gets one meaningful result rather than cursor controls or a stream of stable progress frames. A reproduced three-step Progress currently emits 84 control sequences, including 25 dim-color openings. It requires explicit owner approval before implementation.

### 4. Forked Task process ownership

The decorated, non-coroutine process renderer becomes a single transactional operation.

#### Setup

1. Create and validate the socket pair before changing signal or cursor state.
2. Capture the exact prior SIGINT handler and `pcntl_async_signals()` mode.
3. Hide/render only after setup can proceed.
4. Fork through two protected one-line native seams: socket-pair creation and fork creation. These are deterministic failure-injection boundaries, not public hooks.
5. Treat `pcntl_fork() === -1` as setup failure; restore all state and use static rendering before the callback has run.
6. Store `protected ?int $pid = null`; signal only an explicitly positive PID.

#### Child

- Close the parent endpoint immediately.
- Read protocol messages FIFO.
- Render until reset or EOF.
- On reset, use the transmitted callback-success bit to decide whether a completion line is valid.
- Keep Laravel's `receiveMessages($socket): void` and `resetTerminal(bool $originalAsync, bool $success = true): void` extension points. A reset delegates to `resetTerminal()`, and the child loop observes the existing `finished` flag.
- Complete the final render, close the endpoint, and `exit` on every normal path.
- On `Throwable`, close the endpoint and exit nonzero so the parent can report renderer failure. Never return into the caller's application after a fork.
- On EOF, discard any incomplete buffered frame rather than rendering truncated protocol content.

#### Parent

- Close the child endpoint immediately and keep the parent endpoint blocking.
- Apply the named Logger write timeout before invoking the callback.
- Preserve the callback result or exception while completing the reset/settlement protocol.
- If Logger recorded a transport failure, do not send reset on the corrupted line protocol. Close the socket, terminate the positive owned child, and reap it.
- Otherwise send reset through the shared checked writer, keep the endpoint open while waiting for child EOF with a named one-second settlement timeout, then close and reap with `waitpid()`.
- Inspect stream metadata: `timed_out` means a wedged child, empty read with `eof` means clean closure, and other read failures remain errors.
- Inspect the reaped child's exit status; a nonzero or signaled exit is renderer failure.
- Escalate to a signal when Logger transport failed or settlement/reset times out or fails. Retry a blocking `waitpid()` only when it is interrupted, treat `ECHILD` as already reaped by the kernel when the caller ignores `SIGCHLD`, and clear the PID only after that outcome is known. Exit status is unavailable in the `ECHILD` case by definition.
- Restore the exact prior SIGINT handler, async mode, cursor, socket state, and terminal state through exhaustive cleanup.

Exception precedence is fixed:

1. the user callback exception remains primary;
2. otherwise the earliest Logger write, reset, or child-renderer failure is primary;
3. cleanup, termination, and reap failures are primary only if no earlier failure exists.

This precedence applies in static, coroutine, and process modes. Static and coroutine cleanup no longer replaces the callback failure and relegates it to `previous`; process cleanup no longer loses the stored callback failure entirely.

Socket/fork setup failure uses the existing static renderer because ownership has not reached the callback. No process manager, reaper service, shutdown registry, polling loop, or configuration surface is added.

The new non-isolated Logger fork regressions exposed that a forked child inherits Testbench's runtime and purge shutdown callbacks and can delete its parent PHPUnit worker's skeleton on exit. The repaired Task renderer also exits normally instead of relying on the parent to kill an infinite child loop, making the same valid process boundary reachable in application tests. Capture the registering PID in both Testbench shutdown closures and skip cleanup in inherited children. Do not isolate the Logger tests, add Prompts knowledge of Testbench, or add mutable owner state.

`Task::renderInProcess()` may capture its signal state before entering the guarded terminal/fork block because the fixed valid SIGINT and handler calls at that boundary do not throw. Do not add rollback machinery around this non-throwing acquisition.

A Logger transport failure can leave the child's last rendered process frame on screen because reset never becomes a valid frame and the parent does not know the child copy's rendered line count. Cursor, signal, socket, and process cleanup remain complete. Do not add erase acknowledgements or replicated child-frame bookkeeping to correct cosmetic residue on this failure path.

### 5. Complete and observable process-socket writes

Add one `Support\Utils::writeAll()` boundary for every Prompts process-socket write, including Logger frames and Task reset:

```php
$length = strlen($payload);
$offset = 0;

while ($offset < $length) {
    $written = @fwrite($stream, substr($payload, $offset));

    if (is_int($written) && $written > 0) {
        $offset += $written;

        if ($offset === $length) {
            continue;
        }
    }

    $metadata = stream_get_meta_data($stream);

    if ($metadata['timed_out']) {
        throw new RuntimeException('The prompt renderer timed out while receiving output.');
    }

    if ($written === false || $written === 0) {
        throw new RuntimeException(
            $metadata['eof']
                ? 'The prompt renderer closed while receiving output.'
                : 'Unable to write output to the prompt renderer.',
        );
    }
}
```

The implementation may avoid repeated length calculation and unnecessary copies while keeping this exact ownership model. It advances only by a positive returned count, suppresses warnings only at the immediately checked native boundary, and distinguishes timeout, EOF, and generic failure through stream metadata. A positive short count continues only while `timed_out` is false; a positive short count with `timed_out=true` records the bytes written and fails immediately rather than consuming a second timeout window. It makes no `SOCK_STREAM` atomicity claim.

The stream timeout is a no-progress window, not a total payload deadline or a wall-clock bound for one `fwrite()`. PHP may keep one native write running longer while the child continues draining. Use separate named bounds:

- ten seconds for a Logger write to tolerate a temporarily stalled live renderer; this must remain well above Task's default render interval;
- one second for child settlement after user work has finished.

`Logger` records only the first transport `RuntimeException`, nulls its socket, and makes later writes no-ops. `Task` retains that Logger and reads `transportFailure()` before attempting reset. A failed newline-framed write makes the stream terminal: the child may hold an incomplete line, so a reset appended later is guaranteed to be parsed as part of the truncated frame. Task therefore skips reset, terminates, and reaps. The getter is simpler than a callback sink and avoids re-entrant Task mutation inside user code.

The base Logger's `partial()` method returns before extending its buffer when no socket remains. Static and coroutine Tasks use an `InProcessLogger` that mutates the Task directly without rendering; this keeps every Logger API meaningful in static mode while preserving its single final render.

PHP latches `timed_out` for the stream lifetime. Add one concise comment in `writeAll()` stating that callers must never reuse the stream after a recorded failure. Set the timeout once for Logger writes, then change it once to the settlement bound after every frame has completed. Do not re-arm it per write iteration or Logger frame: Task creates a fresh socket for each operation, a timeout ends that protocol, and reset is attempted only after every Logger frame completed. This avoids an extra native call on the ordinary logging path.

A temporary terminal stall can time out a write, later clear, and still let the child exit normally; settlement alone cannot reconstruct that lost output, so Logger's stored failure remains required even though Task terminates the renderer itself.

The terminal-stall rationale is that the child renderer inherits terminal flow-control state: XOFF, a suspended emulator, or a slow SSH link can block its output. Do not claim `Prompt::setTty()` disables flow control; its mode does not include `-ixon`.

`partial()` continues to accumulate and resend the current buffer. That is the ordinary route to frames larger than the socket buffer and therefore makes full writes necessary. Its quadratic protocol cost is recorded but not redesigned: measurements did not show a workload benefit sufficient to justify a delta protocol and child-side accumulator.

### 6. Progress, Terminal, FormBuilder, and renderer correctness

#### Progress

- Reset `progress`, `state`, and `prevFrame` whenever `start()` begins a new operation.
- For a non-countable Traversable, materialize once with `iterator_to_array($steps, false)` and count that list. Keep arrays and Countable inputs unbuffered.
- Reject explicit or derived totals `<= 0` before rendering.
- Capture the exact prior SIGINT handler and async mode.
- Install a WeakReference-based handler so releasing an unfinished manual Progress can reach its destructor.
- Store the captured async mode as `?bool = null`, where `null` means no signal state was captured.
- Restore exact signal state on normal finish, callback failure, and destructor fallback, then clear the captured handler and async-mode sentinels so restoration is idempotent.
- Preserve callback and render failures over terminal-restoration failures. Cancellation rendering from SIGINT is best effort; signal and terminal settlement still run before the unconditional process exit.

No streaming-progress variant or signal manager is added.

#### Terminal and Stream

- `Terminal::read()` throws a truthful `RuntimeException` for `fread() === false` and for empty reads where `feof(STDIN)` is true. A transient empty, non-EOF read remains valid for existing input doubles.
- Change Prompt's input loop to `while (true)` and remove the dead nullable-read guard, its PHPStan ignore, and the stale comment that calls a transient empty read a failure.
- Open `/dev/tty` as warning-free `r+` before any `stty`, OSC query, or terminal mutation.
- Initialize Terminal's nullable lazy TTY-mode slot explicitly to `null`.
- Query, read, write, restore, and close through that owned handle in `try/finally`.
- Reuse Terminal's existing `proc_open` execution boundary for `stty`, extending it only to accept the owned TTY input. Remove the parallel `shell_exec` path.
- Preserve `exec(string $command): string`; it delegates to a new protected `execWithInput()` seam that owns the shared `proc_open` implementation and accepts the optional TTY resource.
- If `/dev/tty` is unavailable, return existing fallback colors without warnings or terminal mutation.
- Undecorated Stream selects its direct mode in the constructor before reading terminal dimensions or colors, then writes chunks directly with no fade, query, delay, cursor, or erase work and returns the same final value.
- Decorated Stream computes terminal dimensions and fading colors before hiding the cursor. Once cursor acquisition succeeds, no throwing constructor work remains; this prevents a color-probe failure from leaking cursor ownership when PHP skips destruction for the failed constructor.
- Keep every closure stored by decorated Stream's `fadeOut()` static so the closure array does not retain its owner and defer destructor cleanup. Preserve the protected Laravel method's signature, return type, and rendered bytes; `fadeOut()` itself remains the extension point.

Do not add retries, sleeps, a terminal capability registry, configuration, or another TTY abstraction.

#### FormBuilder

Wrap the complete submission loop in `try/finally` and always call `Prompt::preventReverting()`. Keep the static callback slot because concurrent interactive forms on one physical terminal are not a supported coherent schedule. Remove inaccurate `Tests only.` wording but retain the existing `@internal` boundary.

No CoroutineContext key, nested stack, form registry, or snapshot object is added.

#### Task limits and DataTable

- Reject constructor Task limits below zero; preserve zero.
- Clamp terminal-derived Task limits with `max(0, ...)`.
- Handle DataTable's zero-header/zero-row schema before `max([])` in both active and cancelled rendering.
- Do not add guards to every public-property mutation or cache DataTable widths.

#### Renderer diagnostics

`Themes::getRenderer()` throws an `InvalidArgumentException` naming the concrete prompt class when neither active nor default theme has a renderer. Do not walk parent classes: renderer inheritance is an unrequested capability and would change theme semantics.

Task fixture subclasses must register their renderer and activate a testing theme in each `setUp()`, because authoritative test cleanup resets theme state.

### 7. Completion behavior and static reset ownership

Print the current Laravel completion line only when all are true:

- callback succeeded;
- `keepSummary` is true;
- no stable status message already supplies a summary.

Apply it in coroutine, static, and process modes. Do not print it after callback failure, when summaries are disabled, or in addition to stable messages.

For static state cleanup:

- remove `Terminal::flushState()` from `Prompt::flushState()`;
- keep both explicit entries in `AfterEachTestSubscriber` as the authoritative registry;
- replace aggregate assumptions with direct Prompt-state and Terminal-state tests;
- initialize `Prompt::$cancelUsing` explicitly to `null`.

This removes duplicate cleanup ownership without adding production state.

### 8. Dead code, package metadata, and documentation

Delete `src/prompts/src/Concerns/Termwind.php` and remove only Prompts' direct `nunomaduro/termwind` dependency. Console retains its independent active dependency and behavior. Do not leave README entries, source tombstones, or test tombstones for unused protected internals.

Add `nunomaduro/termwind: ^2.0` to `src/queue/composer.json` because `Queue\Console\WorkCommand` calls Termwind directly. Add it to `tests/Queue/PackageMetadataTest.php`'s direct-runtime dependency inventory. The existing repository manifest-consistency test then verifies that the root carries the same constraint.

Make the Prompts README thin and ordered:

1. package header;
2. `Documentation: https://hypervel.org/docs/prompts`;
3. `Differences From Laravel` covering only the complete Collection-compatible input set (option lists, multi-select defaults, table headers and rows, and grid items) and the requirement that Spinner animation runs inside a Swoole coroutine;
4. `Ported from: https://github.com/laravel/prompts`.

Do not document process, signal, ANSI, Termwind, or renderer implementation details in the README.

Update `src/boost/docs/prompts.md` in Laravel-docs prose:

- port the current Callout section and examples with Hypervel namespaces;
- mention Collection inputs at the natural option-argument sections;
- state concisely that Spinner renders statically outside a coroutine;
- describe public Task/Progress behavior only where users need it;
- do not turn the guide into a native-process or terminal-internals tutorial.

Keep the Prompts split manifest's existing support links and sort configuration unchanged. Add suggestions:

- `ext-pcntl`: Progress cancellation handling and, with POSIX, animated standalone Tasks;
- `ext-posix`: animated standalone Tasks when used with PCNTL.

Do not add `ext-sockets`; the implementation uses streams.

### 9. Preserve non-obvious upstream corrections

Add one concise WHY comment at each non-obvious upstream-divergent boundary a future Prompts sync could overwrite:

- `CalloutRenderer`: list array keys are not display ordinals;
- `Logger`: complete frame writes are required because stream writes may be partial;
- `Task`: a truncated newline-framed message makes a later reset unrecoverable;
- `Progress`: weak signal callbacks and exact restoration prevent process-global handler state from retaining or replacing an operation;
- `InteractsWithStrings`: measurement and undecorated rendering must delegate to the same escape-stripping implementation;
- `FakesInputOutput`: the fake represents an interactive decorated terminal.
- `Stream`: stored fade closures must remain static so an abandoned stream can reach destructor cleanup immediately.

Keep the separate `writeAll()` timeout-latch comment required by the transport contract. Do not comment the obvious empty-list guard or routine numeric-key cast.

## Test plan

### Current upstream and renderer behavior

- Port current `CalloutTest` and related ANSI wrapping coverage.
- Add empty key-value, sparse spaced bullets, sparse numbering from one, and numeric key tests.
- Prove CSI, SGR, BEL-terminated OSC 0, ST-terminated OSC 8 with its visible label, Symfony named/inline tags, and non-SGR sequences followed by visible `m` text strip correctly.
- Prove parameterized and BEL-terminated OSC 8 links retain their link state while wrapping, CSI intermediate bytes do not swallow later styles, and unterminated CSI/OSC input round-trips literally through parsing and wrapping.
- Prove undecorated frames contain visible content without ANSI/OSC bytes.
- Prove an undecorated interactive prompt can append successive plain frames without terminal-control bytes.
- Prove transient empty non-EOF reads skip the prompt callback and retry.
- Prove a missing renderer names the concrete prompt class.
- Preserve `ClearPromptTest`'s CSI assertion, `TitlePromptTest`'s OSC assertion, and `ProgressTest`'s initial/intermediate/final frame assertions under decorated `Prompt::fake()`; do not rewrite them around the new undecorated paths.

### Operation and output settlement

- Retained Prompt, Spinner, and Task objects have already restored cursor/TTY state before destruction.
- A custom decorated output that fails during cursor cleanup still permits TTY restoration and releases only the cross-operation cursor marker; the Terminal keeps failed TTY state for retry.
- Prompt form reverts, Spinner callbacks, Progress callbacks, and Stream rendering retain their operation result or failure when terminal cleanup also fails.
- TTY setup failure remains primary when its notice cannot be written; fallback is neither enabled nor invoked.
- Retained Spinner, Task, and Progress instances begin later sequential operations without stale frames, completion state, logs, or animation loops from the prior operation.
- Coroutine teardown produces no destroyed-context warning or late output write.
- Decorated coroutine behavior remains animated.
- Decorated `Prompt::fake()` keeps Spinner, Task, and Progress renderer-mode coverage reachable; every undecorated mode test installs an explicitly undecorated buffered output.
- Spinner outside a coroutine runs its callback and renders statically.
- Undecorated Spinner and Task emit stable plain output only.
- Undecorated Stream emits exact chunks and preserves its return value.
- Decorated Stream releases immediately and restores its cursor when abandoned in both true-color branches; disable cyclic collection around the `unset()` assertion so automatic collection cannot hide a closure-capture regression. Do not impose immediate object release on interactive prompts, whose instance-bound listeners are settled by `prompt()` cleanup.
- Undecorated Progress emits no initial/intermediate frames, one terminal frame, and no output for an abandoned start.
- Task completion lines cover success, failure, stable-message, and `keepSummary` combinations in every renderer mode.

### Native Task process protocol

Use isolated subprocess tests and protected fixture seams to prove:

- socket-pair failure and fork `-1` fall back before callback execution without leaked terminal/signal state;
- exact SIGINT handler and async mode restoration;
- unused endpoints close in both processes;
- successful callback, callback exception, child-renderer exception, reset failure, timeout, and destructor fallback all terminate and reap the owned child;
- an unrelated handled signal interrupting `waitpid()` is retried until the owned child is reaped; the regression must run red before the fix with `Unable to reap the prompt renderer process.` so its alarm timing is proven non-vacuous;
- ignored `SIGCHLD` permits a clean operation after the kernel auto-reaps the renderer, without claiming a recoverable exit status;
- no child remains live or zombie and no nonpositive PID is signaled;
- callback failure remains primary over renderer/cleanup failure;
- renderer failure surfaces only after successful user work.
- a reset write after a clean callback but closed renderer endpoint fails through the checked writer and still reaps the child.

Register fixture renderers in each test `setUp()`.

### Socket writes

- Ordinary and typed Logger frames arrive completely.
- A payload larger than the socket buffer reaches the reader intact while it drains.
- A peer that never reads triggers the no-progress bound deterministically; a reader paced near the bound is not used because it would be scheduler-dependent.
- Peer close, timeout, zero progress, and partial-positive writes cannot report success or spin.
- A positive short write with `timed_out=true` fails within one timeout window; assert elapsed time below twice the configured test bound.
- A reader draining well inside the no-progress window completes a large write even when total elapsed time exceeds the bound.
- Static Task Logger calls update the same Task state as coroutine calls, including the stable-summary branch.
- A callback failure remains primary when process cleanup also encounters a TTY restoration failure.
- After first failure Logger writes no more and retains the first failure for Task settlement.
- Task sends no reset after Logger failure, terminates the child, and proves it is reaped by return.
- Task reset uses the same writer only after all Logger frames completed.

### Remaining regressions

- Progress accepts generators once, preserves yielded value order despite duplicate keys, leaves arrays/Countable inputs unbuffered, and rejects zero/negative totals.
- Progress restores ignored and Closure SIGINT handlers and permits destructor fallback after manual start.
- A Progress initial-render failure restores the exact non-default prior SIGINT handler, async mode, and cursor state before `start()` throws.
- Progress callback failure remains the exact thrown object when error-frame rendering also fails.
- A dedicated non-coroutine, process-isolated Progress regression forks once and sends SIGINT to the child after enabling cancellation-frame failure. A non-throwing shutdown observer records exact handler and async-mode restoration, cursor-marker release, the attempted cancellation render, and that execution never reached the statement after signal delivery. The parent reaps the exact child and verifies its clean handler-owned exit. No socket or production seam is needed.
- Terminal `/dev/null` EOF fails within a subprocess bound; transient empty non-EOF input still retries.
- Missing `/dev/tty` is warning-free, emits no OSC query, and leaves terminal state untouched; include Stream construction.
- A decorated Stream color-probe failure emits no cursor-hide sequence and retains no cursor ownership marker.
- FormBuilder clears revert state after success and failure.
- Task rejects negative limits, supports zero, and handles tiny terminals.
- Task rejects a public negative limit before resetting state or invoking a callback, restores its configured limit after terminal clamping, and uses a larger terminal on a later run.
- Empty DataTable renders and cancels coherently.
- Direct Prompt and Terminal static reset tests match subscriber ownership.
- Dedicated non-coroutine, process-isolated Testbench regressions prove both directions of shutdown ownership. An inherited child must not delete the registering process's runtime or purge targets. A child that registers its own callbacks must delete a runtime copy and separate purge targets so neither callback can mask an unconditional no-op in the other. Disable PHPUnit global-state preservation where `BASE_PATH` must belong to the isolated process, install a real purge configuration before registration, and make every child-shutdown observation non-vacuous. Do not branch an existing test class's execution mode by method name or add a method-level coroutine attribute.
- Prompts and Queue package metadata satisfy repository-wide package manifest invariants.
- Console prompt configuration, console prompt assertions, and representative command prompt consumers remain green. Their test paths use prompt fallbacks rather than `Prompt::fake()`, so verify them separately against their real command output objects.

Run each changed/new test file immediately. After coherent slices, run focused Prompts, Console, Testing, and Queue tests. At the final checkpoint run `composer fix` once.

## Expected file surface

### Prompts source

- New Callout/Elements/Callout renderer files from current upstream.
- `Concerns/Cursor.php`, `Erase.php`, `FakesInputOutput.php`, `Themes.php`.
- `Themes/Default/Concerns/InteractsWithStrings.php` and renderer files for Task, Progress, DataTable, and Callout.
- `Support/Logger.php`, `Support/Utils.php`.
- `Prompt.php`, `FormBuilder.php`, `Spinner.php`, `Task.php`, `Progress.php`, `Stream.php`, `Terminal.php`, `DataTablePrompt.php`, and `helpers.php` as source tracing requires.
- Delete `Concerns/Termwind.php`.

### Tests and cross-package files

- Existing Prompts tests plus focused new Callout, Prompt lifecycle, Terminal, non-coroutine Spinner, and Task process test files; `tests/Prompts/ProgressSignalTest.php` owns the SIGINT-exit regression.
- Prompts direct reset tests; the Testing subscriber's separate Prompt/Terminal entries remain unchanged.
- Prompts/Queue split manifests, Queue's direct-dependency test, and the repository manifest-consistency test.
- `src/testbench/src/Bootstrapper.php` and a focused `tests/Testbench/BootstrapperForkCleanupTest.php` for owner-process-only shutdown cleanup.
- Prompts README and Boost guide.
- Audit routing, ledger, cross-package revalidation, and completion records after implementation/review.

Do not touch files merely because they appear in this inventory; edit only when the implemented control flow requires it.

## Implementation order

1. Add the safe shared escape helper and missing-renderer diagnostic; run focused helper/theme tests.
2. Port Callout current source/tests/docs and apply the three renderer corrections.
3. Correct common undecorated cursor/erase/frame behavior.
4. Correct Prompt/Spinner operation cleanup and Spinner execution selection.
5. Rebuild Task/Logger process ownership, full writes, completion behavior, process regressions, and the Testbench shutdown ownership they expose.
6. Correct Progress iterable, signals, totals, and undecorated terminal-only rendering.
7. Correct Terminal EOF/TTY ownership and Stream direct output.
8. Correct FormBuilder, Task limits, DataTable, static resets, and callback initialization.
9. Remove dead Termwind code; update Prompts/Queue manifests, README, guide, and metadata tests.
10. Run focused consumers, `composer fix`, fresh self-review, and code review.
11. Update audit routing/ledger/checklist only after executable work and review are complete.

This order ensures Task fixture subclasses receive a truthful renderer error before native seam tests are added and ensures all process writes share the final checked boundary from their first use.

## API, performance, and compatibility assessment

- Callout is additive Laravel parity. Existing Laravel APIs and configuration are unchanged.
- Collection support remains an intentional Hypervel extension; new upstream element factories remain unchanged.
- `Prompt::fake()` remains a tests-only API and now truthfully models the decorated interactive terminal its input and terminal doubles already simulate.
- The process, signal, terminal, ANSI, and cleanup corrections are internal ownership fixes.
- The only intentional public behavior beyond Laravel is one clean terminal Progress frame on undecorated output.
- Task restores the caller-configured public log limit after each run instead of leaving Laravel's terminal clamp in the retained instance.
- No new work enters normal web request hot paths.
- Decoration stripping runs once per completed undecorated frame and replaces duplicated regex work.
- Static/direct undecorated modes remove animation, terminal probes, sleeps, and repeated frames.
- Task's normal Hypervel coroutine mode is unchanged. The repaired standalone process path replaces a fixed delay with near-zero normal EOF settlement; longer bounds run only when external progress stalls.
- Logger's full-write loop adds iterations only when native writes are partial. A stalled renderer consumes at most one ten-second window because Logger disables itself and Task skips reset after transport failure.
- Testbench adds two PID comparisons at process shutdown only; no test or application hot path changes.
- Progress adds one signal lookup and WeakReference per operation, not per tick.
- Deleted Termwind and duplicate reset work reduce code and teardown cost.

No measured or source-proven hot-path regression is accepted.

## Rejected complexity

- No Collection widening for new element factories.
- No Callout validator or normalization layer.
- No renderer inheritance through parent classes.
- No cursor reference counting or concurrent-form model for one physical terminal.
- No animation coroutine joins.
- No process manager, background reaper, finalizer registry, signal manager, or shutdown registry.
- No writer class, writer registry, retry loop, background drain, configurable timeout, or public transport-control API.
- No Logger callback sink, ProcessLogger subclass, Task reference inside Logger, or worker/global failure state.
- No delta `partial()` protocol or child accumulator.
- No streaming Progress mode or second progress abstraction.
- No terminal capability registry, TTY abstraction, input retry/backoff, or config surface.
- No DataTable width cache or broad tiny-terminal renderer rewrite.
- No Sleep dependency solely to fake visual delays.
- No README/internal tombstones for the deleted Termwind concern.
- No external upstream issue or pull request without explicit authorization.

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

## Completion criteria

- Current Callout source, tests, and documentation are present and adapted without speculative API expansion.
- All accepted Prompts and Queue findings are fixed at their owning boundaries with no stale/dead code or superseded comments.
- Operation cleanup is deterministic; Task children/sockets/signals settle and reap on every path.
- Every process-socket payload is written completely or produces an observable failure after user work is preserved.
- Decorated behavior remains intact; undecorated output contains no terminal controls and Progress emits one terminal result only.
- Existing fake-output assertions continue to exercise decorated rendering; undecorated tests opt in explicitly.
- Progress, Terminal, FormBuilder, Task limits, and DataTable regressions are covered deterministically.
- Package README/docs/metadata are accurate and Laravel-style.
- No Laravel public API is broken; the approved Progress difference is recorded.
- Focused suites and `composer fix` pass.
- Fresh self-review finds no missing caller/callee, resource, failure, performance, or stale-code issue.
- Independent code review signs off before owner pre-commit review.
