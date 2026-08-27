# Prompts Audit Remediation Plan

## Objective

Resolve framework-audit findings 135–145 and the related current upstream Prompts fixes in one coherent change. The result must keep Laravel's public API and extension ergonomics except for documented protected methods whose old contracts cannot coexist with the corrected transport and validation architecture.

The package should read as one design:

- strict integer input with one validation pipeline in interactive, non-interactive, and framework-fallback modes;
- bounded, linear Task streaming with unambiguous transport and deterministic animation settlement;
- prompt-local DataTable layout work that is correct for styled, ragged, and narrow content;
- no speculative registries, invalidation APIs, protocol negotiation, compatibility shims, production instrumentation, or worker-lifetime caches.

This is framework bug-fix, performance, and architectural-remediation work. Backward compatibility with earlier Hypervel releases is not a constraint. Current Laravel APIs remain the default contract.

## References and verified current behavior

- Audit master list: `docs/plans/2026-08-22-0604-components-04-audit-remediation-plan-codex.md`, findings 135–145.
- Hypervel package: `src/prompts`; framework fallback: `src/console/src/Concerns/ConfiguresPrompts.php`.
- Current local upstreams: `examples/laravel/prompts` and `examples/laravel/framework/src/Illuminate/Console/Concerns/ConfiguresPrompts.php`.
- `docs/todo.md` has no Prompts item. Do not add an unrelated ledger for pre-existing test methods without `: void`; type every new or touched test method.
- Current upstream Prompts has two source fixes absent from Hypervel since the port point:
  - recursively strip nested Symfony inline style tags;
  - truncate Grid items before computing cell widths.
- The package otherwise has the same source-file count and recognizable Laravel structure. Hypervel's coroutine-scoped overrides, terminal handling, process settlement, and ANSI-aware rendering are justified adaptations, not candidates for wholesale rollback.

Measured costs that constrain the design:

- Task partial output is superlinear because every chunk resends and rewraps the entire accumulated prefix. Local probes showed the growth accelerating sharply as chunk counts doubled.
- DataTable currently rescans and sorts every cell on every frame. A 10,000-row table costs roughly 10 ms per render before ANSI stripping.
- ANSI/markup stripping across 30,000 cells costs roughly 9–10 ms versus roughly 1 ms for bare width measurement. Correct visible-width measurement and one-scan caching must land together; adding stripping alone would regress every keystroke.

## Non-negotiable invariants

1. Public and protected Laravel APIs stay compatible unless this plan records a concrete reason they cannot. Retain a protected extension seam whenever its contract remains satisfiable under the new design. Remove such a seam only when its contract cannot be honored and an override would silently stop running; every such removal requires the README difference and source `REMOVED:` note.
2. Prompt transforms run once for each successful submitted/default/fallback candidate. The value returned is the exact value validated.
3. Intrinsic prompt rules run before caller rules and use the transformed candidate in every execution mode.
4. Task IPC preserves payload bytes and message boundaries under partial/coalesced reads. Parent-to-child traffic is framed; the renderer acknowledgement remains the intentionally raw one-byte reverse signal. Complete and incremental output paths expose the same visible text for the same terminal controls.
5. Task transport, partial layout, and retained memory scale linearly. Plain-text carry is limited to one structural UTF-8 suffix, and any input unit that normal parsing cannot break has one fixed 4096-byte ceiling.
6. No animation frame may be written after final settlement starts. An animation render failure must not disappear in a child coroutine.
7. DataTable scans search-invariant source cells once per prompt instance. Terminal resizes refit in O(column count).
8. No process-global or static cache is added. New mutable objects are operation-local and are not container-resolved services.

## Finding disposition

| Finding | Final remedy |
|---|---|
| 135 | Strict signed-decimal integer parsing; overflow-safe bounds/arrows; remove Number's public-validator swap; unify transformed validation and fallback behavior. |
| 136 | Recompute the cursor from the complete arrow-mutated typed value. |
| 137 | Bound Number renderer width/padding and preserve a cancelled value of `"0"` in all six affected renderers. |
| 138 | Replace newline/id/regex IPC with fixed binary frames and an incremental decoder. |
| 139 | Send partial deltas and use one bounded incremental layout path in process and coroutine/static Tasks. |
| 140 | Share one Channel-backed animation owner between Spinner and coroutine Task; stop and join before settlement. |
| 141 | Never replace process signal handlers from Progress inside a coroutine. |
| 142 | Restore the state that preceded a transient non-revertible error. |
| 143 | Add Ctrl-P/Ctrl-N navigation to MultiSearch. |
| 144 | Cache natural DataTable metrics per one-shot prompt; fix styled widths, ragged data, long headers, and terminal fitting. |
| 145 | Fix erase counts, Number helper transform placement, static/context cleanup, ANSI/Unicode scrollbar replacement, and upper-bound wording. |

## Implementation plan

### 1. Unify Prompt lifecycle, transforms, and intrinsic validation

Files:

- `src/prompts/src/Prompt.php`
- `src/prompts/src/Concerns/Interactivity.php`
- `src/console/src/Concerns/ConfiguresPrompts.php`
- `tests/Prompts/PromptLifecycleTest.php`
- `tests/Console/ConfiguresPromptsTest.php`

Add a public extension hook to `Prompt`:

```php
/**
 * Validate rules intrinsic to the prompt type.
 */
public function validateIntrinsic(mixed $value): ?string
{
    return null;
}
```

This is deliberately public, not `@internal`: `Prompt` is an extensible abstract base, and a user-defined prompt with its own grammar should override the same hook used by first-party prompts. It prevents subclasses from reimplementing the required → intrinsic → caller-rule order.

Refactor the private validation pipeline to:

1. receive one already-transformed candidate;
2. run required validation;
3. run `validateIntrinsic()`;
4. run the caller closure or configured framework validator;
5. preserve the existing string-or-null validator-result contract.

The existing no-external-rules short circuit moves below required and intrinsic validation and applies only when intrinsic validation returned a nonempty error, immediately before caller/global validation. Both `null` and `''` mean that intrinsic validation passed; only a nonempty string blocks caller/global validation. A prompt with neither `$validate` nor `validateUsing` must still run and honor `validateIntrinsic()`. Pin this with standalone Number tests that configure neither validation source and still enforce integer grammar, overflow, min, and max, plus a custom prompt whose empty intrinsic result still reaches a rejecting configured validator.

Invoke the configured global validation closure as `($prompt, $value)`, where `$value` is that same transformed candidate. Update Console's installed closure to validate the second argument instead of re-reading `$prompt->value()`. Existing user-defined zero/one-argument PHP closures continue to accept the additional argument because PHP ignores surplus arguments to user-defined closures; do not add a `func_num_args()` compatibility shim. Framework array rules now observe transforms without temporary Prompt state or a second transform call. Document the callback shape in the `validateUsing()` docblock.

