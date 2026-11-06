# Hypervel schema parser

## Status and objective

Implementation is in progress. Shared results, the MySQL/MariaDB reader/API with SQL-mode handling, native dump coverage, counter cleanup, dump retry/quoting fixes, PostgreSQL load diagnostics and TLS mapping/coverage are implemented and reviewed. Stop for the owner's discussion after committing and pushing this checkpoint; do not start the next slice. PostgreSQL and SQLite remain unfinished. Continue without modifying the database clients or adding workarounds for their event-delimiter defect. The parser design and TLS compatibility changes are approved. Codex implements; `claude-stan` provides second opinions and independent plan/code review.

### Remaining port files

Remove each entry when its adaptation and applicable verification are complete. Other remaining work is defined by the implementation sections below.

- PostgreSQL `src/Internal/SqlScanner.php` → Postgres/SqlScanner.
- PostgreSQL `src/Internal/StatementSplitter.php` → Postgres/StatementSplitter.
- PostgreSQL `src/Internal/TypeNameResolver.php` → Postgres/TypeNameResolver.
- PostgreSQL `src/PgDumpParser.php` → PostgresParser.
- PostgreSQL `tests/Concerns/AssertsSchema.php`.
- PostgreSQL `tests/Integration/RealDumpTest.php`.
- PostgreSQL `tests/Unit/AllTypesSchemaTest.php`.
- PostgreSQL `tests/Unit/PgDumpParserTest.php`.
- PostgreSQL `tests/Unit/SqlScannerTest.php`.
- PostgreSQL `tests/data/all-types-schema.sql`.
- PostgreSQL `tests/data/all-types-source.sql`.

Create `hypervel/schema-parser` in `src/schema-parser`, on `feature/schema-parser` based on `0.4`. It reads MySQL/MariaDB, PostgreSQL and SQLite schema dumps without a database connection. Applications, generators and analysis tools receive the same typed schema model. Complete this package and its adjacent database fixes in one PR into `0.4`; the PHPStan work resumes after incorporating that merge and consumes this package. Further verified parser refinements discovered during that integration remain normal framework work.

This is a tracked port of two schema readers, with an adapter for a third-party SQLite parser. Preserve useful upstream structure and tests while fixing defects at their source. The public API, names and documentation must feel like a first-party Laravel-style Hypervel component. Churn is not a reason to preserve poor code; neither is a new package a reason to create unnecessary infrastructure.

## Package boundary

- `MySqlParser` handles MySQL and MariaDB dumps; `PostgresParser` handles plain-text PostgreSQL dumps; `SQLiteParser` handles SQLite schema SQL.
- Every parser implements `Hypervel\SchemaParser\Contracts\Parser::parse(string $sql): Schema`. Explicit parser selection avoids guessing a dialect from SQL contents.
- Results describe persistent schema. Skip temporary table/view declarations at statement dispatch, before they can replace a same-named persistent definition. Retain PostgreSQL UNLOGGED tables. Recognize SQLite's explicit `temp` namespace case-insensitively, including quoted names; compare PostgreSQL's normalized identity to `pg_temp` exactly, preserving quoted case. No session/shadow registry is needed.
- Parse schema facts, including declaration identity and constraints. Do not convert SQL types to PHP/PDO/Eloquent types, execute expressions or SQL, open database connections, launch processes, discover files, boot an application, or maintain a persistent result cache.
- Supported input is database-generated schema dumps and the equivalent schema declarations described below, including the separate `ALTER` statements emitted by dump tools. This is not a general migration replay engine, SQL validator, schema comparator or query analyser.
- Match the framework's documented database floor: MySQL 5.7, MariaDB 10.11, PostgreSQL 10 and SQLite 3.32. Accept later dump syntax for the represented facts, including PostgreSQL 18 constraint/generated-column forms. Engine version is not a parser configuration knob; tests distinguish version-dependent fixtures and live capabilities.
- Describe completeness against the represented model, not every database feature. Routine bodies, grants, optimizer/storage settings and sequence definitions are not interpreted. Their statement boundaries must still be respected. Unknown syntax affecting represented facts produces an issue, not fabricated certainty.
- Keep this optional component independent of `hypervel/database`, PHPStan, the container, Swoole and application boot. No service provider, facade, manager, driver registry or parser fallback chain is needed.

```php
namespace Hypervel\SchemaParser\Contracts;

use Hypervel\SchemaParser\Schema;

interface Parser
{
    /**
     * Parse a SQL schema dump.
     */
    public function parse(string $sql): Schema;
}
```

```php
use Hypervel\SchemaParser\PostgresParser;

$schema = (new PostgresParser)->parse($sql);
$users = $schema->table('users', 'public');
$email = $users?->column('email');

if ($schema->issues === [] && $users !== null && $users->isComplete()) {
    // Missing columns can now be distinguished from an incomplete table definition.
}
```

## Layout, dependencies and upstream ownership

```text
src/schema-parser/
    composer.json
    LICENSE.md
    README.md
    src/
        Contracts/Parser.php
        MySqlParser.php
        PostgresParser.php
        SQLiteParser.php
        MySql/SqlParser.php
        Postgres/SqlScanner.php
        Postgres/StatementSplitter.php
        Postgres/TypeNameResolver.php
        Schema.php, Table.php, Column.php, ColumnType.php, ...
tests/SchemaParser/
    MySql/
    Postgres/
    Fixtures/
    ... shared-model and public-adapter tests
tests/Integration/SchemaParser/Database/
    MySql/
    MariaDb/
    Postgres/
    Sqlite/
src/docs/schema-parser.md
```

The integration directory spelling `Sqlite` is required by `bin/run-database-tests.sh`; the public class spelling is `SQLiteParser`, matching predominant framework naming. Create additional internal classes only for concrete shared/complex logic, not to make all dialect directory trees symmetric.

