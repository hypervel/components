# Database Foreign-Key Target Command Ordering

## Status and objective

Implementation, verification, and peer code review are complete; ready for owner handoff.

Correct an inherited `Blueprint` ordering defect that lets a foreign-key statement run before the primary, unique, or ordinary index it references. The fix belongs in schema command preparation, must work through the existing Laravel-style `Schema` and `Blueprint` APIs, and must preserve explicit ALTER ordering and SQLite's rebuild state machine.

The finished code must have no migration-specific workaround, compatibility layer, public setting, new extension API, dead path, or stale explanation. Application query paths are unchanged; the added work is bounded linear array processing during schema compilation only.

After every context compaction, re-read the monorepo `CLAUDE.md`, this worktree's `AGENTS.md`, and this plan in full before continuing.

## Anti-overengineering constraints

The following wording is retained verbatim from the core audit plan. Its principle numbering is also retained; principles 1–6 remain in the core operating plan. In principle 9, “later in this plan” refers to the core plan's [Established remediation vocabulary](2026-07-12-0900-framework-coroutine-state-lifecycle-audit.md#established-remediation-vocabulary) section.

### What this audit is not

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

## Verified framework facts and failure

### Command construction and execution

| Current code | Consequence |
|---|---|
| `Builder::create()` adds `create` before invoking the callback; `Builder::table()` invokes the callback directly. | CREATE callbacks describe a final table, while ALTER callbacks describe an operation sequence. |
| `Blueprint::addColumnDefinition()` stores every column in `$columns`, but adds it to `$commands` only for ALTER. | An ALTER column has an exact command-list position and can own generated work. CREATE columns do not. |
| `addColumnDefinition()` is the only method that adds to `$columns`; `removeColumn()` removes the same definition from both `$columns` and ALTER `$commands`. | Every retained ALTER column carrying a fluent key has exactly one owning `ColumnDefinition` in `$commands`. |
| `Blueprint::addFluentIndexes()` appends column-fluent index commands after the callback has finished. | A fluent key declared on a column before a foreign key can compile after that foreign key. |
| Commands generated by `addFluentIndexes()` form one appended tail, but positive keys and generated drops can be interleaved in that tail. | Placement must select the captured positive command objects, not move or slice the whole generated tail. |
| `Blueprint::addImpliedCommands()` converts ALTER `ColumnDefinition` objects to `add` / `change` only after fluent indexes are generated. | A generated key can be associated with its exact owning column before conversion without changing protected method signatures. |
| `Blueprint::foreign()` replaces the `Fluent` just appended by `indexCommand()` with a `ForeignKeyDefinition` carrying the same attributes. | Command-order and identity logic must operate on the final object stored in `$commands`. |
| `Blueprint::toSql()` compiles `$commands` in order and `Builder::executeBlueprint()` executes the resulting statements in order. | Wrong command order becomes wrong database statement order. |
| MySQL compiles CREATE primary keys inline but emits other keys and foreign keys as later statements. PostgreSQL emits primary, unique, index, and foreign-key statements after CREATE. | A target key must precede the foreign-key statement on engines that emit both separately. |
| SQLite CREATE reads primary and foreign commands into the table definition. SQLite ALTER updates `BlueprintState` in command order and groups rebuild commands. | CREATE normalization is harmless, but moving generated ALTER keys changes rebuild behavior and can duplicate rebuilds or index creation. |

The current Laravel `Blueprint` has the same `addImpliedCommands()`, `addFluentIndexes()`, and `addColumnDefinition()` ordering. This confirms the defect is inherited, not that it should be retained.

### Database contract and reproduced behavior