Capture the transformed candidate in `submit()` only after it passes validation, and return that stored value from the run loop only when `state === 'submit'`. The cancel path retains its current `transformedValue()` return because test fakes deliberately make `Terminal::exit()` a no-op and therefore reach the run-loop return after Ctrl-C. This avoids an uninitialized submitted value without transforming a successful candidate twice. Live revalidation after an error may transform once for each changed candidate, but no individual candidate is transformed twice.

`Interactivity::default()` must transform once, validate that value, and return it. It must still throw `NonInteractiveValidationException` on failure.

Enforce the actual one-shot input-prompt lifecycle with a dedicated private boolean checked and set at method entry in base `Prompt::prompt()`, before fallback, interactivity, and terminal setup can return or throw. Do not infer prior use from mutable prompt state. A second call on the same input-prompt instance throws `RuntimeException` with a direct message, including when the first call failed part-way through. Do not add reset/reuse machinery. Display elements fully override `prompt()`, while Task, Spinner, and Progress already reject it; the guard therefore covers only the real stale-state case.

Keep `ConfiguresPrompts::promptUntilValid($prompt, $required, $validate)` at its exact Laravel-compatible three-parameter protected signature. Add two private helpers:

```php
private function transformFallbackAnswer(Prompt $prompt, mixed $answer): mixed
{
    return $prompt->transform === null ? $answer : ($prompt->transform)($answer);
}

private function validateFallbackPrompt(Prompt $prompt, mixed $value): mixed
{
    $intrinsicError = $prompt->validateIntrinsic($value);

    return is_string($intrinsicError) && $intrinsicError !== ''
        ? $intrinsicError
        : (is_callable($prompt->validate)
            ? ($prompt->validate)($value)
            : $this->validatePrompt($value, $prompt->validate));
}
```

Apply both helpers uniformly to all thirteen framework fallback registrations. The base intrinsic hook is free for other prompt types and avoids special-case drift when a second prompt gains intrinsic validation. Keep the helper return type `mixed`: `promptUntilValid()` rejects only a nonempty string, while an existing user validation closure may return any value. Narrowing the helper to `?string` would change the protected Laravel fallback seam. Do not route fallback validation through `Prompt::validateUsing()`; `promptUntilValid()` already reaches `validatePrompt()` and doing both would validate twice.

Change the required check to `$required !== false` and mirror `Prompt::isInvalidWhenRequired()`'s empty set: `''`, `[]`, `false`, and `null`. Use a custom required message only when the configured string is nonempty, matching interactive behavior; `required: ''` reports `Required.`, while `required: '0'` reports `0`. Keep a concise comment linking the two manually synchronized rules. `SelectPrompt` remains unaffected because its framework fallback passes `false` for required.

Replace five `$prompt->default ?: null` expressions with an explicit empty-string decision so text, textarea, suggest, autocomplete, and number preserve the valid default `"0"`/`0`.

Tests must prove:

- a transform with a call counter runs once per submitted/default/fallback candidate;
- interactive, non-interactive, and framework fallback return the transformed value they validate;
- Ctrl-C under the fake terminal retains the existing cancel result path and never reads an uninitialized submitted candidate;
- interactive framework array rules receive the transformed candidate rather than the raw value returned by the Prompt;
- all framework fallbacks use intrinsic-before-caller validation without double-running the framework validator;
- closure and array rules retain their existing dispatch;
- `required: ''` and `required: '0'` still mean required, and `null` is rejected through the protected fallback seam;
- zero defaults survive all five affected fallback readers;
- second use of an input Prompt fails immediately even when the first use returned early or threw, while repeatable display components retain their override behavior.

### 2. Make Number a strict integer prompt

Files:

- `src/prompts/src/NumberPrompt.php`
- `src/prompts/src/helpers.php`
- `src/prompts/src/Themes/Default/NumberPromptRenderer.php`
- `src/prompts/src/Themes/Default/TextPromptRenderer.php`
- `src/prompts/src/Themes/Default/SuggestPromptRenderer.php`
- `src/prompts/src/Themes/Default/AutoCompletePromptRenderer.php`
- `src/prompts/src/Themes/Default/SearchPromptRenderer.php`
- `src/prompts/src/Themes/Default/MultiSearchPromptRenderer.php`
- `src/console/src/Concerns/ConfiguresPrompts.php`
- `tests/Prompts/NumberPromptTest.php`
- `tests/Prompts/TextPromptTest.php`
- `tests/Prompts/SuggestPromptTest.php`
- `tests/Prompts/AutoCompletePromptTest.php`
- `tests/Prompts/SearchPromptTest.php`
- `tests/Prompts/MultiSearchPromptTest.php`
- `tests/Console/ConfiguresPromptsTest.php`

Add `NumberPrompt::parseInteger(string $value): ?int` as a public static method and the one shared parser used by Prompts and Console. Static access lets the framework fallback parse raw input without constructing a second prompt and makes the grammar directly testable. It accepts only:

```text
[+-]?[0-9]+
```

It accepts leading zeroes and both signs, returns PHP integer boundaries exactly, and returns null for whitespace, decimal/exponent notation, other characters, and overflow. Implement overflow checks with normalized decimal-string comparison against `PHP_INT_MAX` and the absolute `PHP_INT_MIN`; never parse through float or rely on a coercing cast.

Normalize by removing the sign and leading zeroes, compare digit count and then lexical order against `(string) PHP_INT_MAX` or `ltrim((string) PHP_INT_MIN, '-')`, and cast only after the comparison proves representability. Do not call `abs(PHP_INT_MIN)`, which itself produces a float.

`NumberPrompt::value()` returns:

- `''` for empty input;
- the parsed `int` for a representable integer;
- the original typed string for invalid or unrepresentable input.

Rendering and cursor editing always use `typedValue`, never the normalized `value()`, so a user still sees `+007` and the cursor does not jump.

Override `validateIntrinsic()`:

- `''` and `null`: no intrinsic error; required owns emptiness;
- `int`: check bounds only;
- a representable grammar-valid integer string returned by a transform: parse it and check bounds;
- a syntactically valid but unrepresentable integer string: report the applicable lower/upper bound according to its normalized sign;
- any other value, including floats, booleans, other strings, arrays, and objects: `Must be an integer`;
- lower bound: `Must be at least {min}`;
- upper bound: `Must be at most {max}`.

Keep the existing no-period message style; change only the words required for accuracy and the integer contract.

Remove the protected Laravel `wrapValidation(mixed $validate): callable` method. Its wrapper contract conflicts with the single base pipeline because retaining it would either run intrinsic validation twice or restore Number's temporary public-validator swap. `validateIntrinsic()` is the direct replacement extension point. Record the deliberate protected-API divergence in the package README and leave a concise `REMOVED:` note at the method's natural source position; upstream has no matching method-level test to annotate.

