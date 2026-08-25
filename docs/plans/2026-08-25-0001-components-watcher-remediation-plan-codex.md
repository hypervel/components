# Hypervel Watcher Remediation Plan

Status: Implementation, verification, documentation, and code review complete

## Objective

Make the watcher package correct, portable, and cheap enough to run beside several development applications without needless CPU, disk I/O, memory, subprocess, temporary-file, or inotify-descriptor use.

This slice closes audit findings 105–111 and the Watcher section of `docs/todo.md`. It also fixes defects found while tracing the same code paths: invalid watch configuration can silently select the whole project, a bare `--path` reaches a raw type error, polling waits before establishing its baseline, `ScanFileDriver` materializes and sorts every discovered file, unreadable watched roots or child subtrees abort its scan, `FswatchDriver` corrupts newline-containing paths and applies one recursive setting to unrelated shallow roots, and `ServerRestartStrategy` mishandles absent and failed process signals.

The package remains Laravel-style at its public surface:

- `FindDriver`, `ScanFileDriver`, `FswatchDriver`, `Option`, and `WatchPath` keep their current public names and roles.
- `FindNewerDriver` is removed. It is a second Hypervel/Hyperf-era implementation of the same capability, not a Laravel API or a useful compatibility surface.
- Custom drivers continue to implement `DriverInterface` and receive `Option` through the container.
- No compatibility alias, driver mode, retry policy, tuning setting, or new public filesystem API is added.

## Verified baseline

| Area | Current behavior | Required result |
|---|---|---|
| Glob roots | `Option::parseGlob()` keeps the filename prefix before a wildcard, so `app/Foo*.php` becomes the nonexistent target `app/Foo` and `.env*` becomes `.env`. | Resolve the existing directory before the wildcard: `app` and `.` respectively. |
| Invalid paths | An empty watch entry becomes the application root and matches everything; a missing/empty watch list silently watches nothing; leading-slash paths are treated as if relative. | Reject empty entries, absolute entries, and an empty combined config/CLI list. Keep `.` as the explicit project-root form. |
| CLI paths | `--path` accepts no value and passes `null` into strict path normalization, producing a raw `TypeError`. | Require a value at the Symfony Console definition so the parser reports the invalid option before command execution. |
| Poll timing | `AbstractDriver::watchAtInterval()` waits one interval before its first scan. | Scan immediately to establish a baseline, then wait between scans. |
| Find portability | `FindDriver` uses non-portable `-mmin`, requires GNU `gfind` on macOS, rounds short intervals to `-0.00`, and deduplicates with PHP's whole-second `filemtime()`. | Use the system `find`, portable `-newer`, unique reference files, and no PHP mtime map. |
| Find correctness | Newline output corrupts valid paths; deletions are invisible; a failed scan can lose later changes or repeat data without a clear contract. | NUL-safe parsing, exact matched-path inventory, explicit partial-failure semantics, and cutoff rotation only after complete changed traversals. |
| ScanFile cost | `Filesystem::allFiles()` sorts and materializes every file once per configured directory, repeats overlapping identical roots, and then hashes matches. | Stream an unsorted Symfony Finder once per unique target and hash each matched path once. |
| ScanFile resilience | Opening an unreadable root can throw `UnexpectedValueException` and end the watcher; unreadable child subtrees can also abort discovery. | Skip unreadable roots and child subtrees while preserving other watched roots and readable siblings. |
| Fswatch protocol and registration | Both the command and parser are newline-delimited even though newlines are valid in filenames; macOS and Linux request different event sets; one global recursive flag makes the default exact `.env` target register the whole project tree on Linux. | Use one incremental NUL protocol, the same event filter, and separate shallow/recursive Linux process groups read by one flat loop. |
| Restart settings | A shallow replacement of `server.settings` can make the typed daemonize lookup fail; stop output is printed without a PID; native `posix_kill()` false is ignored. | Default daemonize to false, print only for a current PID, re-read after yielding output, and report false or thrown signal failures. |

Audit mapping:

- 105 is fixed by the corrected glob base and shared traversal-depth fact.
- 106 is superseded by the reference cutoff recorded before each scan; elapsed-time/minute rounding is removed with `-mmin`.
- 107 and the valid portion of 108 are direct `ServerRestartStrategy` fixes.
- 109 remains deliberately unchanged: full-content hashing is `ScanFileDriver`'s correctness contract.
- 110 is fixed by explicitly including hidden files in the streamed Finder.
- 111 disappears with the obsolete mtime map; there is no historical per-path state to prune.

## Research and cost evidence

- POSIX `find -newer reference` avoids the GNU-only `-mmin` path. POSIX Issue 8 also standardizes `-print0`. The direct-depth bound, `-maxdepth 1`, remains a GNU and BSD/Apple extension supported by the target systems for this package.
- `Swoole\Coroutine\System::exec()` invokes `/bin/sh -c`. Its POSIX `command -v` builtin probes the same command environment without depending on an external `which` executable or spawning it.
- The filesystem stores sub-second mtimes, but PHP's `filemtime()`, `stat()['mtime']`, and `SplFileInfo::getMTime()` expose whole seconds. A retained PHP mtime map therefore cannot detect two writes within one second and must not return in the consolidated driver.
- `Filesystem::allFiles()` delegates to `files()` with `sortByName()` and `iterator_to_array()`. Measured on 20,000 files:

  | Scan shape | Wall time | Peak memory |
  |---|---:|---:|
  | Existing sorted/materialized helper | about 537 ms | about 169 MB |
  | Unsorted but materialized | about 252 ms | about 165 MB |
  | Streamed Symfony Finder | about 118 ms | about 4 MB |

  Hashing all 20,000 files with `xxh128` took about 232 ms. Full hashing remains the driver's correctness guarantee; the avoidable work is sorting, materialization, and duplicate traversal.
