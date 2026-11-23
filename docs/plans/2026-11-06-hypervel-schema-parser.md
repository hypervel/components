# Hypervel schema parser

## Status and objective

Create `hypervel/schema-parser` in `src/schema-parser` on `feature/schema-parser`, as one PR into `0.4`. It reads MySQL, MariaDB, PostgreSQL and SQLite schema dumps, without a database connection, into one typed schema model for applications, generators and analysis tools. The PHPStan work resumes after this merges and consumes the package. `claude-stan` implements; `codex-stan` gives second opinions, reviews, and commits and pushes signed-off checkpoints.

Write it as Taylor would write a first-party Laravel package, then port and enhance it for Hypervel: Laravel-style names, APIs and structure; complete features without unnecessary machinery; high performance.

Implementation complete: the result model, adjacent database fixes and their consumers, CI dump clients, serial TLS job, shared parsing base, and first-party PostgreSQL, MySQL, MariaDB and SQLite parsers. Package documentation, licensing, standalone installation, test consolidation, performance review and disposable-resource cleanup are complete. The vendored readers and their dependencies are removed. The owner approved the recorded PostgreSQL performance tradeoff. Next: PR review and merge into `0.4`, before resuming the PHPStan package work.

Interpret the SQL actually present. Never invent catalog facts a dump lacks, modify native clients, work around the dump clients' event-delimiter defect or the PostgreSQL dump fidelity gaps (#19773 and #5555), or target unreleased fixes.

## Package boundary

- `MySqlParser`, `MariaDbParser`, `PostgresParser` (plain-text pg_dump output) and `SQLiteParser` match the `mysql`, `mariadb`, `pgsql` and `sqlite` drivers. Callers choose explicitly; the package never guesses a dialect from SQL contents.
- Every parser implements `Hypervel\SchemaParser\Contracts\Parser::parse(string $sql): Schema`.
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

## Architecture

```text
src/schema-parser/src/
    Contracts/Parser.php
    Parser.php                 abstract base implementing the contract
    MySqlParser.php
    MariaDbParser.php          extends MySqlParser
    PostgresParser.php
    SQLiteParser.php
    Parsing/                   @internal
        Lexer.php              abstract
        MySqlLexer.php
        PostgresLexer.php
        SQLiteLexer.php
        TokenStream.php
        TokenType.php
        ParseState.php
        TableDefinition.php
        ColumnDefinition.php
    Schema.php, Table.php, Column.php, ...   result classes
tests/SchemaParser/
    public parser tests and SchemaTest.php (see Offline tests)
    Parsing/                   lexer tests
    Fixtures/
tests/Integration/SchemaParser/Database/{MySql,MariaDb,Postgres,Sqlite}/
```

This follows Laravel's grammar/processor pattern: an abstract base and one class per driver, with `MariaDb*` extending `MySql*`. Laravel grammars compile objects into SQL; classes that read text are parsers (`Console\Parser`, `ValidationRuleParser`). Class names use `SQLite` like the framework grammars; `bin/run-database-tests.sh` requires the `Sqlite` integration directory. Add classes only for a demonstrated responsibility.

- **Stateless parsers.** Parser instances hold no state, so container auto-singletons are safe. `parse()` creates the lexer and `ParseState`; protected methods receive `(TokenStream $tokens, ParseState $state)` as grammar methods receive `$blueprint`. No container bindings, lifetime markers or static caches.
- **Flow.** `parse()` loops over `$lexer->nextStatement()`, calls `parseStatement()`, then `$tokens->skipRest()`, and freezes the state into a `Schema`. A dialect's `parseStatement()` handles its own statements and falls back to the parent.
- **Base parser.** Owns syntax every dialect shares:
  - names, qualified names and name lists;
  - the table-body loop that tells columns from table constraints;
  - the column outline: name, dialect `parseType()`, then a constraint loop;
  - `NULL`/`NOT NULL`, `DEFAULT`, `CHECK`, `PRIMARY KEY`/`UNIQUE`, `COLLATE` and `GENERATED ALWAYS AS`;
  - `REFERENCES` with target columns, `MATCH` and `ON DELETE`/`ON UPDATE`;
  - key and index column lists, and the `CREATE INDEX` and `CREATE VIEW` outlines;
  - issue and error helpers.

  Dialects override hooks only where syntax or meaning differs. There is no universal grammar with flags for every quirk.
- **Lexers.** One lexer per dialect over the original source. The lexer owns statement boundaries, including MySQL `DELIMITER`, PostgreSQL COPY data and meta-commands, and SQLite trigger bodies. The base handles shared whitespace, comments, quoting, numbers, words and symbols; dialect lexers override what differs. Reading and skipping use the same scanning primitives. Offsets always refer to the original source, never a reconstructed statement buffer.
  - Each lexer reads a run of ordinary tokens with one native match through `Lexer::matchRun()`: an upper-case copy of at most 8 KB of source, cut before whitespace, a comma or a parenthesis so no unquoted token is split. The first token is matched alone, so a token that needs the scanner costs no full-window match. Pattern repetitions are bounded to stay within PCRE's match and compiled-size limits (PCRE copies a bounded group once per repetition), and a PCRE failure throws. Tokens that need boundary handling (comments other than plain line comments, executable comments, PostgreSQL continued strings and dollar quotes, quoted tokens cut by the window or past a bound) go through the scanner. Each dialect keeps its own `read()` loop for the types its runs record.
  - MySQL runs only under the default `;` delimiter and not directly after a qualifying dot, where a digit-leading name follows; one pattern per string mode (backslash escapes, `NO_BACKSLASH_ESCAPES`, `ANSI_QUOTES`). A `--` comment needs whitespace or a control byte after it, checked by lookahead so an empty `--` comment never consumes the next line. SQLite runs only once a statement can no longer be a trigger, so trigger tracking sees every token. Runs stop after `;` in both.
  - Skipping jumps natively to the bytes that can change the boundary state, tracking parenthesis depth as psql does.