- PostgreSQL requires referenced columns to be backed by a non-deferrable primary or unique constraint, or a suitable non-partial unique index ([PostgreSQL `CREATE TABLE`](https://www.postgresql.org/docs/current/sql-createtable.html)).
- MySQL InnoDB requires indexes on the foreign and referenced keys and accepts a referenced leading index under its documented rules ([MySQL foreign-key constraints](https://dev.mysql.com/doc/refman/8.0/en/create-table-foreign-keys.html)).
- MariaDB requires the referenced columns to be an index or its leading columns ([MariaDB foreign keys](https://mariadb.com/docs/server/ha-and-performance/optimization-and-tuning/optimization-and-indexes/foreign-keys)).
- SQLite requires a parent primary or unique key and handles CREATE constraints inline ([SQLite foreign keys](https://www.sqlite.org/foreignkeys.html)).

Real Hypervel schema execution produced this matrix:

| Blueprint shape | MySQL | MariaDB | PostgreSQL | SQLite |
|---|---:|---:|---:|---:|
| CREATE fluent primary before later self-FK | passes because primary is inline | passes because primary is inline | fails | passes |
| CREATE fluent unique before later self-FK | fails | fails | fails | passes |
| CREATE explicit unique after its FK | fails | fails | fails | passes |
| ALTER fluent primary before later self-FK | fails | fails | fails | passes |
| ALTER fluent unique before later self-FK | fails | fails | fails | passes |

MariaDB also accepts an ordinary index as a self-FK target, but both fluent CREATE and ALTER forms fail when the generated index is emitted after the FK; the same forms pass when the index statement is explicitly placed first.

## Required behavior

1. CREATE is declarative. A `primary`, `unique`, or ordinary `index` command declared anywhere in one CREATE blueprint must compile before the first `foreign` command while all other relative order is preserved.
2. ALTER is imperative. Explicit commands retain callback order, including an explicitly misplaced target key; only a positive key command displaced by fluent column expansion moves immediately after its exact owning column.
3. The target set is exactly `primary`, `unique`, and `index`. Full-text, spatial, vector, and drop commands do not gain ordering semantics that their database contracts do not support. `rawIndex()` uses the ordinary `index` command and therefore follows CREATE's command-kind rule; the normalizer does not claim that its expression can back an FK.
4. SQLite ALTER keeps its current command order. Its ordered state/rebuild compiler already resolves these cases, and relocation measurably changes the number and placement of rebuild statements.
5. Existing command objects and their attributes are moved, never reconstructed. Blueprint subclasses, macros that use native key methods, grammar command inspection, `online`, algorithm, lock, and other command metadata therefore remain intact.
6. Public and protected Laravel-compatible signatures, named arguments, builder resolution, grammar macros, and `Blueprint::getCommands()` remain compatible. No configuration switch or new public API is added.
7. `removeColumn()` compares identifier names strictly so distinct numeric-looking strings cannot remove each other's column definition and ALTER command.

## Implementation design

### Shared command classification

Add one private typed class constant to `Blueprint`:

```php
private const array FOREIGN_KEY_TARGET_COMMANDS = ['primary', 'unique', 'index'];
```

Use strict membership checks against this list in both normalization paths. Do not generalize it into a registry or grammar capability surface: the three command kinds are the verified cross-driver contract.

### CREATE: stable dependency promotion

Inside `addImpliedCommands()`, branch on `creating()` after both `addFluentIndexes()` and `addFluentCommands()` have materialized the complete implicit command list. Normalize the CREATE branch and keep the existing ALTER conversion in the `else` branch:

```php
$this->addFluentIndexes();
$this->addFluentCommands();

if ($this->creating()) {
    $this->promoteCreateIndexesBeforeForeignKeys();
} else {
    // Keep the existing ALTER conversion and addAlterCommands() logic unchanged.
}
```

Use `if` / `else` rather than an inverted condition and early return so the port's existing ALTER block remains structurally aligned with Laravel. Keeping normalization after both generators gives it one finalized implicit-command list and avoids splitting ordering across multiple sites. The private helper then:

- scan until the first `foreign` command;
- keep the prefix through the command before that FK unchanged;
- stably collect only target commands from the remaining suffix;
- place those targets immediately before the first FK;
- append every non-target suffix command in its original order.

Conceptual shape:

```php
$foreignKeyOffset = array_find_key(
    $this->commands,
    static fn (Fluent $command): bool => $command->name === 'foreign',
);

if (is_null($foreignKeyOffset)) {
    return;
}

$prefix = array_slice($this->commands, 0, $foreignKeyOffset);
[$targets, $remainder] = [[], []];

for ($offset = $foreignKeyOffset, $count = count($this->commands); $offset < $count; ++$offset) {
    $command = $this->commands[$offset];

    if (in_array($command->name, self::FOREIGN_KEY_TARGET_COMMANDS, true)) {
        $targets[] = $command;
    } else {
        $remainder[] = $command;
    }
}

$this->commands = [...$prefix, ...$targets, ...$remainder];
```

The final implementation may use ordinary conditionals if clearer; it must remain one stable linear pass. Return without rebuilding the array when no FK exists.

Do not move every FK to the end. A custom command deliberately placed after an FK—such as a PostgreSQL validation/constraint extension—must remain there. The narrower promotion fixes the dependency while preserving extension command order.

### ALTER: attach generated targets to their owning columns

Keep the `addFluentIndexes(): void` signature unchanged. While its existing column loop creates a positive target command for a non-SQLite ALTER blueprint, collect the exact `ColumnDefinition` and returned `Fluent` command as a pair. Do not collect generated drops or non-target index kinds.

After that loop, one private `placeGeneratedIndexesAfterColumns()` helper rebuilds `$commands` once:

1. Group generated commands by `spl_object_id()` of their exact owner, retaining generation order.
2. Build a set of the generated command object IDs.
3. Walk `$commands` once, omitting each generated command from its appended position.
4. Copy every other command unchanged; immediately after an owning `ColumnDefinition`, append its grouped generated commands.

This happens before `addImpliedCommands()` converts ALTER columns to `add` / `change`, so object identity is available without names, extra attributes, or a second lookup. It also preserves multiple generated commands per owner if the upstream fluent loop later permits them.

The helper is private because this is internal normalization, not an extension point. Add a short source comment explaining that fluent indexes are created after the callback and must be restored to their owning ALTER position. Add the SQLite exclusion's WHY beside the branch: its compiler consumes ordered state and rebuild groups rather than independent ALTER statements.

### Complexity and runtime impact

- CREATE adds one O(command count) scan only when compiling a schema blueprint; it allocates replacement arrays only after finding an FK.
- Eligible ALTER blueprints add one O(command count) rebuild plus bounded object-ID maps. There are no repeated splices and no quadratic walk.
- Ordinary queries, Eloquent, HTTP, queues, coroutines, workers, and pooled-connection checkout do not call this path.
- The change adds no query, network round trip, container lookup, lock, yield, serialization, static state, worker-lifetime cache, or retained memory.

## Files and documentation

- Modify `src/database/src/Schema/Blueprint.php` only for production behavior.
  - Replace `removeColumn()`'s two loose name comparisons with strict comparisons. Numeric-looking names such as `1e2`, `0100`, and `100` are distinct identifiers; PHP's numeric-string coercion can currently remove the wrong definition and command.
- Add focused unit coverage in `tests/Database/DatabaseSchemaBlueprintTest.php`.
- Add real cross-engine behavior in `tests/Integration/Database/SchemaBuilderTest.php`.
- Add the SQLite ordered-rebuild regression beside the existing exact SQL assertions in `tests/Integration/Database/Sqlite/DatabaseSchemaBlueprintTest.php`.
- Correct `src/boost/docs/migrations.md` to call `online()` on the index definition returned by `Blueprint::unique()`, not on a column definition whose attribute is not transferred to the generated index.
- Update other existing expected SQL only where the intended normalization changes it; investigate each failure before editing its assertion.

No user documentation change is required for command ordering. Existing Schema/Blueprint calls become reliable without a new workflow, option, or compatibility note. The private algorithm belongs in source comments and tests, not public migration documentation.

## Testing plan

### Unit Blueprint contract tests

Cover these branches through `Blueprint::toSql()` and inspect exact command order and identity:

- CREATE with interleaved FKs, explicit target commands, and fluent target commands promotes every target before the first FK while preserving target order and FK/non-target order.
- A target already before the first FK does not move.
- A custom command after an FK stays after that FK, proving the implementation is not a foreign-last partition.
- Non-SQLite ALTER places generated primary, unique, and ordinary index commands immediately after their exact owning columns.
- A Blueprint subclass that adds metadata to the generated command receives the same command object after placement; no command is reconstructed.
- Explicit ALTER target/FK order remains exactly authored.
- An ALTER FK authored before its target column remains before the generated target command. Pin this deliberate failure boundary so a later change cannot turn owner placement into broad ALTER promotion.
- Generated drop commands and full-text/spatial/vector commands retain their existing positions.
- SQLite ALTER retains its current generated-command order.
- `removeColumn()` removes only the exact requested numeric-looking column name from both `$columns` and ALTER `$commands`; the two other distinct names remain.

### Real database behavior

Run the shared `SchemaBuilderTest` through MySQL, MariaDB, PostgreSQL, and SQLite:

- CREATE one self-referencing table where a fluent primary and an explicit unique target are declared after or materialized after their FKs. Assert both constraints exist, insert a valid parent/child pair, and prove an invalid reference is rejected.
- ALTER an empty table with fluent primary and unique target columns followed by self-FKs in the same callback. Assert the final keys and FKs, valid inserts, and FK rejection.
- On MariaDB, cover ordinary-index targets for both CREATE and ALTER, including one explicit and one fluent form, because that supported engine contract is why `index` belongs in the target set.

Use `#[RequiresDatabase(...)]` only for engine-specific semantics. Shared behavior stays in the shared test class so `bin/run-database-tests.sh` exercises it for every supported driver.

### SQLite rebuild regression

Retain the existing named and unnamed fluent-unique SQL assertions, which already prove one rebuild followed by one index creation. Add an exact primary-key alteration assertion that would expose premature relocation as a second rebuild. Assert the ordered SQL, not only final schema state.

### Verification sequence

1. Run each changed test file immediately after editing it.
2. Run the focused Blueprint and schema-grammar/builder unit files for MySQL, MariaDB, PostgreSQL, and SQLite; investigate every changed expectation against its protected behavior.
   - The source audit predicts no existing expected-SQL fixture changes: the current SQLite CREATE fixture is position-independent, and the current ALTER fluent-index fixture uses excluded vector index behavior. Confirm this across the full focused suite. Treat any existing expected-SQL edit as evidence of an implementation defect until tracing proves otherwise.
3. Run the affected shared integration filters with:
   - `./bin/run-database-tests.sh mysql --filter=...`
   - `./bin/run-database-tests.sh mariadb --filter=...`
   - `./bin/run-database-tests.sh pgsql --filter=...`
   - `./bin/run-database-tests.sh sqlite --filter=...`
4. Run the complete `tests/Integration/Database` group for all four drivers after focused coverage is green.
5. Run `composer fix` once after the complete implementation. If it fails, follow the repository's targeted correction and remaining-script procedure rather than restarting blindly.
6. Run `git diff --check`, inspect the complete diff, and trace the final logic through Blueprint construction, implied-command generation, grammar compilation, builder execution, extension points, and affected tests.
7. Complete a fresh self-review for correctness, Laravel-style naming and placement, command identity, stable ordering, SQLite behavior, overengineering, dead code, and schema-path allocation cost; then loop on adversarial peer code review until signoff.

## Rejected designs

- **Repair the Workflow migration:** fixes one consumer while leaving every CREATE/ALTER callback vulnerable.
- **Move all FKs to the end:** breaks deliberate custom commands after an FK and changes more order than the dependency requires.
- **Match key and FK table/column names:** duplicates database semantics, mishandles engine differences and expressions, and is unnecessary because all target commands can safely precede FKs during CREATE.
- **Normalize every ALTER command:** destroys callback sequence semantics, including required `dropForeign()` before a target-index drop.
- **Relocate SQLite ALTER keys:** its state machine uses order to form rebuild groups; measured fluent-primary placement causes a second rebuild and fluent-unique placement duplicates index work.
- **Reconstruct generated commands or tag them with temporary attributes:** risks losing subclass/macro metadata and exposes internal bookkeeping when exact object identity already solves the problem.
- **Add grammar capabilities, configuration, or a public ordering API:** no consumer needs them; the defect and its three target command kinds are already proven and bounded.
- **Repeated `array_splice()` placement:** simpler-looking per command but quadratic. One stable rebuild is equally clear and scales with large migrations.

## Completion criteria

The change is complete when all supported engines accept the formerly failing CREATE and ALTER shapes; explicit ALTER and custom command order remain intact; SQLite rebuild counts and ordering remain unchanged; command objects and extension metadata retain identity; `removeColumn()` preserves distinct identifier strings; targeted and complete database suites plus `composer fix` are green; and self-review and peer review find no correctness, compatibility, performance, Laravel-ergonomics, stale-code, or overengineering issue.