The fallback reader uses the same parser before applying the transform, so valid entries become integers and invalid entries remain available for the shared intrinsic hook. The transformed candidate is authoritative, matching existing transform-before-validation semantics and permitting an intentional normalizing transform.

Constructor and helper changes:

- widen `$default` from `string` to `int|string` in both Number entry points;
- cast the default to its internal typed string before tracking it;
- append `?Closure $transform = null` after every existing helper parameter;
- reject `min > max` with `InvalidArgumentException`;
- keep nonpositive/null step normalization at one, matching current Laravel behavior.

Arrow behavior:

- empty up starts at configured min, or 1 when unbounded, then clamps into `[min, max]`;
- empty down starts at configured max, or 0 when unbounded, then clamps;
- a representable current value uses overflow-safe saturating arithmetic, then preserves Laravel's directional result clamp: increase clamps only to `max`, while decrease clamps only to `min`. Do not pre-clamp the current value; an out-of-range value should continue stepping toward the range rather than jumping through it, while an arrow farther out still snaps to the applicable bound;
- invalid current text is not coerced;
- after every mutation, set `cursorPosition = mb_strlen(typedValue)`.

Number renderer width must cap its effective minimum width against the terminal body width before building arrows, then use `max(0, $padding)`. Do not add a second truncation pass.

`NumberPromptRenderer::getArrows()` must read `value()` once and use the bounds branch only when that value is an `int`. Empty input keeps both arrows active; every other raw string rejected by the strict parser, including PHP-numeric fractions or exponents, dims both arrows without a coercing cast.

In all six affected cancel renderers, distinguish `''` from `"0"` explicitly rather than using truthiness.

Tests must cover signs, leading zeroes, both integer boundaries, positive/negative overflow, fractions, exponents, whitespace, non-ASCII digits, min/max inclusivity, `min > max`, transform order, array/closure rules, zero/int defaults, step normalization, saturating arrows, bounded seed selection, cursor placement, integer-only bound dimming, both arrows dimmed for rejected PHP-numeric strings, raw typed rendering, narrow terminals, and cancelled `"0"` in all six renderers.

### 3. Replace Task's text protocol with compact binary frames

Files:

- new internal frame codec under `src/prompts/src/Support/`
- `src/prompts/src/Support/Logger.php`
- `src/prompts/src/Support/InProcessLogger.php`
- `src/prompts/src/Task.php`
- new `tests/Prompts/TaskFrameTest.php`
- `tests/Prompts/LoggerTest.php`
- `tests/Prompts/TaskTest.php`
- `tests/Prompts/TaskProcessTest.php`

Use one fixed frame for every parent-to-renderer message:

```text
+------------+----------------------+-------------------+
| type: u8   | payload length: u32  | payload bytes     |
| 1 byte     | 4-byte big endian    | exact length      |
+------------+----------------------+-------------------+
```

Define compact numeric types for line, success, warning, error, label, sublabel, reset, partial delta, and partial commit. Reset carries one byte for success/failure. Do not retain identifier-prefixed text, newline delimiters, regex dispatch, base64, version negotiation, or a guessed frame-size cap.

Implement the codec as one `Support\TaskFrame` class. The internal frame codec owns:

- frame encoding and fail-fast rejection of an unmapped protected Logger type name before any transport write;
- an append-only receive buffer plus consumed cursor;
- extraction of fragmented or coalesced complete frames;
- compaction only after a meaningful consumed prefix;
- rejection of unknown types;
- failure on EOF with an incomplete header/payload.

The four-byte length creates a protocol-defined `0xffffffff` payload maximum. The encoder fails before packing a larger PHP string; do not add a smaller policy cap.

The producer and consumer are one package/deployment unit, so malformed lengths are implementation defects. Do not invent a policy limit; EOF provides the finite failure boundary.

`Logger::write()` encodes one frame and uses `Utils::writeAll()`. Preserve its transport-failure behavior and Laravel-compatible constructor. Remove `prefix()`: text prefixes cannot produce valid binary frames and retaining an unused formatter would be dead, misleading extension surface.

Record that protected-method divergence in `src/prompts/README.md` under `Differences From Laravel`. At the former method's natural source-order position, retain the concise `REMOVED:` note required by the porting rules: binary framing replaces text prefixes, so subclasses extend `write()` instead. Preserve Logger's public API, protected `write()`, and identifier property/constructor argument. Also retain Task's Laravel-compatible public `$identifier` property even though binary frames make both identifiers operationally unread; removing either extension surface is unrelated to the protocol fix.

The renderer acknowledgement remains a raw `\x06` byte written child-to-parent after successful final cleanup. Document this intentional reverse-channel asymmetry beside `RENDERER_ACKNOWLEDGEMENT`; do not turn it into a normal frame.

Retain `Task::createSocketPair()` and `Task::forkProcess()` as small protected OS seams. Add concise WHY docblocks explaining that subclasses/tests use them to exercise socket/fork/reaping failures without an injected process abstraction.

Retain protected `Task::receiveMessages($socket): void` under its exact Laravel name and signature as the renderer's decode entry point. Rework its body around `TaskFrame`; do not absorb the protected extension seam into the codec.

Rewrite raw protocol tests rather than preserving text-wire assertions. Cover exact representative bytes, every type with multiline/blank/NUL payloads, split header/payload, multiple frames in one read, decoder rejection of an unknown byte, encoder rejection of an unmapped protected type string before writing, incomplete EOF, reset settlement, write failure, ACK/ECHILD behavior, and process/in-process parity.

`Task::resetOperationState()` must reset/recreate the frame decoder and incremental output state so supported repeated `Task::run()` calls cannot inherit a partial frame, escape sequence, or log token from an earlier operation.

### 4. Make partial Task layout incremental and bounded

Files:

- new `src/prompts/src/Concerns/TracksTaskOutput.php`;
- `src/prompts/src/Task.php`
- `src/prompts/src/Support/Logger.php`
- `src/prompts/src/Support/InProcessLogger.php`
- `src/prompts/src/Themes/Default/Concerns/InteractsWithStrings.php` only where shared parsing support is genuinely needed;
- `src/prompts/src/Support/Utils.php`
- `tests/Prompts/LoggerTest.php`
- `tests/Prompts/TaskTest.php`
- `src/prompts/src/Themes/Default/CalloutRenderer.php`
- `src/prompts/src/Themes/Default/Concerns/DrawsBoxes.php`
- `tests/Prompts/AnsiWordwrapTest.php`
- `tests/Prompts/CalloutTest.php`
- `tests/Prompts/MultiByteWordWrapTest.php`
- `tests/Prompts/ParseAnsiTextTest.php`
- `tests/Prompts/UtilsTest.php`

