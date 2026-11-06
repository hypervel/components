Schema Parser for Hypervel
===

Documentation: https://hypervel.org/docs/schema-parser

## Differences From Upstream

### iamcal/SQLParser

- `MySqlParser::parse()` returns the shared immutable `Schema` model instead of populating public arrays on the parser. The underlying lexer is internal.
- Detected malformed input always throws `ParseException`. Unsupported schema facts remain explicit through scoped issues on the returned schema or table.
- Results describe persistent schema; temporary declarations are skipped.

### calebdw/pg-schema-parser

- PostgreSQL uses the same result classes and parser contract as MySQL, MariaDB and SQLite.
- Unqualified names retain their unspecified schema instead of assuming `public`. Unqualified lookup requires a unique match and throws when a name is ambiguous.

Ported from:

- MySQL/MariaDB reader: https://github.com/iamcal/SQLParser
- PostgreSQL reader and shared-model foundation: https://github.com/calebdw/pg-schema-parser
