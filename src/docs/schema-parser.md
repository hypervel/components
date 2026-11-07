# Schema Parser

- [Introduction](#introduction)
- [Installation](#installation)
- [Parsing a Dump](#parsing-a-dump)
    - [Choosing a Parser](#choosing-a-parser)
- [Inspecting the Schema](#inspecting-the-schema)
    - [Names and Schemas](#names-and-schemas)
    - [Columns and Types](#columns-and-types)
    - [PostgreSQL Types](#postgresql-types)
    - [Indexes and Constraints](#indexes-and-constraints)
    - [Views](#views)
- [Completeness and Errors](#completeness-and-errors)
    - [Checking Completeness](#checking-completeness)
    - [Handling Parse Errors](#handling-parse-errors)
- [Supported Input](#supported-input)
- [Credits](#credits)

<a name="introduction"></a>
## Introduction

Hypervel's schema parser allows you to inspect SQL schema dumps without connecting to a database. You may use it to build development tools, generate code or analyze the tables and columns used by your application.

The package supports MySQL, MariaDB, PostgreSQL and SQLite, returning the same types of immutable objects for each database. Parsing a dump does not execute its SQL or require a database client or a Hypervel application.

<a name="installation"></a>
## Installation

To get started, install the package using Composer:

```shell
composer require hypervel/schema-parser
```

<a name="parsing-a-dump"></a>
## Parsing a Dump

To parse a dump, pass its SQL text to the `parse` method of the appropriate parser. For example, you may use `MySqlParser` to read a MySQL dump written by the `schema:dump` Artisan command:

```php
use Hypervel\SchemaParser\MySqlParser;

$schema = (new MySqlParser)->parse(file_get_contents(database_path('schema/mysql-schema.sql')));

foreach ($schema->tables as $table) {
    echo $table->name;
}
```

The `parse` method returns a `Hypervel\SchemaParser\Schema` instance containing the definitions found in the dump. You may reuse the same parser for multiple dumps; each call returns a separate result.

<a name="choosing-a-parser"></a>
### Choosing a Parser

Choose the parser that matches the database that produced your dump:

| Driver | Parser |
| --- | --- |
| `mysql` | `Hypervel\SchemaParser\MySqlParser` |
| `mariadb` | `Hypervel\SchemaParser\MariaDbParser` |
| `pgsql` | `Hypervel\SchemaParser\PostgresParser` |
| `sqlite` | `Hypervel\SchemaParser\SQLiteParser` |

Within a Hypervel application, you may use the connection's driver name to select a parser:

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

Use `MariaDbParser` for MariaDB dumps so MariaDB-specific declarations are read correctly. This includes `/*M! ... */` executable comments, which `MySqlParser` ignores, along with application-time periods and vector indexes.

All four parsers implement the `Hypervel\SchemaParser\Contracts\Parser` interface. The package does not automatically detect the database from the SQL.

<a name="inspecting-the-schema"></a>
## Inspecting the Schema

You may iterate over the schema's `tables`, `views`, `enums` and `domains` properties, or retrieve an individual definition using its lookup method:

```php
$users = $schema->table('users');
$report = $schema->view('user_report');
$status = $schema->enum('status', 'public');
$email = $schema->domain('email_address', 'public');
```

These methods return `null` when no matching definition is found. Each table also provides `column` and `primaryKey` methods, along with `columns`, `indexes`, `foreignKeys` and `checks` lists. The lists preserve declaration order.

<a name="names-and-schemas"></a>
### Names and Schemas

If your dump contains more than one schema, you may pass a schema name as the second argument to a lookup method:

```php
$current = $schema->table('events', 'public');
$archive = $schema->table('events', 'archive');
```

When you omit the schema name, the lookup must find a unique match. If several schemas contain a definition with that name, the method throws an `InvalidArgumentException`.

Lookup methods compare names exactly as stored in the result. PostgreSQL lowercases unquoted names and preserves quoted case, while MySQL, MariaDB and SQLite keep the spelling used in the dump. Statements within the dump are matched using each database's own case rules. Pass the definition's name and schema separately; a dot inside a quoted name remains part of that name.

Schema names depend on the database that produced the dump:

- PostgreSQL definitions keep the schema named in the SQL, or `null` if none was supplied. The parser does not assume `public` or use your application's search path.
- MySQL and MariaDB definitions use an explicit database qualifier or the database selected by a preceding `USE` statement. Otherwise, their schema is `null`.
- SQLite definitions use `main`, while definitions in attached databases keep those databases' names.

<a name="columns-and-types"></a>
### Columns and Types

You may use a table's `column` method to retrieve a column and inspect its type, nullability and default value:

```php
$users = $schema->table('users');
$email = $users->column('email');

$email->type->name;
$email->nullable;
$email->default;
```

The column's `type` property contains a `ColumnType` instance. In addition to the type's name, it provides its `modifiers`, array `dimensions`, `unsigned` flag and enum or set `values`. For example, a `decimal(10, 2)` declaration has `['10', '2']` as its modifiers. Types remain SQL types; the parser does not convert them to PHP types.

Columns also expose auto-increment behavior through `autoIncrement`, generated expressions through `generation`, and update expressions through `onUpdate`. Defaults and expressions remain SQL text. For example, an explicit `DEFAULT NULL` has the string value `'NULL'`, while a `null` default property means no expression was supplied or resolved. Enum labels and comments are decoded strings.

<a name="postgresql-types"></a>
### PostgreSQL Types

When inspecting PostgreSQL columns, use both the type's `name` and `schema` properties. SQL-standard spellings such as `bigint` and `timestamp with time zone` identify built-in types, so the parser returns their canonical names in the `pg_catalog` schema:

```php
// created_at timestamp(0) with time zone
$type = $users->column('created_at')->type;

$type->name; // 'timestamptz'
$type->schema; // 'pg_catalog'
$type->modifiers; // ['0']
```

Other type names keep their declared identity. For example, an unqualified `int8` or `text` has a `null` schema, since the database's search path could resolve that name to a user-defined type. If an extension type's definition is absent from the dump, the parser keeps its name without guessing its underlying type.

For a column declared using a domain, the type keeps the domain's name and schema. You may inspect its resolved underlying type through the `base` property. An interval's field restriction, such as `day to second`, is available through `intervalFields`, separate from its precision in `modifiers`. A domain's resolved `base` type keeps this restriction as well.

To determine whether a column can contain `NULL`, inspect the column's `nullable` property. A domain's `nullable` flag describes a restriction applied when converting values to that domain; it does not guarantee that columns using the domain contain no null values. PostgreSQL can store null values that already have the domain's type.

Column nullability accounts for validated not-null constraints, primary keys and identity columns. If a column has only an unvalidated not-null constraint, existing rows may still contain null values, so the column remains nullable and the table receives an [issue](#checking-completeness).

<a name="indexes-and-constraints"></a>
### Indexes and Constraints

You may use the `primaryKey` method to retrieve a table's primary key, or iterate over its `indexes` property to inspect every index:

```php
$primary = $users->primaryKey();

foreach ($users->indexes as $index) {
    foreach ($index->columns as $key) {
        echo $key->name ?? $key->expression;
    }
}
```

Each index provides its ordered keys in `columns`, along with `unique` and `primary` flags. A partial index's condition is available as SQL text in `where`. Included PostgreSQL columns are listed separately in `include` because they are not part of the indexed key.

An index key contains either a column `name` or SQL text in `expression`. The expression may describe a computed key or a specialized key part such as MariaDB's `WITHOUT OVERLAPS`, so you should not treat it as a column name.

You may inspect foreign keys through the table's `foreignKeys` property. Each foreign key provides its local `columns`, referenced `foreignColumns`, and a `foreignTable` with separate `name` and `schema` properties. Its `onUpdate`, `onDelete` and `match` properties describe the declared actions and match behavior. [MySQL](https://dev.mysql.com/doc/refman/8.0/en/ansi-diff-foreign-keys.html) and [SQLite](https://sqlite.org/foreignkeys.html#fk_unsupported) accept a `MATCH` clause but check foreign keys using `MATCH SIMPLE` semantics.

Check constraints are available through the table's `checks` property. They retain their SQL expressions and their `valid` and `enforced` flags. The parser records these declarations without evaluating them.

<a name="views"></a>
### Views

To inspect a view, retrieve it using the schema's `view` method. Its query is available as SQL text through the `definition` property:

```php
$report = $schema->view('user_report');

echo $report->definition;
```

Views are stored separately from tables, so you should check both lists when determining whether a model's table or view exists. PostgreSQL materialized views have `materialized` set to `true`. The parser does not infer view column types.

<a name="completeness-and-errors"></a>
## Completeness and Errors

Some declarations contain details the parser cannot fully describe. You may still inspect the parts it understands, but you should check completeness before relying on a result to establish that a table or column is missing.

<a name="checking-completeness"></a>
### Checking Completeness

The schema's `isComplete` method returns `false` when the schema or any of its tables has issues. You may inspect the `issues` property on the schema or a table to find out what could not be determined. Each issue provides a `message` and, when available, a SQL `line`.

If you only need information about one table, check the schema-level issues and that table's `isComplete` method. This allows you to use a complete table even when another table has issues:

```php
$users = $schema->table('users');

if ($schema->issues === [] && $users !== null && $users->isComplete()) {
    $hasEmailColumn = $users->column('email') !== null;
}
```

When these checks pass, you may treat a missing column as absent. To establish that a table is absent, its lookup must return `null` and the schema's `issues` list must be empty. Issues on another known table do not by themselves make table names uncertain.

<a name="handling-parse-errors"></a>
### Handling Parse Errors

If the parser detects malformed input, it throws a `Hypervel\SchemaParser\ParseException`. You may use the exception's `sqlLine` property to locate the error in the SQL input when that information is available. The exception's normal `getLine` method refers to the PHP source.

A complete result means the parser understood the schema facts it represents. It does not guarantee that a database server would accept every statement in the input.

<a name="supported-input"></a>
## Supported Input

You may parse dumps written by the `schema:dump` Artisan command, schema SQL produced by `mysqldump` or `mariadb-dump`, plain-text `pg_dump` output, or SQLite's `.schema` command. You may also pass equivalent schema declarations directly to the parser. PostgreSQL custom and binary archives must first be exported as SQL text.

The parser describes persistent schema and skips temporary declarations. It skips routine bodies, grants and physical storage settings; a `CREATE TABLE` statement inside a routine body does not add a table to the result. Use it to inspect a schema dump, rather than to replay arbitrary migrations.

Some definitions remain available even when their details cannot be fully described. For example, SQLite virtual tables retain their names, but receive an issue because their column definitions are incomplete. Tables with MariaDB periods or system versioning also receive issues because the parser cannot fully describe their temporal rules and implicit columns.

For handwritten MariaDB vector indexes, use `VECTOR KEY` or `VECTOR INDEX`. Bare `VECTOR` syntax is ambiguous with MySQL column declarations.

<a name="credits"></a>
## Credits

Hypervel Schema Parser began with [iamcal/SQLParser](https://github.com/iamcal/SQLParser) for MySQL and [calebdw/pg-schema-parser](https://github.com/calebdw/pg-schema-parser) for PostgreSQL, and its tests still use fixtures from both projects. It is maintained independently, with its own parser for each supported database.
