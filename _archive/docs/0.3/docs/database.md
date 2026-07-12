# Database: Getting Started
[[toc]]

## Introduction

Almost every modern web application interacts with a database. Hypervel makes interacting with databases extremely simple across a variety of supported databases using raw SQL, a [fluent query builder](/docs/queries), and the [Eloquent ORM](/docs/eloquent). Currently, Hypervel provides first-party support for five databases:

<div class="content-list" markdown="1">

- MariaDB 10.3+ ([Version Policy](https://mariadb.org/about/#maintenance-policy))
- MySQL 5.7+ ([Version Policy](https://en.wikipedia.org/wiki/MySQL#Release_history))
- PostgreSQL 10.0+ ([Version Policy](https://www.postgresql.org/support/versioning/))
- SQLite 3.26.0+
- SQL Server 2017+ ([Version Policy](https://docs.microsoft.com/en-us/lifecycle/products/?products=sql-server))

</div>

::: info
By default Hypervel Hyperf doesn't include drivers for PostgresSQL and SQL Server. You can install these drivers with `hyperf/database-pgsql` and `hyperf/database-sqlserver-incubator` manually.
:::

::: warning
Because MongoDB extension doesn't support coroutine feature, there's no mongo driver in Hypervel Hyperf at this moment.
:::

### Configuration

The configuration for Hypervel Hyperf's database services is located in your application's `config/database.php` configuration file. In this file, you may define all of your database connections, as well as specify which connection should be used by default. Most of the configuration options within this file are driven by the values of your application's environment variables. Examples for most of Hypervel Hyperf's supported database systems are provided in this file.

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

::: note
Hypervel Hyperf will automatically create a `database/database.sqlite` file and run the default [database migrations](/docs/migrations) for you when you install from composer.
:::

### Read and Write Connections

Sometimes you may wish to use one database connection for SELECT statements, and another for INSERT, UPDATE, and DELETE statements. Hypervel makes this a breeze, and the proper connections will always be used whether you are using raw queries, the query builder, or the Eloquent ORM.

To see how read / write connections should be configured, let's look at this example:

```php
'mysql' => [
    'read' => [
        'host' => [
            '192.168.1.1',
            '196.168.1.2',
        ],
    ],
    'write' => [
        'host' => [
            '196.168.1.3',
        ],
    ],
    'sticky' => true,

    'database' => env('DB_DATABASE', 'laravel'),
    'username' => env('DB_USERNAME', 'root'),
    'password' => env('DB_PASSWORD', ''),
    'unix_socket' => env('DB_SOCKET', ''),
    'charset' => env('DB_CHARSET', 'utf8mb4'),
    'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
    'prefix' => '',
    'prefix_indexes' => true,
    'strict' => true,
    'engine' => null,
],
```

Note that three keys have been added to the configuration array: `read`, `write` and `sticky`. The `read` and `write` keys have array values containing a single key: `host`. The rest of the database options for the `read` and `write` connections will be merged from the main `mysql` configuration array.

You only need to place items in the `read` and `write` arrays if you wish to override the values from the main `mysql` array. So, in this case, `192.168.1.1` will be used as the host for the "read" connection, while `192.168.1.3` will be used for the "write" connection. The database credentials, prefix, character set, and all other options in the main `mysql` array will be shared across both connections. When multiple values exist in the `host` configuration array, a database host will be randomly chosen for each request.

#### The `sticky` Option

The `sticky` option is an *optional* value that can be used to allow the immediate reading of records that have been written to the database during the current request cycle. If the `sticky` option is enabled and a "write" operation has been performed against the database during the current request cycle, any further "read" operations will use the "write" connection. This ensures that any data written during the request cycle can be immediately read back from the database during that same request. It is up to you to decide if this is the desired behavior for your application.

### Connection Pooling

Hypervel implements database connection pooling to efficiently manage and reuse database connections across coroutines. Connection pooling helps improve performance by reducing the overhead of establishing new database connections for each request.

```php
'mysql' => [
    'pool' => [
        'min_connections' => 1,
        'max_connections' => 10,
        'connect_timeout' => 10.0,
        'wait_timeout' => 3.0,
        'heartbeat' => -1,
        'max_idle_time' => (float) env('DB_MAX_IDLE_TIME', 60),
    ]
],
```

Each configuration option serves a specific purpose:

- `min_connections`: The minimum number of connections that will be created and maintained in the pool, ensuring a base level of available connections.
- `max_connections`: The maximum number of connections that can be created. When all connections are in use and a new request arrives, it will wait for an available connection.
- `connect_timeout`: The maximum time to wait while trying to establish a new database connection.
- `wait_timeout`: The maximum time to wait for an available connection from the pool before throwing an exception.
- `heartbeat`: The interval at which the pool checks if connections are still alive.
- `max_idle_time`: The maximum time a connection can remain idle in the pool before being closed.

The connection pool automatically handles connection lifecycle management:

1. When a connection is requested, the pool first tries to reuse an existing idle connection
2. If no idle connections are available and the pool hasn't reached `max_connections`, a new connection is created
3. If the pool is at capacity, the request waits up to `wait_timeout` seconds for a connection to become available
4. After use, connections are returned to the pool instead of being closed, allowing for reuse

This approach significantly reduces the overhead of database operations in high-concurrency scenarios by eliminating the need to establish new connections for each request.

## Running SQL Queries

Once you have configured your database connection, you may run queries using the `DB` facade. The `DB` facade provides methods for each type of query: `select`, `update`, `insert`, `delete`, and `statement`.

#### Running a Select Query

To run a basic SELECT query, you may use the `select` method on the `DB` facade:

```php
<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Hypervel\Support\Facades\DB;
use Hyperf\ViewEngine\Contract\ViewInterface;

class UserController extends Controller
{
    /**
     * Show a list of all of the application's users.
     */
    public function index(): ViewInterface
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

#### Selecting Scalar Values

Sometimes your database query may result in a single, scalar value. Instead of being required to retrieve the query's scalar result from a record object, Hypervel allows you to retrieve this value directly using the `scalar` method:

```php
$burgers = DB::scalar(
    "select count(case when food = 'burger' then 1 end) as burgers from menu"
);
```

#### Using Named Bindings

Instead of using `?` to represent your parameter bindings, you may execute a query using named bindings:

```php
$results = DB::select('select * from users where id = :id', ['id' => 1]);
```

#### Running an Insert Statement

To execute an `insert` statement, you may use the `insert` method on the `DB` facade. Like `select`, this method accepts the SQL query as its first argument and bindings as its second argument:

```php
use Hypervel\Support\Facades\DB;

DB::insert('insert into users (id, name) values (?, ?)', [1, 'Marc']);
```

#### Running an Update Statement

The `update` method should be used to update existing records in the database. The number of rows affected by the statement is returned by the method:

```php
use Hypervel\Support\Facades\DB;

$affected = DB::update(
    'update users set votes = 100 where name = ?',
    ['Anita']
);
```

#### Running a Delete Statement

The `delete` method should be used to delete records from the database. Like `update`, the number of rows affected will be returned by the method:

```php
use Hypervel\Support\Facades\DB;

$deleted = DB::delete('delete from users');
```

#### Running a General Statement

Some database statements do not return any value. For these types of operations, you may use the `statement` method on the `DB` facade:

```php
DB::statement('drop table users');
```

#### Running an Unprepared Statement

Sometimes you may want to execute an SQL statement without binding any values. You may use the `DB` facade's `unprepared` method to accomplish this:

```php
DB::unprepared('update users set votes = 100 where name = "Dries"');
```

::: warning
Since unprepared statements do not bind parameters, they may be vulnerable to SQL injection. You should never allow user controlled values within an unprepared statement.
:::

#### Implicit Commits

When using the `DB` facade's `statement` and `unprepared` methods within transactions you must be careful to avoid statements that cause [implicit commits](https://dev.mysql.com/doc/refman/8.0/en/implicit-commit.html). These statements will cause the database engine to indirectly commit the entire transaction, leaving Hypervel unaware of the database's transaction level. An example of such a statement is creating a database table:

```php
DB::unprepared('create table a (col varchar(1) null)');
```

Please refer to the MySQL manual for [a list of all statements](https://dev.mysql.com/doc/refman/8.0/en/implicit-commit.html) that trigger implicit commits.

### Using Multiple Database Connections

If your application defines multiple connections in your `config/database.php` configuration file, you may access each connection via the `connection` method provided by the `DB` facade. The connection name passed to the `connection` method should correspond to one of the connections listed in your `config/database.php` configuration file or configured at runtime using the `config` helper:

```php
use Hypervel\Support\Facades\DB;

$users = DB::connection('sqlite')->select(/* ... */);
```

You may access the raw, underlying PDO instance of a connection using the `getPdo` method on a connection instance:

```php
$pdo = DB::connection()->getPdo();
```

### Listening for Query Events

If you would like to specify a closure that is invoked for each SQL query executed by your application, you may use the `DB` facade's `listen` method. This method can be useful for logging queries or debugging. You may register your query listener closure in the `boot` method of a [service provider](/docs/providers):

```php
<?php

namespace App\Providers;

use Hyperf\Database\Events\QueryExecuted;
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
        });
    }
}
```

## Database Transactions

You may use the `transaction` method provided by the `DB` facade to run a set of operations within a database transaction. If an exception is thrown within the transaction closure, the transaction will automatically be rolled back and the exception is re-thrown. If the closure executes successfully, the transaction will automatically be committed. You don't need to worry about manually rolling back or committing while using the `transaction` method:

```php
use Hypervel\Support\Facades\DB;

DB::transaction(function () {
    DB::update('update users set votes = 1');

    DB::delete('delete from posts');
});
```

#### Handling Deadlocks

The `transaction` method accepts an optional second argument which defines the number of times a transaction should be retried when a deadlock occurs. Once these attempts have been exhausted, an exception will be thrown:

```php
use Hypervel\Support\Facades\DB;

DB::transaction(function () {
    DB::update('update users set votes = 1');

    DB::delete('delete from posts');
}, 5);
```

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

::: note
The `DB` facade's transaction methods control the transactions for both the [query builder](/docs/queries) and [Eloquent ORM](/docs/eloquent).
:::