- **TokenStream.** Reads the current statement's tokens in runs, never past its terminator, so a mode change in one statement applies before the next statement is lexed. Dispatch reads only a statement's leading token. It keeps the tokens it has read, so a parser can look behind and rewind within the statement. `skipRest()` and `skipGroup()` skip unread source without creating tokens: a statement's remainder, such as a routine body or appended data, and the rest of a group whose source text is all the parser needs. Skipped source cannot be replayed, so a rewind spans only tokens that were read: `parseType()` measures a type's span with `skipExpression()`, which reads every token, to reuse an earlier interpretation.
- **Tokens.** A token is its start offset and upper-case source text. Quoted tokens keep their delimiters, so only a word can equal a keyword. Types that depend on lexer state, such as strings and the terminator, are recorded when read; other types follow from the text when first needed.
  - Keep the original spelling of names and literals, decoded from the source.
  - Decode quoted names and strings only when a consumer needs the value: names, comments, enum/set labels.
  - Mode-dependent classification (MySQL quotes and escapes, PostgreSQL `standard_conforming_strings`) is fixed when the token is produced.
- **Expressions.** Defaults, generation, checks, index keys, predicates, partition bounds and view queries stay exact source spans between token offsets. Shared primitives read balanced brackets and spans; each clause parser decides where its expression stops. No expression tree.
- **State.** One `ParseState` per parse holds definitions, identity lookups, issues and the incremental diagnostic line position, including dialect-only fields (PostgreSQL's maps, the default schema from SQLite's `main` or MySQL's `USE`, saved SQL modes) and SQLite's case-insensitive identity keys; split it only for a concrete benefit. `TableDefinition` and `ColumnDefinition` are small typed mutable definitions, frozen once into results after dependent facts resolve.

### Package metadata and documentation

- **Composer.** The split manifest requires `php: ^8.4` and `ext-mbstring` (MySQL name folding). Remove `pg_builder` and the SQLite toolkit from the root `require-dev` (`composer remove --dev`) and from the split manifest, and drop `ext-ctype` unless code still needs it. Keep the root PSR-4 mapping and `replace: hypervel/schema-parser: self.version`; the root `composer.lock` stays untracked. Don't add the package to the framework metapackage. `bin/split.sh` discovers it automatically; the split repository must exist before publishing. Verify a standalone install using only the split manifest, with no root autoloader masking missing requirements.
- **README and license.** The README has the header and documentation link only. `LICENSE.md` is Hypervel MIT plus the full MIT notices of iamcal/SQLParser and calebdw/pg-schema-parser, whose code shaped the parsers and whose fixtures the tests still use. `pg_builder` was only a Composer dependency, so it needs no notice. The cleanup grep must not remove legitimate attribution.
- **Docs.** Write `src/docs/schema-parser.md` in the style of `src/docs/processes.md` and link it from `src/docs/documentation.md`. Cover:
  - an introduction and installation;
  - choosing a parser by driver;
  - inspecting tables, types, constraints and views;
  - defaults, completeness and errors;
  - supported dumps, including that temporary declarations are skipped;
  - the PostgreSQL type-identity rule and interval fields.

## Shared result model

Use typed readonly result objects, PHPDoc generics for lists/maps, and PascalCase enum cases. The following table defines stored facts; constructor ordering/defaults should put required fields first and optional facts last.

| Class | Facts and public helpers |
| --- | --- |
| `Schema` | Lists of `Table`, `View`, `EnumType`, `Domain`, plus schema-level `Issue`s. `table()`, `view()`, `enum()` and `domain()` accept `(string $name, ?string $schema = null)`; `isComplete()` includes table issues. |
| `Table` | Nullable schema identity, name, ordered columns/indexes/foreign keys/checks, optional comment/collation/engine, parent `TableReference`s, optional partition parent/bounds, table-level issues. `column(string $name): ?Column`, `primaryKey(): ?Index`, `isComplete(): bool`. |
| `Column` | Name, `ColumnType`, column-level `nullable` guarantee, raw nullable SQL `default`, `autoIncrement`, nullable `Identity`, nullable `Generation`, raw nullable `onUpdate` expression, invisible flag, optional collation/comment. Domain NOT NULL alone does not make a column non-null. |
| `ColumnType` | Canonical name and nullable schema, ordered string modifiers, array dimensions, unsigned flag, enum/set values, optional resolved domain base `ColumnType`, and nullable lowercase PostgreSQL `intervalFields` (e.g. `day to second`) appended last to the constructor. Preserve declared identity; do not replace a domain name with its base type. |
| `EnumType` | Nullable schema, name, decoded labels in declaration order. |
| `Domain` | Nullable schema, name, underlying `ColumnType`, declared conversion nullability including scalar base-domain restrictions, raw nullable default, checks. |
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
- Statement name resolution follows the engine, while results keep declared spellings and public lookups stay exact. SQLite folds ASCII case for schema, relation, index and column names within a parse. MySQL folds column and index names with simple Unicode lowercase (`MB_CASE_LOWER_SIMPLE`), which matches MySQL and all valid MariaDB input. PostgreSQL compares exactly. Exact matches are tried first.
- Build table/column/type lookup indexes once. Keep declaration ordering in exposed lists. No linear rescan per property lookup.
- Default expressions, generation expressions, checks, index expressions and predicates remain SQL text; collation and operator-class names remain SQL identifier text. Enum labels and comments are decoded values. Never rebuild text by joining tokens, which changes strings, spaces and operators.
- PHP null for a default means no expression was supplied/resolved; an explicit SQL `NULL` or `NULL::text` remains a string. A column's explicit default, including NULL, overrides a domain or inherited default. Do not add `isRequired()` based only on default presence.
- Preserve PostgreSQL declared enum/domain identities while resolving known base types and array dimensions. Exact qualified lookup prevents conflating identically named types. A qualified external type with no definition in the dump stays opaque without an issue: extension types such as vector and citext are normal input, and parsing does not promise to infer their hidden definitions. Detect genuine dependency cycles without an arbitrary fixed recursion-depth cutoff.
- PostgreSQL SQL-standard keyword spellings name `pg_catalog` types (`bigint` → `pg_catalog.int8`, `timestamp with time zone` → `pg_catalog.timestamptz`). Unqualified identifiers keep a null schema, because `search_path` can resolve `int8` or `text` to a user type; never normalize them to `pg_catalog`. Carry `intervalFields` through every reconstruction, including scalar and array domain resolution.
- Omitted referenced FK columns resolve only when the target table and its primary key are known and unambiguous; otherwise retain the unresolved fact and a scoped issue. Do not guess a target from the first matching table.

### Completeness and errors

`Schema::isComplete()` and `Table::isComplete()` mean the advertised schema facts were recovered without uncertainty at their respective scopes. Known columns remain useful when some other facts are unknown. Unsupported syntax affecting one table adds a table issue; uncertainty that prevents identifying its owner adds a schema issue. Before treating a missing column as proof, consumers require both no schema-level issues and a complete table. A missing table requires no schema-level issues, not complete metadata for every other table: a known FTS table with unknown columns does not make the relation-name inventory unknown. Consumers looking for model-backed relations check views as well as tables.

Keep named views separately from tables. Keep unsupported named table forms (SQLite virtual tables, PostgreSQL typed tables or unresolved inheritance/partition forms) with known facts and a precise issue; do not erase the relation. No query-type inference is required to expose a view definition. Unsupported statements unrelated to represented metadata, such as grants, do not make every table incomplete.

Throw `ParseException` for detected malformed supported structures, including unterminated quoted/commented spans and unbalanced table definitions. Never catch programming errors or convert them into opaque types. Report accurate source locations through comments and executable wrappers, or an unknown location. Reject non-text PostgreSQL archive input explicitly rather than treating it as an empty complete schema.

The parser is not a database server validator: recognizing an opaque type or ignoring a non-schema statement does not certify that the entire input can execute successfully on a particular server.

## Dialect implementation

### MySQL and MariaDB

`MySqlLexer` and `MySqlParser` read mysqldump and mariadb-dump output and equivalent DDL. `MariaDbParser` adds only MariaDB-specific syntax (items 5, 7 and 11); MySQL dumps never need it.

1. **Types.** Recognize the type spellings dumps emit, including UUID, VECTOR, GEOMCOLLECTION, INET4 and INET6. Expand SERIAL into BIGINT UNSIGNED NOT NULL AUTO_INCREMENT with a unique key. Keep balanced opaque type names and modifiers where the grammar can delimit them; a missing type must not be mistaken for a type named `NOT` or `DEFAULT`.
2. **Defaults.** Read complete DEFAULT expressions by source span: signed and prefixed literals (bit, hex, national, charset), named expressions with arguments, balanced expressions and timestamp precision. Keep `ON UPDATE` separately. `NOT NULL DEFAULT 'NULL'` stays non-null; default text never sets nullability.
3. **Lexing.**
   - Decode backslash escapes including NUL, backspace and Ctrl-Z; `\%` and `\_` keep their backslash. Decode quote doubling and doubled backticks.
   - Support digit- and dollar-leading identifiers, including qualified names, without merging decimal or exponent literals with a following alias. Signs are operator tokens.
   - `#` starts a comment; `--` does so only before whitespace or a control character.
4. **Column facts.** Read generated expressions and their STORED/VIRTUAL kind, AUTO_INCREMENT, explicit PK membership, invisible columns, comments and collations. Primary-key columns are non-null. Never infer PHP types.
5. **Executable comments.** `/*! ... */` fragments are dump SQL, including split CREATE/DEFINER/VIEW fragments and invisible/check modifiers. `MariaDbParser` also reads `/*M! ... */` as SQL, except the sandbox client directive; `MySqlParser` treats `/*M!` as a comment, as MySQL servers do. Version numbers are not compared: this reads a dump's source, not execution against a chosen server version. Ordinary comments stay comments.
6. **`DELIMITER` and SQL modes.**
   - Honour `DELIMITER` outside quotes and comments, so routines, triggers and events are single statements. A CREATE TABLE inside a routine body is not a schema table.
   - Track `NO_BACKSLASH_ESCAPES` and `ANSI_QUOTES` (`ANSI` also enables identifier quotes). Apply native top-level SET forms, with or without executable wrappers, at statement boundaries: literal mode lists, `@variable = @@sql_mode` saves, restores from saved variables, and combined header/footer assignments.
   - Saved values are parse-local; never assume a restore enables escapes or disables identifier quotes. SET text inside routines doesn't change the mode. No general expression evaluator.
   - Cover NBE and ANSI_QUOTES routines, plus restoration before a following table, with one focused provider whose restore case uses unwrapped SETs.
7. **Indexes and checks.**
   - Read ordinary, unique, primary, fulltext/spatial and expression indexes, with prefix length, order, names and predicates where the dialect supplies them. Keep check expressions, names and enforcement status. Normalize keyword case without changing identifiers or literals.
   - `MariaDbParser` also reads `VECTOR KEY/INDEX`. It accepts only the unambiguous compound forms that dumps and the framework emit, so columns named `vector` stay valid; document those forms for handwritten SQL.
   - Consume index visibility/ignored flags and physical tuning options without adding fields.
   - Support standalone CREATE INDEX with OR REPLACE or IF NOT EXISTS, and DROP INDEX, matching declared index names case-insensitively within their table. An unresolved server-generated name produces a table issue, not a guessed replacement. An unrecognized index declaration never leaves the schema silently complete.
8. **Foreign keys.** Keep constraint names and qualified targets. Read ON DELETE and ON UPDATE independently. Preserve MATCH without claiming MySQL enforces it.
   - Without a constraint name, `MySqlParser` reports a null name: MySQL 8.0.16+ generates one, while earlier versions used the FK's index name, so only an explicit constraint name holds on every version. `MariaDbParser` names it by the index name, as MariaDB does.
   - Column-level REFERENCES declares a foreign key on that column through the same reader, with a null generated name. Like CHECK, this reports the declaration, not whether an older MySQL server enforces it.
9. **Tables and views.**
   - Qualified table identities must not collide through dotted-string keys.
   - Dump DROP/CREATE replacement and view stand-ins leave no phantom ordinary tables. An existing view survives IF NOT EXISTS. View text excludes a trailing WITH [LOCAL|CASCADED] CHECK OPTION.
   - Named `CREATE TABLE ... LIKE/SELECT` forms stay explicitly incomplete; SELECT without explicit columns produces one issue.
10. **Table options.**
    - Read options past physical settings, so later ENGINE, COMMENT and COLLATE facts aren't lost.
    - Known options are explicit cases that own their optional-equals and no-equals forms, including TABLESPACE with optional STORAGE, SRID and bare COMPRESSED. After them, one fallback reader accepts only an unknown bare or quoted `NAME = scalar` assignment for table, column and index options; anything else adds an issue. The fallback stops on complex values and rejects missing operands.
    - An unrecognized table tail adds one scoped issue. A terminal PARTITION clause alone doesn't, since physical layout isn't represented. Keep the SELECT-specific issue.
11. **MariaDB temporal syntax.** `MariaDbParser` recognizes PERIOD FOR and AS ROW START/END without inventing columns or generated expressions.
    - Report scoped uncertainty for temporal semantics and WITH SYSTEM VERSIONING, including its implicit columns. Don't repeat the issue for each row-start/end column.
    - A WITHOUT OVERLAPS key part is kept as its exact SQL expression, with no plain column name.
    - No temporal model.
12. **Errors.** Validate required operands at recognized USE, column, index and FK-action branches. Malformed input throws ParseException with an accurate or unknown location, never a warning, TypeError or enum ValueError. Not a general SQL validator. Add focused native temporal and vector regressions, not a dialect-by-option cross-product.

### PostgreSQL

`PostgresLexer` and `PostgresParser` read plain-text pg_dump output and equivalent DDL.

1. **Lexing.**
   - Preserve quotes, dollar-quoted routine bodies, nested comments and statement boundaries.
   - Recognize E-string, `U&` and dollar-tag prefixes only at identifier boundaries, including dollar and high-bit continuation bytes. Statement boundaries follow psql's parenthesis depth; expression spans also nest square brackets, so array defaults stay intact.
   - Skip psql meta-commands, and native COPY FROM stdin data through the exact `\.` line; a missing terminator is a parse error. Unterminated constructs fail during the scan.
2. **Identity.** Never substitute `public` for unqualified table, type or ALTER identities, and never guess search paths. Only keyword spellings are `pg_catalog` types (see Identity, lookup and raw values).
3. **Types.** `parseType()` reads quoted and qualified names and the SQL-standard multi-word spellings (mapped to canonical `pg_catalog` names). It also reads modifiers, `with`/`without time zone`, interval fields with precision, and `[]`/`[n]`/`ARRAY` dimensions. Unrecognized syntax keeps its raw text as an opaque type; quoted user types keep their case. Verify the spellings against `format_type()` output from PG10 to PG18.
4. Parse table columns and inline/table primary keys, including effective not-null membership. Read separate ALTER defaults and identity declarations; pg_dump emits identity with `ALTER TABLE ... ALTER COLUMN ... ADD GENERATED ... AS IDENTITY`, not only inline syntax. Treat unreserved EXCLUDE as a constraint only before USING or an opening parenthesis; otherwise it is a column name. A DEFAULT expression runs through nested CASE ... END, and `DEFAULT NULL` consumes its NULL. Words adjacent to either side of a qualification dot are identifiers, not clause keywords, including storage.buckettype and public.compression.
5. Resolve enum labels and domain chains, including domain NOT NULL and defaults. Preserve explicit column DEFAULT NULL overrides. Apply referenced-domain defaults and root-value checks only to scalar references: arrays retain their element type without those array-level restrictions. Domain NOT NULL describes conversion, not a column guarantee: PostgreSQL permits already-domain-typed NULLs to be stored. Preserve that restriction on Domain, including scalar chains, but derive Column::nullable only from column/table NOT NULL, PK and identity. Keep unvalidated column NOT NULL uncertainty even with a NOT NULL domain. Apply ALTER DOMAIN ADD CHECK constraints, including NOT VALID, before freezing. An enum label or comment must be exactly one string literal. `PostgresLexer` decodes it on demand: ordinary, E, dollar and `U&` literals with `UESCAPE`, surrogate pairs and continuation across newlines, written from the PG10–PG18 lexical documentation. `standard_conforming_strings` is lexer state: default on, changed by native `SET standard_conforming_strings = on|off`.
6. Resolve INHERITS from parent state at child creation, maintaining inherited column order and redeclared positions, then apply later per-table ALTER statements with ONLY semantics. Merge inherited not-null, generated and inheritable check facts appropriately. Keep an internal false-by-default noInheritNotNull flag: a copied NO INHERIT column becomes nullable and loses that flag. Parent identity/primary/unique/FK constraints and comments are not inherited. Clone each inherited `ColumnDefinition` before child-specific changes, so the parent and siblings are never mutated; immutable types and results stay shared. No generic deep-clone mechanism. Serial nextval defaults differ from identity generation. Missing parents produce scoped uncertainty, not guessed columns.
7. Preserve local default and generated-expression precedence. Use one conflict map and resolve it after local declarations merge; compare generation kind and expression strictly. Read SET DEFAULT NULL used to cancel inherited defaults. Maintain parse-local parent-to-child links and traverse existing descendants once with a visited set for recursive ALTER operations: defaults and inheritable CHECK/NOT NULL propagate; ADD PRIMARY KEY propagates nullability without a child primary key; identity does not recurse. Include partition links when attached. Avoid copying a parent's final later state retroactively into its children.
8. Read CREATE INDEX (including ON ONLY) and ALTER constraint forms, including expression keys, INCLUDE payload columns, predicates, collations/operator classes/null ordering, deferral, validation/enforcement and unique NULLS NOT DISTINCT. For equivalent DDL using ADD CONSTRAINT USING INDEX, merge the existing index into one constraint-backed result rather than duplicating it. Recognize ALTER INDEX ATTACH PARTITION without duplicating an index or producing an issue: index attachment topology is not part of the represented model.
9. Preserve checks and FK references/actions/match modes, including NOT VALID and NO INHERIT semantics. Keep unvalidatedNotNull internally and emit its table issue at freeze only if the effective column still permits null. A new child validates inherited enforced CHECK/NOT NULL constraints; recursive ALTER on existing children preserves the incoming validation flag. NO INHERIT propagates neither. Merge equal named checks from parents, local declarations and recursive ALTER into one, retaining validation and enforcement when either copy supplies them; conflicting expressions produce a scoped issue. Unnamed checks remain separate. NOT ENFORCED implies valid=false, also for MySQL checks. Document valid as validation represented by the declaration, not a guarantee about stored data. For handwritten recursive DROP NOT NULL, make previously non-null descendants conservatively nullable with a scoped issue: local/other inherited guarantees cannot be reconstructed without constraint-lineage tracking. Do not add that machinery or a public not-null constraint object. In FK lists, recognize unquoted PERIOD as a modifier only when followed by another identifier; retain column names and one scoped temporal-semantics issue per FK. A lone period or quoted "PERIOD" remains a column.
10. Handle PostgreSQL 18 VIRTUAL generated columns, including default virtual kind when STORED is omitted, and named/unnamed not-null constraints. Classify table-body NOT NULL column declarations as constraints and apply them after inherited/local columns merge; also accept the older ALTER TABLE ONLY ... ALTER COLUMN ... SET NOT NULL dump form. Retain supported PostgreSQL 10/11 declarations without referencing later server catalog columns in framework integration.
11. Read view/materialized-view definitions, explicit partition relationships/bounds and ordinary partition-child table definitions from pg_dump. Exclude the trailing WITH [LOCAL|CASCADED] CHECK OPTION from view query text, including the native pg_dump form. Distinguish INHERITS from partition attachment. Typed tables/partition forms whose columns cannot be recovered retain their identity and issues.
12. Read COMMENT ON TABLE/FOREIGN TABLE/COLUMN statements, including IS NULL removal, into the corresponding comment fields. Preserve column COLLATE declarations. Collation and operator-class strings retain their SQL identifier spelling, including qualification/quoting where supplied, so quoted dots and case are not lost or silently conflated.
13. Route FOREIGN TABLE declarations, ALTERs and drops through the table parser. Consume SERVER and table/column OPTIONS as unrepresented connection metadata, including native ALTER COLUMN OPTIONS; never contact a foreign server. Retain inheritance and partition attachment. Bound partition text to DEFAULT or the complete FOR VALUES clause, excluding later storage/server clauses. Foreign tables are absent from framework getTables(), so use catalog assertions for their live comparisons.
14. Ignore native CLUSTER ON, REPLICA IDENTITY and trigger/rule enable modes, which do not change represented facts. Use one indexed, qualification-aware view lookup to skip view column/default/index metadata that the model does not expose. Handle identity changes before that bypass. Unresolved DROP CONSTRAINT and ALTER COLUMN DROP DEFAULT are quiet clean-dump prefixes. Remove a resolved DROP INDEX without a separate registry; ambiguous unqualified index names produce an issue. Parse DROP target lists before consuming CASCADE/RESTRICT, preserving targets named cascade or restrict.
15. Keep unsupported replay honest through schema-level issues when represented objects exist: top-level ALTER ... RENAME TO / SET SCHEMA, enum ADD VALUE / RENAME VALUE, and cascading DROP or ALTER TABLE DROP actions can affect facts beyond their immediate target. Recognize them from tokens, so literal defaults and FK ON DELETE CASCADE are not mistaken for these actions. Recognized drops remove an exact or uniquely resolved target; ambiguous unqualified table/domain/view/enum/index names produce an issue. Reuse existing identity maps and scan enums only when needed for DROP TYPE. Do not build a dependency graph, opaque-object registry or rename replay engine. Cover the unrecognized-kind DROP fallback once. Native --clean prefixes remain quiet when no represented objects exist.

### SQLite

`SQLiteLexer` and `SQLiteParser` read the framework's SQLite dumps, `.schema` and `.schema --indent` output, and equivalent DDL. The facts below were verified against native SQLite.

- **Lexing.**
  - Names can be double-quoted, bracketed, backticked or, where SQLite accepts it, single-quoted (`CREATE TABLE 'x'`).
  - Comments are ordinary. Semicolons inside literals don't end statements. A trigger ends only at `; END ;`, outside quotes and comments, as in SQLite's `complete.c`.
  - In CREATE TABLE and CREATE INDEX text, `.schema --indent` collapses whitespace runs and drops spaces next to brackets everywhere, including inside literals and quoted names. Views and triggers keep their text. The parser reads the text it is given and never tries to restore it.
- **Names and databases.**
  - A single-quoted string is a name wherever SQLite reads one.
  - Unqualified persistent tables and views belong to `main`, which results report as their schema, like the framework's metadata. An explicit `main` qualifier is the same database; attached database names keep their spelling. `temp` declarations are skipped.
  - Qualified DROP and index targets resolve exactly. FK targets resolve exactly in the referencing table's database, never across databases. Unqualified DROP and index targets prefer `main`, then a single attached match; several attached matches are an issue, with no attachment-order emulation. Index names resolve through `ParseState`'s index map, keyed by name identity and then qualified name, without scanning tables; PostgreSQL's DROP INDEX uses the same map. Most dumps never look an index up by name, so the map is built from the stored tables on the first lookup and maintained from then on. Qualified lookups stay direct, so schema-per-tenant dumps don't scan every schema's indexes.
- **Statements.**
  - Read CREATE TABLE, CREATE [UNIQUE] INDEX and CREATE VIEW. DROP TABLE, VIEW and INDEX remove their target.
  - ALTER TABLE is not applied: RENAME TO adds a schema issue, since it changes relation names; other actions add a table issue, or a schema issue when the table is unknown.
  - CREATE VIRTUAL TABLE stays a known incomplete table with an issue. `AS SELECT` tables are incomplete.
  - Skip triggers and the appended migration INSERTs.
- **Constraint names.** The latest CONSTRAINT name applies to every following constraint until the column ends, or until a table constraint's comma; a later CONSTRAINT clause replaces it, and adjacent table constraints need no comma. PRIMARY KEY and UNIQUE constraint indexes have null names: SQLite names them `sqlite_autoindex_*`, so a constraint name is not an index name.
- **Primary keys and nullability.**
  - NOT NULL makes a column non-null. So does a primary key in a STRICT or WITHOUT ROWID table, and a rowid alias.
  - A plain table's other primary keys can hold NULL.
  - In a rowid table, INTEGER PRIMARY KEY (type name exactly `integer`, quoted or not) as a column or single-column table key is a rowid alias, except the inline `PRIMARY KEY DESC` form; table-level DESC is not that exception. A WITHOUT ROWID table has no rowid alias.
  - Only a rowid alias is `autoIncrement`, with or without the AUTOINCREMENT keyword, matching the framework's column metadata.
- **Defaults and expressions.**
  - Generated columns may omit `GENERATED ALWAYS`, and default to VIRTUAL.
  - Raw defaults keep quoted literals, signs and `DEFAULT NULL`. In `DEFAULT (expr)` the outer brackets are syntax, so the text matches `pragma_table_info`: `(1 + 2) * 3` for `DEFAULT ((1 + 2) * 3)`, and `(1)` for `DEFAULT ((1))`.
  - Expression spans include their own brackets, with no expression tree.
  - Index and key columns follow `pragma_index_xinfo`. A name stays a column through any brackets and COLLATE wrappers, and the outermost collation applies: `((a COLLATE nocase) COLLATE rtrim)` is column `a` with RTRIM. A name the table lacks is a column only where SQLite reads it as one: unquoted NULL is a constant, an unknown TRUE or FALSE is a boolean, and an unknown double-quoted name is a string. Index keys and FK local columns report the declared column spelling.
  - COLLATE binds tighter than any binary operator, so an expression key's trailing COLLATE is its collation only after a single operand: a literal, name, function call or bracketed group, after any unary signs (`lower(name) COLLATE nocase`, `(a + 1) COLLATE nocase`). CASE ... END is read whole by the expression reader, which pairs CASE with END, so a collation after it stays in the text. Otherwise, as in `a + 1 COLLATE nocase` or `a COLLATE nocase || 'x'`, the collation stays in the expression text and the key reports none. Outer brackets around a collated expression also keep it in the text; that collation is not derived. Read expression keys forward only: a group consumed by `skipGroup()` cannot be rewound.
- **Foreign keys.**
  - Keep the actions and the MATCH mode as written, and document that SQLite doesn't enforce MATCH. Dumps keep whatever was declared: a MATCH name the model can't represent adds an issue, and ON INSERT, which SQLite ignores, is consumed.
  - `NOT DEFERRABLE [INITIALLY ...]` means not deferrable (null Deferral).
  - A column-level deferral clause is its own constraint. It applies to the table's most recently declared foreign key (`build.c`), even after another constraint or from a later column.
  - `INITIALLY` without `DEFERRABLE` is a syntax error.
- **Types and checks.**
  - `name` is lowercased like the framework's column metadata. Unquoted type words are joined with single spaces, so `UNSIGNED   BIG  INT` gives `unsigned big int` from either dump form. A quoted type keeps its decoded contents, spaces included: `"INTEGER "` gives `integer ` and is not a rowid alias.
  - `modifiers` are the signed numbers as written, so `numeric( 10.5 , -2 )` gives `numeric` with `['10.5', '-2']`, with no truncation.
  - A column with no type has an empty name and no modifiers.
  - Never derive a type from affinity.
  - Consume ON CONFLICT clauses. Don't simulate CHECK evaluation, affinity or storage classes, since affinity doesn't guarantee one PHP type.

## Lifetime and performance

All mutable state belongs to one parse (see Architecture). Parsing is synchronous CPU work with no coroutine yields.

- **One forward pass.** The unread remainders of skipped statements and groups create no tokens; tokens that were read are kept only until the next statement. Expressions are offset spans and values are decoded on demand. Use native span searches between significant bytes, not PHP calls or buffer appends per ordinary byte.
- **No repeated work.** No rescanning or copying of growing statements, and no rescanning all tables for lookups. Use whichever primitive is clearest and efficient: `strcspn`/`strpos`, or an anchored or small bounded regex.
- **Memoize and index.** Memoize repeated PostgreSQL type text within the parse. Resolve dependencies through indexed identities, with traversal bounded by the actual definitions. Freeze each definition once, and retain no raw dump text on results when source fragments suffice.
- **Line numbers.** Count diagnostic lines incrementally as issue offsets advance; one-shot syntax failures may count from the start.
- **Nothing persistent.** No persistent result caching, locks, background processes or public streaming API. Callers can cache by input SQL and parser.
- **Benchmark.** Compare at each checkpoint. The owner's permission to benchmark stands until withdrawn; check that the machine is quiet before timing, and rerun the affected comparisons if competing work ran. A regression needs an explanation and owner approval. Tests use large fixtures for correctness only, without timing assertions. Medians of interleaved runs on PHP 8.4 NTS (opcache off / on / tracing JIT), for 2,000-table dumps with 2,001 tables, 30,003 columns, 1,999 FKs and 200 views (PostgreSQL 10,000 indexes, MySQL and SQLite 10,001):

  | Parser | Input | Old reader | Completion | Peak memory (old → completion) |
  | --- | --- | --- | --- | --- |
  | PostgreSQL | 5.6 MB | 642 / 568 / 400 ms | 683 / 610 / 398 ms | +49 → +35 MB |
  | MySQL | 3.7 MB | 1272 / 1193 / 967 ms | 756 / 675 / 482 ms | +221 → +44 MB |
  | SQLite | 2.0 MB | none | 462 / 416 / 305 ms | +40 MB |

  The SQLite input is `SqliteSchemaState`'s dump of a database with the same shape, plus 2,000 migration INSERTs; its time grows linearly with size.

  The owner approved checkpoint 1's PostgreSQL tradeoff: the 2,000-table dump parses 4–10% slower (17–59 ms across opcache and JIT modes), because parsed CREATE INDEX and ALTER statements are read token by token, while peak memory is lower and wide tables, large CHECK groups and skipped bodies are much faster. At completion the gap is 6–7% (41–42 ms) with opcache off or on and none under JIT, with 29% less peak memory. Extra time on numeric INSERTs and BEGIN ATOMIC bodies is correct psql boundary work that the old reader skipped.

## Database integration

Complete on this branch:
- dump/load fixes in the MySQL, MariaDB, PostgreSQL and SQLite schema states: quoting, TLS mapping, counter cleanup, flag retries, migration-table escaping and psql diagnostics;
- lossless index and FK metadata with positional `key_columns`;
- SQLite rebuild fixes;
- their framework consumers: `db:table`, `ModelInspector` and `PendingCommand`.

Also complete:
- `SQLiteProcessor::processColumns` derives `type_name` with `strtok($type, '(') ?: ''`, so `varchar (255)` gives `varchar ` with a trailing space, and a quoted type `"0"` gives an empty name. That breaks `Schema::getColumnType()` and Wayfinder's type mapping. Laravel has the same code. Remove only the whitespace before a `(`, without a truthiness fallback, so quoted names such as `"INTEGER "` survive; keep `type` unchanged; add a processor regression test.
- Laravel's `SqliteSchemaState::dump()` edits declarations. `.schema --indent` reformats CREATE TABLE and CREATE INDEX text, including literals (`default 'Untitled (draft)'` loads back as `'Untitled(draft)'`). Its regexes for `sqlite_*` and shadow tables search the whole dump, so they delete matching text inside multiline literals, and they miss shadow names with doubled quotes (fts5 `'docs''o_data'`, rtree `"r""t_node"`). Owner-approved fix: read each stored statement of `main.sqlite_master` through the write connection, in rowid order (`.schema`'s own query), skip `sqlite\_%` names and the `shadow` rows of `pragma main.table_list`, and write `sql;` per row. Keep `.schema`'s one rewrite, `CREATE TABLE ['"]` → `CREATE TABLE IF NOT EXISTS`, at the start of a statement only: below SQLite 3.37, `table_list` lists nothing, so shadow tables stay in the dump and must not fail after their virtual table creates them. `removeShadowTables()` is removed, and the schema read no longer goes through `baseCommand()` or the process factory; the migration-data append and `load()` keep using the CLI. Dumps lose `.schema`'s `/* view(cols) */` comments. Covered by a catalog unit test on an in-memory connection and live dump/load regressions for literals, quoted fts5/rtree names and shadow tables.
- Three `SchemaStateTest` dump tests skip when `sqlite3` is missing, contradicting the configured-engine rule. Remove those skips; keep the in-memory exclusion.