`Logger::partial()` sends only the new chunk. It must not append to or transmit `streamBuffer`. `commitPartial()` sends an empty commit frame.

Keep Logger's protected `write(string $message, ?string $type = null)` signature. Its exact existing type vocabulary remains `null` for an ordinary line plus `success`, `warning`, `error`, `label`, `sublabel`, `partial`, and `commitpartial`; do not rename those protected-seam values while assigning compact frame bytes. The internal settlement frame maps to `reset`. An unmapped string passed by a subclass, such as `debug`, throws `InvalidArgumentException` during encoding before transport-failure capture or any write. This intentionally replaces today's accidental fall-through into a plain identifier-prefixed line; it does not expand the supported protected vocabulary or require a README difference. Subclasses do not need to understand numeric protocol identifiers.

Remove the socket-null early return from `Logger::partial()` when partials become delta-only, so `InProcessLogger` can forward the chunk through its sole `write()` override. Keep the null-socket guard in base `Logger::write()` so a directly constructed socketless Logger retains its existing no-op behavior.

Both IPC and in-process execution call one public `@internal` `Task::applyMessage(?string $type, string $payload)` boundary. The boundary accepts the IPC superset, including the reset type and its one-byte settlement payload. `InProcessLogger` emits only Logger's ordinary message subset because in-process settlement calls `finishRendering()` directly; do not add a dead in-process reset branch for symmetry. The public visibility is the smallest honest cross-class seam for the in-process Logger, while `@internal` keeps it outside the supported user API. Keep `InProcessLogger` because `Task::run()` publicly supplies a Laravel Logger; reduce it to overriding only `write()` and forwarding the same typed message. Delete the six Hypervel-only public Task mutation bridges and their duplicate Logger overrides.

Put the cohesive partial/log layout state in a private package concern composed only by Task. Its docblock must identify it as Task-owned, and the concern must declare its own incremental parsing/layout state properties while Task remains responsible for lifecycle calls such as reset and commit. Compose `InteractsWithStrings` directly in the concern so its ANSI/string dependency is explicit. This keeps the already-large Task lifecycle readable without inverting `Support` onto theme code or creating callbacks between layout objects.

Retain Task's protected `?int $partialStartIndex = null` under its exact name, type, default, and meaning as the suffix boundary where the current partial region begins. Declare it with the concern's incremental state and adjust it when ring-buffer trimming discards earlier logs. The same boundary supports incremental commit/reset and the retained full-replacement seam without duplicating state.

Retain protected `Task::addLogLines(string $line): void` under its exact Laravel name and signature, with its wrapping/ring-buffer internals delegated to the concern. Also retain protected `Task::replacePartialLines(string $text): void`: reset only the current incremental partial state, then consume the supplied full text as one fresh partial input. The optimized Logger/IPC path never calls this full-prefix compatibility seam, so preserving it adds no hot-path accumulation or repeated processing.

Complete messages stay on the faster `ansiWordwrap()` path rather than passing through the incremental parser, which costs roughly two to four times as much for whole payloads. Normalize CRLF to LF once, then wrap the entire message in one call so SGR and OSC 8 state can span logical lines. While aligning parsed source characters with wrapped visible lines, `ansiWordwrap()` skips source LF bytes as separators removed by `mbWordwrap()`; never skip a lone carriage return. This shared fix also covers multiline Stream and Callout output without adding a cross-line state carrier or another parser.

Task Logger styling supports ANSI terminal controls, including SGR and OSC 8, rather than Symfony style markup. Complete-versus-incremental parity applies to plain text and those supported controls; do not add a second cross-chunk markup parser for an input Laravel does not define as part of the Task Logger contract.

The incremental partial layout retains only:

- visible wrapped log lines up to Task's existing limit;
- the unfinished logical line/current word needed for wrapping;
- at most the three-byte suffix that can be the start of a split UTF-8 codepoint;
- an unfinished terminal-control buffer no larger than 4096 bytes, plus its small parser mode;
- an indivisible grapheme fragment no larger than the same fixed ceiling;
- active SGR state and OSC 8 hyperlink state.

Completed discarded prefixes are never retained or reprocessed. Long unbroken words are split at terminal width so the unfinished token is bounded. Commit makes current partial output permanent and resets incremental state; stable status/reset also clear the partial state.

Mirror `mbWordwrap()`'s word model instead of maintaining a separator queue: each space completes the current word, consecutive spaces therefore complete empty words, and the one implicit join column is dropped only when the next word wraps. Retain the real join token only for its SGR/link attributes; a long-word cut uses the same one-column candidate with no emitted join token. Track whether the current line is unset because an empty-but-set line has distinct leading-space behavior. Coalesce each complete UTF-8 word fragment that fits as one token and fall back to grapheme tokens only when the fragment needs cutting.

Correct the shared `mbWordwrap()` boundary because Textarea calls it directly with long-word cutting enabled. Normalize the effective width to at least one before the short-line check. In the cut loop, skip every word whose `mb_strwidth()` already fits. Split over-width words through one internal `Utils` grapheme helper shared with Task's incremental path. The helper uses PCRE Unicode graphemes (`\X`), falls back to the current byte-preserving `mb_str_split()` behavior for malformed UTF-8, and losslessly splits any single grapheme beyond the shared 4096-byte unbreakable-input ceiling at UTF-8 codepoint boundaries. Both paths ask the same PCRE boundary check whether adjacent fragments remain one grapheme; keep them atomic across width or chunk boundaries only while their combined bytes remain within the ceiling. This preserves combining, modifier, flag, keycap, Hangul, Indic, and ZWJ clusters without a Unicode property registry, preview delay, or complete/incremental exception, while pathological Zalgo output remains bounded.

When an allowed grapheme itself is wider than the target, never append the still-empty preceding fragment. Accept the first word onto an unset line even when that indivisible word exceeds the width, rather than emitting a leading blank. When a later word wraps from an empty-but-set line, do not store that zero-character overflow line; the pending implicit join is dropped by wrapping. Apply the same narrow suppression in both incremental emitters: reset an empty wrapped line instead of storing it during word completion, and omit the same empty line from the unfinished-word preview. Preserve nonempty whitespace-only lines, the intentional empty preview from `partial('')`, and explicit LF/CRLF blank lines. Remove the `\p{Cf}` width-two shortcut and measure every word with `mb_strwidth()`, matching the incremental path. This deliberately uses one consistent and conservative width model: PHP measures many ZWJ emoji wider than terminals display them. Do not add separator-carry state, a terminal cell-width engine, width cache, or second wrapping algorithm to optimize display-only mismatches.

