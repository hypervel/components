# Signals

- [Introduction](#introduction)
- [Defining Signal Handlers](#defining-signal-handlers)
    - [Process Groups](#process-groups)
- [Registering Signal Handlers](#registering-signal-handlers)
    - [Handler Priority](#handler-priority)
- [Signal Lifecycle](#signal-lifecycle)
    - [Worker Signals](#worker-signals)
    - [Server Process Signals](#server-process-signals)
- [Native Signal Limitations](#native-signal-limitations)

<a name="introduction"></a>
## Introduction

Operating systems use signals to notify running processes about events such as termination requests or application-defined commands. Hypervel's Signal package allows your application to handle these signals within server workers and custom [server processes](/docs/{{version}}/server-processes).

If you only need to handle a signal within an Artisan command, you should use the command's [signal handling methods](/docs/{{version}}/artisan#signal-handling) instead.

<a name="defining-signal-handlers"></a>
## Defining Signal Handlers

To define a signal handler, implement the `Hypervel\Contracts\Signal\SignalHandler` contract. The `signals` method declares the signals handled by the class, while the `handle` method receives the signal that was delivered:

```php
<?php

namespace App\Signals;

use Hypervel\Contracts\Signal\SignalHandler;

class WriteDiagnostics implements SignalHandler
{
    /**
     * Get the signals handled by the class.
     */
    public function signals(): array
    {
        return [
            self::WORKER => [SIGUSR1],
            self::SERVER_PROCESS => [SIGUSR1],
        ];
    }

    /**
     * Handle the received signal.
     */
    public function handle(int $signal): void
    {
        // Write a diagnostic snapshot...
    }
}
```

Each configured handler is resolved through the service container when a process starts. The same handler instance is used for every signal declared by that handler within the process. Because the same handler instance may handle different signals at the same time, you should not store data for an individual signal delivery on the handler instance.

<a name="process-groups"></a>
### Process Groups

The `SignalHandler::WORKER` group applies to the server's event workers. It also applies to task workers when the `task_enable_coroutine` server setting is enabled. The `SignalHandler::SERVER_PROCESS` group applies to coroutine-enabled custom server processes. You may declare either group or both groups, and an empty signal list is allowed:

```php
return [
    self::WORKER => [SIGUSR1, SIGUSR2],
    self::SERVER_PROCESS => [],
];
```

Signal handlers are not started in processes where coroutine support is disabled.

<a name="registering-signal-handlers"></a>
## Registering Signal Handlers

Signal handlers are registered in the `handlers` array of your application's `config/signal.php` configuration file:

```php
use App\Signals\WriteDiagnostics;

'handlers' => [
    WriteDiagnostics::class,
],
```

Handlers are resolved when each worker or server process starts. Register handlers in configuration before starting the server rather than changing the list while the application is running.

<a name="handler-priority"></a>
### Handler Priority

When several handlers listen for the same signal, you may assign each handler a numeric priority. Handlers with a higher priority run first:

```php
use App\Signals\FlushMetrics;
use App\Signals\WriteDiagnostics;

'handlers' => [
    FlushMetrics::class => 20,
    WriteDiagnostics::class => 10,
],
```

If a handler throws an exception, Hypervel reports the exception and continues running the remaining handlers. Once every handler has finished, Hypervel listens for the next delivery of the signal.

<a name="signal-lifecycle"></a>
## Signal Lifecycle

A signal is delivered to one operating system process. It is not automatically broadcast to every worker or server process. Use the server's normal lifecycle controls instead of assuming that one application signal reaches every process.

Keep signal handlers short. While a handler is running, another delivery of the same signal may use the operating system's default behavior before Hypervel begins listening again.

<a name="worker-signals"></a>
### Worker Signals

Swoole manages normal worker shutdown through `SIGTERM`. Registering an application handler for this signal replaces that native behavior within the worker, so your handler becomes responsible for completing the required shutdown.

Swoole does not handle `SIGINT` in workers. If your application registers a handler for this signal, Hypervel handles an interrupt that would otherwise terminate the worker.

<a name="server-process-signals"></a>
### Server Process Signals

Graceful shutdown for a custom server process is opt-in. First, register Hypervel's `ProcessStopHandler` in your `config/signal.php` file:

```php
use Hypervel\ServerProcess\Handlers\ProcessStopHandler;

'handlers' => [
    ProcessStopHandler::class,
],
```

Then, ensure the process checks `ProcessManager::isRunning()` and returns from its `handle` method when the server is stopping:

```php
use Hypervel\ServerProcess\ProcessManager;

/**
 * Run the server process.
 */
public function handle(): void
{
    while (ProcessManager::isRunning()) {
        $this->processNextReport();
    }
}
```

Any blocking work within the loop must return periodically so the running state can be checked. If your process needs more time to finish its current work, increase the server's [graceful shutdown allowance](/docs/{{version}}/deployment#graceful-shutdown).

The `server:reload` command reloads event and task workers, but it does not reload custom server processes. Restart the server when server-process code or configuration changes.

<a name="native-signal-limitations"></a>
## Native Signal Limitations

Swoole does not support waiting for `SIGCHLD` through the coroutine signal API used by Hypervel. In addition, you should not use `Swoole\Process::signal` in a process that uses Hypervel signal handlers. The two native signal mechanisms are mutually exclusive within a process.