The owner approved the metadata grammar/processor compatibility changes and accepted their impact on custom overrides, which also covers SQLite's automatic-key field. The owner also approved the TLS client restrictions. The facts the parser work relies on:

- **MySQL/MariaDB dumps.** The schema dump runs `--no-data --routines --skip-comments`, with counters removed outside DELIMITER regions. Migrations are appended as `--compact --complete-insert --skip-extended-insert` INSERTs.
- **PostgreSQL dumps.** Plain-text `pg_dump --no-owner --no-acl`, with migration data appended by `--data-only` as COPY.
- **SQLite dumps.** The stored `main.sqlite_master` statements in rowid order, without `sqlite_*` objects or shadow tables, and with quoted table names given IF NOT EXISTS as `.schema` does. Migration INSERT lines are appended from the CLI's `.dump`.
- **Live comparisons.** They use `getColumns()`/`getIndexes()`/`getForeignKeys()`: index `columns` is complete or empty, `key_columns` is positional with null for expressions, and SQLite FK metadata resolves implicit targets.
- **CI.** The image provides sqlite3, `mariadb-client-compat` and `postgresql-client-18`. The serial MySQL TLS test runs after the normal database suites. The image must be rebuilt, by a push to `0.4` or a manual dispatch, before parser integration checks pass in CI.