Clamp Callout's `minWidth` to `max(1, min($this->minWidth, terminal columns - 6))` before resolving any content, so plain text and all three list renderers wrap against the same live terminal width. Keep the corresponding `max(1, ...)` safeguard in `DrawsBoxes::box()` because that trait also serves renderers that do not pre-resolve content. Two direct clamps at distinct lifecycle boundaries are clearer than a width-lifecycle helper.

Scan each chunk once with one local byte cursor; never rescan a growing unfinished control from its introducer. Hold a trailing carriage return for the next chunk so split CRLF is recognized, while a confirmed lone carriage return remains ordinary content. On invalid non-final UTF-8 input, inspect only the final three bytes structurally: retain exactly a suffix that starts with a valid two-, three-, or four-byte lead, contains only the available continuation bytes, and is shorter than the required sequence. Consume every preceding byte even when it contains invalid UTF-8. Stray continuations, invalid leads, and complete invalid sequences must make forward progress.

Starting a partial region represents its first logical line even when the first chunk is empty. Consuming LF or CRLF finishes the current line and immediately starts the next logical line, including a trailing empty line. A commit without any preceding partial remains a no-op because no region exists. This keeps `partial('')`, trailing/repeated newlines, and protected `replacePartialLines('')` aligned with complete-output and upstream behavior without special cases.

Implement one operation-local terminal-control scanner. The shared 4096-byte unbreakable-input constant also bounds its retained control prefix; after the ceiling the scanner discards retained bytes while continuing only its small mode state. Match the specialized introducers before the generic grammar because `[`, `]`, `P`, `X`, `^`, and `_` are themselves valid generic final bytes:

- CSI is `ESC [` followed by parameter bytes `0x30–0x3f`, then intermediate bytes `0x20–0x2f`, then one final byte `0x40–0x7e`;
- OSC is `ESC ]` and ends at BEL or ST;
- DCS, SOS, PM, and APC begin with `ESC P`, `ESC X`, `ESC ^`, and `ESC _` and end at ST. A BEL is invalid in these string controls and recovers by discarding through the BEL;
- every other ESC sequence is `ESC`, zero or more intermediate bytes `0x20–0x2f`, and one final byte `0x30–0x7e`;
- do not recognize 8-bit C1 introducers because their byte values are ambiguous with UTF-8 continuation bytes.

The scanner uses one mode field covering generic ESC, CSI parameter/intermediate phases, OSC, and the other string controls; one pending-ESC bit distinguishes ST from a malformed bare ESC. It retains a control only while it remains within the fixed ceiling. Beyond that ceiling it drops the retained bytes but continues the same small state until completion or local abort, so memory remains bounded without rendering control payload as text. Complete SGR and valid OSC 8 update formatting state; every other complete control is discarded.

Recognize specialized CSI/OSC/string introducers only while the retained control is exactly `ESC`. After one or more generic intermediate bytes, every byte in the final range completes that generic control, including `[`, `]`, `P`, `X`, `^`, and `_`; do not add another parser mode for a phase already represented by the retained prefix.

Malformed controls abort locally instead of swallowing later output. A byte invalid for the current CSI or generic-ESC phase ends and discards that prefix, then is reprocessed normally. Inside OSC or another string control, `ESC \\` terminates through ST; a bare ESC followed by anything else aborts the string and reprocesses that ESC as a new control. This reprocessing always starts at a later byte than the discarded introducer and must guarantee forward progress for repeated ESC input. At end of input, discard any unfinished control rather than re-emitting raw terminal bytes.

Keep the bounded effective-SGR updater in `InteractsWithStrings`, shared by `parseAnsiText()` and Task's incremental parser. The SGR map tracks fixed terminal attributes, including indexed/RGB underline color through codes 58/59; arbitrary codes remain in one bounded fallback slot. Treat a colon-form parameter such as `4:3` or `38:2:...` as one complete raw parameter: use its leading integer only to choose the fixed attribute slot, preserve the full token, and do not consume later semicolon parameters. This fixes cumulative, colon-form, and selective-reset styling for Task, Stream, and Callout without a general terminal parser or user-derived map keys.

Keep `parseAnsiText()`, Task's scanner, and `Utils::stripEscapeSequences()` aligned on visible bytes. Complete parsing removes generic ESC controls, character-set designators, DCS/SOS/PM/APC with their payloads, malformed locally aborted prefixes, and final incomplete controls. `parseAnsiText()` retains state only for SGR and valid OSC 8. `Utils` owns one capture-free OSC 8 grammar, composes it into the typed formatting fragment shared with the scrollbar helper, and resolves link state through one internal static method. Validate with the grammar first, then split the already-validated body at its first separator to preserve semicolons in the URI; do not add redundant fallback handling for a missing separator. Do not add a public parser, formatter registry, styled-string object, or payload interpretation. Always run complete Task log lines through the corrected `ansiWordwrap()` so fitting and wrapped lines use the same control rules and canonical effective style.

One reset method clears every concern-owned parser/partial field while retaining rendered logs. All operation reset, ordinary-line replacement, stable settlement, and partial commit paths use it, so committed partial lines cannot be replayed by a later partial.

Preserve current terminal semantics:

- split committed logical log lines on CRLF and LF;
- preserve blank committed lines in both execution modes: `line('')` adds one empty entry and `line("a\n\nb")` adds `['a', '', 'b']`;
- do not split a lone carriage return, which command-line tools use for in-place redraw;
- continue removing cursor-reset/erase sequences from ordinary output;
- preserve styles and hyperlinks across chunk boundaries and close/reopen them correctly across displayed lines;
- handle chunks split within UTF-8, CSI, OSC 8, generic ESC, and string-control sequences;
- handle CRLF split across chunks and discard a final incomplete control;
- avoid redundant reset accumulation;
- preserve the fast path when a pending plain-text line has no escape sequence and fits the width.

Use an effective wrap width of at least one column. A zero log limit discards completed/visible log output and still must not retain an unbounded partial token.

Tests must demonstrate that output and retained memory grow linearly for many small chunks, with final parity for process and in-process modes, including empty payloads and interior blank lines. Retain direct coverage of the three protected Laravel Task methods and the protected partial-region boundary: complete-line append, full partial replacement, framed receive/decode, and `partialStartIndex` movement/reset. Pin both socketless base-Logger no-op behavior and in-process partial forwarding through the shared `partial()` method. Rewrite the negative-limit state seed and sub-label assertions that call deleted Hypervel-only public bridges to go through `InProcessLogger`; do not preserve test-only bridge calls. Timing assertions do not belong in CI; add or document a reproducible local benchmark comparing the branch base and final implementation for 1k/2k/4k chunks.

