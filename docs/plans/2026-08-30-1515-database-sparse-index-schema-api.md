# Database sparse-index schema API implementation plan

## Status and authority

This plan owns the approved database schema-builder enhancement for sparse ordinary indexes. Implementation and verification are complete. The Components capability must be made available to the aggregate package before implementing the separate Workflow sparse-index adoption plan.

The first consumer is the Workflow sparse-index adoption plan at `packages/hypervel/docs/plans/workflow/2026-08-30-2112-workflow-sparse-index-adoption.md`. Components owns the public API, SQL compilation, schema introspection, SQLite rebuild behavior, framework documentation, and framework tests. Workflow owns its index choices and performance gates.

## Goal and design boundary

Add one Laravel-style modifier for the common portable case where an ordinary index should contain only rows whose chosen column is not null:

```php
$table->index(
    ['status', 'admission_reconcile_at', 'id'],
    'workflow_executions_admission_index',
)->whereNotNull('admission_reconcile_at');
```

PostgreSQL and SQLite compile this as a partial index with `WHERE <column> IS NOT NULL`. MySQL and MariaDB compile the existing full ordinary index because they do not support partial indexes. The migration therefore keeps one portable definition and a safe full-index fallback.

Keep the surface deliberately narrow:

- support one `whereNotNull(string $column)` predicate on ordinary `index()` and `rawIndex()` definitions;
- reject the modifier immediately on primary, unique, full-text, spatial, and vector indexes with an actionable `LogicException`;
- let a repeated call replace the prior column, matching `Fluent` modifier behavior;
- do not validate table or column existence before the database compiles the migration;
- do not require the predicate column to be part of the index; and
- do not add arbitrary SQL predicates, closures, predicate lists, an expression tree, engine strategy objects, or a second schema abstraction.

Partial unique indexes are useful in other domains, but they carry different correctness semantics and have no approved consumer here. Rejecting them keeps this enhancement an ordinary-index storage optimization rather than a portable constraint API.

The supported floors already include partial indexes: PostgreSQL 10+ and SQLite 3.26+. No server-version branch or compatibility shim is needed.

## Public schema API

### Index definitions

Narrow the native return type of these `Blueprint` methods from `Fluent` to `IndexDefinition`:

- `primary()`
- `unique()`
- `index()`
- `fullText()`
- `spatialIndex()`
- `vectorIndex()`
- `rawIndex()`

These methods already return index commands in practice. The concrete type makes existing fluent index modifiers and the new method visible to IDEs and PHPStan without changing runtime call shape. `foreign()` continues to return `ForeignKeyDefinition`, and non-index commands continue to use `Fluent`.

Add a subtype-preserving `addCommandDefinition(Fluent $definition)` helper, including the same template PHPDoc shape as the existing `addColumnDefinition()`. The generic `addCommand()` sends its created `Fluent` through that helper, while `indexCommand()` constructs an `IndexDefinition`, sends it through the same append path, and returns the concrete subtype. Keep the existing command attributes and order unchanged.

`foreign()` also reuses `indexCommand()` for conventional name generation and attribute assembly, then immediately replaces the appended definition with a `ForeignKeyDefinition`. Preserve that behavior and add one short comment at the call site explaining the temporary index definition; do not split or duplicate index-name generation merely to avoid the intermediate subtype.

Add a concrete method to `IndexDefinition`:

```php
public function whereNotNull(string $column): static
{
    if ($this->get('name') !== 'index') {
        throw new LogicException(
            'The [whereNotNull] modifier is only available for ordinary indexes.',
        );
    }

    return $this->set('whereNotNull', $column);
}
```

Use a concrete method rather than an `@method` annotation because the index-kind guard is part of the public contract. Keep the existing magic modifier annotations for their existing behavior.

`rawIndex()` delegates to `index()`, so it receives the ordinary command name `index` and passes the same guard without a separate branch.