## Tests and verification

### Offline tests

- **Layout.** Use `Hypervel\Tests\TestCase`. Tests call the public parsers; split a dialect into focused feature files (types, constraints, inheritance, dump fixtures) where that reads better than one large class. Add `SchemaTest` for identity lookup, ambiguity and completeness, and lexer tests in `tests/SchemaParser/Parsing/`.
- **Migrating old tests.** Rewrite `MySqlParserTest` and `PostgresParserTest` for the new parsers. Before deleting the `MySql/*Test` and `Postgres/*Test` classes, move each useful assertion into the public parser and lexer tests. Merge duplicates, keeping the stronger assertion. Correct an expected value only where a verified fix requires it. Keep the committed fixtures: the all-types dump and its source, the real dumps, COPY data, and the MySQL option and period fixtures.
- **Scope.** Don't test readonly enforcement or constructors, or pad counts. Test freezing only where our own resolution adds behavior.

### Behavior coverage

| Flow | Required assertions |
| --- | --- |
| Common results | Qualified and quoted/dotted names, missing versus ambiguous identity, ordered columns/keys, explicit NULL versus absent default, retained declared/resolved types, scoped issues without losing known facts. |
| MySQL | Every verified type, default, escape, identifier, generated, FK and index case; executable comments, with `/*M!` ignored; routine DELIMITER boundaries; no phantom routine tables; view stand-ins; expression indexes; decoded enum/set labels; malformed input. |
| MariaDB | `/*M!` fragments and the sandbox directive, temporal syntax, `VECTOR KEY/INDEX` with columns named `vector`, MariaDB-only fixtures. |
| PostgreSQL | Real ALTER identity output; inherited column order/local overrides, multiple parents, serial versus identity, NO INHERIT, explicit NULL domain overrides, qualified enums/domains/arrays, PK nullability, PG18 generated/constraint forms, indexes and USING INDEX attachment. |
| PostgreSQL types | Keyword versus identifier type identity, interval fields and precision through domains and arrays, string decoding (E, dollar, `U&` with `UESCAPE`, continuation, `standard_conforming_strings`). |
| SQLite | Type name/modifier split, with unquoted spacing equal across both dump forms; rowid alias and inline PK DESC, quoted `"INTEGER "` and WITHOUT ROWID INTEGER PRIMARY KEY as non-aliases in the same provider, STRICT, nullable ordinary PK, raw/default/generated expressions matching pragma text, indexes/FKs/checks/views, deferral attachment and NOT DEFERRABLE, virtual-table issue, all four name quotings, semicolons in trigger bodies. |
| Lexical boundaries | In checkpoint 1, before the base is final: MySQL executable comments, SQL modes and DELIMITER, PostgreSQL COPY and meta-commands, SQLite triggers; skipping matches reading; offsets survive comments and wrappers. |
| Reuse/errors | Two distinct dumps on the same parser, malformed then valid input, source lines across comments, no contamination, deterministic completeness. No synthetic yield hook in the CPU-only parsing loop. |