Also pin consecutive partials across commit, exact-width and repeated-space wrapping, escape-heavy linear scanning, complete non-formatting control removal, final-incomplete control discard, split CRLF, cumulative/sequential SGR, selective resets, SGR 58/59, and colon-form SGR such as `4:3` and `38:2:...;1`. Add unconditional complete-versus-incremental parity for empty output, trailing/repeated blank lines, formatting spanning a newline, formatting beginning after a newline, and wrapped content whose formatting begins after a newline, across every possible chunk split; separately prove a bare partial commit is a no-op. Add parity cases for every split of short valid controls; malformed CSI/OSC followed by visible output; repeated ESC forward progress; generic controls with one and two intermediates followed by each specialized-introducer byte as their final; `ESC ( B` character-set designation; DCS/APC payload removal; controls exceeding the 4096-byte ceiling; bound/reset behavior with a zero log limit; and complete-line, partial, and stripped-width agreement. Put multiline `ansiWordwrap()` regressions and shared ANSI state regressions in `AnsiWordwrapTest`, `ParseAnsiTextTest`, and `UtilsTest` so Stream and Callout are covered at their owning boundary; Task tests cover the incremental and complete-line paths. In `MultiByteWordWrapTest`, cover combining, modifier, flag, keycap, Hangul, Indic, and ZWJ graphemes; every split of representative clusters; an oversized grapheme split losslessly at the shared ceiling; a wide grapheme narrower than the target in both cut modes; the absence of leading, empty, and ZWJ-only overflow rows; malformed UTF-8 fallback; zero/negative width normalization; and unchanged nonempty whitespace-only lines. Extend the every-split complete-versus-incremental Task harness with the same grapheme set, an oversized combining cluster, double-space over-width words, and a leading separator before an over-width word; keep parity unconditional and explicit blank logical lines byte-for-byte intact. Assert that complete and incremental retained entries stay within `limit × ceiling`, including repeated complete lines and streamed/single-chunk oversized clusters. At every chunk boundary assert that wrap overflow never creates a zero-character row; assert preview/commit equality only after the final chunk because a pending separator may legitimately appear in an intermediate preview and then be dropped when the next word wraps. In `CalloutTest`, cover narrow plain, bulleted, numbered, and key-value content and prove the rendered frame remains within the terminal.

### 5. Join animation ownership before settlement

Files:

- new `src/prompts/src/Support/PromptAnimation.php`
- `src/prompts/src/Spinner.php`
- `src/prompts/src/Task.php`
- `src/prompts/composer.json`
- `tests/Prompts/{Spinner,SpinnerNonCoroutine,Task,CoroutineCreateFailure}Test.php`

The animation owner contains exactly:

- a capacity-one `Hypervel\Engine\Channel` used as an interruptible stop signal;
- the animation coroutine id;
- the first render failure captured inside that coroutine.

The animation loop calls `pop($interval)` on the channel: `false` is the frame timeout and `true` is the stop signal. Settlement pushes `true` once, joins the exact coroutine with `Coroutine::join([$id])`, then surfaces a captured render failure. Do not close the channel, because a closed channel and a timeout both return `false` and require a second state query. Do not treat `join()` returning `false` as a general failure; it also means no supplied coroutine remained active. Do not use Swoole cancellation, polling sleeps, a WaitGroup, or a general animation framework.

Spinner and coroutine Task render frame zero synchronously before starting the wait-before-render animation owner. Each timeout must therefore increment the frame index before rendering, producing frame one after one interval and matching Laravel's visible cadence without its duplicate frame-zero render. The standalone Task process loop has no synchronous pre-render and remains render, increment, then sleep; the loop shapes require different increment positions to produce the same visible cadence.

Spinner and coroutine Task must stop/join before final erase, final render, or terminal restoration. Callback failure remains primary when both callback and cleanup fail, matching current settlement conventions. Immediate completion must not wait a full animation interval.

Declare `hypervel/engine` directly in the Prompts split package because source imports `Channel`; do not rely on `hypervel/coroutine`'s transitive dependency. The root monorepo already directly requires `hypervel/engine`, so its dependency list needs no change.

Tests suspend an in-flight render and prove settlement waits for it, cover immediate and normal completion, callback failure, animation-render failure, coroutine-creation failure, cursor restoration, absence of late frames, and unchanged non-coroutine Spinner behavior. Also cover an animation render dying before settlement: the stop push must not block, and a `false` join result must not obscure the captured render failure or become a new failure by itself.

### 6. Keep process signals out of coroutine Progress

Files:

- `src/prompts/src/Progress.php`
- `tests/Prompts/ProgressSignalTest.php`
- `tests/Prompts/ProgressTest.php`

Only capture/replace `SIGINT` and `pcntl_async_signals` when PCNTL exists and the Progress operation is not inside a coroutine. Inside a coroutine, leave process-global signal ownership untouched. Preserve standalone cancellation rendering, process exit, exact handler/async-mode restoration, manual start/finish, map success/failure, and destructor cleanup.

Tests compare both handler identity and async mode before/during/after coroutine Progress, then retain standalone signal replacement/restoration coverage. No signal emulation or process-wide lock is needed.

### 7. Restore transient prompt state and keyboard parity

Files:

- `src/prompts/src/Prompt.php`
- `src/prompts/src/SearchPrompt.php`
- `src/prompts/src/MultiSearchPrompt.php`
- `tests/Prompts/PromptLifecycleTest.php`
- `tests/Prompts/DataTablePromptTest.php`
- `tests/Prompts/SearchPromptTest.php`
- `tests/Prompts/MultiSearchPromptTest.php`

Add one nullable transient return-state field to Prompt. When Ctrl-U cannot revert, record the current state before showing the error. On the next key, restore that recorded state and clear it. Ordinary validation errors continue restoring to `active`; do not create a generic state-machine abstraction.

This keeps DataTable search active after the message and lets the next key update its search query. Test browse, search, and ordinary validation recovery.

Add `Key::CTRL_P` and `Key::CTRL_N` to MultiSearch's existing previous/next arms. They must preserve the match cache and boundary behavior exactly like arrow keys.

Do not let navigation depend on a renderer populating the nullable match cache. In every up/down/tab/shift-tab/Ctrl-P/Ctrl-N arm in Search and MultiSearch, call the memoized `matches()` accessor before counting. The default renderer has already populated it on the normal path, so this remains O(1); a custom theme registered through `Prompt::addTheme()` that does not render options now receives the same navigation semantics without rerunning its options callback. Test both prompts with a minimal custom renderer that never reads matches.

### 8. Cache and correct DataTable natural layout

Files:

- `src/prompts/src/DataTablePrompt.php`
- `src/prompts/src/Themes/Default/DataTableRenderer.php`
- `tests/Prompts/DataTablePromptTest.php`

