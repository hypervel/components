# Schema Parser

- [Introduction](#introduction)
- [Installation](#installation)
- [Parsing a Dump](#parsing-a-dump)
- [Inspecting the Schema](#inspecting-the-schema)
    - [Names and Schemas](#names-and-schemas)
    - [Columns and Types](#columns-and-types)
    - [PostgreSQL Types](#postgresql-types)
    - [Indexes and Constraints](#indexes-and-constraints)
    - [Views](#views)
- [Completeness and Errors](#completeness-and-errors)
- [Supported Input](#supported-input)
- [Credits](#credits)

<a name="introduction"></a>
## Introduction

Hypervel's schema parser reads SQL schema dumps into typed, immutable objects. You may use these objects to inspect tables, build development tools or analyze application code without connecting to a database.

The package reads SQL text. It does not execute SQL, run database clients or boot an application. The same result classes are used for MySQL, MariaDB, PostgreSQL and SQLite.

<a name="installation"></a>
## Installation

Install the package using Composer:

```shell
composer require hypervel/schema-parser
```

The package may also be used outside a Hypervel application.

<a name="parsing-a-dump"></a>
## Parsing a Dump

Choose the parser for the database that produced your dump, then pass its SQL text to the `parse` method. For example, you may parse the dump written by the `schema:dump` Artisan command:

```php
use Hypervel\SchemaParser\MySqlParser;

$schema = (new MySqlParser)->parse(file_get_contents(database_path('schema/mysql-schema.sql')));

foreach ($schema->tables as $table) {
    echo $table->name;
}
```

Each database connection driver has its own parser:

| Driver | Parser |
| --- | --- |
| `mysql` | `Hypervel\SchemaParser\MySqlParser` |
| `mariadb` | `Hypervel\SchemaParser\MariaDbParser` |
| `pgsql` | `Hypervel\SchemaParser\PostgresParser` |
| `sqlite` | `Hypervel\SchemaParser\SQLiteParser` |

The parser does not guess the database from the SQL it is given, so you may choose one from a connection's driver name:

```php
use Hypervel\SchemaParser\MariaDbParser;
use Hypervel\SchemaParser\MySqlParser;
use Hypervel\SchemaParser\PostgresParser;
use Hypervel\SchemaParser\SQLiteParser;
use Hypervel\Support\Facades\DB;

$parser = match (DB::connection()->getDriverName()) {
    'mysql' => new MySqlParser,
    'mariadb' => new MariaDbParser,
    'pgsql' => new PostgresParser,
    'sqlite' => new SQLiteParser,
};
```

`MariaDbParser` also reads syntax that only MariaDB writes, such as `/*M! ... */` executable comments, application-time periods and vector indexes. `MySqlParser` treats `/*M!` comments as ordinary comments, as MySQL does.

Each parser implements `Hypervel\SchemaParser\Contracts\Parser` and returns a `Schema`. You may reuse a parser for multiple inputs; it does not retain an earlier dump or its results.

<a name="inspecting-the-schema"></a>
## Inspecting the Schema

The schema exposes ordered `tables`, `views`, `enums` and `domains` lists. You may find individual definitions through the corresponding lookup methods:

```php
$users = $schema->table('users');
$report = $schema->view('user_report');
$status = $schema->enum('status', 'public');
$email = $schema->domain('email_address', 'public');
```

Missing definitions return `null`. Tables provide `column` and `primaryKey` lookups, along with ordered `columns`, `indexes`, `foreignKeys` and `checks` lists.

<a name="names-and-schemas"></a>
### Names and Schemas

Pass a schema name as the second argument when a definition is qualified:

```php
$current = $schema->table('events', 'public');
$archive = $schema->table('events', 'archive');
```

An unqualified lookup requires a unique match. If several schemas contain the same name, the lookup throws `InvalidArgumentException`. Names and schema names are separate strings, so a dot inside a quoted identifier remains part of that identifier. Lookups compare names exactly as they were declared, including for MySQL and SQLite, even though the statements in a dump are resolved with each database's own case rules.

The parser preserves quoted case and does not assume that an unspecified PostgreSQL schema is `public`. It does not consult an application's search path. MySQL and MariaDB definitions have the database named by a qualifier or a preceding `USE` statement as their schema, or `null` when the dump names none. SQLite definitions have `main` as their schema, as in the framework's schema metadata, while attached databases keep their own names.

<a name="columns-and-types"></a>
### Columns and Types

A column describes its declared type, nullability, default, auto-increment behavior and any represented generation expression:

```php
$users = $schema->table('users');
$email = $users->column('email');

$email->type->name;
$email->nullable;
$email->default;
```

`ColumnType` retains type modifiers, array dimensions, unsigned status and enum or set labels. PostgreSQL domains keep their declared identity, with the resolved underlying type available through `base`. Extension types whose definitions are absent from the dump remain opaque. SQL types are not converted to PHP types.

Defaults, generated expressions and `onUpdate` expressions remain SQL text. For example, an explicit `DEFAULT NULL` has the string value `'NULL'`, while a `null` default property means no expression was supplied or resolved. Enum labels and comments are decoded strings.

A PostgreSQL domain's `nullable` flag describes its conversion restriction. It does not make columns of that domain non-null: PostgreSQL can store already-domain-typed null values. A column's `nullable` flag reflects its own validated not-null guarantee, including primary keys and identity columns. Unvalidated not-null constraints leave the column nullable and add a table issue.

<a name="postgresql-types"></a>
### PostgreSQL Types

PostgreSQL's SQL-standard keyword spellings name built-in types, so they are reported by their canonical name in the `pg_catalog` schema. An identifier keeps the spelling and schema it was written with; an unqualified `int8` or `text` has a `null` schema, since the database's search path could resolve it to a user-defined type:

```php
// created_at timestamp(0) with time zone
$type = $users->column('created_at')->type;

$type->name; // 'timestamptz'
$type->schema; // 'pg_catalog'
$type->modifiers; // ['0']
```

An interval's field restriction, such as `day to second`, is available through the type's `intervalFields` property, separate from its precision in `modifiers`. A domain's resolved `base` type keeps the restriction as well.

<a name="indexes-and-constraints"></a>
### Indexes and Constraints

An index describes its ordered keys, uniqueness, primary-key status, any partial-index predicate in `where` and any included columns:

```php
$primary = $users->primaryKey();

foreach ($users->indexes as $index) {
    foreach ($index->columns as $key) {
        echo $key->name ?? $key->expression;
    }
}
```

Each key has a column name or raw key SQL in `expression`. An expression may describe a computed key or a specialized key part such as MariaDB's `WITHOUT OVERLAPS`; it must not be treated as a plain column. Included PostgreSQL columns are stored separately in `include` because they are not part of the indexed key.

Foreign keys retain local and referenced columns, a qualified `foreignTable`, update/delete actions and declared match behavior. Checks retain their SQL expressions and represented enforcement or validation flags. The parser records declarations rather than evaluating constraints; MySQL and SQLite do not enforce every written match mode as PostgreSQL does.

<a name="views"></a>
### Views

Views are separate from tables and expose their query text through `definition`. PostgreSQL materialized views have `materialized` set to `true`. View column types are not inferred.

If you are checking whether a model-backed relation exists, check both the table and view lists.

<a name="completeness-and-errors"></a>
## Completeness and Errors

A parser may recover useful facts from a declaration without understanding every part of it. Such uncertainty appears in the schema's or table's `issues` list. Each issue includes a message and, when available, its SQL line.

`Schema::isComplete()` checks all represented schema and table facts. To inspect one table without rejecting useful results because another table is incomplete, check the schema-level issues and that table's completeness:

```php
$users = $schema->table('users');

if ($schema->issues === [] && $users !== null && $users->isComplete()) {
    $hasEmailColumn = $users->column('email') !== null;
}
```

Only treat a missing column as known to be absent when these checks pass. A missing table requires no schema-level issues; an incomplete known table does not by itself make other table names uncertain.

Detected malformed input throws `Hypervel\SchemaParser\ParseException`. Its `sqlLine` property refers to the SQL input when a location is available; the normal exception line still refers to PHP source. Completeness concerns the represented schema facts, not whether a database server would accept every statement in the input.

<a name="supported-input"></a>
## Supported Input

Use the dumps written by the `schema:dump` Artisan command, schema SQL produced by `mysqldump` or `mariadb-dump`, plain-text `pg_dump` output, or SQLite's `.schema` command. Equivalent schema declarations may also be supplied directly. PostgreSQL custom and binary archive formats must first be exported as SQL text.

Results describe persistent schema. Temporary declarations are skipped. Routine bodies, grants and physical storage settings are not interpreted, but their boundaries are respected so declarations inside a routine do not become schema tables. The parser is not a general migration replay engine.

Named unsupported forms remain explicit: for example, SQLite virtual tables can retain their names with incomplete column metadata. MariaDB periods and system-versioned tables produce scoped issues because their temporal rules and implicit columns are not fully represented. For handwritten MariaDB vector indexes, use `VECTOR KEY` or `VECTOR INDEX`; bare `VECTOR` syntax is ambiguous with MySQL column declarations.

<a name="credits"></a>
## Credits

Hypervel Schema Parser began with [iamcal/SQLParser](https://github.com/iamcal/SQLParser) for MySQL and [calebdw/pg-schema-parser](https://github.com/calebdw/pg-schema-parser) for PostgreSQL, and its tests still use fixtures from both projects. It is maintained independently, with its own parser for each supported database.