### Live engines

Live suites are in `tests/Integration/SchemaParser/Database/{MySql,MariaDb,Postgres,Sqlite}/`, with `Sqlite` new. `bin/run-database-tests.sh` already discovers these paths; preserve that mechanism. Use existing database CI jobs rather than creating a parser-specific engine/version matrix.

Each suite creates representative schema objects, generates a real schema dump through the framework schema-state API, parses it and compares represented facts with engine metadata. Where framework introspection omits a fact or contains a known defect, use the engine catalog as the independent oracle. PostgreSQL column read-nullability uses attnotnull plus any column NOT NULL constraint's convalidated flag; a NOT VALID constraint restricts new writes but can leave existing NULLs. Do not use information_schema domain nullability or assert PostgreSQL's typed-NULL insertion behavior as a separate live test. Assert dialect normalization deliberately; do not compare arbitrary raw expression formatting byte-for-byte against server-normalized text.

Use the existing isolated per-worker test database. Dump tools run in another connection, so schema setup must be committed: use the database-migration/fresh-schema pattern rather than a RefreshDatabase transaction invisible to the external process. SQLite's existing CI job supplies `/tmp/testing.sqlite`, and `TestDatabases` creates worker-specific files. Follow the existing SQLite schema-state test setup; skip dump execution only for an explicitly configured in-memory SQLite connection. Do not override the managed connection with a second isolation mechanism. Dump to `ParallelTesting::tempDir(...)` and clean up owned schema/files through teardown. Use `withMigrationTable(null)` when migration data is irrelevant; the PostgreSQL suite must keep the default migration-data append path with an apostrophe-containing row to exercise COPY records. Check SQLite's appended INSERT data in its slice too. Reuse Testbench environment and connection configuration instead of reading secrets into logs.