- For 80,000 `find` candidates with 20,000 retained matches, cursor parsing with inline matching took about 14.5 ms and 3.1 MB of transient peak memory. `explode()` followed by filtering took about 19.1 ms and 9.3 MB. The cursor avoids about 6.2 MB per watcher without adding an abstraction.
- Current fswatch inotify source confirms that a watched directory reports events for first-level children without recursive registration. Recursive mode adds watches for descendant directories. FSEvents is recursive regardless of the flag.
- A direct file operand misses an atomic replacement event but reports later edits after the monitor re-registers the new inode during its next root scan. The replacement event is therefore the only observation that distinguishes a direct file operand from the required parent-directory operand.
- On a 1,028-directory application-shaped fixture, the shipped default's one recursive Linux process registered 1,028 inotify watches. Splitting the exact root file into a shallow process and `app`/`config` into a recursive process registered 26 in total. The current components checkout contains more than 19,000 directories, so this is a default-path resource defect rather than a tuning edge case.
- Fswatch has one recursive setting per monitor, not per operand. Installed 1.14.0 happens to use ordinary output filters while registering inotify watches, while current upstream uses a separate prune-filter collection. A filter-based depth emulation is therefore version-dependent and would add a second path matcher.
- Fswatch 1.14.0 canonicalizes existing command operands once at startup but retains the literal spelling of missing operands. Current upstream makes operands absolute without canonicalizing them. Passing canonical existing operands and literal missing operands from Hypervel gives both versions one stable output-prefix contract.
- Both installed and current inotify monitors retry missing root operands on each loop. A missing configured base can therefore become active without restarting; it must retain a literal matcher mapping from startup.
- All three driver primitives follow a symlink supplied as a root operand but do not follow symlinked directories found during recursive descent. An initially missing direct fswatch operand that later becomes a symlink is followed and continues to emit its literal startup spelling. Operand pruning must preserve both facts.
- Hooked `stream_select()` yields correctly over multiple process pipes. Closing a selected pipe from another coroutine can leave it blocked and produces Swoole reactor warnings with a timeout; sending `SIGKILL` to the direct child while the owner retains the read pipe wakes it cleanly through EOF.
- Current fswatch source maps `Created`, `Updated`, `Removed`, and `Renamed` on FSEvents as well as inotify. `-E` clears filters before any are installed, so it is inert in the current Linux command.

These figures guide the design and will be re-measured during implementation. They are not timing assertions for CI.

## Design invariants

1. A polling cycle never misses a change merely because the preceding traversal was slow.
2. A failed changed traversal never advances the authoritative cutoff. Already discovered true positives may repeat; repeat suppression is not allowed to hide a second same-path change.
3. Deletions come only from a complete current inventory. Partial inventory output can never remove retained paths.
4. Before the first complete inventory, untouched newly inventoried paths establish the baseline without an addition flood; paths also proved changed are emitted. A retained path was individually proven live and may still produce a deletion on that first complete inventory.
5. Driver state is operation-local to one driver instance and naturally bounded: two reference files plus the currently retained matched live-path set. No worker-static or coroutine-scoped watcher state is needed.
6. Glob matching remains owned by `WatchPath` and Symfony's `Glob`; drivers only choose the minimum safe traversal depth and apply the existing matcher.
7. `ScanFileDriver` still detects exact content changes by hashing every matched file. It does not substitute metadata, periodic rehashing, or a tuning threshold for correctness.
8. Each configured path is interpreted relative to `base_path()`. An absolute-looking entry is rejected instead of being silently rebased under the application by `join_paths()`.
9. Stop remains terminal and idempotent for a driver instance. The normal `Watcher`/`WatchCommand` lifecycle does not restart one stopped driver instance.
10. On Linux, each operand receives only the recursion depth its configured paths require. At most two direct fswatch children are read by one coroutine; no child coroutine or pipe-multiplexer protocol is needed.
11. Every driver follows a symlink supplied as a configured root but does not follow symlinked directories found during recursive descent.

## Implementation

### 1. Validate and classify watch paths

Update `src/watcher/src/Option.php`:

- Merge configured `watch` entries with CLI `--path` entries and reject an empty combined list with `InvalidArgumentException`.
- Validate and normalize each raw entry once before wildcard detection or filesystem probing: reject `''` and leading `/`; remove trailing separators; split on `/`; discard empty and exact `.` segments wherever they occur; preserve every `..` segment; join the remaining segments with one `/`. When the entry consists only of root-dot segments, canonicalize it to `.` rather than rejecting it. Then deduplicate the normalized strings before constructing `WatchPath` values. This keeps traversal bases, public paths, and Symfony glob patterns on the same canonical form without resolving legitimate sibling paths such as `../packages/foo`.
- Keep `.` as the deliberate application-root entry.
- Define the command's repeatable `--path` option as `VALUE_REQUIRED | VALUE_IS_ARRAY`. A bare option has no meaning and must receive Symfony Console's native missing-value error; an explicit empty value continues through normal path validation.
- Fix `parseGlob()` by finding the first wildcard and truncating its preceding normalized literal prefix at the last slash:

  ```php
  $prefix = substr($glob, 0, $wildcardPosition);
  $slashPosition = strrpos($prefix, '/');
  $baseDirectory = $slashPosition === false ? '.' : substr($prefix, 0, $slashPosition);
  ```

  Preserve `.` when the slash is absent.

