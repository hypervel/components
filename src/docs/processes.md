# Processes

- [Introduction](#introduction)
- [Invoking Processes](#invoking-processes)
    - [Array Commands](#array-commands)
    - [Process Options](#process-options)
    - [Process Output](#process-output)
    - [Pipelines](#process-pipelines)
- [Asynchronous Processes](#asynchronous-processes)
    - [Process IDs and Signals](#process-ids-and-signals)
    - [Asynchronous Process Output](#asynchronous-process-output)
    - [Asynchronous Process Timeouts](#asynchronous-process-timeouts)
    - [Extending Invoked Processes](#extending-invoked-processes)
- [Concurrent Processes](#concurrent-processes)
    - [Pool Results](#pool-results)
    - [Naming Pool Processes](#naming-pool-processes)
    - [Pool Process IDs and Signals](#pool-process-ids-and-signals)
- [Testing](#testing)
    - [Faking Processes](#faking-processes)
    - [Faking Specific Processes](#faking-specific-processes)
    - [Faking Process Sequences](#faking-process-sequences)
    - [Faking Asynchronous Process Lifecycles](#faking-asynchronous-process-lifecycles)
    - [Available Assertions](#available-assertions)
    - [Preventing Stray Processes](#preventing-stray-processes)

<a name="introduction"></a>
## Introduction

Hypervel provides an expressive, minimal API around the [Symfony Process component](https://symfony.com/doc/current/components/process.html), allowing you to conveniently invoke external processes from your Hypervel application. Hypervel's process features are focused on the most common use cases and a wonderful developer experience.

<a name="invoking-processes"></a>
## Invoking Processes

To invoke a process, you may use the `run` and `start` methods offered by the `Process` facade. The `run` method will invoke a process and wait for the process to finish executing, while the `start` method is used for asynchronous process execution. We'll examine both approaches within this documentation. First, let's examine how to invoke a basic, synchronous process and inspect its result:

```php
use Hypervel\Support\Facades\Process;

$result = Process::run('ls -la');

return $result->output();
```

Of course, the `Hypervel\Contracts\Process\ProcessResult` instance returned by the `run` method offers a variety of helpful methods that may be used to inspect the process result:

```php
$result = Process::run('ls -la');

$result->command();
$result->successful();
$result->failed();
$result->output();
$result->errorOutput();
$result->exitCode();
```

<a name="array-commands"></a>
### Array Commands

Instead of passing a shell command string to the `run` method, you may pass the command and its arguments as an array:

```php
$result = Process::run(['php', 'artisan', 'inspire']);
```

String commands are interpreted by your operating system's shell, while array commands keep the executable and arguments separate. Therefore, you should use array commands when including dynamic or untrusted values, or when you need to signal the executable directly.

If you need shell features such as pipes or conditional execution, you may safely include dynamic values using environment placeholders:

```php
$result = Process::env(['NAME' => $name])
    ->run('echo "${:NAME}"');
```

<a name="throwing-exceptions"></a>
#### Throwing Exceptions

If you have a process result and would like to throw an instance of `Hypervel\Process\Exceptions\ProcessFailedException` if the exit code is greater than zero (thus indicating failure), you may use the `throw` and `throwIf` methods. If the process did not fail, the `ProcessResult` instance will be returned:

```php
$result = Process::run('ls -la')->throw();

$result = Process::run('ls -la')->throwIf($condition);
```

You may pass a closure to the `throw` or `throwIf` methods to perform additional work before the exception is thrown. The closure receives the process result and the `ProcessFailedException` instance:

```php
use Hypervel\Contracts\Process\ProcessResult;
use Hypervel\Process\Exceptions\ProcessFailedException;

$result->throw(function (ProcessResult $result, ProcessFailedException $exception) {
    report($exception);
});
```

<a name="process-options"></a>
### Process Options

Of course, you may need to customize the behavior of a process before invoking it. Thankfully, Hypervel allows you to tweak a variety of process features, such as the working directory, timeout, and environment variables.

<a name="working-directory-path"></a>
#### Working Directory Path

You may use the `path` method to specify the working directory of the process. If this method is not invoked, the process will inherit the working directory of the currently executing PHP script:

```php
$result = Process::path(__DIR__)->run('ls -la');
```

<a name="input"></a>
#### Input

You may provide input via the "standard input" of the process using the `input` method:

```php
$result = Process::input('Hello World')->run('cat');
```

<a name="timeouts"></a>
#### Timeouts

By default, processes will throw an instance of `Hypervel\Process\Exceptions\ProcessTimedOutException` after executing for more than 60 seconds. However, you can customize this behavior via the `timeout` method:

```php
$result = Process::timeout(120)->run('bash import.sh');
```

The `timeout` and `idleTimeout` methods also accept `CarbonInterval` instances:

```php
use function Hypervel\Support\minutes;

$result = Process::timeout(minutes(2))->run('bash import.sh');
```

Or, if you would like to disable the process timeout entirely, you may invoke the `forever` method:

```php
$result = Process::forever()->run('bash import.sh');
```

The `idleTimeout` method may be used to specify the maximum number of seconds the process may run without returning any output:

```php
$result = Process::timeout(60)->idleTimeout(30)->run('bash import.sh');
```

<a name="environment-variables"></a>
#### Environment Variables

Environment variables may be provided to the process via the `env` method. The invoked process will also inherit all of the environment variables defined by your system:

```php
$result = Process::forever()
    ->env(['IMPORT_PATH' => __DIR__])
    ->run('bash import.sh');
```

If you wish to remove an inherited environment variable from the invoked process, you may provide that environment variable with a value of `false`:

```php
$result = Process::forever()
    ->env(['LOAD_PATH' => false])
    ->run('bash import.sh');
```

<a name="proc-open-options"></a>
#### Proc Open Options

If you need to customize the options passed to PHP's `proc_open` function, you may invoke the `options` method:

```php
$result = Process::options(['create_new_console' => true])
    ->run('php artisan inspire');
```

<a name="tty-mode"></a>
#### TTY Mode

The `tty` method may be used to enable TTY mode for your process. TTY mode connects the input and output of the process to the input and output of your program, allowing your process to open an editor like Vim or Nano as a process:

```php
Process::forever()->tty()->run('vim');
```

You may use the `supportsTty` method to determine if TTY mode is supported by the current operating system:

```php
if (Process::supportsTty()) {
    Process::forever()->tty()->run('vim');
}
```

> [!WARNING]
> TTY mode is not supported on Windows.

<a name="process-output"></a>
### Process Output

As previously discussed, process output may be accessed using the `output` (stdout) and `errorOutput` (stderr) methods on a process result:

```php
use Hypervel\Support\Facades\Process;

$result = Process::run('ls -la');

echo $result->output();
echo $result->errorOutput();
```

However, output may also be gathered in real-time by passing a closure as the second argument to the `run` method. The closure will receive two arguments: the "type" of output (`out` for standard output or `err` for error output) and the output string itself:

```php
$result = Process::run('ls -la', function (string $type, string $output) {
    echo $output;
});
```

If an output callback throws an exception, Hypervel will stop the affected process and rethrow the same exception.

Hypervel also offers the `seeInOutput` and `seeInErrorOutput` methods, which provide a convenient way to determine if a given string was contained in the process' output:

```php
if (Process::run('ls -la')->seeInOutput('hypervel')) {
    // ...
}
```

<a name="disabling-process-output"></a>
#### Disabling Process Output

If your process is writing a significant amount of output that you are not interested in, you can conserve memory by disabling output retrieval entirely. To accomplish this, invoke the `quietly` method while building the process:

```php
use Hypervel\Support\Facades\Process;

$result = Process::quietly()->run('bash import.sh');
```

<a name="process-pipelines"></a>
### Pipelines

Sometimes you may want to make the output of one process the input of another process. This is often referred to as "piping" the output of a process into another. The `pipe` method provided by the `Process` facade makes this easy to accomplish. The `pipe` method will execute the piped processes synchronously and return the process result for the last process in the pipeline:

```php
use Hypervel\Process\Pipe;
use Hypervel\Support\Facades\Process;

$result = Process::pipe(function (Pipe $pipe) {
    $pipe->command('cat example.txt');
    $pipe->command('grep -i "hypervel"');
});

if ($result->successful()) {
    // ...
}
```

If you do not need to customize the individual processes that make up the pipeline, you may simply pass an array of command strings to the `pipe` method:

```php
$result = Process::pipe([
    'cat example.txt',
    'grep -i "hypervel"',
]);
```

The process output may be gathered in real-time by passing a closure as the second argument to the `pipe` method. The closure will receive two arguments: the "type" of output (`out` for standard output or `err` for error output) and the output string itself:

```php
$result = Process::pipe(function (Pipe $pipe) {
    $pipe->command('cat example.txt');
    $pipe->command('grep -i "hypervel"');
}, function (string $type, string $output) {
    echo $output;
});
```

Hypervel also allows you to assign string keys to each process within a pipeline via the `as` method. This key will also be passed to the output closure provided to the `pipe` method, allowing you to determine which process the output belongs to:

```php
$result = Process::pipe(function (Pipe $pipe) {
    $pipe->as('first')->command('cat example.txt');
    $pipe->as('second')->command('grep -i "hypervel"');
}, function (string $type, string $output, string $key) {
    // ...
});
```

<a name="asynchronous-processes"></a>
## Asynchronous Processes

While the `run` method invokes processes synchronously, the `start` method may be used to invoke a process asynchronously. This allows your application to continue performing other tasks while the process runs in the background. Once the process has been invoked, you may utilize the `running` method to determine if the process is still running:

```php
$process = Process::timeout(120)->start('bash import.sh');

while ($process->running()) {
    // ...
}

$result = $process->wait();
```

As you may have noticed, you may invoke the `wait` method to wait until the process is finished executing and retrieve the `ProcessResult` instance:

```php
$process = Process::timeout(120)->start('bash import.sh');

// ...

$result = $process->wait();
```

An asynchronously started process remains owned by the coroutine that started it. Before that coroutine exits, you must call `wait` to let the process finish or `stop` to terminate it. Returning from `waitUntil` only stops waiting; it does not stop the process.

<a name="process-ids-and-signals"></a>
### Process IDs and Signals

The `id` method may be used to retrieve the operating system assigned process ID of the running process:

```php
$process = Process::start('bash import.sh');

return $process->id();
```

You may use the `signal` method to send a "signal" to the running process. A list of predefined signal constants can be found within the [PHP documentation](https://www.php.net/manual/en/pcntl.constants.php):

```php
$process->signal(SIGUSR2);
```

You may use the `stop` method to stop a running process:

```php
$process->stop();
```

By default, Hypervel sends `SIGTERM` and waits up to 10 seconds for the process to exit. If the process is still running, Hypervel sends `SIGKILL`. You may customize the grace period and fallback signal by passing them to the `stop` method:

```php
$process->stop(timeout: 5, signal: SIGKILL);
```

<a name="asynchronous-process-output"></a>
### Asynchronous Process Output

While an asynchronous process is running, you may access its entire current output using the `output` and `errorOutput` methods; however, you may utilize the `latestOutput` and `latestErrorOutput` to access the output from the process that has occurred since the output was last retrieved:

```php
$process = Process::timeout(120)->start('bash import.sh');

while ($process->running()) {
    echo $process->latestOutput();
    echo $process->latestErrorOutput();

    sleep(1);
}
```

Like the `run` method, output may also be gathered in real-time from asynchronous processes by passing a closure as the second argument to the `start` method. The closure will receive two arguments: the "type" of output (`out` for standard output or `err` for error output) and the output string itself:

```php
$process = Process::start('bash import.sh', function (string $type, string $output) {
    echo $output;
});

$result = $process->wait();
```

Alternatively, you may pass the output closure to the `wait` method:

```php
$process = Process::start('bash import.sh');

$result = $process->wait(function (string $type, string $output) {
    echo $output;
});
```

Instead of waiting until the process has finished, you may use the `waitUntil` method to stop waiting based on the output of the process. Hypervel will stop waiting for the process to finish when the closure given to the `waitUntil` method returns `true`:

```php
$process = Process::start('bash import.sh');

$process->waitUntil(function (string $type, string $output) {
    return $output === 'Ready...';
});
```

<a name="asynchronous-process-timeouts"></a>
### Asynchronous Process Timeouts

While an asynchronous process is running, you may verify that the process has not timed out using the `ensureNotTimedOut` method. This method will throw a [timeout exception](#timeouts) if the process has timed out. The `running` method alone does not enforce asynchronous process timeouts. Therefore, you should invoke `ensureNotTimedOut` regularly while polling a process, or call `wait`, which will also enforce the configured timeouts:

```php
$process = Process::timeout(120)->start('bash import.sh');

while ($process->running()) {
    $process->ensureNotTimedOut();

    // ...

    sleep(1);
}
```

<a name="extending-invoked-processes"></a>
### Extending Invoked Processes

The `Hypervel\Process\InvokedProcess` class is macroable, allowing you to add custom methods to invoked processes. You should register these macros within the `boot` method of your application's `App\Providers\AppServiceProvider` class:

```php
use Hypervel\Process\InvokedProcess;

public function boot(): void
{
    InvokedProcess::macro('summary', function () {
        return 'Running: ' . $this->command();
    });
}
```

Once the macro has been registered, you may invoke it on a running process:

```php
$process = Process::start('bash import.sh');

return $process->summary();
```

> [!WARNING]
> Macros should only be registered during worker boot. Macro registrations are stored in static state for the worker lifetime and affect every subsequent invoked process.

Macros are only available on real invoked processes; fake invoked processes returned when using `Process::fake()` do not support registered macros.

<a name="concurrent-processes"></a>
## Concurrent Processes

Hypervel also makes it a breeze to manage a pool of concurrent, asynchronous processes, allowing you to easily execute many tasks simultaneously. To get started, invoke the `pool` method, which accepts a closure that receives an instance of `Hypervel\Process\Pool`.

Within this closure, you may define the processes that belong to the pool. Once a process pool is started via the `start` method, you may access the [collection](/docs/{{version}}/collections) of running processes via the `running` method:

```php
use Hypervel\Process\Pool;
use Hypervel\Support\Facades\Process;

$pool = Process::pool(function (Pool $pool) {
    $pool->path(__DIR__)->command('bash import-1.sh');
    $pool->path(__DIR__)->command('bash import-2.sh');
    $pool->path(__DIR__)->command('bash import-3.sh');
})->start(function (string $type, string $output, int $key) {
    // ...
});

while ($pool->running()->isNotEmpty()) {
    // ...
}

$results = $pool->wait();
```

An asynchronously started pool remains owned by the coroutine that started it. Before that coroutine exits, you must call `wait` to let every process finish or `stop` to terminate the running processes.

If the pool fails while starting or waiting, Hypervel stops any processes it has already started or still owns before rethrowing the original exception.

If you do not need to inspect the pool while its processes are running, you may use the `run` method to start the process pool and immediately wait on its results:

```php
$results = Process::pool(function (Pool $pool) {
    $pool->path(__DIR__)->command('bash import-1.sh');
    $pool->path(__DIR__)->command('bash import-2.sh');
    $pool->path(__DIR__)->command('bash import-3.sh');
})->run();
```

Or, for convenience, the `concurrently` method may be used to start an asynchronous process pool and immediately wait on its results. This can provide particularly expressive syntax when combined with PHP's array destructuring capabilities:

```php
[$first, $second, $third] = Process::concurrently(function (Pool $pool) {
    $pool->path(__DIR__)->command('ls -la');
    $pool->path(app_path())->command('ls -la');
    $pool->path(storage_path())->command('ls -la');
});

echo $first->output();
```

<a name="pool-results"></a>
### Pool Results

You may wait for all of the pool processes to finish executing and resolve their results via the `wait` method. The `wait` method returns an array accessible object that allows you to access the `ProcessResult` instance of each process in the pool by its key:

```php
$results = $pool->wait();

echo $results[0]->output();
```

The process pool results object also offers `successful` and `failed` methods which may be used to determine if the pool succeeded or failed. You may use the `collect` method to retrieve the results as a collection:

```php
if ($results->successful()) {
    $results->collect()->each->output();
}
```

<a name="naming-pool-processes"></a>
### Naming Pool Processes

Accessing process pool results via a numeric key is not very expressive; therefore, Hypervel allows you to assign string keys to each process within a pool via the `as` method. This key will also be passed to the closure provided to the `start` method, allowing you to determine which process the output belongs to:

```php
$pool = Process::pool(function (Pool $pool) {
    $pool->as('first')->command('bash import-1.sh');
    $pool->as('second')->command('bash import-2.sh');
    $pool->as('third')->command('bash import-3.sh');
})->start(function (string $type, string $output, string $key) {
    // ...
});

$results = $pool->wait();

return $results['first']->output();
```

<a name="pool-process-ids-and-signals"></a>
### Pool Process IDs and Signals

Since the process pool's `running` method provides a collection of all invoked processes within the pool, you may easily access the underlying pool process IDs:

```php
$processIds = $pool->running()->each->id();
```

And, for convenience, you may invoke the `signal` method on a process pool to send a signal to every process within the pool:

```php
$pool->signal(SIGUSR2);
```

You may use the `stop` method to stop all running processes within the pool:

```php
$pool->stop();
```

The `stop` method accepts the same grace period and fallback signal arguments as an individual process.

<a name="testing"></a>
## Testing

Many Hypervel services provide functionality to help you easily and expressively write tests, and Hypervel's process service is no exception. The `Process` facade's `fake` method allows you to instruct Hypervel to return stubbed / dummy results when processes are invoked.

<a name="faking-processes"></a>
### Faking Processes

To explore Hypervel's ability to fake processes, let's imagine a route that invokes a process:

```php
use Hypervel\Support\Facades\Process;
use Hypervel\Support\Facades\Route;

Route::get('/import', function () {
    Process::run('bash import.sh');

    return 'Import complete!';
});
```

When testing this route, we can instruct Hypervel to return a fake, successful process result for every invoked process by calling the `fake` method on the `Process` facade with no arguments. In addition, we can even [assert](#available-assertions) that a given process was "run":

```php tab=Pest
<?php

use Hypervel\Contracts\Process\ProcessResult;
use Hypervel\Process\PendingProcess;
use Hypervel\Support\Facades\Process;

test('process is invoked', function () {
    Process::fake();

    $response = $this->get('/import');

    // Simple process assertion...
    Process::assertRan('bash import.sh');

    // Or, inspecting the process configuration...
    Process::assertRan(function (PendingProcess $process, ProcessResult $result) {
        return $process->command === 'bash import.sh' &&
               $process->timeout === 60;
    });
});
```

```php tab=PHPUnit
<?php

namespace Tests\Feature;

use Hypervel\Contracts\Process\ProcessResult;
use Hypervel\Process\PendingProcess;
use Hypervel\Support\Facades\Process;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_process_is_invoked(): void
    {
        Process::fake();

        $response = $this->get('/import');

        // Simple process assertion...
        Process::assertRan('bash import.sh');

        // Or, inspecting the process configuration...
        Process::assertRan(function (PendingProcess $process, ProcessResult $result) {
            return $process->command === 'bash import.sh' &&
                   $process->timeout === 60;
        });
    }
}
```

As discussed, invoking the `fake` method on the `Process` facade will instruct Hypervel to always return a successful process result with no output. However, you may easily specify the output and exit code for faked processes using the `Process` facade's `result` method:

```php
Process::fake([
    '*' => Process::result(
        output: 'Test output',
        errorOutput: 'Test error output',
        exitCode: 1,
    ),
]);
```

<a name="faking-specific-processes"></a>
### Faking Specific Processes

As you may have noticed in a previous example, the `Process` facade allows you to specify different fake results per process by passing an array to the `fake` method.

The array's keys should represent command patterns that you wish to fake and their associated results. The `*` character may be used as a wildcard character. Any process commands that have not been faked will actually be invoked. You may use the `Process` facade's `result` method to construct stub / fake results for these commands:

```php
Process::fake([
    'cat *' => Process::result(
        output: 'Test "cat" output',
    ),
    'ls *' => Process::result(
        output: 'Test "ls" output',
    ),
]);
```

If you do not need to customize the exit code or error output of a faked process, you may find it more convenient to specify the fake process results as simple strings:

```php
Process::fake([
    'cat *' => 'Test "cat" output',
    'ls *' => 'Test "ls" output',
]);
```

<a name="faking-process-sequences"></a>
### Faking Process Sequences

If the code you are testing invokes multiple processes with the same command, you may wish to assign a different fake process result to each process invocation. You may accomplish this via the `Process` facade's `sequence` method:

```php
Process::fake([
    'ls *' => Process::sequence()
        ->push(Process::result('First invocation'))
        ->push(Process::result('Second invocation')),
]);
```

If a sequence is empty, invoking another process will throw an exception. You may use the `whenEmpty` method to define a default result that should be returned when the sequence is empty:

```php
Process::fake([
    'ls *' => Process::sequence()
        ->push(Process::result('First invocation'))
        ->whenEmpty(Process::result('Default invocation')),
]);
```

If an empty sequence should return a successful process result with no output, you may invoke the `dontFailWhenEmpty` method:

```php
Process::fake([
    'ls *' => Process::sequence()
        ->push(Process::result('First invocation'))
        ->dontFailWhenEmpty(),
]);
```

<a name="faking-asynchronous-process-lifecycles"></a>
### Faking Asynchronous Process Lifecycles

Thus far, we have primarily discussed faking processes which are invoked synchronously using the `run` method. However, if you are attempting to test code that interacts with asynchronous processes invoked via `start`, you may need a more sophisticated approach to describing your fake processes.

For example, let's imagine the following route which interacts with an asynchronous process:

```php
use Hypervel\Support\Facades\Log;
use Hypervel\Support\Facades\Route;

Route::get('/import', function () {
    $process = Process::start('bash import.sh');

    while ($process->running()) {
        Log::info($process->latestOutput());
        Log::info($process->latestErrorOutput());
    }

    return 'Done';
});
```

To properly fake this process, we need to be able to describe how many times the `running` method should return `true`. In addition, we may want to specify multiple lines of output that should be returned in sequence. To accomplish this, we can use the `Process` facade's `describe` method:

```php
Process::fake([
    'bash import.sh' => Process::describe()
        ->id(1234)
        ->output('First line of standard output')
        ->errorOutput('First line of error output')
        ->output('Second line of standard output')
        ->exitCode(0)
        ->runsFor(3),
]);
```

Let's dig into the example above. The `id` method may be used to specify the process ID returned by the fake process. Using the `output` and `errorOutput` methods, we may specify multiple lines of output that will be returned in sequence. The `exitCode` method may be used to specify the final exit code of the fake process. Finally, the `runsFor` method may be used to specify how many times the `running` method should return `true`. The `iterations` method may also be used as an alias for `runsFor`.

If your code sends a signal to an asynchronous process, you may use the `hasReceivedSignal` method to assert that the fake process received the signal:

```php
Process::fake([
    'bash import.sh' => Process::describe()->runsFor(3),
]);

$process = Process::start('bash import.sh');

$process->signal(SIGUSR2);

$this->assertTrue($process->hasReceivedSignal(SIGUSR2));
```

<a name="available-assertions"></a>
### Available Assertions

As [previously discussed](#faking-processes), Hypervel provides several process assertions for your feature tests. We'll discuss each of these assertions below.

<a name="assert-process-ran"></a>
#### assertRan

Assert that a given process was invoked:

```php
use Hypervel\Support\Facades\Process;

Process::assertRan('ls -la');
```

The `assertRan` method also accepts a closure, which will receive an instance of a process and a process result, allowing you to inspect the process' configured options. If this closure returns `true`, the assertion will "pass":

```php
Process::assertRan(fn ($process, $result) =>
    $process->command === 'ls -la' &&
    $process->path === __DIR__ &&
    $process->timeout === 60
);
```

The `$process` passed to the `assertRan` closure is an instance of `Hypervel\Process\PendingProcess`, while the `$result` is an instance of `Hypervel\Contracts\Process\ProcessResult`.

<a name="assert-process-didnt-run"></a>
#### assertDidntRun

Assert that a given process was not invoked. You may also use the `assertNotRan` method:

```php
use Hypervel\Support\Facades\Process;

Process::assertDidntRun('ls -la');

Process::assertNotRan('ls -la');
```

Like the `assertRan` method, the `assertDidntRun` method also accepts a closure, which will receive an instance of a process and a process result, allowing you to inspect the process' configured options. If this closure returns `true`, the assertion will "fail":

```php
Process::assertDidntRun(fn (PendingProcess $process, ProcessResult $result) =>
    $process->command === 'ls -la'
);
```

<a name="assert-process-ran-times"></a>
#### assertRanTimes

Assert that a given process was invoked a given number of times:

```php
use Hypervel\Support\Facades\Process;

Process::assertRanTimes('ls -la', times: 3);
```

The `assertRanTimes` method also accepts a closure, which will receive an instance of `PendingProcess` and `ProcessResult`, allowing you to inspect the process' configured options. If this closure returns `true` and the process was invoked the specified number of times, the assertion will "pass":

```php
Process::assertRanTimes(function (PendingProcess $process, ProcessResult $result) {
    return $process->command === 'ls -la';
}, times: 3);
```

<a name="assert-nothing-ran"></a>
#### assertNothingRan

Assert that no processes were invoked:

```php
use Hypervel\Support\Facades\Process;

Process::assertNothingRan();
```

<a name="preventing-stray-processes"></a>
### Preventing Stray Processes

If you would like to ensure that all invoked processes have been faked throughout your individual test or complete test suite, you can call the `preventStrayProcesses` method. After calling this method, any processes that do not have a corresponding fake result will throw an exception rather than starting an actual process:

```php
use Hypervel\Support\Facades\Process;

Process::preventStrayProcesses();

Process::fake([
    'ls *' => 'Test output...',
]);

// Fake response is returned...
Process::run('ls -la');

// An exception is thrown...
Process::run('bash import.sh');
```