The live MySQL suite exercises the MySQL server through the compatible MariaDB dump client; committed fixtures also cover Oracle mysqldump output from supported MySQL versions. Generate those fixtures using the matching official server images' bundled clients, without adding another persistent CI matrix. Once a test engine is configured, a missing required dump binary fails clearly rather than silently skipping; this rule needs no CI-environment detection. Failed configured connections/dumps also fail. Bound external processes and overall test execution. Never dump an unrelated developer database.

Use version-specific assertions for features absent at the supported floor: MySQL expression indexes/enforcement and PG15 nulls-not-distinct/PG18 constraints. Keep committed real-dump fixtures for format variants that the local server cannot generate. No running server is needed for the normal offline suite.

### Check commands and completion evidence

Run from the components-parser root:

```sh
composer lint:fix
composer analyse
composer test:parallel -- --processes=4 tests/SchemaParser
./vendor/bin/phpunit --no-progress tests/Composer/PackageManifestConsistencyTest.php
```

Root formatting and analysis scripts also run the base skeleton's tooling; install its dependencies in this worktree if needed. Run the live parser suites under the matching `DB_CONNECTION`. At completion, verify root and split Composer metadata, a standalone install that parses, the minimum PHP version and all four live engine paths.

## Implementation order

1. **Base and PostgreSQL.** Build:
   - `Parsing/`: all three lexers, `TokenStream`, `TokenType`, `ParseState` and the definitions;
   - `Parser`, and `PostgresParser` with its existing semantics moved across;
   - `ColumnType::$intervalFields`;
   - the lexical-boundary tests;
   - the migrated PostgreSQL tests.

   Delete `Postgres/`, `pg_builder` and the unused SQLite toolkit. Checkpoint review.