Update `src/watcher/src/WatchPath.php`:

- Add a computed public readonly `bool $recursive`, consistent with the existing public readonly `path`, `type`, and `pattern` facts.
- Exact files are not recursive.
- Plain directories are recursive.
- Derive a glob's suffix explicitly. Use the complete pattern for a `.` base; otherwise remove the normalized base and its first separator: `$suffix = $path === '.' ? $pattern : substr($pattern, strlen($path) + 1)`. A glob is recursive when this suffix contains `/` or `**`. The explicit `**` check matters because Symfony's `app/**` regex matches deep descendants even though its suffix contains no slash.
- Keep `matches()` and its Symfony regex as the sole semantic matcher. Do not build an exact glob-depth parser.

Required examples:

| Entry | Base | Recursive |
|---|---|---:|
| `.env*` | `.` | no |
| `app/Foo*.php` | `app` | no |
| `app//*.php` → `app/*.php` | `app` | no |
| `./app/*.php` → `app/*.php` | `app` | no |
| `app/./*.php` → `app/*.php` | `app` | no |
| `routes/?.php` | `routes` | no |
| `config/{app,queue}.php` | `config` | no |
| `app/*/Actions/*.php` | `app` | yes |
| `app/**` | `app` | yes |
| `app/**/*.php` | `app` | yes |
| `app` | `app` | yes |
| `app/` → `app` | `app` | yes |
| `./` → `.` | `.` | yes |
| `.env` | `.env` | no |

### 2. Make polling establish its baseline immediately

Update `AbstractDriver::watchAtInterval()` to execute the scan before waiting:

```php
while (true) {
    $scan();

    if ($this->stopping) {
        return;
    }

    $signal = $stopSignal->pop($seconds);

    if ($signal !== false || ! $stopSignal->isTimeout()) {
        return;
    }
}
```

Keep the existing exception-safe channel cleanup. Cover:

- immediate first scan;
- the first post-baseline change being detected within one interval, not two;
- stop before watch performs no scan;
- stop during a yielding scan prevents another wait/scan;
- scan exceptions still clean the signal channel.

Do not add a concurrent-watch guard or retry loop. Concurrent `watch()` calls on one driver are not a supported or reachable `Watcher` lifecycle, and stop is terminal.

### 3. Consolidate the find drivers

Replace `src/watcher/src/Driver/FindDriver.php` with the final reference-file design and delete `src/watcher/src/Driver/FindNewerDriver.php`.

#### Lifecycle and cutoff ownership

- Probe `command -v find` in the constructor and use its exit status. Remove the external `which` dependency, `gfind`, GNU version probing, fractional-minute state, `startTime`, and the mtime map.
- Replace the inherited fragment with the grammatical, portable error ``The FindDriver requires the `find` executable.`` when the probe fails.
- Create two unique files with `tempnam()` only when an active `watch()` lifecycle begins. If creation fails partway, remove any file already created and throw.
- Remove both references from the outer `watch()` `finally`, whether polling returns or throws.
- Report reference cleanup failures through the driver's injected logger rather than PHP's process-global error log.
- `stop()` only signals the inherited polling loop. It does not unlink a reference that an active `System::exec()` may still be reading.
- If stop arrives while a scan is yielding, finish ownership of the active command but do not publish its output or rotate the reference afterward. The outer `finally` removes the references once the scan callback returns.
- Do not retain `FindNewerDriver::$scanning`, its deferred cleanup path, or its same-instance restart exception. They protect an unsupported restart shape and add lifecycle state without improving the actual caller.
- Before each scan, touch the inactive reference. Search relative to the active reference. Swap reference roles only when every changed traversal succeeded, including quiet successful scans.
- Inventory success is independent: an inventory failure suspends reconciliation but does not prevent a successful changed traversal from advancing the cutoff.
- One shared reference pair serves both depth groups. Per-group pairs add temp files and rotation state for a rare failure without improving normal detection.

A process forcibly abandoned after `Watcher`'s bounded cleanup wait may leave two zero-byte temp files if its `find` subprocess is permanently hung. Do not add leases, shutdown registries, stale-file scans, or unsafe unlinking during active execution for that exceptional process-lifecycle failure.

#### Target grouping and commands

Use one `AbstractDriver::groupWatchPathsByTarget()` helper for Find and ScanFile's identical literal-target grouping. It returns each resolved absolute target with its combined recursion requirement and contributing watch paths. Fswatch remains bespoke because it groups by canonical identity when available and literal identity otherwise.

For Find, group each shared-helper target by its required traversal depth:

- identical targets are scanned once;
- recursive wins when any matcher for that target is recursive;
- exact files join the direct group;
- targets are rechecked with `existingTargets()` each cycle so disappearance and later creation are observable;
- do not attempt to merge different contained roots.
- Return the scan facts as a keyed array (`files`, `changedComplete`, `inventoryComplete`, and `failureCode`) so the adjacent completion flags cannot be transposed by an override.

For each nonempty group, execute changed first and inventory second:

```text
find -H <targets> -maxdepth 1 -newer <reference> -type f -print0
find -H <targets> -maxdepth 1 -type f -print0
```