| Source | Treatment and mapping |
| --- | --- |
| [iamcal/SQLParser](https://github.com/iamcal/SQLParser), `master` | Copy `src/SQLParser.php` into `MySql/SqlParser.php`, using the shared ParseException instead of retaining an empty internal exception class. Preserve recognisable lexer/reader responsibilities and internal array representation. `MySqlParser` converts its output into the shared model. |
| [calebdw/pg-schema-parser](https://github.com/calebdw/pg-schema-parser), `master` | Adapt `PgDumpParser.php` directly into `PostgresParser.php`. Move the three `Internal/` helpers under `Postgres/`. Adapt `Database`, `Table`, `Column`, `TypeRef` and `EnumType` into the shared `Schema`, `Table`, `Column`, `ColumnType` and `EnumType` classes, rather than keeping a second PostgreSQL model plus conversion pass. |
| [sad_spirit/pg_builder](https://packagist.org/packages/sad_spirit/pg_builder) | Composer dependency `^3.3.1`; `TypeNameResolver` uses its type-name parser. It is not our dump reader. Its documented server execution floor does not require a live server or exclude older type declarations from offline parsing; verify the relevant PG10 forms. |
| [bleksak/sqlite-toolkit](https://github.com/Bleksak/sqlite-toolkit) | Composer dependency `^1.0.4`; `SQLiteParser` reads its typed AST and source spans. Do not vendor its large parser implementation or its unrelated query-builder APIs. |

Use PHP `^8.4`, normal Hypervel package metadata and matching root dependency constraints. Recheck latest compatible stable dependency releases before installation; do not select a beta simply because it is newer. Both vendored sources and SQLite toolkit are MIT; `pg_builder` is BSD-2-Clause. Preserve the actual upstream copyright/license notices in the split package. Use existing package metadata conventions without copying unrelated dependencies or providers.

Add `Hypervel\\SchemaParser\\` root and split PSR-4 mappings and root `replace: hypervel/schema-parser: self.version`. Add the two external requirements through Composer at the root and to the split manifest. Root development requirements are sufficient when only this optional split package needs them. Root `composer.lock` remains untracked. Do not add this package to the standard framework metapackage merely for availability.

`bin/split.sh` discovers committed `src/*` packages automatically; no hardcoded package list is needed. Ensure the `hypervel/schema-parser` split repository is available before publishing. Verify a standalone install using only this package's manifest and declared dependencies, with no root development autoloader masking missing requirements.

### Upstream maintenance

Track both vendored repositories' `master` branches as ports into components. Record the exact fully reviewed source boundary after the initial port is verified, with mechanical source/test mappings. Keep private tracking configuration outside this public repository. Do not claim unreviewed source history has been reconciled or start an upstream-sync run as part of package implementation.

Package README order: header, canonical documentation link, `Differences From Upstream` with `iamcal/SQLParser` and `calebdw/pg-schema-parser` subheadings where public adaptations require explanation, then explicitly mapped upstream links. Explain the shared result API, deterministic qualified lookup, strict parse failures and partial-result issues. SQLite's toolkit is an external dependency, not a third vendored upstream.

Use concise comments beside substantive fixes/adaptations that an upstream sync could accidentally revert. Explain the invariant, not the conversation or a chronological bug catalogue. Mechanical namespace/type changes need no comments. Retain useful upstream explanations, update incorrect ones and remove stale descriptions of replaced behavior. Do not keep duplicate upstream model classes or dead compatibility switches for the sake of a smaller diff.

Write `src/docs/schema-parser.md` in the style of `src/docs/processes.md`, with a short introduction/index, installation, choosing a parser, inspecting tables/types/constraints/views, defaults and completeness/errors, and supported dump formats. State that temporary declarations are skipped because results describe persistent schema. Link it from `src/docs/documentation.md`. Keep detailed usage here rather than duplicating it in the package README. The existing database bug fixes do not need a Laravel porting-guide entry unless implementation establishes an actual compatibility change beyond corrected behavior.

## Shared result model

Use typed readonly result objects, PHPDoc generics for lists/maps, and PascalCase enum cases. The following table defines stored facts; constructor ordering/defaults should put required fields first and optional facts last. Mutable parsing state is private to one invocation; results are frozen once after resolution.

| Class | Facts and public helpers |
| --- | --- |
| `Schema` | Lists of `Table`, `View`, `EnumType`, `Domain`, plus schema-level `Issue`s. `table()`, `view()`, `enum()` and `domain()` accept `(string $name, ?string $schema = null)`; `isComplete()` includes table issues. |
| `Table` | Nullable schema identity, name, ordered columns/indexes/foreign keys/checks, optional comment/collation/engine, parent `TableReference`s, optional partition parent/bounds, table-level issues. `column(string $name): ?Column`, `primaryKey(): ?Index`, `isComplete(): bool`. |
| `Column` | Name, `ColumnType`, effective `nullable`, raw nullable SQL `default`, `autoIncrement`, nullable `Identity`, nullable `Generation`, raw nullable `onUpdate` expression, invisible flag, optional collation/comment. |
| `ColumnType` | Canonical name and nullable schema, ordered string modifiers, array dimensions, unsigned flag, enum/set values, optional resolved domain base `ColumnType`. Preserve declared identity; do not replace a domain name with its base type. |
| `EnumType` | Nullable schema, name, decoded labels in declaration order. |
| `Domain` | Nullable schema, name, underlying `ColumnType`, effective nullable flag, raw nullable default, checks. |
| `View` | Nullable schema, name, raw query definition, materialized flag. View column types are not inferred. |
| `Index` | Optional name, ordered `IndexColumn`s, included payload column names, optional method/type, unique/primary/constraint-backed flags, nulls-not-distinct flag, nullable `Deferral`, raw nullable predicate. |
| `IndexColumn` | Nullable column name or expression, descending flag, nullable explicit null-order flag, optional prefix length/collation/operator class. Name and expression represent alternatives, not two conflicting keys. |
| `ForeignKey` | Optional name, ordered local columns, target `TableReference`, ordered target columns, update/delete `ReferentialAction`, `ForeignKeyMatch`, nullable `Deferral`, valid/enforced flags. |
| `CheckConstraint` | Optional name, raw expression, valid/enforced/no-inherit flags. Include column-level checks in the owning table's list. |
| `TableReference` | Separate nullable schema and name. |
| `Generation` | `GenerationType` and raw expression. |
| `Issue` | Human-readable message and nullable SQL line. Ownership supplies scope; do not duplicate the same issue in both schema and table lists. |
| `ParseException` | Detected syntax failure, nullable `sqlLine` and original cause when applicable. Preserve PHP exception `getLine()` semantics. |

Enums: `Identity` has Always/ByDefault; `GenerationType` Stored/Virtual; `Deferral` Immediate/Deferred (null means not deferrable); `ForeignKeyMatch` Simple/Full/Partial; `ReferentialAction` Cascade/Restrict/SetNull/SetDefault/NoAction. Use SQL-style string values for backed enums. Do not expose meaningless combinations such as deferral on a standalone PostgreSQL index.

Index method/type names are lowercase. `constraint` is true for PostgreSQL/SQLite PRIMARY KEY and UNIQUE constraints, distinct from standalone CREATE UNIQUE INDEX. MySQL exposes every primary/unique key as a constraint, including keys created through CREATE UNIQUE INDEX; do not invent an origin distinction its dumps cannot recover.

### Identity, lookup and raw values

- Index names/schema identities without concatenating them using dots: quoted names can contain dots. Distinguish an unspecified schema from an actual schema name. Keep quoted case; normalize only dialect-defined unquoted identifiers and builtin type keywords.
- A lookup with explicit schema is exact. An unqualified lookup succeeds only for a unique name; ambiguous matches throw `InvalidArgumentException` naming the ambiguity. Missing names return null. Do not invent a `public` schema or consult application search-path configuration.
- Build table/column/type lookup indexes once. Keep declaration ordering in exposed lists. No linear rescan per property lookup.
- Default expressions, generation expressions, checks, index expressions and predicates remain SQL text; collation and operator-class names remain SQL identifier text. Enum labels and comments are decoded values. Use original source spans rather than joining tokens, which changes strings, spaces and operators.
- PHP null for a default means no expression was supplied/resolved; an explicit SQL `NULL` or `NULL::text` remains a string. A column's explicit default, including NULL, overrides a domain or inherited default. Do not add `isRequired()` based only on default presence.
- Preserve PostgreSQL declared enum/domain identities while resolving known base types and array dimensions. Exact qualified lookup prevents conflating identically named types. A qualified external type with no definition in the dump stays opaque without an issue: extension types such as vector and citext are normal input, and parsing does not promise to infer their hidden definitions. Detect genuine dependency cycles without an arbitrary fixed recursion-depth cutoff.
- Omitted referenced FK columns resolve only when the target table and its primary key are known and unambiguous; otherwise retain the unresolved fact and a scoped issue. Do not guess a target from the first matching table.

### Completeness and errors

`Schema::isComplete()` and `Table::isComplete()` mean the advertised schema facts were recovered without uncertainty at their respective scopes. Known columns remain useful when some other facts are unknown. Unsupported syntax affecting one table adds a table issue; uncertainty that prevents identifying its owner adds a schema issue. Before treating a missing column as proof, consumers require both no schema-level issues and a complete table. A missing table requires no schema-level issues, not complete metadata for every other table: a known FTS table with unknown columns does not make the relation-name inventory unknown. Consumers looking for model-backed relations check views as well as tables.

Keep named views separately from tables. Keep unsupported named table forms (SQLite virtual tables, PostgreSQL typed tables or unresolved inheritance/partition forms) with known facts and a precise issue; do not erase the relation. No query-type inference is required to expose a view definition. Unsupported statements unrelated to represented metadata, such as grants, do not make every table incomplete.

Throw `ParseException` for detected malformed supported structures, including unterminated quoted/commented spans and unbalanced table definitions. Catch only actual parser syntax exceptions, including `sad_spirit\pg_builder\exceptions\SyntaxException`; do not convert programming errors into opaque types. Preserve accurate source locations through comment processing/splitting, or report unknown location. Compute diagnostic line numbers only when needed. Reject non-text PostgreSQL archive input explicitly rather than treating it as an empty complete schema.

The parser is not a database server validator: recognizing an opaque type or ignoring a non-schema statement does not certify that the entire input can execute successfully on a particular server.

## Dialect implementation

### MySQL and MariaDB

Retain the upstream lex/walk/definition structure in a fresh internal `MySql\SqlParser` for each public call. Modernize types/names and split the exception without creating a parallel parser or preprocessing workaround. Internal index/table-props parsing remains necessary even when only some fields enter the shared model.

1. Port current master, including its UUID support. Add real dump type spellings still missing: VECTOR, GEOMCOLLECTION, INET4 and INET6. Expand SERIAL into BIGINT UNSIGNED NOT NULL AUTO_INCREMENT with a unique key. Preserve balanced opaque type names/modifiers where grammar can delimit them; a missing type must not be mistaken for a type named `NOT` or `DEFAULT`.
2. Read complete DEFAULT expressions by source span: signed/prefixed literals, bit/hex/national/charset prefixes, named expressions with arguments, balanced expressions and timestamp precision. Retain `ON UPDATE` separately. `NOT NULL DEFAULT 'NULL'` remains non-null; default text never sets nullability.
3. Correct string escape decoding for backslash, NUL, backspace and Ctrl-Z, retaining the documented backslash behavior of percent/underscore escapes. Keep quote-doubling behavior and fix doubled backticks in the lexer and identifier decoder. Support digit-leading and dollar-leading identifiers, including qualified names, without merging decimal/exponent literals with following aliases. Tokenize signs as operators; the expression reader preserves signed defaults by source span.
4. Extract generated expressions and STORED/VIRTUAL kind, AUTO_INCREMENT, explicit PK membership, invisible columns, comments and collations. Primary-key columns are effectively non-null. Never infer PHP types from these facts.
5. Treat executable `/*! ... */` and `/*M! ... */` fragments as source-dump SQL, including split CREATE/DEFINER/VIEW fragments and invisible/check modifiers. Exclude the specific MariaDB sandbox client directive. Ordinary comments stay comments. This does not emulate executing the dump against a different server version.
6. Honour `DELIMITER` outside quotes/comments so routines and triggers are single statements. A CREATE TABLE inside a routine body is not a schema table. Preserve raw source locations while handling executable comments. Track the two quote flags controlled by `NO_BACKSLASH_ESCAPES` and `ANSI_QUOTES` (`ANSI` also enables identifier quotes). Apply native top-level SET forms with or without executable-comment wrappers at statement boundaries within the existing scan: literal mode lists, `@variable = @@sql_mode` saves, restores from saved variables, and combined header/footer assignments. Keep saved values parse-local rather than assuming a restore always enables escapes or disables identifier quotes. Each statement's scanning and literal/identifier decoding use its own mode; SET text inside routines does not change the reader's mode. Do not add a general SQL expression evaluator. Cover NBE and ANSI_QUOTES routines plus restoration before a following table declaration with one focused provider; use unwrapped SET statements in its restore case.
7. Read ordinary, unique, primary, fulltext/spatial, VECTOR KEY/INDEX and expression indexes. Preserve prefix length, order, names and predicates where the dialect supplies them. Retain check expressions/names and enforcement status. Normalize keyword case without changing identifiers or literals. Accept only the unambiguous compound vector-key forms, which dumps and the framework emit; document them for handwritten MariaDB SQL so MySQL columns named `vector` remain valid. Consume index visibility/ignored flags and physical tuning options without adding fields for unrepresented facts. Support standalone CREATE INDEX with OR REPLACE or IF NOT EXISTS, matching declared index names case-insensitively within their table; unresolved server-generated names produce a table issue rather than a guessed replacement. Unrecognized index declarations cannot silently leave the schema complete.
8. Fix FK constraint-name loss, qualified target identity, and the ON UPDATE branch incorrectly overwriting ON DELETE. Process actions independently and preserve MATCH syntax without claiming MySQL enforces it like PostgreSQL.
9. Preserve qualified table identities without dotted-string key collisions. Handle dump DROP/CREATE replacement and view stand-ins so final views do not remain phantom ordinary tables. Preserve an existing view for IF NOT EXISTS. View query text excludes trailing WITH [LOCAL|CASCADED] CHECK OPTION. Named `CREATE TABLE ... LIKE/SELECT` forms that cannot establish a full definition remain explicitly incomplete rather than empty complete tables; SELECT without explicit columns produces one issue for that uncertainty.
10. Read table options past physical settings so subsequent ENGINE, COMMENT and COLLATE facts are not lost. Keep explicit option cases first, including their optional-equals forms and retained raw fields. Use one private helper across table, column and index option loops for a bare/quoted NAME, equals sign and one scalar token; stop on complex values and reject missing operands. Handle TABLESPACE with optional STORAGE, SRID, bare COMPRESSED and other no-equals forms explicitly. An unrecognized table tail adds one scoped issue; a terminal PARTITION clause alone does not, because physical partition layout is not represented. Preserve the existing SELECT-specific issue.
11. Recognize MariaDB PERIOD FOR definitions and AS ROW START/END without inventing columns or ordinary generated expressions. Report scoped uncertainty for temporal semantics and WITH SYSTEM VERSIONING, including implicit columns. Preserve a WITHOUT OVERLAPS key part as its exact SQL expression with no plain column name. Do not add a temporal model or duplicate the same uncertainty for each row-start/end column.
12. Remove permissive parse-error behavior from the public path. Validate required operands at recognized USE, column, index and FK-action branches; malformed input must produce ParseException with an accurate or unknown SQL location, not warnings, TypeError or enum ValueError. Remove the duplicate type-specific charset/collation helpers and use the validated column-option loop. Do not turn this into a general SQL validator. Keep applicable upstream tests, adding cases to existing providers and focused native temporal/vector regressions rather than a dialect/option cross-product.

### PostgreSQL

Adapt `PgDumpParser` to build the common model using its scanner/splitter and parse-local `TypeNameResolver`. The external pg_builder dependency interprets type declarations; it is not a substitute for the dump-reader layer.

1. Preserve quotes, dollar-quoted routine bodies, nested comments and statement boundaries. Strip/skip psql meta-commands without consuming later SQL. Detect unterminated constructs and retain diagnostic positions.
2. Remove hardcoded `public` substitution from table/type/ALTER identities. Dumps normally qualify user-defined types and tables; preserve unqualified identities without guessing connection search paths. Do not port the incorrect `isBuiltin()` rule that treats every unqualified type as builtin.
3. Parse table columns and inline/table primary keys, including effective not-null membership. Read separate ALTER defaults and identity declarations; pg_dump emits identity with `ALTER TABLE ... ALTER COLUMN ... ADD GENERATED ... AS IDENTITY`, not only inline syntax.
4. Resolve enum labels and domain chains, including domain NOT NULL and defaults. Preserve explicit column DEFAULT NULL overrides. Retain opaque raw type text on genuinely unrecognised type syntax; do not lowercase quoted user types.
5. Resolve INHERITS from parent state at child creation, maintaining inherited column order and redeclared positions, then apply later per-table ALTER statements with ONLY semantics. Merge inherited not-null, generated and inheritable check facts appropriately. Parent identity/primary/unique/FK constraints are not inherited. Serial nextval defaults differ from identity generation. Missing parents produce scoped uncertainty, not guessed columns.
6. Preserve local default precedence, multiple-parent conflicts and child-specific ALTER default/nullability changes. Read the real pg_dump sequence, including SET DEFAULT NULL used to cancel inherited defaults. Avoid copying a parent's final later state retroactively into its children.
7. Read CREATE INDEX (including ON ONLY) and ALTER constraint forms, including expression keys, INCLUDE payload columns, predicates, collations/operator classes/null ordering, deferral, validation/enforcement and unique NULLS NOT DISTINCT. For equivalent DDL using ADD CONSTRAINT USING INDEX, merge the existing index into one constraint-backed result rather than duplicating it. Recognize ALTER INDEX ATTACH PARTITION without duplicating an index or producing an issue: index attachment topology is not part of the represented model.
8. Preserve checks and FK references/actions/match modes, including NOT VALID and NO INHERIT semantics. PG18 NOT VALID NOT NULL yields conservative `nullable = true` plus a precise table issue: new writes are checked, existing rows are unverified. No dedicated not-null object is needed for this exception to a boolean guarantee.
9. Handle PostgreSQL 18 VIRTUAL generated columns, including default virtual kind when STORED is omitted, and named not-null constraints. Retain supported PostgreSQL 10/11 declarations without referencing later server catalog columns in framework integration.
10. Read view/materialized-view definitions, explicit partition relationships/bounds and ordinary partition-child table definitions from pg_dump. Exclude the trailing WITH [LOCAL|CASCADED] CHECK OPTION from view query text, including the native pg_dump form. Distinguish INHERITS from partition attachment. Typed tables/partition forms whose columns cannot be recovered retain their identity and issues.
11. Read COMMENT ON TABLE/COLUMN statements, including IS NULL removal, into the corresponding comment fields. Preserve column COLLATE declarations. Collation and operator-class strings retain their SQL identifier spelling, including qualification/quoting where supplied, so quoted dots and case are not lost or silently conflated.

### SQLite

Use `bleksak/sqlite-toolkit\Parser` through the public adapter; do not copy its AST or state machine. Map typed CREATE TABLE/INDEX/VIEW nodes and source spans into the shared objects. Keep parser-only dependencies optional for apps by keeping them in this split package.

- Support `.schema --indent` quoted identifiers, comments, semicolons inside literals/triggers, multi-statement schema output, generated columns, expression/partial indexes, inline/table checks and FK declarations.
- Determine PK/nullability from NOT NULL, STRICT, WITHOUT ROWID and rowid-alias rules. A plain noninteger PRIMARY KEY can be nullable; INTEGER PRIMARY KEY aliases rowid except the inline PRIMARY KEY DESC form. Table-level descending PK syntax is not that exception.
- Expose generated stored/virtual kind, column COLLATE declarations and raw defaults, preserving quoted literals and DEFAULT NULL. Preserve FK actions/deferral and written MATCH mode while documenting SQLite's effective MATCH behavior.
- Preserve declared type information; SQLite affinity does not guarantee stored values have one PHP type. Do not simulate CHECK evaluation or SQLite storage classes.
- Views remain view definitions; virtual tables remain known incomplete tables unless their metadata can be recovered correctly from the supported AST. Framework schema dumps already remove SQLite internal/shadow tables.
- Synchronous parsing has no coroutine yield. The dependency reuses one expression-state object, resets it for the next parse and releases it after success. A failed parse can retain its last partial graph, not cumulative graphs; do not invent an external cleanup workaround on that evidence. Verify failure-followed-by-success behavior.

## Lifetime and performance

Public parser services retain no SQL, tokens, mutable AST, result builders or input-keyed caches between calls, so normal container auto-singleton resolution is safe. Internal mutable readers are constructed for an invocation; no container bindings or framework lifetime marker is needed for private construction.

Memoize repeated PostgreSQL type declarations only within the parse. Resolve dependencies using indexed identities, with bounded traversal by the actual definitions. Accumulate mutable table state and freeze once. Avoid copying an entire readonly table for each ALTER/column, repeatedly scanning all tables for lookups, or retaining raw dump text on result objects when source fragments suffice. Do not add persistent result caching, locks, background processes or a streaming API without a verified need. Caller-owned caching remains straightforward because parsing depends on input SQL and the selected parser implementation.

Review large-input loops for repeated scans, quadratic copying and unbounded retained state. Large deterministic fixtures can verify result correctness without timing assertions. Any actual performance benchmark requires fresh owner confirmation that the machine is idle.

## Adjacent database correctness fixes

These ship in the same PR. Preserve existing Laravel-style method signatures, extension points and metadata result shapes; do not describe corrected wrong output as an intentional API departure.

| Owning files | Correction and evidence |
| --- | --- |
| `src/database/src/Schema/Grammars/PostgresGrammar.php`, `Query/Processors/PostgresProcessor.php` | Include `attidentity` in column metadata and treat identity columns as auto-increment even without a nextval default. PostgreSQL's identity default does not live in pg_attrdef. Remove the dead pre-9.1 collation branch while modifying compileColumns; retain the pre-12 generated-column branch because PG10/11 remain supported. |
| PostgreSQL `compileIndexes()` | Restrict aggregated keys to `indnkeyatts` for PG11+, so INCLUDE payloads are not reported as key columns. Use a server-version-dependent SQL shape: PG10 has no indnkeyatts column, and a SQL CASE cannot hide a nonexistent column. |
| PostgreSQL and MySQL `compileIndexes()` | Return a column-name list only when every key is a simple column. Mixed `(a, lower(b))` indexes currently lose the expression and make `hasIndex(table, ['a'], 'unique') incorrectly true. Use the existing SQLite grammar's count-equality guard; processors already map null lists to `[]`. Index-name lookup still works; no new metadata field or extra query is required. MariaDB inherits the MySQL grammar. |
| PostgreSQL `compileUnique()` | Preserve requested NULLS [NOT] DISTINCT in the CREATE UNIQUE INDEX branch used by online/algorithm before attaching the constraint. The current branch computes the flag but drops it. Preserve its existing deferrable suffix on ADD CONSTRAINT USING INDEX. |
| `src/database/src/Schema/PostgresSchemaState.php` | Use the quoted `HYPERVEL_LOAD_PATH` placeholder for both dump redirections. Quote schema and prefixed table separately inside `getMigrationTable()`, doubling embedded quotes before joining, then pass that selector through a quoted placeholder to pg_dump. This preserves case, literal pattern characters and dots within identity parts. Keep the protected signature and override dispatch. Cover both command paths and selector values offline; native quoting behavior is verified separately. For plain SQL loads, forward only psql stderr through the existing output handler and process-output guard. Preserve native continuation and exit status: valid reloads encounter existing-schema and older-server setting errors, so do not enable ON_ERROR_STOP. A live regression verifies an existing-schema diagnostic reaches the handler while a following CREATE succeeds; successful stdout stays quiet. |
| `src/database/src/Schema/MySqlSchemaState.php` | Use `run()` and its success result so callback/start/timeout failures propagate without being caught. On a failed exit, read stderr once and match the exact `unknown variable '<argument>'` diagnostic for each owned flag (`--column-statistics=0`, `--set-gtid-purged=OFF`). Remove all diagnosed flags in one pass and retry through `makeProcess()` only if the command changed; otherwise throw `ProcessFailedException`. Never match exception text containing the command itself. Retain the protected depth parameter/guard. Test both removals together, one removal with the other flag retained, unrelated failure, and an already-removed flag; keep callback-stop coverage. Pass the migration table through a quoted placeholder in the append command and test that actual command path through the existing factory seam. |
| `src/database/src/Schema/SqliteSchemaState.php` | The migration table is exposed to shell expansion and LIKE wildcards. Escape backslash/percent/underscore for SQLite's LIKE pattern, then escape backslash/double quote for its double-quoted dot-command argument, using two separate `strtr()` calls. Pass the entire `.dump ...` argument through one quoted placeholder; retain `getMigrationTable()` and existing output filtering. One native regression in the existing SQLite schema-state integration test uses a name containing underscore, dollar and double quote plus a matching pattern-neighbor table, and asserts only the intended migration rows are appended. These quoting rules work at the supported SQLite floor without new CLI options. |
| MySQL/MariaDB counter cleanup | Preserve `removeAutoIncrementingState()` and a single native dump. Outside DELIMITER regions, remove counters only from native closing table-options lines, allowing the TABLESPACE clause before ENGINE. Preserve authored regions unchanged using one protected character-scan helper that respects quotes/doubling, mode-dependent backslashes, ordinary comments and executable wrappers. Read every statement-boundary DELIMITER directive: Oracle and older MariaDB clients repeat DELIMITER ;; across a multi-statement event region; fixed MariaDB clients use one routine-style region per event. End only at the actual semicolon reset. Native literal SQL-mode assignments before each authored body supply the helper's two quote flags, including assignments within event regions; it needs no general save/restore map, tokens, AST or table parser. Recognize SET statements with or without executable-comment wrappers. Missing terminators fail explicitly. One exact-output unit fixture covers escaped literals, TABLESPACE, routine seeds and embedded reset text, documentation comments, two native events with a repeated directive and an NBE event/routine followed by a real table counter. Use an unwrapped mode assignment for that fixture's NBE routine. Identify the fixture as adapted native output; one short comment beside its reader should explain that this assignment covers MariaDB's MDEV-30295 format and must survive regeneration. Carry a literal through live dump/parser coverage. No extra dump process or parser-package dependency. |
| MySQL/MariaDB schema-state connection options | Match requested TLS encryption and verification separately, including CA-directory/cipher options and PHP trust precedence. The current verify-false branch disables encryption even when TLS material is supplied, while modern MariaDB clients unexpectedly require verified TLS for plaintext PDO configurations. Apply the mapping below to both dump and load. |

For index aggregation, exclude included payloads before counting keys, so expression detection and INCLUDE exclusion work together. Do not change the already-correct SQLite expression-index implementation. Partial indexes already expose their predicate status; they are not the same defect.

Trace these changes through `Schema::getColumns()`, `getIndexes()`, `hasIndex()` and the related schema builder paths. Check relevant framework, package and application consumers under the root instructions. Fix any further verified adjacent defects in this PR; do not broaden into unrelated preference-driven refactoring.

### Dump/load TLS mapping

Owner-approved compatibility changes: refuse requested TLS with verification disabled when using a MariaDB command-line client; require the MySQL command-line client distributed by Oracle to be 5.7.11+ for requested TLS, using its native `--ssl-mode` support rather than adding support branches for older clients. These are client-tool restrictions, not changes to supported database server versions.

Determine whether TLS is requested from the PDO KEY, CERT, CA, CAPATH or CIPHER options before adding trust-store defaults. INI trust settings alone do not enable TLS in PDO. Use one protected helper on `MySqlSchemaState` to resolve effective TLS options for `connectionString()` and `baseVariables()`, without a resolver class, cache or extra connection.

| Connection intent | Oracle command-line client | MariaDB command-line client |
| --- | --- | --- |
| No TLS material; verification unset/true | Preserve existing client default, with no TLS mode flag. | `--ssl=off`, matching the plaintext PDO connection. |
| No TLS material; verification false | Preserve `--ssl-mode=DISABLED` for modern clients and existing `--ssl=off` compatibility for older clients. | `--ssl=off`. |
| TLS material; verification false | `--ssl-mode=REQUIRED`; forward key/cert/cipher, omit unused CA/CAPATH. | Refuse before launching the dump/load process with an actionable message explaining that this client cannot require encryption without verification. |
| TLS material; verification unset/true | `--ssl-mode=VERIFY_IDENTITY`, forwarding effective trust paths and key/cert/cipher. | `--ssl --ssl-verify-server-cert`, forwarding the same effective trust paths and key/cert/cipher. |

Resolve each trust input independently: connection CA then `openssl.cafile`; connection CAPATH then `openssl.capath`. Only if neither is configured through connection options or INI, use `openssl_get_cert_locations()` defaults with their named environment overrides, passing existing default paths. Do not combine system defaults with explicitly selected trust. Do not silently replace invalid explicit paths or weaken verification when no usable default path exists; retain the strict mode and let the native client report its error. Use existing quoted environment placeholders, adding CA-directory and cipher variables. No custom trust-store discovery, bundle copy or optional-dependency shim is needed.

MariaDB's verified limitation is specific: `--ssl --skip-ssl-verify-server-cert` can still connect in plaintext to a server without TLS. The framework cannot rely on a prior PDO handshake: load() starts the independent client directly, and an earlier successful handshake would not secure this later session anyway. Do not use a preflight connection, post-connect cipher check or forced verification contrary to the caller's setting. The error should recommend enabling verification with valid CA/CAPATH configuration; only suggest an Oracle client where the actual command path supports using one.

Use focused provider cases in the existing MySQL/MariaDB schema-state tests: plaintext/default and explicit false; TLS with false; explicit CA and CAPATH; key/cert/cipher; environment default trust and absent default paths; and strict verification flags. Restore environment mutations. Cover INI precedence with one PHP subprocess using `-d` because those directives cannot be changed with `ini_set()`. Include load-path policy and refusal-before-process assertions; do not create a full client/configuration cross-product. Explain the supported CLI requirements in the existing schema dump documentation and, for the approved behavior restriction, the Laravel porting guide/package differences section as appropriate.

Keep live verified-TLS success and failure regression coverage in one existing MySQL 8.4 CI job, using its actual installed MariaDB client. Add one named volume mounted at the same fixture path in the job and MySQL service containers. In a dedicated serial `SchemaStateTlsTest` under the existing database integration tests, generate a short-lived test CA/server certificate with SAN matching the configured service hostname and an unrelated CA for rejection cases. Write them into that shared fixture directory with directory traversal/file readability for the server's mysql user, including readable permissions on the throwaway test key. Set the server's TLS file variables and run `ALTER INSTANCE RELOAD TLS`; reload failure must fail the test. Save and restore the changed server settings and remove owned fixture files through exception-safe cleanup. This needs no image variant, service restart, custom client wrapper or new test framework. Do not apply it to MySQL 5.7, which lacks TLS reload.

Run this serial TLS test after the normal database suites, with its explicit fixture-directory environment setting supplied only to that step. Other runs skip this opt-in server-global test; they do not race a ParaTest worker changing TLS configuration. Document the fixture-directory setting in the existing environment examples. Verify successful real dump and load with the trusted CA, and certificate-verification rejection for both operations with the unrelated CA. Assert the native certificate-verification error, not merely a nonzero exit that could hide an unreadable path or unavailable server. The positive and negative cases together prove verified encryption and detect disabled TLS/verification; no global secure-transport toggle or separate session-status inspection is needed. MariaDB's unsupported verification-disabled combination remains a focused refusal-before-launch unit regression.

Oracle/MySQL client REQUIRED and VERIFY_IDENTITY branches keep persistent provider-test coverage. Also verify the generated options with the native client from a matching official MySQL container during implementation: encrypted success with verification disabled and refusal against a TLS-disabled endpoint. These focused live checks are implementation evidence, not a claim that the MariaDB-only CI image executes Oracle flags. Do not introduce another image/client matrix solely for them. The normal parser live suites already regress the no-TLS-options path against both server families, where the MariaDB 11.4+ client otherwise fails because of its changed TLS default.

## Tests and verification

### Upstream test mapping

Copy source/tests before adapting them; keep every applicable upstream test. Keep test-file mapping recognisable for future syncs. Correct wrong expected values where verified bug fixes require it, with regression assertions for the underlying behavior.

- iamcal `tests/{Collapse,Field,Full,Index,Invalid,Lex,Odds,TableProps,Tables}Test.php` → `tests/SchemaParser/MySql/` with the same filenames. Copy referenced `data/` fixtures into the package's `Fixtures/` tree and update paths. These exercise the internal tracked reader as well as public-adapter regressions where appropriate.
- PostgreSQL `tests/Unit/{AllTypesSchema,PgDumpParser,SqlScanner}Test.php` → `tests/SchemaParser/Postgres/`. Adapt parser/model imports and assertions without maintaining duplicate model classes.
- PostgreSQL `tests/data/{all-types-schema,all-types-source}.sql` → `tests/SchemaParser/Fixtures/Postgres/` with the same filenames; update their readers and retain both the generated dump and its reproducible source.
- PostgreSQL `tests/Concerns/AssertsSchema.php` → the package test-support `Fixtures/` tree when still useful; keep it only if shared assertions remain meaningful.
- PostgreSQL `tests/Integration/RealDumpTest.php` → a fixture-backed `RealDumpTest` in `tests/SchemaParser/Postgres/`, reading committed representative dumps instead of an optional environment variable that silently skips coverage. Actual live dump tests are separate below.
- New common-model/public-adapter tests cover deterministic identity lookup, ambiguity, complete/partial/error results, and mappings not exercised through the upstream reader tests. Do not test PHP's readonly enforcement, mirror constructors or add duplicate tests merely to increase counts. Only test freezing behavior if our own builder/resolution logic adds behavior beyond readonly.
- Use `Hypervel\Tests\TestCase` for offline tests and the established Testbench/database base for live tests. No instruction-file exception or custom base class is needed merely for coroutine support.

### Behavior coverage

| Flow | Required assertions |
| --- | --- |
| Common results | Qualified and quoted/dotted names, missing versus ambiguous identity, ordered columns/keys, explicit NULL versus absent default, retained declared/resolved types, scoped issues without losing known facts. |
| MySQL/MariaDB | Every verified type/default/escape/identifier/generated/FK/index defect; executable-comment fragments, routine DELIMITER boundaries, no phantom routine tables, view stand-ins, expression indexes, decoded enum/set labels, malformed input. |
| PostgreSQL | Real ALTER identity output; inherited column order/local overrides, multiple parents, serial versus identity, NO INHERIT, explicit NULL domain overrides, qualified enums/domains/arrays, PK nullability, PG18 generated/constraint forms, indexes and USING INDEX attachment. |
| SQLite | Rowid/PK DESC, STRICT/WITHOUT ROWID, nullable ordinary PK, raw/default/generated expressions, indexes/FKs/checks/views, virtual-table issue, quoted names and semicolons in trigger bodies. |
| Reuse/errors | Two distinct dumps on the same parser, malformed then valid input, source lines across comments, no contamination, deterministic completeness. No synthetic yield hook in the CPU-only parsing loop. |
| Framework fixes | Identity and serial metadata, INCLUDE key exclusion, mixed/all-expression index lookup by name versus complete column list, ordinary indexes unaffected, PG10 query compatibility, NULLS NOT DISTINCT with online/algorithm and deferral. |

### Live engines

Add focused suites in `tests/Integration/SchemaParser/Database/{MySql,MariaDb,Postgres,Sqlite}/`. `bin/run-database-tests.sh` already discovers these paths; preserve that mechanism. Use existing database CI jobs rather than creating a parser-specific engine/version matrix.

Each suite creates representative schema objects, generates a real schema dump through the framework schema-state API, parses it and compares represented facts with engine metadata. Where framework introspection omits a fact or contains a known defect, use the engine catalog as the independent oracle. Assert dialect normalization deliberately; do not compare arbitrary raw expression formatting byte-for-byte against server-normalized text.

Use the existing isolated per-worker test database. Dump tools run in another connection, so schema setup must be committed: use the database-migration/fresh-schema pattern rather than a RefreshDatabase transaction invisible to the external process. SQLite's existing CI job supplies `/tmp/testing.sqlite`, and `TestDatabases` creates worker-specific files. Follow the existing SQLite schema-state test setup; skip dump execution only for an explicitly configured in-memory SQLite connection. Do not override the managed connection with a second isolation mechanism. Dump to `ParallelTesting::tempDir(...)`, call `withMigrationTable(null)` to avoid irrelevant migration data and clean up owned schema/files through teardown. Reuse Testbench environment and connection configuration instead of reading secrets into logs.

Provision dump clients once in `.github/docker/ci/Dockerfile`: retain sqlite3, install Debian `mariadb-client-compat` (including the mysql/mysqldump compatibility commands and mariadb/mariadb-dump), and install `postgresql-client-18` from the official signed PGDG repository. PG18's client can dump the supported older servers; Debian's default PG17 client cannot dump the PG18 CI server. Do not install conflicting Oracle and MariaDB clients together or create per-dialect image variants. Validate the executables in the image and arrange the existing CI-image build before expecting parser integration checks to pass; the current workflow otherwise builds changed images only after a push to 0.4 or manual dispatch.

The live MySQL suite exercises the MySQL server through the compatible MariaDB dump client; committed fixtures also cover Oracle mysqldump output from supported MySQL versions. Generate those fixtures using the matching official server images' bundled clients, without adding another persistent CI matrix. Once a test engine is configured, a missing required dump binary fails clearly rather than silently skipping; this rule needs no CI-environment detection. Failed configured connections/dumps also fail. Bound external processes and overall test execution. Never dump an unrelated developer database.

Use version-specific assertions for features absent at the supported floor: MySQL expression indexes/enforcement and PG15 nulls-not-distinct/PG18 constraints. Keep committed real-dump fixtures for format variants that the local server cannot generate. No running server is needed for the normal offline suite.

### Check commands and completion evidence

Run from the components-parser root. Run each changed/new test file immediately during implementation. At each scheduled checkpoint, finish formatting, static analysis and remaining targeted tests sequentially; reuse passing results unless subsequent changes invalidate them.

```sh
composer lint:fix
composer analyse
composer test:parallel -- --processes=4 tests/SchemaParser
./vendor/bin/phpunit --no-progress tests/Composer/PackageManifestConsistencyTest.php
```

Root formatting/analysis scripts also invoke the base skeleton's tooling: install its own dependencies if needed, without sharing vendor/autoload/runtime directories with another worktree. Add targeted database tests for modified grammars/processors and live parser suites under the appropriate `DB_CONNECTION`. Do not run full repository suites merely because the package is new; use existing CI for the broad matrix. Do not run PHPStan on tests or suppress source defects to make checks pass.

Verify root/split Composer metadata, standalone package installation and parsing, supported minimum PHP, and all four live engine paths. No benchmark or blanket cache-clearing requirement is introduced.

## Implementation order

These groups define implementation order, not fixed review boundaries. Aim for roughly two reviews of complete related flows; the orchestration document governs the actual checkpoints.

1. **Shared model and MySQL/MariaDB flow:** package metadata/licenses, shared contract/results and internal reader port with its complete public adapter, applicable upstream tests, meaningful regressions and live dump coverage. Add each remaining dialect only as a complete implementation; do not introduce placeholder production APIs.
2. **PostgreSQL flow and adjacent database fixes:** shared-model reader port, all agreed domain/inheritance/constraint semantics, upstream/fixture/live coverage, grammar/processor fixes and affected consumer regression checks.
3. **SQLite and complete integration:** AST adapter, SQLite semantics and fixture/live coverage, complete public documentation/README, final split/standalone/CI verification and cleanup. Review interactions across all dialects and the common model, not just the final diff.

At each checkpoint: full self-review through callers/callees and supported use outside the diff; then peer code review until signoff. Resolve substantive unexpected issues through a consolidated evidence-backed second-opinion request before implementing their fix. Routine obvious low-risk fixes do not need a separate loop. Commit coherent whole files under the owner's approved workflow, with detailed bodies; publish only with the applicable owner authorization. The deliverable is one complete package PR, not phased feature delivery.

After implementation, remove superseded code, stale comments/docs, scratch resources and temporary fixtures not needed as retained tests. Document the supported API as the final design, without migration history or agent discussion. Merge the completed parser PR into `0.4` before resuming PHPStan integration. No PHPStan branch code is copied into this worktree.

## References for implementation and review

- Framework conventions and consumers: `AGENTS.md`, `src/database/README.md`, `src/database/src/Schema/Builder.php`, `Schema/Grammars/{MySql,Postgres,SQLite}Grammar.php`, `Query/Processors/{MySql,Postgres,SQLite}Processor.php`, `Schema/*SchemaState.php`, `tests/Integration/Database/SchemaBuilderTest.php` and `bin/run-database-tests.sh`.
- Public package conventions: `src/permission/{composer.json,README.md,LICENSE.md}`; user-doc prose: `src/docs/processes.md`.
- [PostgreSQL CREATE TABLE](https://www.postgresql.org/docs/18/sql-createtable.html), [CREATE DOMAIN](https://www.postgresql.org/docs/18/sql-createdomain.html), [ALTER TABLE](https://www.postgresql.org/docs/18/sql-altertable.html): inheritance, identity/defaults and constraint semantics.
- [PostgreSQL 10 pg_index](https://www.postgresql.org/docs/10/catalog-pg-index.html) and [PostgreSQL 11 pg_index](https://www.postgresql.org/docs/11/catalog-pg-index.html): INCLUDE boundary and expression-key representation.
- [MySQL string literals](https://dev.mysql.com/doc/refman/8.4/en/string-literals.html), [MariaDB executable comments](https://mariadb.com/docs/server/reference/sql-statements/comment-syntax), [SQLite CREATE TABLE](https://www.sqlite.org/lang_createtable.html): lexical and constraint rules.

Load-bearing reproductions to retain as tests: a domain with DEFAULT 'domain-value' overridden by column DEFAULT NULL retains a pg_attrdef expression NULL::text; a unique `(a, lower(b))` index permits two equal `a` values with distinct `b`; an INCLUDE payload is not part of the indexed key; pg_dump identity declarations are separate ALTER statements. These prevent attractive but incorrect simplifications of the model and framework fixes.