2. **MySQL and MariaDB.** `MySqlParser` and `MariaDbParser` with their tests and live suites, moving the cores they share with `PostgresParser` into the base. Delete `MySql/` and the old upstream-named MySQL tests. Checkpoint review.
3. **SQLite.** `SQLiteParser` with its tests and a new live suite that checks the appended INSERTs, plus the remaining SQLite database fixes (see Database integration). Checkpoint review.
4. **Completion.**
   - README, LICENSE, docs and Composer, plus the standalone install;
   - Profile representative workloads across all parsers. Optimize measured bottlenecks, repeated work and unnecessary allocations where the gains justify the complexity. Recheck correctness, execution time and peak memory.
   - the benchmark comparison;
   - a final grep for upstream names, stale comments and dead code.

   Final review across all dialects and the shared model.

At each checkpoint:
- run `lint:fix`, `analyse` and the targeted tests sequentially;
- self-review fully through callers, callees and supported use;
- get peer review until signoff;
- `codex-stan` commits and pushes; the implementer does not.

The finished codebase reads as if designed this way from the start, with no superseded code, stale comments or docs, migration history or scratch resources. Merge into `0.4` before resuming the PHPStan work.

## References for implementation and review

- Framework conventions and consumers: `AGENTS.md`, `src/database/README.md`, `src/database/src/Schema/Builder.php`, `Schema/Grammars/{MySql,Postgres,SQLite}Grammar.php`, `Query/Processors/{MySql,Postgres,SQLite}Processor.php`, `Schema/*SchemaState.php`, `tests/Integration/Database/SchemaBuilderTest.php` and `bin/run-database-tests.sh`.
- Public package conventions: `src/permission/{composer.json,README.md,LICENSE.md}`; user-doc prose: `src/docs/processes.md`.
- [PostgreSQL CREATE TABLE](https://www.postgresql.org/docs/18/sql-createtable.html), [CREATE DOMAIN](https://www.postgresql.org/docs/18/sql-createdomain.html), [ALTER TABLE](https://www.postgresql.org/docs/18/sql-altertable.html): inheritance, identity/defaults and constraint semantics.
- [PostgreSQL 10 pg_index](https://www.postgresql.org/docs/10/catalog-pg-index.html) and [PostgreSQL 11 pg_index](https://www.postgresql.org/docs/11/catalog-pg-index.html): INCLUDE boundary and expression-key representation.
- [PostgreSQL lexical structure](https://www.postgresql.org/docs/18/sql-syntax-lexical.html) (with the [PG10 version](https://www.postgresql.org/docs/10/sql-syntax-lexical.html)): string forms, `U&`/`UESCAPE`, dollar quotes, nested comments.
- [MySQL string literals](https://dev.mysql.com/doc/refman/8.4/en/string-literals.html), [MySQL comments](https://dev.mysql.com/doc/refman/8.4/en/comments.html), [MariaDB executable comments](https://mariadb.com/docs/server/reference/sql-statements/comment-syntax): lexical rules.
- [SQLite CREATE TABLE](https://www.sqlite.org/lang_createtable.html), [SQLite foreign keys](https://www.sqlite.org/foreignkeys.html), and SQLite's `parse.y`, `build.c` and `complete.c`: constraint, deferral and trigger-completion rules.

Keep these reproductions as tests, because each blocks an attractive but wrong simplification:
- a domain with `DEFAULT 'domain-value'` overridden by column `DEFAULT NULL` keeps the expression `NULL::text`;
- pg_dump writes identity declarations as separate ALTER statements;
- a unique `(a, lower(b))` index allows two equal `a` values;
- an INCLUDE payload is not part of the indexed key.