Place `-H` before the operands and omit `-maxdepth 1` for the recursive group. `-H` follows only symlinks named as operands, not symlinks found during descent, matching Symfony Finder's default treatment of a symlinked root without enabling `followLinks()`. Escape every target and reference with the existing `shellArguments()` helper. This is at most four short-lived `find` subprocesses per cycle: changed and inventory for at most two groups.

Do not combine the passes with an external `printf` tag protocol. It adds subprocesses and parsing states. Do not replace `System::exec()` with `proc_open()` streaming; buffering remains a cost, but streaming adds pipe/process ownership while inventory still has to retain the matched live set.

#### NUL parsing and matching

Use one small method for the two output consumers because both changed and inventory output need identical parsing and matching:

- walk the output with `strpos($output, "\0", $offset)`;
- ignore an unterminated tail from a killed/failed command;
- convert each complete absolute path to a base-relative path;
- test every record against the complete configured watch-path list and stop at the first match; `find` output does not identify its originating operand, and matcher partitioning adds attribution logic for no meaningful saving;
- add a matched absolute path to `array<string, true>` and stop after the first matcher;
- never construct an exploded list or all-candidate set.

This cursor is deliberate hot-path code, not a parser abstraction. Add a short comment explaining that inline filtering avoids retaining every candidate path emitted by a broad `find` target.

#### Inventory reconciliation

Retain:

```php
/** @var array<string, true> */
protected array $inventory = [];

protected bool $hasCompleteInventory = false;
```

When every inventory traversal completes:

```php
$additions = array_diff_key($currentInventory, $this->inventory);

if (! $this->hasCompleteInventory) {
    $additions = array_intersect_key($additions, $changedFiles);
}

$deletions = array_diff_key($this->inventory, $currentInventory);
$modifications = array_diff_key(
    array_intersect_key($changedFiles, $currentInventory),
    $additions,
);
```

- While `$hasCompleteInventory === false`, retain only additions also proved by the changed traversal. This suppresses untouched pre-existing files while preserving a file genuinely created or modified after the reference cutoff during the first complete cycle.
- Emit deletions even on that first complete inventory. Every retained pre-baseline key came from changed output and was individually proven live, so its absence is a real deletion.
- Emit each addition, deletion, and modification once.
- Publish the current inventory and set the flag true.

When any inventory traversal is incomplete:

- publish the matched changed set directly; do not intersect it with a partial inventory;
- merge changed paths into the retained inventory as known-live entries;
- emit no deletions and do not replace the inventory;
- leave `$hasCompleteInventory` unchanged.

An empty target set is a successful empty inventory. It reports deletion of retained paths after a target disappears and lets a target that later appears enter as additions after the baseline exists.

#### Failure behavior

- A partial changed traversal may publish paths already found, but any changed-traversal failure holds the old reference so those paths remain eligible on the next cycle.
- An inventory-only failure suspends deletions for that cycle but does not force changed paths to repeat because the complete changed traversal advances the cutoff.
- Log one warning per degraded cycle with the first nonzero exit code. The text must describe the guarantees actually affected: inventory failure suspends deletion detection; changed failure can repeat detected changes until the filesystem error is fixed. Combine both clauses when both fail.
- Do not suppress repeated sets. That would miss a real second edit to the same path during the degraded window.
- A creation after the changed traversal but before inventory appears as an addition and remains newer than the already-recorded next cutoff for the next cycle. It may therefore be reported once more on the next cycle. Suppressing that overlap without a content read or portable sub-second mtime would hide a genuine second edit, so correctness wins over eliminating this narrow duplicate.
- A path changed and then deleted before inventory is emitted only as a deletion, not as a modification and deletion.

The user documentation must state that incomplete inventory suspends deletion detection. When the changed traversal also fails, already detected changes can be reported again on every interval; with restart enabled, that can restart the watched process repeatedly until the terminal filesystem error is fixed.

### 4. Stream `ScanFileDriver` snapshots

Update `src/watcher/src/Driver/ScanFileDriver.php`:

- Accept `Option $option` without redeclaring the protected property already owned by `AbstractDriver`; keep the logger protected, consistent with `FindDriver` and the package's open extension/test surface.
- Import and use `Symfony\Component\Finder\Finder` directly; it is already a direct watcher dependency.
- Reuse `AbstractDriver::groupWatchPathsByTarget()` for unique resolved directory targets and their contributing matchers. Recursive wins for an identical target. Do not optimize containment among different roots.
- For each existing target, build an unsorted streaming finder:

  ```php
  $finder = Finder::create()
      ->files()
      ->ignoreDotFiles(false)
      ->ignoreUnreadableDirs()
      ->in($target);

  if (! $recursive) {
      $finder->depth(0);
  }
  ```

- Preserve Finder's default VCS-directory exclusion.
- Iterate it directly. Convert each candidate to the base-relative path, stop after its first matching `WatchPath`, and hash it once.
- Keep the injected `Filesystem` only for `hash()`; do not add a special walker or new `Filesystem` API.
- Continue hashing explicit file targets. Skip an explicit-path hash when the same absolute path was already collected by a directory scan.
- Let `Finder::in()` own missing-root detection instead of checking `is_dir()` first, removing the disappearance race between probing and opening the root. Catch `DirectoryNotFoundException|UnexpectedValueException`: the latter covers unreadable roots and Symfony's `AccessDeniedException`, while `ignoreUnreadableDirs()` lets readable child subtrees continue. Paths under an unreadable root or child disappear from the snapshot and emit deletions, then additions when access returns. This is finite and self-healing, so no degraded-mode state or second traversal is warranted.