### SQL compilation

Add one protected grammar helper beside the base grammar's other SQL-fragment helpers. It returns either an empty string or the wrapped ` where <column> is not null` suffix. PostgreSQL and SQLite `compileIndex()` append it to their existing SQL. This keeps quoting identical in both engines without introducing a dialect object.

Preserve every existing composition rule:

- PostgreSQL `online()` still emits `CONCURRENTLY` and `algorithm()` still emits `USING ...` before the predicate;
- raw index expressions remain the index key expression while the predicate column is wrapped as an identifier;
- SQLite schema-qualified index names and target tables remain unchanged; and
- MySQL and MariaDB do not inspect the modifier and emit the same SQL as a normal full index.

### Public index introspection

Extend every `Schema::getIndexes()` record with `partial: bool`:

```php
array{
    name: string,
    columns: list<string>,
    type: null|string,
    unique: bool,
    primary: bool,
    partial: bool,
}
```

Populate it at the lowest owning layer:

| Engine | Source |
|---|---|
| PostgreSQL | `pg_index.indpred IS NOT NULL` |
| SQLite | `pragma_index_list.partial` |
| MySQL / MariaDB | literal `false` in `MySqlProcessor` |

Update the `Builder` and base `Processor` array-shape PHPDocs. Do not expose raw predicate SQL; callers need the stable capability fact, not an engine-specific expression parser.

### SQLite schema-state preservation

SQLite already treats a partial index as non-reconstructible and replays its exact `sqlite_master.sql` during a table rebuild. Preserve that design rather than creating a partial-index parser.

Extend SQLite's internal index record with `partial: bool`. Both arms of `SQLiteGrammar::compileIndexes()` must project it: the synthetic primary-key row reports `false`, while ordinary rows retain `pragma_index_list.partial`. Keep the flag in the processor record for the public projection, but do not duplicate it onto `BlueprintState` index definitions: existing partial indexes are already identified by `reconstructible = false` and stored SQL, while new definitions retain `whereNotNull` directly.

Keep `reconstructible = false` for an introspected partial index because its stored predicate may be arbitrary SQL and must be replayed exactly. Document this invariant beside the SQLite introspection expression because both rebuild and rename depend on it. A newly declared simple `whereNotNull()` index remains reconstructible because the command retains the complete supported predicate; a newly declared raw index still follows the existing raw-expression rules. Existing partial indexes continue through the stored-SQL rebuild and index-rename paths without a predicate parser.

Narrow `BlueprintState`'s primary-key property and accessor to `?IndexDefinition`: both introspected and newly declared primary keys now have that exact type. Because `update(Fluent $command)` prevents static narrowing, the `primary`, `index`, and `foreign` cases narrow the command with checked `@var` annotations. Do not use `assign.propertyType` suppressions there because they would hide a later genuine mismatch on the assignment line.

## Source and documentation changes

Implement one file at a time in this order:

1. `src/database/src/Schema/IndexDefinition.php` — public modifier and index-kind validation.
2. `src/database/src/Schema/Blueprint.php` — concrete return types and `IndexDefinition` command construction.
3. `src/database/src/Schema/Grammars/Grammar.php` — shared predicate suffix compiler.
4. `src/database/src/Schema/Grammars/PostgresGrammar.php` — partial DDL and `indpred` introspection.
5. `src/database/src/Schema/Grammars/SQLiteGrammar.php` — partial DDL and public/internal `partial` metadata.
6. `src/database/src/Schema/BlueprintState.php` — narrow primary-key state to `?IndexDefinition` and replace assignment suppressions with checked case-local `@var` narrowing.
7. `src/database/src/Query/Processors/PostgresProcessor.php` — normalize PostgreSQL partial state.
8. `src/database/src/Query/Processors/SQLiteProcessor.php` — expose the public flag and retain it internally.
9. `src/database/src/Query/Processors/MySqlProcessor.php` — normalize the unsupported-engine fallback as `false`; MariaDB inherits it.
10. `src/database/src/Query/Processors/Processor.php`, `src/database/src/Schema/Builder.php`, and `src/database/src/Schema/SQLiteBuilder.php` — complete public and internal record types.
11. `src/database/src/Console/TableCommand.php` — include `partial` in the index attributes rendered by `db:table`.
12. `src/docs/migrations.md` — add an anchored Partial Indexes subsection with a concise example and explain PostgreSQL/SQLite partial behavior, MySQL/MariaDB full fallback, and ordinary-index-only validation. Do not add a porting-guide entry because the change is additive and requires no Laravel porter action.

