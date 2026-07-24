# Coroutines

- [Introduction](#introduction)
    - [Coroutines and Concurrent Tasks](#coroutines-and-concurrent-tasks)
- [Creating Coroutines](#creating-coroutines)
    - [Running Code in a Coroutine Container](#running-code-in-a-coroutine-container)
    - [Getting the Current Coroutine ID](#getting-the-current-coroutine-id)
    - [Parent Coroutine IDs](#parent-coroutine-ids)
    - [Determining if Code is Running in a Coroutine](#determining-if-code-is-running-in-a-coroutine)
    - [Creating a Child Coroutine](#creating-a-child-coroutine)
    - [Copying Coroutine Context](#copying-coroutine-context)
    - [Nested Coroutines](#nested-coroutines)
- [Error Handling](#error-handling)
    - [Reporting Unhandled Exceptions](#reporting-unhandled-exceptions)
- [Deferred Coroutine Cleanup](#deferred-coroutine-cleanup)
- [Channels](#channels)
- [Waiting for Results](#waiting-for-results)
    - [The `wait` Helper](#the-wait-helper)
    - [Wait Groups](#wait-groups)
    - [Barriers](#barriers)
- [Running Work in Parallel](#running-work-in-parallel)
    - [Limiting Parallel Work](#limiting-parallel-work)
    - [Inspecting Parallel Failures](#inspecting-parallel-failures)
- [Limiting Concurrent Coroutines](#limiting-concurrent-coroutines)
    - [Waiting for Limited Coroutines](#waiting-for-limited-coroutines)
- [Locks](#locks)
    - [Mutexes](#mutexes)
    - [Lockers](#lockers)
- [Advanced Coroutine APIs](#advanced-coroutine-apis)
- [Common Pitfalls](#common-pitfalls)

<a name="introduction"></a>
## Introduction

Hypervel uses Swoole coroutines to run many tasks within a single worker process. When one coroutine waits for input or output (I/O), such as a network request, Redis command, database query, file operation, or timer, the worker may continue running other coroutines. This allows I/O-heavy applications to handle many tasks at once without creating a separate operating system process or thread for each request.

A coroutine runs a function that can pause and later resume from the same point. Swoole switches between coroutines when one reaches an operation that can pause, such as an I/O operation supported by Swoole, a channel operation, a sleep, or an explicit coroutine call. Ordinary PHP code continues running until it reaches one of these operations.

Hypervel uses this model for HTTP requests, console commands, queued jobs, scheduled tasks, tests, and I/O connection pools. Store request-specific and coroutine-specific state in coroutine context instead of global variables or mutable static properties.

For a detailed overview of Hypervel's runtime model, see the [introduction](/docs/{{version}}/introduction#why-hypervel).

<a name="coroutines-and-concurrent-tasks"></a>
### Coroutines and Concurrent Tasks

If your application needs to run several independent tasks and collect their results, you should start with Hypervel's [concurrency](/docs/{{version}}/concurrency) APIs. The `Concurrency` facade provides a high-level API that works with the rest of the framework.

Use the lower-level coroutine APIs in this guide when you need to start work without waiting for a result, limit how many coroutines may run, clean up when a coroutine exits, use channels or locks, customize waiting, or control how context is copied.

<a name="creating-coroutines"></a>
## Creating Coroutines

Hypervel provides the `Hypervel\Coroutine\Coroutine` class and several helper functions in the `Hypervel\Coroutine` namespace.

<a name="running-code-in-a-coroutine-container"></a>
### Running Code in a Coroutine Container

Most Hypervel entry points already run inside a coroutine. This includes HTTP requests, Hypervel console commands, queue workers, and framework tests.

If your code starts outside a coroutine and needs coroutine support, you may use the `run` function to create a coroutine container:

```php
use Hypervel\Coroutine\Coroutine;

use function Hypervel\Coroutine\run;

echo Coroutine::id(); // -1

run(function () {
    echo Coroutine::id(); // A positive coroutine ID...
});
```

You may pass Swoole hook flags as the second argument:

```php
use function Hypervel\Coroutine\run;

run(function () {
    // ...
}, SWOOLE_HOOK_ALL);
```

> [!WARNING]
> The `run` function may only be called outside an existing coroutine. Calling it inside a coroutine will throw an exception.

<a name="getting-the-current-coroutine-id"></a>
### Getting the Current Coroutine ID

You may retrieve the current coroutine ID using the `id` method:

```php
use Hypervel\Coroutine\Coroutine;

$coroutineId = Coroutine::id();
```

When code is running outside a coroutine, `Coroutine::id()` returns `-1`. Inside a coroutine, it returns a positive integer.

<a name="parent-coroutine-ids"></a>
### Parent Coroutine IDs

You may retrieve the parent coroutine ID using the `parentId` method or its `pid` alias:

```php
use Hypervel\Coroutine\Coroutine;

$parentId = Coroutine::parentId();

$parentId = Coroutine::pid();
```

When the current coroutine is a top-level coroutine, the parent ID is `0`. You may also pass a coroutine ID to inspect the parent of another coroutine:

```php
$parentId = Coroutine::parentId($coroutineId);
```

<a name="determining-if-code-is-running-in-a-coroutine"></a>
### Determining if Code is Running in a Coroutine

The `inCoroutine` method determines if the current code is running inside a coroutine:

```php
use Hypervel\Coroutine\Coroutine;

if (Coroutine::inCoroutine()) {
    // ...
}
```

<a name="creating-a-child-coroutine"></a>
### Creating a Child Coroutine

You may create a child coroutine using the `go` function:

```php
use function Hypervel\Coroutine\go;

go(function () {
    sleep(1);

    echo 'In coroutine' . PHP_EOL;
});

echo 'Hello world!' . PHP_EOL;
```

The `go` function returns a positive ID for the created coroutine. If Swoole cannot create the coroutine, Hypervel throws a `CoroutineCreateException`. The `co` function is an alias of `go`:

```php
use function Hypervel\Coroutine\co;

$coroutineId = co(function () {
    // ...
});
```

You may also create a coroutine directly through the `Coroutine` class. The `create` and `fork` methods follow the same contract: they return a positive coroutine ID on success and throw `CoroutineCreateException` when creation fails:

```php
use Hypervel\Coroutine\Coroutine;

$coroutineId = Coroutine::create(function () {
    // ...
});
```

<a name="copying-coroutine-context"></a>
### Copying Coroutine Context

Child coroutines start with a fresh coroutine context by default:

```php
use Hypervel\Context\CoroutineContext;

use function Hypervel\Coroutine\go;

go(function () {
    CoroutineContext::set('request_id', 'abc');

    go(function () {
        CoroutineContext::get('request_id'); // null
    });
});
```

When you enable context copying, Hypervel copies values from the current coroutine, such as the coroutine handling an HTTP request, console command, queued job, or test.

If the child coroutine needs the parent context, pass `copyContext: true` to copy all parent context keys:

```php
go(function () {
    CoroutineContext::set('request_id', 'abc');

    go(function () {
        $requestId = CoroutineContext::get('request_id');
    }, copyContext: true);
});
```

You may also copy only specific keys:

```php
go(function () {
    CoroutineContext::set('request_id', 'abc');
    CoroutineContext::set('user_id', 123);

    go(function () {
        $requestId = CoroutineContext::get('request_id');
        $userId = CoroutineContext::get('user_id'); // null
    }, copyContext: ['request_id']);
});
```

The `Coroutine::fork` method provides the same behavior when you prefer the class API:

```php
use Hypervel\Context\CoroutineContext;
use Hypervel\Coroutine\Coroutine;

use function Hypervel\Coroutine\go;

go(function () {
    CoroutineContext::set('request_id', 'abc');

    Coroutine::fork(function () {
        $requestId = CoroutineContext::get('request_id');
    }, ['request_id']);
});
```

When the copied value is an object, the object reference is shared unless the object implements `Hypervel\Context\ReplicableContext`. See the [coroutine context](/docs/{{version}}/coroutine-context) documentation for more information.

<a name="nested-coroutines"></a>
### Nested Coroutines

Coroutines may create other coroutines:

```php
use function Hypervel\Coroutine\go;

go(function () {
    echo 'In parent coroutine' . PHP_EOL;

    go(function () {
        sleep(1);

        echo 'In nested coroutine' . PHP_EOL;
    });

    echo 'Back to parent coroutine' . PHP_EOL;
});

echo 'Main process' . PHP_EOL;
```

Each nested coroutine has its own coroutine ID and its own coroutine context. Context values are isolated unless you explicitly copy them into the child coroutine.

<a name="error-handling"></a>
## Error Handling

A `try` / `catch` block only catches exceptions thrown inside the same coroutine. Calling `go()` creates a new coroutine and returns immediately, so a caller-side `try` / `catch` block will not catch exceptions thrown in the child coroutine:

```php
use function Hypervel\Coroutine\go;

try {
    go(function () {
        throw new RuntimeException('Unable to process task.');
    });
} catch (Throwable $exception) {
    // This will not run...
}
```

Place the `try` / `catch` block inside the coroutine:

```php
use function Hypervel\Coroutine\go;

go(function () {
    try {
        throw new RuntimeException('Unable to process task.');
    } catch (Throwable $exception) {
        report($exception);
    }
});
```

If you need to collect results or rethrow child coroutine exceptions in the parent coroutine, use [`parallel`](#running-work-in-parallel) or [`wait`](#the-wait-helper).

<a name="reporting-unhandled-exceptions"></a>
### Reporting Unhandled Exceptions

Hypervel catches unhandled exceptions thrown inside `Coroutine::create`, `go`, `co`, or `Coroutine::fork` and reports them through the application's exception handler when one is available.

You may disable this automatic reporting for the entire worker process using `enableReportException`:

```php
use Hypervel\Coroutine\Coroutine;

Coroutine::enableReportException(false);
```

> [!WARNING]
> This setting remains active for the lifetime of the Swoole worker and affects every coroutine. Configure it during application boot or tests only.

<a name="deferred-coroutine-cleanup"></a>
## Deferred Coroutine Cleanup

The `Coroutine::defer` method schedules a callback to run when the current coroutine exits. Deferred callbacks are useful for releasing resources that belong to a single coroutine:

```php
use Hypervel\Coroutine\Coroutine;

use function Hypervel\Coroutine\go;

go(function () {
    Coroutine::defer(function () {
        echo 'Cleanup 1' . PHP_EOL;
    });

    Coroutine::defer(function () {
        echo 'Cleanup 2' . PHP_EOL;
    });

    echo 'Main logic' . PHP_EOL;
});
```

Deferred callbacks run in last-in, first-out order.

If a deferred callback throws an exception, Hypervel catches it and reports it through the application's exception handler when one is available. Add your own `try` / `catch` inside the deferred callback only when you want to handle the exception yourself:

```php
go(function () {
    Coroutine::defer(function () {
        try {
            // ...
        } catch (Throwable $exception) {
            report($exception);
        }
    });
});
```

> [!NOTE]
> `Coroutine::defer()` runs when the current coroutine exits. The [`Hypervel\Support\defer`](/docs/{{version}}/helpers#deferred-functions) helper schedules a callback after the current HTTP response, console command, or queued job completes successfully.

<a name="channels"></a>
## Channels

Channels allow coroutines to communicate by passing values. Hypervel's channel implementation is available as `Hypervel\Engine\Channel`:

```php
use Hypervel\Engine\Channel;

use function Hypervel\Coroutine\go;

$channel = new Channel(1);

go(function () use ($channel) {
    $channel->push('Hello from a coroutine.');
});

go(function () use ($channel) {
    echo $channel->pop();
});
```

The channel capacity controls how many values may be buffered. A `push` call waits when the channel is full, and a `pop` call waits when the channel is empty. Both methods accept a timeout in seconds:

```php
$channel->push('value', timeout: 1.0);

$value = $channel->pop(timeout: 1.0);
```

After a failed operation, you may inspect the channel state:

```php
if ($channel->isTimeout()) {
    // The last operation timed out...
}

if ($channel->isClosing()) {
    // The channel is closing or closed...
}
```

You may also inspect the channel's capacity, current length, and availability:

```php
$capacity = $channel->getCapacity();

$length = $channel->getLength();

$available = $channel->isAvailable();
```

You may close a channel using the `close` method:

```php
$channel->close();
```

> [!NOTE]
> Swoole does not provide producer or consumer inspection or general readable or writable checks. Therefore, Hypervel's `hasProducers`, `hasConsumers`, `isReadable`, and `isWritable` channel methods throw an exception.

<a name="waiting-for-results"></a>
## Waiting for Results

<a name="the-wait-helper"></a>
### The `wait` Helper

The `wait` helper runs a closure inside a new coroutine and waits for its return value:

```php
use function Hypervel\Coroutine\wait;

$result = wait(function () {
    return 'done';
});
```

You may pass a timeout in seconds:

```php
$result = wait(function () {
    sleep(1);

    return 'done';
}, timeout: 2.0);
```

If no timeout is provided, `wait` will wait up to 10 seconds for the closure to finish.

If the closure throws an exception, `wait` rethrows it in the waiting coroutine after the child's deferred callbacks have finished.

If the timeout is reached, Hypervel cancels the child by throwing `Swoole\Coroutine\CanceledException` inside it. Hypervel then gives the child up to 10 seconds to finish and run its deferred callbacks before throwing `Hypervel\Coroutine\Exceptions\WaitTimeoutException` in the waiting coroutine.

Code that catches the cancellation and keeps running may remain active after this 10-second cleanup period.

You may also use the `Waiter` class directly:

```php
use Hypervel\Coroutine\Waiter;

$waiter = new Waiter(timeout: 10.0);

$result = $waiter->wait(function () {
    return 'done';
});
```

<a name="wait-groups"></a>
### Wait Groups

A `WaitGroup` allows one coroutine to wait until a group of other coroutines finishes:

```php
use Hypervel\Coroutine\WaitGroup;

use function Hypervel\Coroutine\go;

$waitGroup = new WaitGroup();

foreach ($jobs as $job) {
    $waitGroup->add();

    go(function () use ($job, $waitGroup) {
        try {
            $job->handle();
        } finally {
            $waitGroup->done();
        }
    });
}

$waitGroup->wait();
```

You may initialize the counter in the constructor:

```php
$waitGroup = new WaitGroup(count($jobs));
```

The `wait` method accepts a timeout in seconds and returns `true` when all work has completed or `false` when the wait timed out:

```php
if (! $waitGroup->wait(timeout: 5.0)) {
    // The wait timed out...
}
```

You may inspect the current counter using the `count` method:

```php
$count = $waitGroup->count();
```

<a name="barriers"></a>
### Barriers

A `Barrier` waits for every coroutine that captures it to finish:

```php
use Hypervel\Coroutine\Barrier;
use Hypervel\Coroutine\Coroutine;

$barrier = Barrier::create();

foreach ($jobs as $job) {
    // Capturing the barrier allows Barrier::wait() to observe this coroutine.
    Coroutine::create(function () use ($barrier, $job) {
        $job->handle();
    });
}

Barrier::wait($barrier);
```

<a name="running-work-in-parallel"></a>
## Running Work in Parallel

The `parallel` helper runs multiple callbacks concurrently and waits for all of them to finish. Results are returned using the keys from the input array:

```php
use function Hypervel\Coroutine\parallel;

$results = parallel([
    'users' => fn () => countUsers(),
    'orders' => fn () => countOrders(),
]);

$results['users'];
$results['orders'];
```

You may limit the number of callbacks that run at the same time using the second argument:

```php
$results = parallel($callbacks, concurrent: 10);
```

By default, child coroutines receive a fresh context. You may copy all parent context keys or only specific keys using the `copyContext` argument:

```php
$results = parallel($callbacks, copyContext: true);

$results = parallel($callbacks, copyContext: ['request_id']);
```

If any callback throws an exception, `parallel` waits for every callback to finish and then throws `Hypervel\Coroutine\Exceptions\ParallelExecutionException`. The exception contains the successful results and the throwables captured from failed callbacks:

```php
use Hypervel\Coroutine\Exceptions\ParallelExecutionException;

try {
    parallel($callbacks);
} catch (ParallelExecutionException $exception) {
    $results = $exception->getResults();

    $throwables = $exception->getThrowables();
}
```

<a name="limiting-parallel-work"></a>
### Limiting Parallel Work

For more control, use the `Parallel` class directly:

```php
use Hypervel\Coroutine\Parallel;

$parallel = new Parallel(concurrent: 5, copyContext: true);

$parallel->add(fn () => countUsers(), 'users');
$parallel->add(fn () => countOrders(), 'orders');

$results = $parallel->wait();
```

The `count` method returns the number of registered callbacks:

```php
$count = $parallel->count();
```

The `clear` method removes all registered callbacks, results, and captured throwables:

```php
$parallel->clear();
```

<a name="inspecting-parallel-failures"></a>
### Inspecting Parallel Failures

If you do not want `wait` to throw when one or more callbacks fail, pass `throw: false`:

```php
$results = $parallel->wait(throw: false);

if ($parallel->hasFailures()) {
    $throwables = $parallel->getThrowables();
}
```

You may retrieve the number of failed callbacks using `failedCount`:

```php
$failedCount = $parallel->failedCount();
```

<a name="limiting-concurrent-coroutines"></a>
## Limiting Concurrent Coroutines

The `Concurrent` class limits how many child coroutines may run at the same time:

```php
use Hypervel\Coroutine\Concurrent;

$concurrent = new Concurrent(10);

foreach ($jobs as $job) {
    $concurrent->create(function () use ($job) {
        $job->handle();
    });
}
```

When the limit is reached, `create` waits until an existing child coroutine finishes and releases a slot.

You may inspect the current limit and number of running coroutines:

```php
$limit = $concurrent->getLimit();

$runningCoroutineCount = $concurrent->getRunningCoroutineCount();

$runningCoroutineCount = $concurrent->getLength();

$runningCoroutineCount = $concurrent->length();
```

You may use the `isFull` method to determine if the concurrency limit has been reached and the `isEmpty` method to determine if all child coroutines have finished:

```php
if ($concurrent->isFull()) {
    // The concurrency limit has been reached...
}

if ($concurrent->isEmpty()) {
    // No child coroutines are currently running...
}
```

You may access the underlying channel using `getChannel`:

```php
$channel = $concurrent->getChannel();
```

You may use `fork` instead of `create` when child coroutines should receive a copy of the parent context:

```php
$concurrent->fork(function () {
    // ...
}, ['request_id']);
```

<a name="waiting-for-limited-coroutines"></a>
### Waiting for Limited Coroutines

The `WaitConcurrent` class combines concurrency limiting with a `wait` method:

```php
use Hypervel\Coroutine\WaitConcurrent;

$concurrent = new WaitConcurrent(10);

foreach ($jobs as $job) {
    $concurrent->create(function () use ($job) {
        $job->handle();
    });
}

$concurrent->wait();
```

The `wait` method accepts a timeout in seconds and returns `true` when all child coroutines have completed or `false` when the wait timed out:

```php
if (! $concurrent->wait(timeout: 5.0)) {
    // The wait timed out...
}
```

<a name="locks"></a>
## Locks

<a name="mutexes"></a>
### Mutexes

The `Mutex` class ensures that only one coroutine at a time may hold a lock for a given string key:

```php
use Hypervel\Coroutine\Mutex;

if (Mutex::lock('reports')) {
    try {
        // Only one coroutine may run this block for the key...
    } finally {
        Mutex::unlock('reports');
    }
}
```

The `lock` and `unlock` methods both accept timeouts in seconds:

```php
if (! Mutex::lock('reports', timeout: 1.0)) {
    // The lock could not be acquired...
}

if (! Mutex::unlock('reports', timeout: 1.0)) {
    // The lock was not released...
}
```

You may clear the mutex for a key using the `clear` method:

```php
Mutex::clear('reports');
```

<a name="lockers"></a>
### Lockers

The `Locker` class allows one coroutine to perform work while other coroutines wait for it to finish. The first coroutine to call `lock` for a key receives `true`. Other coroutines wait until the key is unlocked and then receive `false`:

```php
use Hypervel\Coroutine\Locker;

if (Locker::lock('warm-cache')) {
    try {
        rebuildCache();
    } finally {
        Locker::unlock('warm-cache');
    }
} else {
    // Another coroutine rebuilt the cache...
}
```

<a name="advanced-coroutine-apis"></a>
## Advanced Coroutine APIs

The `Coroutine` class provides additional methods for advanced use cases:

```php
use Hypervel\Coroutine\Coroutine;

Coroutine::sleep(0.1);

$joined = Coroutine::join([$firstCoroutineId, $secondCoroutineId], timeout: 5.0);

$statistics = Coroutine::stats();

$coroutineExists = Coroutine::exists($coroutineId);

$coroutineIds = Coroutine::list();
```

The `join` method waits for the supplied child coroutine IDs to finish. You may include IDs for coroutines that have already finished.

A `false` result may mean that none of the supplied coroutines remained active or that the timeout elapsed. It does not always indicate a failure.

The `afterCreated` method registers a callback that runs whenever `Coroutine::create` creates a coroutine. APIs built on `Coroutine::create`, including `go`, `co`, and `Coroutine::fork`, also run the callback:

```php
Coroutine::afterCreated(function () {
    // ...
});
```

> [!WARNING]
> These callbacks remain registered for the lifetime of the Swoole worker. Register them during application boot or tests only.

The `flushState` method clears coroutine settings and callbacks stored for the current worker:

```php
Coroutine::flushState();
```

> [!WARNING]
> `flushState` is intended for tests and package cleanup. Calling it during normal request handling clears coroutine settings and callbacks for every coroutine in the worker.

<a name="common-pitfalls"></a>
## Common Pitfalls

Hypervel workers stay alive and may run many coroutines at the same time. Do not store request-specific state in global variables, mutable static properties, or shared singleton object properties. Store it in [coroutine context](/docs/{{version}}/coroutine-context) instead.

Use `Coroutine::defer()` for cleanup that belongs to one coroutine. Use [`Hypervel\Support\defer`](/docs/{{version}}/helpers#deferred-functions) for callbacks that should run after a successful HTTP response, console command, or queued job.

Prefer the [Concurrency facade](/docs/{{version}}/concurrency) or the `parallel` helper when the parent coroutine needs results or exceptions from child coroutines. Use `go`, `co`, `Coroutine::create`, or `Concurrent` when a child may run independently and its exceptions may be reported instead of returned to the parent.

Swoole can make most stream-based I/O operations yield to other coroutines while they wait. Some PHP extensions cannot be hooked and will block the entire worker process. For CPU-intensive work or extensions that cannot yield, you should run the work in a separate process.