Simplify `processFileHashes()`:

- once a prior snapshot exists, compute additions, deletions, and modified paths directly;
- log and emit only when any set is nonempty;
- remove the order-sensitive whole-array inequality guard.

The unconditional diffs add about 0.35 ms at 20,000 entries compared with the old two-stage guard, while the scan costs tens or hundreds of milliseconds. The simpler logic also remains correct when iterator ordering changes.

Do not add an mtime/size prescreen, periodic full rehash, configurable cap, or sampling. Those mechanisms either miss exact-content changes or delay them and add maintenance state.

### 5. Make `FswatchDriver` NUL-safe and resource-aware

Update `src/watcher/src/Driver/FswatchDriver.php` to use one process on Darwin and at most two process groups on Linux: shallow operands without `-r`, and recursive operands with `-r`. Read every stdout pipe in one flat `stream_select()` loop.

Accept `Option $option` without redeclaring the inherited protected property. Probe `command -v fswatch` and use its exit status rather than depending on external `which`. Replace the inherited fragment with the grammatical, portable error ``The FswatchDriver requires the `fswatch` executable.`` when the probe fails.

Build the common command with:

```text
fswatch -0 --format %p --event Created --event Updated --event Removed --event Renamed <paths>
```

- Linux adds `-m inotify_monitor`; Darwin keeps one process because FSEvents observes each root recursively regardless of `-r`.
- Build one operand record per configured base. Exact files use their parent directory so an editor's atomic temp-file rename is observable; a direct file watch misses the replacement event itself. Directory and glob entries use their configured base.
- Retain the normalized absolute spelling for every operand. If it exists, use `realpath()` as both its command operand and output prefix. If it is missing, use its literal absolute spelling as both; fswatch retries missing roots and emits that startup spelling when they later appear.
- Deduplicate records by canonical identity when available and literal identity otherwise. When records share an identity, recursive wins, matching `FindDriver`'s grouping rule. Retain one matcher mapping per output prefix and configured base so aliases reconstruct every configured spelling before the complete ordered `WatchPath` list is applied.
- On Linux, place aggregated operands into shallow or recursive groups. Test only shallow operands against recursive operands; fswatch already deduplicates overlapping roots inside one recursive process, while nested shallow operands observe different directory levels and must both remain.
- Remove a distinct shallow command operand only when both it and a recursive operand exist and the shallow canonical path is equal to or component-nested beneath the recursive canonical path. Canonical containment proves physical containment because `realpath()` resolves symlinks and parent segments. Literal containment is unsafe: a lexically nested path may resolve through a symlink outside the recursive tree, and a missing path may later become such a symlink.
- Retain every distinct missing shallow operand. Its literal command operand and literal matcher prefix are load-bearing together: fswatch can activate it after it appears as a symlink outside the recursive tree, where the recursive process emits nothing. The rare regular-directory case may produce duplicate native records rather than risk silent event loss.
- Keep `isContainedBy($path, $parent)` on `FswatchDriver`, require canonical inputs, and use equality plus a separator-boundary prefix check. Do not add ancestor inspection or prospective canonicalization; no startup fact can prove what a missing final component will become.
- Retain the one shared matcher-entry list unchanged when a proven-contained shallow command operand is removed, so recursive output can still reconstruct every configured spelling.
- Spawn only nonempty groups. Darwin puts every operand in one group; Linux creates at most two children.
- Keep `proc_open()`'s argument-list form. This bypasses a shell and is required for shutdown: `stop()` must signal the direct process that holds stdout's write end so the reader receives EOF.
- Remove inert `-E`.

Replace newline parsing with incremental NUL parsing, one buffer per stdout pipe:

- preserve a partial record across `fread()` calls;
- publish only complete NUL-terminated paths;
- allow embedded newlines unchanged;
- do not publish an unterminated tail at EOF;
- keep unexpected EOF as a runtime failure;
- preserve path filtering, ordered publication, stop behavior, and exception-safe process/pipe cleanup.

Use `stream_select()` with no timeout over the active stdout pipes. Check stop/channel state immediately before and after it yields, then read every ready pipe. If one child exits unexpectedly, throw and let the shared cleanup terminate its sibling.

Resource ownership is deliberate:

- `stop()` marks the driver stopped and sends `SIGKILL` to each live direct child. It does not close a pipe selected by the watch coroutine.
- The active `watch()` `finally` owns pipe closure and `proc_close()`. Remove each shared handle before performing a potentially yielding close so a concurrent `stop()` cannot signal a handle already being released.
- A normal stop wakes `stream_select()` through child EOF, observes the stopped state, and returns without treating that EOF as failure.

Do not add reader coroutines, a `WaitGroup`, a shared failure slot, a wake pipe, timeout polling, or a record-tagging protocol. The one select loop has the same error semantics for one or two children with less lifecycle state.

### 6. Correct server restart settings and signal handling

Update `src/watcher/src/ServerRestartStrategy.php`:

- Read `server.settings.daemonize` with `boolean(..., false)`. Hypervel shallow-replaces nested settings arrays, and false is both Swoole's default and the required foreground mode.
- In `terminateServer()`, return immediately when there is no published PID so no false `Stop server...` line is printed.
- The output call may yield. After it returns, re-read `$this->processId`; if it is null, return. Capture that current PID and perform no yielding work before signalling it.
- Treat `signalProcess(...) === false` exactly like a thrown `Throwable` and print `<error>Stop server failed.</error>`.
- Preserve the existing best-effort output handling: output failures must not prevent lifecycle cleanup.

