# Coroutine
[[toc]]

## Introduction

In traditional PHP-FPM environments, I/O operations are blocking by nature. This means worker processes remain idle while waiting for I/O responses, leading to inefficient resource utilization. The conventional approach to enhance concurrency is running multiple processes simultaneously, as demonstrated in Laravel's [Concurrency](https://laravel.com/docs/concurrency) feature.

However, process-based concurrency has significant drawbacks. The overhead of context switching between processes is substantial, and the concurrent capacity is constrained by available CPU cores. Essentially, improving concurrency requires either vertical scaling (more powerful hardware) or horizontal scaling (more servers). Even solutions like [Laravel Octane](https://laravel.com/docs/octane) cannot fundamentally address these limitations in I/O-intensive scenarios.

> For a detailed comparison, see [Why Hypervel?](/docs/introduction#why-hypervel)

Unlike Laravel, Hypervel supports coroutine capabilities out of the box. All th I/O operations are non-blocking I/O throughout the framework. Hypervel achieves true concurrent request processing within each worker process. When a coroutine encounters an I/O operation, instead of blocking the entire worker process, it yields control to other coroutines, allowing the worker to continue processing additional requests efficiently.

## What is Coroutine?

Coroutines are functions that can pause their execution and later resume from where they left off, maintaining their state between pauses. Think of them as functions with multiple entry and exit points.

The key mechanism behind coroutines is a concept called "cooperative multitasking." Unlike processes or threads where the operating system controls when to switch between tasks, coroutines voluntarily yield control at specific points in their code. This is typically done at I/O operations or when explicitly yielding.

When a coroutine encounters an operation that would normally block (like waiting for network data), instead of blocking the entire program, it yields control back to a scheduler. The scheduler can then run other coroutines until the blocking operation completes.

The cost for each coroutine is extremely lightweight compared to processes or threads, making them ideal for concurrent I/O operations.

::: info
For PHP developers who have never been exposed to the concept of Coroutines and have no experience with other related programming languages, I strongly recommend learning about Linux operating systems, asynchronous I/O, coroutines, network communication protocols, concurrency, and other fundamental knowledge through [Mastering Swoole PHP](https://swoolebook.com/). This will help you gain a more comprehensive understanding of how Coroutines work behind the scenes when you use them in Hypervel.
:::

### How Coroutines Work

Coroutines achieve concurrency without parallelism. They allow interleaving multiple tasks within a single thread by:

1. Saving the current execution state (variables, program counter)
2. Switching to another coroutine
3. Restoring the previous state when resuming

This approach eliminates the overhead of thread creation, context switching, and synchronization mechanisms required in multithreading. It also avoids the complexity of managing shared state between threads.

Coroutines are particularly efficient for I/O-bound operations where programs spend significant time waiting for external resources rather than performing CPU-intensive calculations.

::: info
For more detailed information, see [Coroutine](https://en.wikipedia.org/wiki/Coroutine).
:::

## Coroutine Features

Coroutines in Hypervel operate within a coroutine container environment. By default, the framework automatically initializes these containers for:

* HTTP requests
* Console commands

In most scenarios, you won't need to manually create coroutine containers as they're handled automatically by the framework.

All the coroutine-related methods in Hypervel are defined in `Hypervel\Coroutine\Coroutine`, and functions in `Hypervel\Coroutine` namespace.

### Creating Container Manually

For scenarios requiring explicit coroutine container creation (e.g., unit tests without a coroutine environment), you can use the `Hypervel\Coroutine\run` function:

```php
use Hypervel\Coroutine\Coroutine;

use function Hypervel\Coroutine\run;

echo 'My coroutine id: ' . Coroutine::id() . PHP_EOL;

run(function () {
    echo 'My coroutine id: ' .  Coroutine::id();
});
```

### Getting Coroutine Id

Each coroutine in Hypervel has a coroutine id. When executing within a coroutine context:

* The ID is a positive integer
* IDs are auto-incrementing as new coroutines are created

You can retrieve the current coroutine ID using:

```php
use Hypervel\Coroutine\Coroutine;

$id = Coroutine::id();
```

If `Coroutine::id()` returns `-1`, it indicates that the code is executing outside of a coroutine context. This is common in traditional PHP-FPM environments or running code before coroutine container initialization.

### Determine if in Coroutine

You can also use `inCoroutine` method to determine if you're in coroutines:

```php
use Hypervel\Coroutine\Coroutine;

use function Hypervel\Coroutine\run;

echo 'in coroutine: ' . (int) Coroutine::inCoroutine() . PHP_EOL;

run(function () {
    echo 'in coroutine : ' . (int) Coroutine::inCoroutine();
});
```

## Creating a Coroutine

Creating a coroutine in Hypervel is as easy as pie. If you have development experience in Golang, you will be pretty similar to its syntax:

```php
use function Hypervel\Coroutine\go;

go(function () {
    sleep(1);
    echo 'In coroutine' . PHP_EOL;
});

echo 'Hello world!' . PHP_EOL;
```

You can create coroutines simply by `go` function. Process will automatically yield out when there's I/O happening in coroutines. And when the I/O result is returned, the process will resume the coroutine and executing the rest of the code.

In the above example, you will get the following result:

```
Hello world!
In coroutine
```

`Hello world!` will be printed first, `In coroutine` will comes out after 1 second. This basic example fully demonstrates how coroutine works.

Besides `go` function, you can also create a coroutine through `Hypervel\Coroutine\Coroutine` class:

```php
use  Hypervel\Coroutine\Coroutine;

Coroutine::create(function () {
    sleep(1);
    echo 'In coroutine' . PHP_EOL;
});

echo 'Hello world!' . PHP_EOL;
```

### Nested Coroutines

Coroutines can be nested, enabling the creation of coroutines inside others:

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

Output will be:

```
Main Process
In parent coroutine
Back to parent coroutine
In nested coroutine
```

Each nested coroutine:

* Gets its own coroutine ID
* Has independent execution flow
* Has its own context storage (context are separated)
* Can be created to any depth (within memory constraints)

### Error Handling in Coroutines

The key principle to remember is that a try/catch block should only operate within a single coroutine. Think of a coroutine as an isolated try/catch cannot span across multiple coroutines. This is because coroutines execute independently in their own contexts, separate from where the try/catch is defined, making it impossible to catch exceptions thrown in different coroutines.

#### Wrong Way to Handle Throwables

```php
use function Hypervel\Coroutine\go;

try {
    go(function () {
        throw new \RuntimeException('test');
    });
} catch (\Throwable $e) {
    echo $e;
}
```

The example above is wrong because it creates a new coroutine context inside the try block. This approach is problematic since any errors occurring within the coroutine won't be caught—the call to `go()` returns immediately and execution continues. Exceptions must be thrown and caught within the same coroutine, not across different ones.

::: info
It's highly recommended to set `swoole.use_shortname` to `Off` in your `php.ini`. If you didn't turn off `swoole.use_shortname` and use global coroutine functions without the namespace, errors occurring in coroutines will not be reported by exception handler. Unhandled errors will be printed out to the console.
:::

#### Right Way to Handle Throwables

```php
<?php

use function Hypervel\Coroutine\go;

function test()
{
    throw new \RuntimeException('test');
}

go(function () {
    try {
        test();
    } catch (\Throwable $e) {
        echo $e;
    }
});
```

The correct example demonstrates how to properly implement error handling by placing the try/catch block entirely within the same coroutine—similar to traditional PHP error handling in a single process. For simplicity, you can conceptualize coroutines as userland threads: they share the same process but maintain separate execution contexts, which is why try/catch blocks cannot work across multiple coroutines.

In the proper implementation, the coroutine is established first, and the try/catch block operates completely within that coroutine, successfully catching any exceptions that occur. Always ensure your try/catch blocks exist within the same coroutine where potential exceptions might be thrown.

### Channel

Coroutines can be considered as application-level executing units controlled by the process itself. However, how to make coroutines communicate each other? Swoole adapts [CSP (Communicating Sequential Processes)](https://en.wikipedia.org/wiki/Communicating_sequential_processes) for communication in coroutines like in Golang. The core concept of this theory is:

`Do not communicate by sharing memory; instead, share memory by communicating.`

Channels are the implementation of CSP, which provides a way for coroutines to communicate with each other in Swoole.

```php
use Hypervel\Coroutine\Channel;

use function Hypervel\Coroutine\go;

// Create a channel with buffer size 1
$channel = new Channel(1);

go(function () use ($channel) {
    $channel->push('Hello from coroutine!');
});

go(function () use ($channel) {
    $data = $channel->pop();
    // Outputs: Hello from coroutine!
    echo $data;
});
```

Channels can be buffered or unbuffered:
* Buffered channels (buffer size > 0): Push operations won't block until the buffer is full
* Unbuffered channels (buffer size = 0): Push operations block until another coroutine pops the data

#### Pub/Sub Pattern

Channels can be conceptualized as an implementation of the publish-subscribe (pub/sub) pattern. In this model:

* **Publishers (producers)**: push data to the channel
* **Subscribers (consumers)**: receive data from the channel

Here's a practical example of using channels for job processing:

```php
use Hypervel\Coroutine\Channel;

use function Hypervel\Coroutine\go;

class JobProcessor
{
    public function process(array $jobs)
    {
        // Buffer 10 jobs
        $channel = new Channel(10);

        // Producer: Send jobs to channel
        go(function () use ($channel, $jobs) {
            foreach ($jobs as $job) {
                $channel->push($job);
            }
            // Signal no more jobs
            $channel->close();
        });

        // Consumer: Process jobs from channel
        go(function () use ($channel) {
            while (true) {
                $job = $channel->pop();
                // Channel closed
                if ($job === false) {
                    break;
                }
                // Process job
                $this->processJob($job);
            }
        });
    }
}
```

The publish-subscribe model through channels is particularly useful for event-driven architectures and distributing work among multiple coroutines.

### Defer

The `defer` function allows you to schedule a function to be executed when the current coroutine exits. It's a powerful coroutine feature that ensures certain code executes when a coroutine terminates, regardless of how it terminates (normally or by exception). This is similar to `try-finally` blocks but specific to coroutines.

Key aspects of `defer` are:

* **Guaranteed execution**: The deferred function will run when the coroutine exits, whether through normal completion, cancellation, or an uncaught exception
* **Resource management**: Ideal for cleaning up resources (closing files, disconnecting from services, etc.)
* **Last-in, first-out order**: Multiple defers execute in reverse order of their registration (like a stack)
* **Execution context**: Deferred functions run in the same context as the coroutine

```php
use function Hypervel\Coroutine\defer;

defer(function () {
    echo 'Cleanup 1' . PHP_EOL;
});

defer(function () {
    echo 'Cleanup 2' . PHP_EOL;
});

echo 'Main logic'. PHP_EOL;
```

The eventual output will be:

```
Main logic
Cleanup2
Cleanup1
```

Multiple defers are executed in LIFO (Last In, First Out) order.

#### Error handling in Defer

Sometimes exceptions may happen during the defer. In this case you may try to catch exceptions like below:

```php
try {
    defer(function () {
        throw new Exception('defer error');
    });
    echo 'Main logic' . PHP_EOL;
} catch (Throwable $e) {
    echo $e->getMessage() . PHP_EOL;
}
```

However, it's not going to work in this case. This is because the `defer` function doesn't execute the provided callback immediately. Instead, it schedules the callback to run at the end of the current coroutine. By the time the deferred function executes and throws the exception, the program has already left the try-catch block.

To handle exceptions in deferred functions, you should implement error handling within the deferred function itself:

```php
defer(function () {
    try {
        // Code that might throw an exception
        throw new Exception('defer error');
    } catch (Throwable $e) {
        echo $e->getMessage() . PHP_EOL;
    }
});
echo 'Main logic' . PHP_EOL;
```

### WaitGroup

WaitGroup is a synchronization primitive that allows one coroutine to wait for the completion of a collection of coroutines. It works like a counter that tracks active coroutines:

This pattern is particularly useful when you need to:
* Launch a dynamic number of concurrent operations
* Ensure all operations complete before proceeding
* Avoid complex channel management for simple synchronization

```php
use Hypervel\Coroutine\WaitGroup;

use function Hypervel\Coroutine\go;

$waiter = new WaitGroup();

for ($i = 0; $i < 3; $i++) {
    $waiter->add(1);
    go(function () use ($waiter, $i) {
        // Do some work
        sleep(1);
        echo "Task {$i} completed\n";
        $waiter->done();
    });
}

// Wait for all coroutines to complete
$waiter->wait();
echo "All tasks completed\n";
```

Here's an example using `WaitGroup` for concurrent data processing in common use cases:

```php
use Hypervel\Coroutine\WaitGroup;

use function Hypervel\Coroutine\go;

class DataProcessor
{
    public function processItems(array $items)
    {
        $results = [];
        $waiter = new WaitGroup();

        foreach ($items as $item) {
            $waiter->add(1);
            go(function () use ($waiter, $item, &$results) {
                try {
                    $result = $this->processItem($item);
                    $results[] = [
                        'item' => $item,
                        'status' => 'success',
                        'result' => $result
                    ];
                } catch (\Exception $e) {
                    $results[] = [
                        'item' => $item,
                        'status' => 'error',
                        'error' => $e->getMessage()
                    ];
                } finally {
                    $waiter->done();
                }
            });
        }

        $waiter->wait();
        return $results;
    }
}
```

### Parallel

The `parallel` function provides a convenient way to run multiple tasks concurrently and wait for all of them to complete. You can use `parallel` to replace `WaitGroup` in most cases for more convenient and concise usage:

```php
use function Hypervel\Coroutine\parallel;

$results = parallel([
    function () {
        sleep(2);
        return 'Task 1';
    },
    function () {
        sleep(1);
        return 'Task 2';
    }
]);
```

Results will contain `['Task 1', 'Task 2']`.

### Concurrent

The `concurrent` function allows you to limit the number of concurrent coroutines. This function provides a controlled way to execute multiple coroutines simultaneously while enforcing a maximum concurrency limit. When you have many coroutines that could potentially run at once, using `concurrent` helps you:

1. **Manage resource usage**: Prevent system overload by limiting how many operations happen at once
2. **Control throughput**: Balance between speed and system stability
3. **Implement rate limiting**: Useful when working with APIs or services that have request limits

```php
use Hypervel\Coroutine\Concurrent;

// Process jobs with max 10 concurrent coroutines
$concurrent = new Concurrent(10);
foreach ($jobs as $job) {
    // It will block here if there are already 10 jobs handling
    $concurrent->create(
        fn () => $job->execute()
    );
}
```

### Context

One of the most important aspects of coroutines is `context isolation`. Each coroutine has its own independent context, which means context values in one coroutine are completely isolated from other coroutines. This isolation is crucial for maintaining data consistency and preventing unexpected behavior in concurrent applications.

::: note
This is the main reason why Laravel can't really adopt full coroutines. All states in Laravel's components are stored as variables in objects, when these objects are shared by different coroutines, states will be a mess, then cause unexpected results.
:::

For example, consider a web application handling multiple requests concurrently:

```php
use Hypervel\Coroutine\Context;

use function Hypervel\Coroutine\go;

// Coroutine 1 handling User A's request
go(function () {
    Context::set('user', 'User A');
    sleep(1); // Simulate some processing
    // Outputs: User A
    echo 'Coroutine 1: ' . Context::get('user') . PHP_EOL;
});

// Coroutine 2 handling User B's request
go(function () {
    Context::set('user', 'User B');
    // Outputs: User B
    echo 'Coroutine 2: ' . Context::get('user') . PHP_EOL;
});
```

In this example:
* Each coroutine maintains its own context
* Changes to context in one coroutine don't affect other coroutines
* No need for locks or mutexes to prevent data races
* Memory is properly isolated between different requests

This context isolation is particularly important when:
* Handling multiple HTTP requests simultaneously
* Processing concurrent database operations
* Managing user sessions or request-specific data
* Dealing with authentication or user-specific information

You can see [Context](/docs/context) for full usages of context.

## Common Pitfalls

1. **Global State**: Avoid using global variables as they're shared between coroutines
2. **Resource Handling**: Always close resources properly
3. **Blocking Operations**: Most of stream-based I/O operations in extensions can be automatically hooked as coroutines by Swoole. But there might be some PHP extensions may block the entire process, like: mongoDB.

By following these guidelines and understanding how coroutines work, you can build efficient, concurrent applications with Hypervel.
