Database for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/database)

Documentation: https://hypervel.org/docs/database

## Differences From Laravel

- PDO connections require exception mode so failed statements cannot silently continue. Warning and silent options are rejected when connecting; custom connectors and supplied handles must honor the same requirement. See [PDO error mode](https://hypervel.org/docs/database#pdo-error-mode).
- Default retry and lost-connection detection recognizes PDO failures, not arbitrary exception text. Non-PDO drivers provide their own policies through the existing detector bindings; see [detecting database failures](https://hypervel.org/docs/database#detecting-database-failures).
- `DB::transaction()` throws if its closure manually ends the transaction it owns. Let `transaction()` manage completion, or use the manual transaction API separately. Balanced nested transactions remain supported; see [database transactions](https://hypervel.org/docs/database#database-transactions).
- Transaction records use the resolved connection's `getNameWithReadWriteType()`, not its base `getName()`, so distinct base, read and write sessions own separate callbacks. Extensions inspecting transaction records must compare against the resolved owner's full name.
- SQLite requires 3.32.0 or later rather than Laravel's 3.26.0 minimum, so framework code assumes the newer default limit of 32,766 bindings per statement.
- Outgoing framework HTTP requests return idle database sessions to their pools. Code using temporary tables, session locks or retained raw PDOs across these calls must use `withPinnedSession()`; ordinary queries and active transactions keep their behavior. See [releasing and pinning connections](https://hypervel.org/docs/database#releasing-and-pinning-connections).
- MySQL and MariaDB connections require `strict` or `modes` in their configuration instead of implicitly inheriting the server's SQL mode, so SQL literals can be escaped correctly.
- Laravel's external database pooler support uses a `::direct` connection suffix. Hypervel instead uses normal named connections for each endpoint and `migrations_connection` for schema and migration paths. This keeps direct and pooled endpoints as normal configured connections with their own pool settings, so Hypervel does not support Laravel's `::direct` suffix.
- Laravel exposes PDO methods on its base connection class. Hypervel's base `Connection` is driver-neutral, while PDO-backed drivers extend `PdoConnection`. Code that requires direct PDO access should accept or narrow to `PdoConnection`. When bringing in Laravel database updates, keep driver-neutral connection behavior on `Connection`, PDO mechanics on `PdoConnection`, and driver-specific behavior on the matching connection, grammar, schema builder, or processor.
- Hypervel provides `BinaryParameter` for explicitly binding already-encoded binary strings in query builder operations and Eloquent key helpers. Laravel has no equivalent wrapper.
- Laravel's `migrate:fresh` command wipes only its selected connection. Hypervel discovers the connection declared by each migration and wipes every resolved target before rebuilding the schema.
- Laravel's deprecated database-inspection forwarding helpers are intentionally not ported. Extensions can call `ConnectionInterface::getDriverTitle()` and `threadCount()` directly.
- Laravel's remaining directly deprecated Database compatibility forwarders are intentionally not ported. Use the current class-keyed factory resolver, schema blueprint and grammar APIs, and correctly named PostgreSQL truncation method instead.
- Laravel's `ConnectionResolver` is a simple registry of connection instances. Hypervel's `ConnectionResolver` is the pooled resolver built by the container, so it has no array constructor or `addConnection()` method. Tests that inject existing connections can mock `ConnectionResolverInterface`, while tests that need real configured connections can use the Capsule manager.
- Laravel's Capsule manager exposes a `setFetchMode()` method that writes configuration its connections do not read. Hypervel omits this ineffective connection-wide setter; use `Query\Builder::fetchUsing()` for each query that needs a custom row shape.
- `make:migration` omits Laravel's deprecated `--fullpath` option and obsolete Composer constructor dependency because migration creation no longer dumps autoload files.
- `Blueprint::dropForeign()` widens Laravel's method signature with an optional constraint name when columns are supplied, allowing explicitly named foreign keys to be dropped portably across SQLite and the server databases. Custom `Blueprint` subclasses that override this method must accept the optional second argument.
- Eloquent models that override `CREATED_AT` or `UPDATED_AT` must declare the compatible `?string` constant type, such as `public const ?string UPDATED_AT = null;`. Laravel's constants are untyped, but omitting the type from an override in Hypervel causes a fatal error.
- Eloquent expression plucks use `Query\Builder::pluckWithColumn()` to retain the returned field name for casts and accessors. Custom query builders overriding `pluck()` must also override `pluckWithColumn()` to customize this path. String-based Eloquent plucks still call `pluck()`.
- Eloquent's protected `refreshSavedAttributes()` method receives the raw attribute values each write stored, so configured attributes are refreshed from the row that write changed. Models that override this method must accept the `array $attributes` argument.
- Eloquent `updateFrom()` maintains the model's `updated_at` timestamp, like `update()`. Laravel forwards this method without adding a timestamp.
- `BelongsToMany` records pivot filters in an ordered `$pivotConstraints` list instead of Laravel's `$pivotWheres`, `$pivotWhereIns`, and `$pivotWhereNulls` properties. Relationship extensions should use the `wherePivot*` methods instead of accessing those arrays.

Ported from: https://github.com/laravel/framework