Cover the subtle replacement ordering: output yields, the prior child exits and a new PID is published, and the strategy signals only the currently owned PID rather than the stale one.

### 7. Remove stale surfaces and update canonical documentation

Update `src/watcher/config/watcher.php` and `src/docs/watcher.md`:

- list only `ScanFileDriver`, `FindDriver`, and `FswatchDriver`;
- remove all `FindNewerDriver`, `gfind`, and `-mmin` guidance;
- describe polling interval use for the two polling drivers;
- state that watch paths are relative to the application, repeated/trailing separators and exact `.` segments are normalized while `..` is preserved, empty and absolute entries are invalid, and at least one configured or command-line entry is required;
- state that every driver follows a symlink supplied as a watch root but does not traverse symlinks encountered inside a watched directory;
- add a concise “Choosing a Driver” subsection:
  - `ScanFileDriver` is dependency-free and most portable, detects exact content changes and add/modify/delete, but reads and hashes all matched files each cycle;
  - `FindDriver` is the Unix polling middle ground, avoids content reads and detects add/modify/delete through metadata plus inventory, but exact preserved-mtime rewrites can be missed and coarse whole-second filesystems can miss a rewrite whose mtime equals the cutoff;
  - `FswatchDriver` has the lowest steady-state work on native filesystems, but needs fswatch and depends on OS event delivery; Linux registers only the depth each configured root needs, while macOS receives each watched root recursively and filters unmatched events in PHP;
  - polling is safer when containers, VMs, or network mounts do not forward filesystem events reliably;
  - explain Find's degraded traversal behavior and ScanFile's unreadable-subtree remove/re-add behavior without implementation jargon.
- Keep `src/watcher/README.md` minimal and remove its `Ported from` line. The package no longer tracks Hyperf; its historical lineage remains in the canonical documentation's Credits section.
- Remove the completed Watcher section from `docs/todo.md` rather than leaving stale tasks.
- Do not add a porting-guide entry. Watcher is Hypervel-owned and this work does not change a Laravel API that porters need to adapt.

## File plan

### Production and configuration

- Modify `src/watcher/src/Console/WatchCommand.php`.
- Modify `src/watcher/src/Option.php`.
- Modify `src/watcher/src/WatchPath.php`.
- Modify `src/watcher/src/Driver/AbstractDriver.php`.
- Rewrite `src/watcher/src/Driver/FindDriver.php` around the consolidated design.
- Delete `src/watcher/src/Driver/FindNewerDriver.php`.
- Modify `src/watcher/src/Driver/ScanFileDriver.php`.
- Modify `src/watcher/src/Driver/FswatchDriver.php`.
- Modify `src/watcher/src/ServerRestartStrategy.php`.
- Modify `src/watcher/config/watcher.php`.

### Tests and fixtures

- Modify `tests/Watcher/OptionTest.php`.
- Modify `tests/Watcher/WatchPathTest.php`.
- Replace obsolete `-mmin` coverage and migrate useful reference-lifecycle cases into `tests/Watcher/Driver/FindDriverTest.php`.
- Delete `tests/Watcher/Driver/FindNewerDriverTest.php`.
- Update `tests/Watcher/Fixtures/FindDriverStub.php` to match the consolidated protected seam.
- Delete `tests/Watcher/Fixtures/FindNewerDriverStub.php`.
- Modify `tests/Watcher/Driver/ScanFileDriverTest.php`.
- Modify `tests/Watcher/Driver/FswatchDriverTest.php`.
- Delete `tests/Watcher/Fixtures/FswatchDriverStub.php`; it replaces the real driver's select loop with an unrelated polling lifecycle and gates that fake behavior on the external executable.
- Modify `.github/docker/ci/Dockerfile` to install `fswatch` so the live Linux test runs instead of skipping.
- Modify `tests/Watcher/ServerRestartStrategyTest.php`.
- Add a focused `tests/Watcher/Driver/AbstractDriverTest.php` only if the inherited scan/wait lifecycle cannot be covered clearly through the existing driver tests without duplicating setup. Do not add it solely to test a protected helper in isolation.
- Modify `tests/Watcher/WatchCommandTest.php` to pin that the repeatable `--path` option requires a value before command execution.
- `tests/Watcher/WatcherTest.php` and `tests/Watcher/PackageMetadataTest.php` should remain unchanged unless implementation exposes a real integration or metadata change; no new dependency is required.
- Re-run `tests/Horizon` after the `Option` contract change. `Horizon\Console\ListenCommand` is the other `Option::fromConfig()` caller: it already rejects an empty effective watch list and its shipped paths are relative, so no Horizon source change is required.

### Documentation

- Modify `src/docs/watcher.md`.
- Modify `src/watcher/README.md` only to remove the stale upstream-tracking line.
- Modify the Watcher section of `docs/todo.md` by removing it after all work passes.

## Test matrix

### Option and WatchPath

- Every base/recursive example in the table above.
- Empty string, leading slash, repeated/trailing separators, leading and interior `.` segments, explicit `.` and `./` root forms, preserved `..` sibling paths, duplicates before and after normalization, missing watch list, empty watch list, config empty plus CLI nonempty, config nonempty plus CLI paths, a bare `--path`, missing plain file, and scan interval validation.
- Existing matching semantics for hidden files, braces, ranges, `?`, single star, double star, trailing slash, and exact files.

### Abstract polling lifecycle

