Database for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/database)

Documentation: https://hypervel.org/docs/database

## Differences From Laravel

- Laravel's external database pooler support uses a `::direct` connection suffix. Hypervel instead uses normal named connections for each endpoint and `migrations_connection` for schema and migration paths. This keeps direct and pooled endpoints as normal configured connections with their own pool settings, so Hypervel does not support Laravel's `::direct` suffix.
- Laravel exposes PDO methods on its base connection class. Hypervel's base `Connection` is driver-neutral, while PDO-backed drivers extend `PdoConnection`. Code that requires direct PDO access should accept or narrow to `PdoConnection`. When bringing in Laravel database updates, keep driver-neutral connection behavior on `Connection`, PDO mechanics on `PdoConnection`, and driver-specific behavior on the matching connection, grammar, schema builder, or processor.
- Hypervel provides `BinaryParameter` for explicitly binding already-encoded binary strings in query builder operations and Eloquent key helpers. Laravel has no equivalent wrapper.
- Laravel's `migrate:fresh` command wipes only its selected connection. Hypervel discovers the connection declared by each migration and wipes every resolved target before rebuilding the schema.
- Laravel's deprecated database-inspection forwarding helpers are intentionally not ported. Extensions can call `ConnectionInterface::getDriverTitle()` and `threadCount()` directly.
- Laravel's remaining directly deprecated Database compatibility forwarders are intentionally not ported. Use the current class-keyed factory resolver, schema blueprint and grammar APIs, and correctly named PostgreSQL truncation method instead.
- Laravel's Capsule manager exposes a `setFetchMode()` method that writes configuration its connections do not read. Hypervel omits this ineffective connection-wide setter; use `Query\Builder::fetchUsing()` for each query that needs a custom row shape.
- `make:migration` omits Laravel's deprecated `--fullpath` option and obsolete Composer constructor dependency because migration creation no longer dumps autoload files.
- `Blueprint::dropForeign()` widens Laravel's method signature with an optional constraint name when columns are supplied, allowing explicitly named foreign keys to be dropped portably across SQLite and the server databases. Custom `Blueprint` subclasses that override this method must accept the optional second argument.
- Eloquent models that override `CREATED_AT` or `UPDATED_AT` must declare the compatible `?string` constant type, such as `public const ?string UPDATED_AT = null;`. Laravel's constants are untyped, but omitting the type from an override in Hypervel causes a fatal error.

Ported from: https://github.com/laravel/framework