Store search-invariant natural column metrics in a protected property on the one-shot DataTablePrompt instance. Expose one no-argument public `@internal` `naturalColumnMetrics()` method that computes and memoizes a `{column count, natural widths}` record from the prompt's headers and rows. The natural metrics derive only from prompt data; terminal-width fitting remains renderer-owned. This avoids a closure-injected memoization contract, exposes no cache mutation API, and makes the data scan directly testable. Compute the metrics lazily on first render from headers and all source rows. Do not add run resets, a WeakMap/static cache, row hashes, public invalidation, production counters, or mutation tracking.

Compute the row column maximum only when rows exist, using zero otherwise, before taking the maximum with the header count. A completely empty table returns zero columns and empty natural widths; the renderer retains its existing terminal-dependent single-column search-affordance fallback rather than calling `max([])` or caching a terminal width.

The natural scan must:

- set column count to `max(count(headers), rows === [] ? 0 : max(array_map(count(...), rows)))` by element count;
- treat header and row integer keys as irrelevant positional metadata during measurement and rendering, preserving the original row value returned on selection;
- normalize an array-valued header cell with the same space-joined representation used when rendering it; do not invent multiline-header semantics;
- measure the maximum visible width of each row-cell line and normalized header after stripping ANSI and Symfony inline markup;
- recognize both CRLF and LF when measuring and rendering multiline row cells, without treating a lone carriage return as an additional row;
- gather and sort nonblank row widths once;
- implement the documented P90 outlier rule consistently;
- return natural metrics independent of terminal width.

Each render then fits those metrics to current terminal width in O(columns):

- reserve the existing padding, separators, scrollbar area, and outer frame;
- give each column at least one display column;
- do not treat headers as unshrinkable floors;
- distribute rounding remainder deterministically;
- guarantee `array_sum(widths) + overhead <= maxWidth` whenever the terminal can physically contain the one-column minima.

Remove protected `DataTableRenderer::computeColumnWidths(headers, rows, numCols, maxWidth)`. Its Laravel contract fuses natural metrics, whose cache lifetime is the immutable prompt data, with fitted widths, whose key is the live terminal width and may change on every render. Retaining it outside the hot path would silently ignore existing overrides; keeping it live would require scratch-state or argument-comparison machinery that restores row-scale work. Leave a concise `REMOVED:` note at its natural source position naming the two live replacement seams: override `DataTablePrompt::naturalColumnMetrics()` for source sizing and protected `DataTableRenderer::fitColumnWidths()` for terminal fitting. Record this deliberate protected-API difference in the package README.

Rendering continues to truncate/pad actual content to fitted widths. Tests cover ragged and sparse-keyed headers/rows, array-valued header cells, LF/CRLF multiline row cells, ANSI/Symfony style width, long headers on an 80-column terminal, empty tables, terminal resize without a second source scan, one scan across search keystrokes, and the exact fit invariant.

### 9. Replace the last visible grapheme safely

Files:

- `src/prompts/src/Themes/Default/Concerns/InteractsWithStrings.php`
- `src/prompts/src/Themes/Default/Concerns/DrawsScrollbars.php`
- `src/prompts/src/Themes/Default/DataTableRenderer.php`
- new `tests/Prompts/InteractsWithStringsTest.php`
- `tests/Prompts/DataTablePromptTest.php`

Add one protected helper to `InteractsWithStrings` that replaces the last visible grapheme while preserving trailing formatting and display width. `DrawsScrollbars` explicitly uses `InteractsWithStrings` instead of relying on consumers also using `DrawsBoxes`. Its inputs are raw option labels rather than `parseAnsiText()` output, so it must sanitize terminal controls itself.

Contract:

- empty input or input whose stripped visible width is zero is returned unchanged before matching, so a line containing only terminal escapes or style tags cannot expose one of their bytes as a grapheme;
- first discard incomplete, malformed, and complete non-formatting terminal controls with the shared `Utils` grammar, preserving only formatting that can affect the visible line;
- split the suffix with one escape-aware pattern shaped as `/(?<grapheme>\X)(?<suffix>(?:(?:SGR)|(?:OSC_8)|(?:STYLE_CLOSE))*)$/u`; deliberately narrow the alternatives to CSI ending in `m` and a complete OSC 8 command with its required parameter/URI separator, rather than depending on prior sanitization to make broader CSI/OSC alternatives safe. Let `STYLE_CLOSE` cover every closing token that the same helper treats as zero-width (`</info>`, `</comment>`, `</question>`, `</error>`, and `</>`). Capture the last visible grapheme separately from the entire trailing zero-width suffix and re-emit that suffix after the replacement;
- invalid UTF-8 is unchanged; require the escape-aware `preg_match(...)` result to be exactly `1` so both a non-match and `false` from `PREG_BAD_UTF8_ERROR` return the original line;
- replace the whole final grapheme, including combining marks and ZWJ emoji;
- preserve trailing SGR resets and OSC 8 closes after the replacement;
- preserve the line's `mb_strwidth`-measured width: if the removed grapheme measures wider than the replacement, insert that measured difference as padding before the scrollbar glyph. This intentionally uses the same width accounting as existing `pad()` and `longest()`; solving package-wide terminal-width inaccuracies for combining/ZWJ graphemes would require unrelated width machinery.

Use PCRE's Unicode grapheme cluster (`\X`) support plus `mb_strwidth`; do not add an `ext-intl` dependency for `grapheme_*` functions.

Use the helper in both the generic scrollbar trait and DataTable's multiline-aware manual scrollbar. Remove both byte-oriented `preg_replace('/.$/')` paths. Explicitly test an exact-width styled line such as `ESC[2mabcdefghijESC[22m`: replace `j`, preserve the complete trailing reset after the scrollbar glyph, and never treat the reset's final `m` as visible content. Cover the same suffix preservation for an OSC 8 close and Symfony named/inline closing tags. Add raw-label cases for incomplete CSI/OSC, a complete malformed OSC 8 command, malformed OSC followed by SGR and visible text, generic ESC, character-set designation, DCS/APC payloads, and unchanged display width after sanitization. Escape-only and style-tag-only inputs remain byte-for-byte unchanged. Pin that the capture-free formatting fragment can be embedded twice in one regex and still match. Do not build a general styled-string object.

### 10. Apply the remaining focused correctness fixes and upstream updates

Files:

- `src/prompts/src/Concerns/Erase.php`
- `src/prompts/src/Prompt.php`
- `src/prompts/src/Support/Utils.php`
- `src/prompts/src/Themes/Default/GridRenderer.php`
- new `tests/Prompts/EraseTest.php`
- `tests/Prompts/PromptLifecycleTest.php`
- `tests/Prompts/UtilsTest.php`
- `tests/Prompts/GridTest.php`

Changes:

1. `eraseLines($count)` returns immediately for nonpositive counts. For each positive line, erase and move up one line only when another line remains, producing exact behavior for 1 and N.
2. Make Prompt's static output and terminal declarations nullable and initialized to null. `flushState()` forgets the output and validation coroutine-context keys and resets both static fields to null; accessors retain lazy `??=` construction. Do not flush unrelated coroutine context.
3. Strip each valid Symfony style tag independently in centralized `Utils::stripEscapeSequences()`, rather than requiring matched pairs. One pass therefore handles nested and unclosed tags and more completely matches the intent of Laravel's repeated matched-pair removal without retaining its loop. Recognize the built-in named styles case-sensitively and the inline `fg`, `bg`, `options`, and `href` keys case-insensitively, including hex and bright color values, semicolon-separated attributes, and escaped angle brackets. Do not consume an escaped opening bracket. Leave unknown/custom tags literal because the formatter registry is unavailable at this low-level hot path. Pin these distinctions with a curated agreement table against Symfony's undecorated `OutputFormatter`; do not instantiate a formatter on the measurement hot path or add randomized CI fuzzing.
4. Port current Laravel Grid truncation before width/cell computation, using `max(1, maxWidth - 5)` and measuring the truncated values used for layout. Type the two touched callbacks as `fn (string $item): string` and `fn (string $item): int` under Hypervel's full-typing rule.

Tests cover escape output for erase counts -1/0/1/3, context-key removal without whole-context loss, lazy output/terminal re-creation, nested and inline Symfony styles (`#rrggbb`, bright colors, combined attributes, and href), escaped angle brackets, unknown/custom tags, long Grid items, narrow widths, and unchanged balanced layout.

## Documentation and package metadata

Files:

- `src/prompts/README.md`
- `src/docs/prompts.md`
- `src/prompts/composer.json`

Documentation rules:

- Use Laravel prose in `src/docs/prompts.md`.
- Keep behavioral documentation proportional: Number is an integer prompt, valid defaults may be integers, transforms precede validation, custom fallback implementations can call `validateIntrinsic()`, and Task partial output retains its public semantics. Update the custom fallback example's truthy default/required checks so the documentation does not retain the same `"0"` bug fixed in source. Guard custom validation with `is_callable()` before invocation and continue to caller validation when `validateIntrinsic()` returns either `null` or `''`, matching source semantics.
- Do not document binary framing, caches, channels, benchmark internals, or bug history in user docs.
- Add only the three deliberate incompatible protected-API differences to README `Differences From Laravel`: Logger's `prefix()` is absent because binary framing requires subclasses to override `write()` rather than emit text prefixes; NumberPrompt's `wrapValidation()` is replaced by `validateIntrinsic()` because validation is centralized in the base pipeline; and DataTableRenderer's fused `computeColumnWidths()` is replaced by prompt-owned `naturalColumnMetrics()` plus renderer-owned `fitColumnWidths()` because those values have different invalidation keys. `validateIntrinsic()` is otherwise an additive extension hook and belongs in the canonical user documentation. Current upstream has no matching test for the removed Logger or Number method; do not invent `REMOVED:` test comments.
- Do not add these fixes to `src/docs/porting-from-laravel.md`; they do not change normal application/package porting decisions.
- Do not modify `docs/todo.md`.

## Expected file ownership and cleanup

- Delete no Laravel public methods. Remove only the explicitly justified protected `Logger::prefix()`, `NumberPrompt::wrapValidation()`, and `DataTableRenderer::computeColumnWidths()` methods, with README and source dispositions for all three.
- Remove `Logger::$streamBuffer` after delta-only partials make it dead.
- Remove the six Hypervel-only public Task mutation bridges after all in-process callers use `applyMessage()`.
- Keep `InProcessLogger`, but reduce it to the one necessary `write()` adapter.
- Retain protected `Task::receiveMessages()`, `Task::addLogLines()`, and `Task::replacePartialLines()` under their Laravel names and signatures; rework their internals around frames and incremental state.
- Retain protected `Task::$partialStartIndex` under its exact name/type/default as the incremental partial-region boundary.
- Remove protected `Task::$buffer`: its incomplete-newline meaning cannot survive binary framing, and `TaskFrame` owns its distinct receive buffer. Remove the newline protocol regex/constants and other line-framing state made dead by frames.
- Protected storage internals do not receive README `Differences From Laravel` entries; unlike a silently bypassed method override, removing the obsolete line buffer does not change a supported extension behavior.
- Do not leave comments describing replaced designs, compatibility branches, or rejected alternatives.

## Verification sequence

During implementation, edit one file at a time and run each changed test file immediately. Recommended coherent order:

1. Prompt/Number validation and Console fallback tests.
2. Frame codec and Logger tests.
3. Task incremental layout and process-settlement tests.
4. Animation owner, Spinner, and coroutine Task tests.
5. Progress signal tests.
6. DataTable/state/keyboard/string-layout tests.
7. Utils/Grid/erase/static-cleanup tests.
8. Package docs and metadata assertions.

Then run the affected package/fallback set together:

```bash
./vendor/bin/phpunit --no-progress tests/Prompts tests/Console/ConfiguresPromptsTest.php
```

At the implementation checkpoint, run exactly one:

```bash
composer fix
```

After any review fixes, rerun only the relevant targeted tests unless the changes justify another full checkpoint.

Before code review, inspect the complete diff against `0.4` and trace:

- every Prompt execution mode and transform/validation caller;
- every Logger message producer and Task consumer;
- callback, animation, renderer, transport, signal, terminal, and coroutine failure precedence;
- ANSI/OSC 8 state across chunk/layout boundaries;
- DataTable widths against every rendering consumer;
- worker-lifetime/static/context state and cleanup;
- Laravel public/protected signatures and named arguments;
- dead fields, methods, tests, comments, and documentation.

Run before/after Task partial and DataTable render benchmarks outside CI and record representative environment-qualified results in the PR description. Do not add timing thresholds or production instrumentation.

## Rejected machinery

Do not add any of the following unless implementation exposes a new, concrete requirement and the plan is amended after review:

- pipe/version negotiation or base64 Task transport;
- arbitrary frame/output caps beyond Task's existing visible limit and the one fixed incomplete-control safety ceiling;
- a generic event bus or production counters;
- Task whole-prefix buffering;
- Swoole coroutine cancellation for animation settlement;
- both Channel and WaitGroup ownership;
- DataTable static/WeakMap/hash caches or public invalidation;
- Prompt reset/reuse support;
- a general terminal styled-string framework;
- a package-wide mechanical `: void` rewrite or Prompts TODO ledger;
- FormBuilder expansion.

These exclusions keep the final architecture proportional: necessary state is explicit and operation-local, hot repeated work is removed, and supported Laravel-shaped extension surfaces remain understandable from source.