- Immediate first scan and one-interval first change detection.
- Stop before start, stop while scan yields, stop while waiting, repeated stop, and thrown scan cleanup.
- No real sleeps in source; use the existing channel/coroutine test seams.

### FindDriver

- Constructor probes system `find` with `command -v` on Linux and Darwin and fails clearly when its exit status is nonzero.
- Command arguments preserve spaces, quotes, shell syntax, newline filenames, operand-only `-H`, direct `-maxdepth 1`, recursive omission, explicit files, overlapping identical roots, and recursive-wins grouping.
- A first complete inventory containing only untouched pre-existing files is silent. A file changed after reference creation during that first traversal is emitted once and the cutoff advances safely.
- Later create, modify, delete, rename, directory removal/reappearance, empty target, and newly created target each emit correctly, subject only to the documented at-least-once overlap for a creation between changed and inventory passes.
- A changed path deleted before inventory emits deletion only; a path created between passes emits addition and remains eligible on the next cutoff.
- Slow scans retain changes made after traversal passes a path; very small positive scan intervals need no special rounding and work normally.
- Changed failure publishes complete NUL records found so far, ignores an unterminated tail, does not rotate the cutoff, and may repeat the path on the next cycle.
- Inventory failure publishes changed paths, advances the cutoff when changed traversals completed, keeps the old inventory, and emits no deletion.
- Recovery after a failed first inventory suppresses the pre-existing-tree addition flood while still deleting a changed path retained during degradation and removed before recovery.
- Multiple group failures log one accurate warning with an exit code.
- Quiet successful scans rotate reference roles.
- Reference paths are unique between instances; partial creation failure cleans up; touch failure and scan exception clean up; normal stop and repeated stop clean up; stop during an active scan never unlinks a referenced file early, publishes after stop, or rotates the cutoff.
- Repeated create/change/delete cycles keep inventory bounded to known-live matched paths.
- A symlinked directory operand is followed, while symlinks encountered during descent are not; ScanFile and Find retain the same matched paths.

### ScanFileDriver

- First scan establishes a silent baseline; add/modify/delete/rename then emit correctly regardless of Finder iteration order.
- Same-size and restored/coarse-mtime content rewrites remain detected by xxh128 hashing.
- Hidden files/directories are included when matched; VCS directories retain Finder defaults.
- Shallow root/file globs do not traverse or hash nested `vendor`, `node_modules`, or `storage`; recursive globs do.
- Identical targets walk once, recursive wins, multiple matchers work, overlapping different roots remain correct, and each matched file is hashed once.
- Explicit files, missing files/directories, file already found through a directory, and hash failure behave correctly.
- An unreadable watched root does not crash the scan or hide other roots, and loss/recovery emits deletion/addition for that root. An unreadable child does not hide later readable siblings. Where local permissions can enforce it, loss/recovery emits deletion/addition for that subtree.
- Large synthetic snapshots confirm order-independent diffing without timing assertions.

### FswatchDriver

- Exact Linux shallow/recursive and Darwin command arrays, including the `command -v` probe, NUL output, event filters, Linux monitor selection, per-group recursive flag, parent-directory mapping for exact files, and no `-E`.
- Existing operands are passed canonically; missing operands and mappings retain literal absolute spelling. Parent-segment and symlinked operands reconstruct every configured spelling, events outside every prefix are ignored, and an isolated missing operand becomes active and publishes after it appears. Include a missing operand beneath an existing symlink so literal-prefix behavior cannot regress.
- Shared operands promote to recursive. Only shallow-against-recursive canonical containment removes distinct command operands; nested recursive and nested shallow operands remain in their respective command. The supported `.` plus `../packages/foo/*.php` shape remains separate. Every distinct missing shallow operand remains even when lexically nested beneath a recursive operand.
- An existing shallow symlink that resolves outside a recursive tree remains in the shallow group and publishes events from its target. A missing shallow child that later becomes an outside symlink remains in the shallow group, and its literal prefix maps and publishes the event. These cases pin the coupling between retaining missing operands and retaining their literal matcher prefixes.
- An existing shallow symlink whose canonical target is an ordinary directory inside the recursive tree is removed from the shallow command while its canonical matcher entry still maps the configured symlink spelling and publishes once.
- Dropping a genuinely contained shallow operand leaves its matcher entry active and publishes its events once. Nested recursive operands are both passed to the recursive child, and nested shallow operands are both passed to the shallow child so direct children at each level remain observable.
- `app/**/*.php` plus an absent shallow `app/Generated/*.js` proves that an event delivered by the recursive operand can be accepted by another configured matcher. Multiple mappings or matchers accepting one record publish it once. Two exact files in one parent share an operand, while different configured spellings for one real directory retain their distinct mappings.
- An exact file's atomic temp-file replacement is observed through its watched parent. Sibling events from that parent are filtered, and multiple exact files in one parent add only one command target.
- Complete and fragmented NUL records, multiple records per read, embedded-newline filenames, empty records, unterminated EOF tail, read failure, unexpected child exit, path matcher exceptions, explicit stop, channel closure, and repeated cleanup.
- One Darwin process and one or two Linux processes as grouping requires. `stream_select()` reads ready records from both without detached coroutines. Stopping while selected terminates the direct children, wakes the loop through EOF, and leaves closure to the owner; repeated stop and stop-before-watch leave no handles.
- Pin that `proc_open()` receives an argument list rather than a shell command.

### ServerRestartStrategy

