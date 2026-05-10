# Concurrency

- [Introduction](#introduction)
    - [How it Works](#how-it-works)
- [Running Concurrent Tasks](#running-concurrent-tasks)
- [Choosing a Driver](#choosing-a-driver)
- [Deferring Concurrent Tasks](#deferring-concurrent-tasks)

<a name="introduction"></a>
## Introduction

Sometimes you may need to execute several slow tasks which do not depend on one another. In many cases, significant performance improvements can be realized by executing the tasks concurrently. Hypervel's `Concurrency` facade provides a simple, convenient API for executing a list of closures concurrently and collecting their results.

Hypervel is coroutine-first. Incoming HTTP requests and console commands run inside coroutine containers, and Hypervel's I/O-heavy services are designed to run safely inside Swoole coroutines. The `Concurrency` facade builds on that foundation, giving you an expressive way to fan out independent work without manually managing lower-level coroutine primitives such as `go`, `parallel`, `Concurrent`, `WaitGroup`, or channels.

If you need lower-level coroutine primitives for streaming work, dynamic concurrency limits, inter-task communication, or fire-and-forget tasks, use Hypervel's coroutine APIs directly.

<a name="how-it-works"></a>
### How it Works

By default, Hypervel executes concurrent tasks using the `coroutine` driver. Each task is executed in its own coroutine within the current worker process, all tasks are allowed to finish, and the returned results are ordered using the keys from the original task array.

If a task throws an exception, Hypervel waits for the other tasks to finish and then rethrows the first exception according to the input order of the task array.

The `Concurrency` facade supports three drivers: `coroutine` (the default), `process`, and `sync`.

The `process` driver is available for unusual situations where you need full operating system process isolation. It serializes each closure, dispatches it to a hidden Artisan command in a separate PHP process, and serializes the result back to the parent process. Hypervel itself uses this for dispatching Composer hook events, which run outside the normal application lifecycle. You may also use it for work that must avoid long-lived worker state, needs a clean memory image, or calls PHP extensions that Swoole cannot hook and that would otherwise block the entire worker process.

The `sync` driver is primarily useful during testing when you want to disable all concurrency and simply execute the given closures in sequence within the current process.

<a name="running-concurrent-tasks"></a>
## Running Concurrent Tasks

To run concurrent tasks, you may invoke the `Concurrency` facade's `run` method. The `run` method accepts an array of closures which should be executed concurrently:

```php
use Hypervel\Support\Facades\Concurrency;
use Hypervel\Support\Facades\DB;

[$userCount, $orderCount] = Concurrency::run([
    fn () => DB::table('users')->count(),
    fn () => DB::table('orders')->count(),
]);
```

The keys from the task array are preserved in the returned results:

```php
$results = Concurrency::run([
    'users' => fn () => DB::table('users')->count(),
    'orders' => fn () => DB::table('orders')->count(),
]);

$results['users'];
$results['orders'];
```

Each task receives a copy of the parent coroutine context. Context values changed inside one task will not leak into sibling tasks or back into the parent coroutine.

To use a specific driver, you may use the `driver` method:

```php
$results = Concurrency::driver('process')->run(...);
```

Or, to change the default concurrency driver, you should publish the `concurrency` configuration file via the `config:publish` Artisan command and update the `default` option within the file:

```shell
php artisan config:publish concurrency
```

In practice, you almost never need to change this default. Coroutine concurrency is fundamental to Hypervel's Swoole architecture, so prefer using `Concurrency::driver(...)` at the call site when a task needs a different driver.

<a name="choosing-a-driver"></a>
## Choosing a Driver

The `coroutine` driver is the correct choice for almost all application code. It is lightweight, runs in the current worker process, uses Hypervel's coroutine-safe framework services, and propagates coroutine context into each task.

The `process` driver should be reserved for tasks that need OS-level process isolation. This includes work that must run outside the current framework lifecycle, work that should not share long-lived worker memory, or work that calls extensions Swoole cannot hook. Since process tasks are serialized before being sent to the child process, the closure and any captured values must be serializable.

The `sync` driver executes tasks one after another. This is useful in tests and simple scripts where you want deterministic execution without concurrency.

<a name="deferring-concurrent-tasks"></a>
## Deferring Concurrent Tasks

If you would like to execute an array of closures concurrently, but are not interested in the results returned by those closures, you should consider using the `defer` method. When the `defer` method is invoked, the given closures are not executed immediately. Instead, Hypervel will execute the closures using its lifecycle-aware deferred callback system.

During an HTTP request, deferred concurrency tasks run after the response has been sent to the user. During a console command, they run after the command completes successfully. During an asynchronous queue job, they run after the job completes successfully.

```php
use App\Services\Metrics;
use Hypervel\Support\Facades\Concurrency;

Concurrency::defer([
    fn () => Metrics::report('users'),
    fn () => Metrics::report('orders'),
]);
```

When using the default `coroutine` driver, each deferred task runs in its own coroutine with the parent context propagated. The `defer` method returns a deferred callback instance, allowing you to mark the callback as one that should always run:

```php
Concurrency::defer([
    fn () => Metrics::report('users'),
    fn () => Metrics::report('orders'),
])->always();
```

For cleanup that should run when a single coroutine exits, use `Hypervel\Coroutine\Coroutine::defer()` instead.