## Test plan

Update each test file and run it immediately before moving to the next.

### Public API and grammar

- `tests/Database/DatabaseSchemaBlueprintTest.php`
  - prove all seven index methods return `IndexDefinition`;
  - prove `index()` and `rawIndex()` retain `whereNotNull`;
  - prove primary, unique, full-text, spatial, and vector definitions reject the modifier with the exact useful error in named data-provider cases;
  - prove `foreign()` still returns and leaves only the replacement `ForeignKeyDefinition` command.
- `tests/Database/DatabasePostgresSchemaGrammarTest.php`
  - assert exact partial-index SQL;
  - assert composition with algorithm, `online()`, and a raw index expression;
  - pin the index-introspection query's partial projection.
- `tests/Database/DatabaseSQLiteSchemaGrammarTest.php`
  - assert exact ordinary, raw, and schema-qualified partial-index SQL;
  - pin the public/internal introspection query shape.
- `tests/Database/DatabaseMySqlSchemaGrammarTest.php` and `DatabaseMariaDbSchemaGrammarTest.php`
  - assert that the same definition compiles to the unchanged full-index SQL.

### Normalization, real engines, and rebuilds

- `tests/Database/DatabasePostgresProcessorTest.php`, `DatabaseSQLiteProcessorTest.php`, and `DatabaseMySqlProcessorTest.php`
  - assert the exact six-field public record;
  - for SQLite, assert the internal record also preserves `partial`, stored SQL, and `reconstructible = false`.
- `tests/Database/DatabaseConsoleJsonTest.php`
  - prove `db:table` identifies partial indexes in its rendered attribute set.
- `tests/Integration/Database/SchemaBuilderTest.php`
  - create the index through the real schema builder on all configured engines;
  - assert `partial === true` on PostgreSQL/SQLite and `false` on MySQL/MariaDB;
  - assert a plain unique index reports `partial === false` on every engine so the projection is proven to discriminate;
  - assert every introspected index, including primary and unique records, reports the complete public shape.
- `tests/Integration/Database/Sqlite/DatabaseSchemaBlueprintTest.php`
  - create a partial index through the public API;
  - trigger a table rebuild and then rename that index;
  - prove the stored predicate, public `partial` flag, and physical index definition survive both paths exactly.
- add `types/Database/Schema.php` to pin the concrete `IndexDefinition` return type and the fluent `static` return from `whereNotNull()`.

Audit existing exact `getIndexes()` array assertions across `tests/` and update each for the new required `partial` member. Do not weaken them to subset assertions merely to accommodate the new field.

## Verification

After the focused files are green:

1. run the complete database unit-test group affected by schema grammars and processors;
2. run the real SQLite schema tests and each configured MySQL, MariaDB, and PostgreSQL schema-builder test group;
3. run `composer fix` once at the meaningful completed checkpoint;
4. inspect the diff for stale `Fluent` return docs, five-field index record shapes, unused imports, obsolete suppressions, duplicate predicate compilation, and documentation drift; and
5. obtain adversarial code review and repeat targeted verification until signed off.

The completed change must add no runtime query cost, worker state, cache, registry, or application configuration. Its only production effect is migration-time DDL and one boolean in explicit schema-introspection results.