- Missing daemonize, explicit false, and explicit true.
- Null PID prints/signals nothing.
- Signal true succeeds, false prints failure, and Throwable prints failure.
- Output failure does not block signalling.
- Output-yield PID replacement signals only the newly current PID.
- Existing start/restart coalescing, stop-before-publication, final stop, process failure, environment reload, and cleanup behavior remain green.

## Performance and resource verification

Use isolated temporary trees and record the command, hardware/process conditions, wall time, CPU time, peak PHP memory, child-process count, open descriptors, and disk reads where available. Compare current `0.4` against the implementation using the same tree under warmed page-cache conditions, which represent a watcher polling the same tree every two seconds. Do not label measurements cold without a verified cache-eviction method.

1. `ScanFileDriver` on 10,000 and 100,000 files:
   - direct-child glob, recursive glob, broad root with a low match ratio, and duplicate identical targets;
   - idle and one-file-changed cycles;
   - wall/CPU, peak RSS, bytes read, and hashes performed.
2. `FindDriver` on the same trees:
   - direct and recursive target groups, idle/change/delete cycles, high and low match ratios;
   - at most four child traversals, NUL parse transient memory, retained inventory size, and temp files/fds after stop.
3. `FswatchDriver` on Linux:
   - all-shallow configuration omits recursive registration;
   - the default exact-root plus recursive `app`/`config` configuration uses two direct children and avoids registering unrelated project directories;
   - identical targets and existing canonically-contained cross-group targets are not registered twice, while distinct missing shallow targets remain protected;
   - inspect `/proc/<pid>/fdinfo` for inotify watches and compare with `fs.inotify.max_user_watches`;
   - verify at most two child processes and stable PHP memory while idle and under event bursts.
4. Run several watcher processes together to observe aggregate CPU, RSS, disk I/O, child-process count, temp files, and inotify descriptors. There must be no worker-lifetime growth across repeated cycles.

Do not add benchmark-only production counters or CI timing thresholds. Existing protected seams and OS observations are enough.

## Verification order

Follow the repository's one-file-at-a-time workflow:

1. Update one test file, then immediately run it from the repository root with `./vendor/bin/phpunit --no-progress <file>`.
2. Make the corresponding source change and rerun that exact test file before moving on.
3. After all watcher files are green, run `./vendor/bin/phpunit --no-progress tests/Watcher`.
4. Run `./vendor/bin/phpunit --no-progress tests/Horizon` after the `Option` change to verify its second production caller.
5. Run the performance/resource checks above and compare against the unchanged 0.4 baseline.
6. On macOS, manually run `FswatchDriver` with the final `-0 --format %p [ -r ] --event ...` command and verify created, updated, removed, renamed, newline-containing filenames, and an exact file that is atomically replaced then edited again. Fragmented records remain covered deterministically by unit tests; Linux CI cannot verify the changed FSEvents command.
7. Run `composer fix` once as the repository checkpoint; do not duplicate its full formatter, PHPStan, parallel suite, or Testbench runs immediately beforehand.
8. Inspect `git diff` and `git status`; confirm deleted driver references, stale documentation/todos, temporary references, benchmark artifacts, and unrelated changes are absent.

## Deliberately rejected machinery

- No legacy `FindNewerDriver` alias, mode flag, or compatibility shim.
- No exact glob parser; one safe recursive boolean is enough because Symfony still owns matching.
- No `find` tag protocol or external per-path process.
- No streaming `proc_open()` replacement for short-lived find commands.
- No retained mtime map, repeat-suppression hash, metadata prescreen, periodic rehash, or scan tuning cap.
- No per-depth-group reference pairs or retry loop for broken filesystem traversal.
- No ScanFile or Find target-containment optimizer between different configured roots.
- No second degraded-mode state machine for Symfony Finder's unreadable subtree behavior.
- No fswatch filter-regex depth emulation, reader coroutines, pipe-tagging protocol, wake pipe, or timeout polling.
- No new Filesystem abstraction, worker-static cache, coroutine context, or global cleanup registry.
- No change to `Watcher`'s one-second fallback cleanup wait. Normal signal shutdown remains inside the channel loop until the active driver scan returns and closes the channel, so the driver has already finished before that wait begins. The timeout only guards a driver that closed its channel without ending or cleanup after another failure; deriving it from the polling interval would not bound scan duration.

Each rejected mechanism either weakens detection, adds state for an exceptional condition, or costs more resources and maintenance than the verified problem it would solve.

## Completion criteria

- Audit findings 105–111 and every Watcher todo are implemented or closed by the superseding consolidated design.
- Only `ScanFileDriver`, `FindDriver`, and `FswatchDriver` remain documented and shipped.
- Valid newline-containing filenames survive both command-backed drivers.
- A bare `--path` is rejected by Symfony Console instead of reaching strict path normalization.
- Polling starts with an immediate silent baseline and detects the first later change within one interval.
- Find detects additions, modifications, renames, and deletions without GNU find or whole-second PHP mtime deduplication, and its degraded behavior never invents deletions or loses a changed cutoff silently.
- ScanFile retains exact-content detection while removing sorted/materialized directory walks and surviving unreadable watched roots and child subtrees.
- Fswatch uses NUL framing, one Darwin child or at most two direct Linux children, the minimum required Linux recursive registration, and one flat reader loop.
- Every retained collection and resource is naturally bounded and released at lifecycle end.
- Documentation describes real costs and failure behavior without exposing stale drivers or internal implementation noise.
- Targeted tests, watcher suite, performance/resource checks, manual macOS smoke, and `composer fix` pass.
