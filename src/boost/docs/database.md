# Database: Getting Started

- [Introduction](#introduction)
    - [Configuration](#configuration)
    - [Read and Write Connections](#read-and-write-connections)
    - [Connection Pooling](#connection-pooling)
    - [Configuring Database Session State](#configuring-database-session-state)
- [Running SQL Queries](#running-queries)
    - [Using Multiple Database Connections](#using-multiple-database-connections)
    - [Listening for Query Events](#listening-for-query-events)
    - [Monitoring Cumulative Query Time](#monitoring-cumulative-query-time)
- [Database Transactions](#database-transactions)
- [Connecting to the Database CLI](#connecting-to-the-database-cli)
- [Inspecting Your Databases](#inspecting-your-databases)
- [Monitoring Your Databases](#monitoring-your-databases)

<a name="introduction"></a>
## Introduction

Almost every modern web application interacts with a database. Hypervel makes interacting with databases extremely simple across a variety of supported databases using raw SQL, a [fluent query builder](/docs/{{version}}/queries), and the [Eloquent ORM](/docs/{{version}}/eloquent). Currently, Hypervel provides first-party support for four databases:

<div class="content-list" markdown="1">

- MariaDB 10.3+ ([Version Policy](https://mariadb.org/about/#maintenance-policy))
- MySQL 5.7+ ([Version Policy](https://en.wikipedia.org/wiki/MySQL#Release_history))
- PostgreSQL 10.0+ ([Version Policy](https://www.postgresql.org/support/versioning/))
- SQLite 3.26.0+

</div>

<a name="configuration"></a>
### Configuration

The configuration for Hypervel's database services is located in your application's `config/database.php` configuration file. In this file, you may define all of your database connections, as well as specify which connection should be used by default. Most of the configuration options within this file are driven by the values of your application's environment variables. Examples for most of Hypervel's supported database systems are provided in this file.

By default, Hypervel's sample [environment configuration](/docs/{{version}}/configuration#environment-configuration) uses SQLite. However, you are free to modify your database configuration as needed for your local database.

<a name="sqlite-configuration"></a>
#### SQLite Configuration

SQLite databases are contained within a single file on your filesystem. You can create a new SQLite database using the `touch` command in your terminal: `touch database/database.sqlite`. After the database has been created, you may easily configure your environment variables to point to this database by placing the absolute path to the database in the `DB_DATABASE` environment variable:

```ini
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/database.sqlite
```

By default, foreign key constraints are enabled for SQLite connections. If you would like to disable them, you should set the `DB_FOREIGN_KEYS` environment variable to `false`:

```ini
DB_FOREIGN_KEYS=false
```

<a name="configuration-using-urls"></a>
#### Configuration Using URLs

Typically, database connections are configured using multiple configuration values such as `host`, `database`, `username`, `password`, etc. Each of these configuration values has its own corresponding environment variable. This means that when configuring your database connection information on a production server, you need to manage several environment variables.

Some managed database providers such as AWS and Heroku provide a single database "URL" that contains all of the connection information for the database in a single string. An example database URL may look something like the following:

```html
mysql://root:password@127.0.0.1/forge?charset=UTF-8
```

These URLs typically follow a standard schema convention:

```html
driver://username:password@host:port/database?options
```

For convenience, Hypervel supports these URLs as an alternative to configuring your database with multiple configuration options. If the `url` configuration option is present, it will be used to extract the database connection and credential information. In Hypervel's default database configuration file, SQLite uses the `DATABASE_URL` environment variable while MariaDB, MySQL, and PostgreSQL use the `DB_URL` environment variable.

<a name="read-and-write-connections"></a>
### Read and Write Connections

Sometimes you may wish to use one database connection for SELECT statements, and another for INSERT, UPDATE, and DELETE statements. Hypervel makes this a breeze, and the proper connections will always be used whether you are using raw queries, the query builder, or the Eloquent ORM.

To see how read / write connections should be configured, let's look at this example:

```php
'mysql' => [
    'driver' => 'mysql',

    'read' => [
        'host' => [
            '192.168.1.1',
            '196.168.1.2',
        ],
    ],
    'write' => [
        'host' => [
            '192.168.1.3',
        ],
    ],
    'sticky' => true,

    'port' => env('DB_PORT', 3306),
    'database' => env('DB_DATABASE', 'hypervel'),
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'unix_socket' => env('DB_SOCKET', ''),
    'charset' => env('DB_CHARSET', 'utf8mb4'),
    'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
    'prefix' => env('DB_PREFIX', ''),
    'prefix_indexes' => true,
    'strict' => true,
    'engine' => null,
    'options' => extension_loaded('pdo_mysql') ? array_filter([
        (PHP_VERSION_ID >= 80500 ? \Pdo\Mysql::ATTR_SSL_CA : \PDO::MYSQL_ATTR_SSL_CA) => env('MYSQL_ATTR_SSL_CA'),
    ]) : [],
    'pool' => [
        'min_connections' => (int) env('DB_MIN_CONNECTIONS', 1),
        'max_connections' => (int) env('DB_MAX_CONNECTIONS', 10),
        'connect_timeout' => 10.0,
        'wait_timeout' => 3.0,
        'heartbeat' => (float) env('DB_HEARTBEAT', -1),
        'heartbeat_timeout' => (float) env('DB_HEARTBEAT_TIMEOUT', 1.0),
        'max_idle_time' => (float) env('DB_MAX_IDLE_TIME', 60),
        'max_lifetime' => (float) env('DB_MAX_LIFETIME', -1),
    ],
],
```

Note that three keys have been added to the configuration array: `read`, `write` and `sticky`. The `read` and `write` keys have array values containing a single key: `host`. The rest of the database options for the `read` and `write` connections will be merged from the main `mysql` configuration array.

You only need to place items in the `read` and `write` arrays if you wish to override the values from the main `mysql` array. So, in this case, `192.168.1.1` will be used as the host for the "read" connection, while `192.168.1.3` will be used for the "write" connection. The database credentials, prefix, character set, pool configuration, and all other options in the main `mysql` array will be shared across both connections. When multiple values exist in the `host` configuration array, a database host will be randomly chosen when a new connection is established.

You may also resolve a specific side of a configured read / write connection by appending `::read` or `::write` to the connection name:

```php
$users = DB::connection('mysql::read')->select('select * from users');
$count = DB::connection('mysql::write')->table('users')->count();
```

Use these suffixes when you need to explicitly inspect a replica or force reads through the write connection. Normal application queries do not need them; Hypervel routes reads, writes, transactions, and sticky reads automatically.

<a name="the-sticky-option"></a>
#### The `sticky` Option

The `sticky` option is an *optional* value that can be used to allow the immediate reading of records that have been written to the database during the current request cycle. If the `sticky` option is enabled and a "write" operation has been performed against the database during the current request cycle, any further "read" operations will use the "write" connection. This ensures that any data written during the request cycle can be immediately read back from the database during that same request. In Hypervel, sticky state is reset when the coroutine's connection is returned to the pool, so it will not leak into another request. It is up to you to decide if this is the desired behavior for your application.

<a name="connection-pooling"></a>
### Connection Pooling

Hypervel uses connection pools to keep database access efficient within long-lived Swoole workers. When a coroutine needs a database connection, Hypervel borrows a connection from the worker's pool, stores it for the current coroutine, and returns it to the pool when the coroutine ends. Before a connection is returned to the pool, Hypervel resets per-request state such as query logs, query duration tracking, transaction callbacks, read / write routing state, and uncommitted transactions.

Each connection may define its own `pool` configuration:

```php
'mysql' => [
    // ...

    'pool' => [
        'min_connections' => (int) env('DB_MIN_CONNECTIONS', 1),
        'max_connections' => (int) env('DB_MAX_CONNECTIONS', 10),
        'connect_timeout' => 10.0,
        'wait_timeout' => 3.0,
        'heartbeat' => (float) env('DB_HEARTBEAT', -1),
        'heartbeat_timeout' => (float) env('DB_HEARTBEAT_TIMEOUT', 1.0),
        'max_idle_time' => (float) env('DB_MAX_IDLE_TIME', 60),
        'max_lifetime' => (float) env('DB_MAX_LIFETIME', -1),
    ],
],
```

The `min_connections` option controls how far trimming excess idle connections may reduce the total managed connection count. It is not an idle-count invariant or a guaranteed total minimum, and it does not prewarm or automatically replenish the pool. The caller that first needs each new connection pays its connection-establishment cost, and the pool may have zero idle connections under load. Lifecycle-expired or unhealthy connections and explicit discards can reduce the managed count below `min_connections`; failed connection creation can leave it below that value. None is automatically replenished. The `max_connections` option determines the maximum number of connections that may be opened for the worker. The `connect_timeout` option controls how long Hypervel will wait while opening a new database connection. The `wait_timeout` option controls how long a coroutine may wait for an available connection when the pool is exhausted. The `heartbeat` option controls how often Hypervel validates idle connections in the worker pool; set this value to `-1` to disable heartbeats. When heartbeats are enabled, Hypervel checks retained idle connections with a raw `SELECT 1` ping that does not fire query events, query logs, or query duration handlers. The `heartbeat_timeout` option controls how long a heartbeat ping may run before the connection is discarded. The `max_idle_time` option controls how long an idle connection may remain in the pool while the total managed count is above `min_connections`. The `max_lifetime` option controls the upper bound for how long a pooled connection generation may live before it is recycled while idle or before it is reused; Hypervel assigns each generation an effective lifetime between 90-100% of this value to avoid synchronized reconnects. Set this value to `-1` to disable lifetime recycling.

For a connection with separate read and write hosts, each base pool slot may lazily open one write PDO and one read PDO. It does not open one PDO per configured host. If `max_connections` is `10`, a worker may therefore hold up to roughly 20 server-side database connections for that configured connection once both sides have been used. Size your database server, PgBouncer, PgDog, or other pooler capacity with that in mind. Increase `max_connections` for more concurrent database work per worker, not simply because you configured more read hosts.

Explicit `::read` connections use a separate read-side pool built from the merged read configuration, including the base `pool` settings unless the read configuration overrides them. Explicit `::write` connections do not create a separate pool, but a coroutine that uses both `mysql` and `mysql::write` at the same time may borrow two slots from the base pool. Most applications do not need these suffixes in normal query paths because Hypervel already routes reads, writes, transactions, and sticky reads automatically.

Heartbeat and max lifetime recycling apply to Hypervel's worker pool whether the connection points directly at the database or through a proxy / pooler. They help long-running workers avoid stale sockets and rotate old idle connection generations before those connections are used by a request.

Hypervel's default database configuration also includes a `pgsql-pooled` connection. This connection is intended for PostgreSQL transaction poolers such as PgBouncer and uses separate `DB_POOLED_*` environment variables. It also sets `migrations_connection` to `pgsql`, allowing your application to use the pooled connection at runtime while migration commands use the direct PostgreSQL connection.

You may use `migrations_connection` on any database connection to instruct migration commands to run against another configured connection. This is useful when a runtime connection points at a database pooler that does not support every operation required by migrations.

When you need a direct connection for migrations or schema operations, configure it as a normal connection and reference it with `migrations_connection`. Hypervel does not use Laravel's `::direct` connection suffix.

<a name="configuring-database-session-state"></a>
### Configuring Database Session State

Pooled database connections keep their physical database sessions alive across requests and jobs. If an application or package relies on context-sensitive session settings, it may register a session configurator to keep those settings synchronized with the exact physical read or write PDO before Hypervel hands it out.

Session configurators implement the `SessionConfigurator` contract. The following example maintains PostgreSQL's `application_name` setting:

```php
<?php

declare(strict_types=1);

namespace App\Database;

use Hypervel\Database\Connection;
use Hypervel\Database\SessionConfigurator;
use PDO;

final class ApplicationNameConfigurator implements SessionConfigurator
{
    public function __construct(
        private readonly string $applicationName,
    ) {
    }

    public function state(Connection $connection): ?string
    {
        return $connection->getDriverName() === 'pgsql'
            ? $this->applicationName
            : null;
    }

    public function apply(PDO $pdo, string $state, Connection $connection): void
    {
        $statement = $pdo->prepare(
            "select set_config('application_name', ?, false)"
        );
        $statement->execute([$state]);
        $statement->closeCursor();
    }
}
```

Register the worker-safe configurator in a service provider's `boot` method:

```php
use App\Database\ApplicationNameConfigurator;
use Hypervel\Database\Connection;

Connection::configureSessionUsing(
    $this->app->make(ApplicationNameConfigurator::class)
);
```

Registration is boot-only, ordered, and appending. Registered instances remain in worker-static state and are used across every coroutine for the worker lifetime. A configurator must therefore be safe to share: it may read request or job context lazily from coroutine-local state, but it must not capture that context in its constructor or a static property.

The `state` method returns an opaque string that completely identifies the desired state owned by that configurator. Hypervel calls it on every synchronized PDO hand-out, so it must be a fast, pure computation with no database or network work. Precompute the canonical string when creating an immutable context frame rather than encoding or hashing it for every query. An empty string is a real state. Return `null` only when the configurator never applies to that connection; this applicability decision must be stable for the connection. In particular, a security-sensitive configurator must represent a missing context with an explicit fail-closed state rather than `null`.

The `apply` method receives the exact physical PDO and must use it directly to establish all session settings represented by the state string. Calling connection query methods or `getPdo` from `apply` is reentrant and fails closed. Dynamic values should be bound as parameters. Settings must apply to the physical session rather than only to the current transaction; for PostgreSQL, use session-level `SET` or `set_config(..., false)`, not `SET LOCAL` or `set_config(..., true)`.

Hypervel remembers applied state separately for each physical read and write PDO. Matching state performs no configuration SQL. A clean internal pool release and a successful commit preserve a truthful memo, while rollback invalidates it because PostgreSQL may revert session settings established within the rolled-back transaction. A changed, newly connected, reconnected, or invalidated session is configured before the next application operation. A session left partially configured or with an ambiguous outcome is not returned as healthy.

The public `getPdo` and `getReadPdo` methods synchronize session state before returning. The unresolved `getRawPdo` and `getRawReadPdo` parameters, framework transaction-control paths after `BEGIN`, and a PDO handle retained after an earlier hand-out are intentionally unsynchronized escape hatches. Application code must not mutate a setting owned by a configurator through raw SQL or a retained PDO and then expect Hypervel's memo to detect the change.

Configurator SQL runs directly through PDO. It does not produce its own prepared-statement event, query event, query-log entry, or query-duration callback; when configuration is needed for an application operation, its time is included in that operation's elapsed time. With no registered configurators, hand-out adds only an empty-list branch and allocates no session state. With a matching state, it performs an in-process physical-PDO lookup, calls `state`, and compares the returned string without issuing SQL.

> [!WARNING]
> Session configuration that must persist across statements requires a direct database connection or a proxy in session-pooling mode. Transaction- and statement-pooling modes may route consecutive statements to different server sessions and are therefore incompatible with such configurators. Hypervel cannot reliably detect a proxy's pooling mode and does not attempt to repair an incompatible deployment automatically.

<a name="running-queries"></a>
## Running SQL Queries

Once you have configured your database connection, you may run queries using the `DB` facade. The `DB` facade provides methods for each type of query: `select`, `update`, `insert`, `delete`, and `statement`.

<a name="running-a-select-query"></a>
#### Running a Select Query

To run a basic SELECT query, you may use the `select` method on the `DB` facade:

```php
<?php

namespace App\Http\Controllers;

use Hypervel\Support\Facades\DB;
use Hypervel\View\View;

class UserController extends Controller
{
    /**
     * Show a list of all of the application's users.
     */
    public function index(): View
    {
        $users = DB::select('select * from users where active = ?', [1]);

        return view('user.index', ['users' => $users]);
    }
}
```

The first argument passed to the `select` method is the SQL query, while the second argument is any parameter bindings that need to be bound to the query. Typically, these are the values of the `where` clause constraints. Parameter binding provides protection against SQL injection.

The `select` method will always return an `array` of results. Each result within the array will be a PHP `stdClass` object representing a record from the database:

```php
use Hypervel\Support\Facades\DB;

$users = DB::select('select * from users');

foreach ($users as $user) {
    echo $user->name;
}
```

<a name="selecting-scalar-values"></a>
#### Selecting Scalar Values

Sometimes your database query may result in a single, scalar value. Instead of being required to retrieve the query's scalar result from a record object, Hypervel allows you to retrieve this value directly using the `scalar` method:

```php
$burgers = DB::scalar(
    "select count(case when food = 'burger' then 1 end) as burgers from menu"
);
```

<a name="selecting-multiple-result-sets"></a>
#### Selecting Multiple Result Sets

If your application calls stored procedures that return multiple result sets, you may use the `selectResultSets` method to retrieve all of the result sets returned by the stored procedure:

```php
[$options, $notifications] = DB::selectResultSets(
    "CALL get_user_options_and_notifications(?)", [$request->user()->id]
);
```

<a name="using-named-bindings"></a>
#### Using Named Bindings

Instead of using `?` to represent your parameter bindings, you may execute a query using named bindings:

```php
$results = DB::select('select * from users where id = :id', ['id' => 1]);
```

<a name="running-an-insert-statement"></a>
#### Running an Insert Statement

To execute an `insert` statement, you may use the `insert` method on the `DB` facade. Like `select`, this method accepts the SQL query as its first argument and bindings as its second argument:

```php
use Hypervel\Support\Facades\DB;

DB::insert('insert into users (id, name) values (?, ?)', [1, 'Marc']);
```

<a name="running-an-update-statement"></a>
#### Running an Update Statement

The `update` method should be used to update existing records in the database. The number of rows affected by the statement is returned by the method:

```php
use Hypervel\Support\Facades\DB;

$affected = DB::update(
    'update users set votes = 100 where name = ?',
    ['Anita']
);
```

<a name="running-a-delete-statement"></a>
#### Running a Delete Statement

The `delete` method should be used to delete records from the database. Like `update`, the number of rows affected will be returned by the method:

```php
use Hypervel\Support\Facades\DB;

$deleted = DB::delete('delete from users');
```

<a name="running-a-general-statement"></a>
#### Running a General Statement

Some database statements do not return any value. For these types of operations, you may use the `statement` method on the `DB` facade:

```php
DB::statement('drop table users');
```

<a name="running-an-unprepared-statement"></a>
#### Running an Unprepared Statement

Sometimes you may want to execute an SQL statement without binding any values. You may use the `DB` facade's `unprepared` method to accomplish this:

```php
DB::unprepared('update users set votes = 100 where name = "Dries"');
```

> [!WARNING]
> Since unprepared statements do not bind parameters, they may be vulnerable to SQL injection. You should never allow user controlled values within an unprepared statement.

<a name="implicit-commits-in-transactions"></a>
#### Implicit Commits

When using the `DB` facade's `statement` and `unprepared` methods within transactions you must be careful to avoid statements that cause [implicit commits](https://dev.mysql.com/doc/refman/8.0/en/implicit-commit.html). These statements will cause the database engine to indirectly commit the entire transaction, leaving Hypervel unaware of the database's transaction level. An example of such a statement is creating a database table:

```php
DB::unprepared('create table a (col varchar(1) null)');
```

Please refer to the MySQL manual for [a list of all statements](https://dev.mysql.com/doc/refman/8.0/en/implicit-commit.html) that trigger implicit commits.

<a name="using-multiple-database-connections"></a>
### Using Multiple Database Connections

If your application defines multiple connections in your `config/database.php` configuration file, you may access each connection via the `connection` method provided by the `DB` facade. The connection name passed to the `connection` method should correspond to one of the connections listed in your `config/database.php` configuration file:

```php
use Hypervel\Support\Facades\DB;

$users = DB::connection('sqlite')->select(/* ... */);
```

Hypervel applications should define database connections in the configuration file before the worker boots. Runtime connection configuration is not supported because database pools are worker-level resources and configuration mutation would affect concurrent coroutines in the same worker.

You may access the underlying PDO instance of a connection using the `getPdo` method. This is a synchronized hand-out: registered database session configurators run before the PDO is returned.

```php
$pdo = DB::connection()->getPdo();
```

Low-level framework extensions may call `getRawPdo` when they intentionally need the unresolved, unsynchronized connection parameter. Its return value may be a PDO, a lazy connection closure, or `null`; normal application database work should use `getPdo` or Hypervel's query APIs instead.

<a name="listening-for-query-events"></a>
### Listening for Query Events

If you would like to specify a closure that is invoked for each SQL query executed by your application, you may use the `DB` facade's `listen` method. This method can be useful for logging queries or debugging. You may register your query listener closure in the `boot` method of a [service provider](/docs/{{version}}/providers):

```php
<?php

namespace App\Providers;

use Hypervel\Database\Events\QueryExecuted;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // ...
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DB::listen(function (QueryExecuted $query) {
            // $query->sql;
            // $query->bindings;
            // $query->time;
            // $query->toRawSql();
        });
    }
}
```

<a name="monitoring-cumulative-query-time"></a>
### Monitoring Cumulative Query Time

A common performance bottleneck of modern web applications is the amount of time they spend querying databases. Thankfully, Hypervel can invoke a closure or callback of your choice when it spends too much time querying the database during a single request. To get started, provide a query time threshold (in milliseconds) and closure to the `whenQueryingForLongerThan` method. You may invoke this method in the `boot` method of a [service provider](/docs/{{version}}/providers):

```php
<?php

namespace App\Providers;

use Hypervel\Database\Connection;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\ServiceProvider;
use Hypervel\Database\Events\QueryExecuted;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // ...
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        DB::whenQueryingForLongerThan(500, function (Connection $connection, QueryExecuted $event) {
            // Notify development team...
        });
    }
}
```

<a name="database-transactions"></a>
## Database Transactions

You may use the `transaction` method provided by the `DB` facade to run a set of operations within a database transaction. If an exception is thrown within the transaction closure, the transaction will automatically be rolled back and the exception is re-thrown. If the closure executes successfully, the transaction will automatically be committed. You don't need to worry about manually rolling back or committing while using the `transaction` method:

```php
use Hypervel\Support\Facades\DB;

DB::transaction(function () {
    DB::update('update users set votes = 1');

    DB::delete('delete from posts');
});
```

<a name="handling-deadlocks"></a>
#### Handling Deadlocks

The `transaction` method accepts an optional second argument which defines the number of times a transaction should be retried when a deadlock occurs. Once these attempts have been exhausted, an exception will be thrown:

```php
use Hypervel\Support\Facades\DB;

DB::transaction(function () {
    DB::update('update users set votes = 1');

    DB::delete('delete from posts');
}, attempts: 5);
```

<a name="manually-using-transactions"></a>
#### Manually Using Transactions

If you would like to begin a transaction manually and have complete control over rollbacks and commits, you may use the `beginTransaction` method provided by the `DB` facade:

```php
use Hypervel\Support\Facades\DB;

DB::beginTransaction();
```

You can rollback the transaction via the `rollBack` method:

```php
DB::rollBack();
```

Lastly, you can commit a transaction via the `commit` method:

```php
DB::commit();
```

> [!NOTE]
> The `DB` facade's transaction methods control the transactions for both the [query builder](/docs/{{version}}/queries) and [Eloquent ORM](/docs/{{version}}/eloquent).

<a name="connecting-to-the-database-cli"></a>
## Connecting to the Database CLI

If you would like to connect to your database's CLI, you may use the `db` Artisan command:

```shell
php artisan db
```

If needed, you may specify a database connection name to connect to a database connection that is not the default connection:

```shell
php artisan db mysql
```

If the connection has separate read and write hosts, you may connect to either host using the `--read` or `--write` options:

```shell
php artisan db mysql --read
```

The `--read` and `--write` options understand list-style read / write configuration and host arrays. When a side contains multiple hosts, the command connects to the first configured host for that side.

<a name="inspecting-your-databases"></a>
## Inspecting Your Databases

Using the `db:show` and `db:table` Artisan commands, you can get valuable insight into your database and its associated tables. To see an overview of your database, including its size, type, number of open connections, and a summary of its tables, you may use the `db:show` command:

```shell
php artisan db:show
```

You may specify which database connection should be inspected by providing the database connection name to the command via the `--database` option:

```shell
php artisan db:show --database=pgsql
```

If you would like to include table row counts and database view details within the output of the command, you may provide the `--counts` and `--views` options, respectively. On large databases, retrieving row counts and view details can be slow:

```shell
php artisan db:show --counts --views
```

The `db:show` command may also return JSON output using the `--json` option. If you would like to include user-defined database types in the output, you may provide the `--types` option.

In addition, you may use the following `Schema` methods to inspect your database:

```php
use Hypervel\Support\Facades\Schema;

$tables = Schema::getTables();
$views = Schema::getViews();
$types = Schema::getTypes();
$columns = Schema::getColumns('users');
$indexes = Schema::getIndexes('users');
$foreignKeys = Schema::getForeignKeys('users');
```

If you would like to inspect a database connection that is not your application's default connection, you may use the `connection` method:

```php
$columns = Schema::connection('sqlite')->getColumns('users');
```

<a name="table-overview"></a>
#### Table Overview

If you would like to get an overview of an individual table within your database, you may execute the `db:table` Artisan command. This command provides a general overview of a database table, including its columns, types, attributes, keys, and indexes:

```shell
php artisan db:table users
```

If you do not provide a table name, Hypervel will prompt you to select a table to inspect. You may also specify a database connection using the `--database` option or return JSON output using the `--json` option:

```shell
php artisan db:table users --database=pgsql --json
```

<a name="monitoring-your-databases"></a>
## Monitoring Your Databases

Using the `db:monitor` Artisan command, you can instruct Hypervel to dispatch a `Hypervel\Database\Events\DatabaseBusy` event if your database is managing more than a specified number of open connections.

To get started, you should schedule the `db:monitor` command to [run every minute](/docs/{{version}}/scheduling). The command accepts the names of the database connection configurations that you wish to monitor as well as the maximum number of open connections that should be tolerated before dispatching an event:

```shell
php artisan db:monitor --databases=mysql,pgsql --max=100
```

Scheduling this command alone is not enough to trigger a notification alerting you of the number of open connections. When the command encounters a database that has an open connection count that exceeds your threshold, a `DatabaseBusy` event will be dispatched. You should listen for this event within your application's `AppServiceProvider` in order to send a notification to you or your development team:

```php
use App\Notifications\DatabaseApproachingMaxConnections;
use Hypervel\Database\Events\DatabaseBusy;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Notification;

/**
 * Bootstrap any application services.
 */
public function boot(): void
{
    Event::listen(function (DatabaseBusy $event) {
        Notification::route('mail', 'dev@example.com')
            ->notify(new DatabaseApproachingMaxConnections(
                $event->connectionName,
                $event->connections
            ));
    });
}
```
