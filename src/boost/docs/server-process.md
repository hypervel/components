# Server Processes

- [Introduction](#introduction)
- [Defining Server Processes](#defining-server-processes)
    - [Process Options](#process-options)
    - [Conditionally Enabling Processes](#conditionally-enabling-processes)
- [Registering Server Processes](#registering-server-processes)
    - [Configuration](#configuration)
    - [Registering Process Instances](#registering-process-instances)
- [Process Lifecycle](#process-lifecycle)
    - [Lifecycle Events](#lifecycle-events)
    - [Signals](#signals)
- [Inter-Process Communication](#inter-process-communication)
    - [Sending Messages](#sending-messages)
    - [Receiving Messages](#receiving-messages)

<a name="introduction"></a>
## Introduction

Server processes are custom Swoole child processes that run alongside your Hypervel application server. They are useful for long-running, application-specific workloads that need their own operating system process and should share the server's lifecycle.

Server processes are different from the [Process facade](/docs/{{version}}/processes), which invokes external commands on demand. For ordinary background jobs, scheduled work, or concurrent subtasks, consider using [queues](/docs/{{version}}/queues), [task scheduling](/docs/{{version}}/scheduling), or [concurrency](/docs/{{version}}/concurrency) instead.

<a name="defining-server-processes"></a>
## Defining Server Processes

To define a server process, extend the `AbstractProcess` class and implement its `handle` method. Server process classes are resolved through the service container, so you may use constructor injection:

```php
<?php

namespace App\Processes;

use App\Services\ReportConsumer;
use Hypervel\Contracts\Container\Container;
use Hypervel\ServerProcess\AbstractProcess;

class ReportProcess extends AbstractProcess
{
    public string $name = 'reports';

    public int $processCount = 2;

    public function __construct(
        Container $container,
        protected ReportConsumer $consumer,
        protected string $queue = 'default',
    ) {
        parent::__construct($container);
    }

    /**
     * Run the server process.
     */
    public function handle(): void
    {
        $this->consumer->listen($this->queue);
    }
}
```

The `handle` method is the entry point for each child process. If you define a custom constructor, always call the parent constructor so Hypervel can initialize the process correctly.

<a name="process-options"></a>
### Process Options

You may customize a process by overriding the following properties on your process class:

| Property | Default | Description |
|---|---:|---|
| `name` | `process` | Name used for process titles, logs, and `ProcessCollector` groups. |
| `processCount` | `1` | Number of child process instances to attach to the server. |
| `redirectStdinStdout` | `false` | Whether Swoole redirects standard input and output to the process pipe. Leave disabled unless you specifically need this native behavior. |
| `pipeType` | `SOCK_DGRAM` | Native Swoole process pipe type. Keep the default when using Hypervel's IPC support. |
| `enableCoroutine` | `true` | Whether the child uses Swoole coroutine mode. Hypervel's IPC listener and collector integration also require this option. |
| `receiveLength` | `65535` | Maximum number of bytes read from one IPC message. |
| `receiveTimeout` | `10.0` | Maximum seconds an IPC receive waits before checking again. |
| `restartInterval` | `5` | Seconds to wait after `handle` finishes or throws before the child callback returns. Swoole restarts the managed process after the callback returns, so this delay throttles the respawn rate. |

<a name="conditionally-enabling-processes"></a>
### Conditionally Enabling Processes

By default, a registered process is enabled for every application server. You may override the `isEnabled` method when a process should only be attached to a particular `Swoole\Server` instance or application configuration.

<a name="registering-server-processes"></a>
## Registering Server Processes

<a name="configuration"></a>
### Configuration

Most server processes should be registered by adding their class names to the `processes` array in your application's `config/server.php` file:

```php
'processes' => [
    App\Processes\ReportProcess::class,
],
```

Hypervel resolves each class through the service container and attaches the enabled processes before the server starts.

<a name="registering-process-instances"></a>
### Registering Process Instances

When you need multiple differently configured instances of the same process class, you may register each instance through `ProcessManager` from a service provider's `boot` method:

```php
use App\Processes\ReportProcess;
use Hypervel\ServerProcess\ProcessManager;

/**
 * Bootstrap any application services.
 */
public function boot(): void
{
    foreach (['critical', 'bulk'] as $queue) {
        $process = $this->app->make(ReportProcess::class, [
            'queue' => $queue,
        ]);

        $process->name = "reports.{$queue}";

        ProcessManager::register($process);
    }
}
```

Use programmatic registration instead of a configuration entry for these instances, otherwise the default class configuration will be attached as an additional process.

> [!WARNING]
> Process registration is boot-time configuration. Register processes from a service provider before the server starts, not from requests, jobs, or other runtime application code.

<a name="process-lifecycle"></a>
## Process Lifecycle

When the server starts, Hypervel resolves each registered definition, calls its `isEnabled` method, and attaches the configured number of child processes. Each child invokes `handle`. If `handle` throws an exception, Hypervel reports it through the application's exception handler. Once the callback returns, Swoole restarts the managed process while the server remains running.

Your `handle` method should remain running for the lifetime of its workload and return only when the child is ready to exit and be restarted.

<a name="lifecycle-events"></a>
### Lifecycle Events

Hypervel dispatches `Hypervel\ServerProcess\Events\BeforeProcessHandle` before calling `handle` and `Hypervel\ServerProcess\Events\AfterProcessHandle` after it finishes. Both events expose the process definition and its zero-based child index. You may listen for these events using Hypervel's normal [event listeners](/docs/{{version}}/events#registering-events-and-listeners).

<a name="signals"></a>
### Signals

When the Signal package is installed, process-scoped handlers configured in `signal.handlers` are active while the server process is running. This allows the same configured signal infrastructure to participate in custom child processes without adding signal handling to the process class itself.

<a name="inter-process-communication"></a>
## Inter-Process Communication

Coroutine-enabled server processes may receive serialized messages from the application's server workers. Hypervel stores successfully attached process handles in `ProcessCollector`, grouped by the process `name`.

<a name="sending-messages"></a>
### Sending Messages

`ProcessCollector` is populated when the application server boots and is available to the server's own workers. A separate process, such as a standalone Artisan command, has an empty collector and sends nothing.

To send a message to every child in a process group, retrieve the handles, export each IPC socket, and send the serialized payload:

```php
use Hypervel\ServerProcess\ProcessCollector;
use RuntimeException;

$message = serialize([
    'type' => 'refresh-reports',
]);
$length = strlen($message);

foreach (ProcessCollector::get('reports') as $process) {
    $socket = $process->exportSocket();

    if ($socket === false || $socket->send($message) !== $length) {
        throw new RuntimeException('Unable to send message to the reports process.');
    }
}
```

The loop above broadcasts the message to every child named `reports`. Select a single returned handle instead when only one child should receive the message.

<a name="receiving-messages"></a>
### Receiving Messages

Hypervel deserializes each valid message in the custom child and dispatches a `PipeMessage` event. Register a listener during application boot and inspect the event's `data` property:

```php
use Hypervel\ServerProcess\Events\PipeMessage;
use Hypervel\Support\Facades\Event;

/**
 * Bootstrap any application services.
 */
public function boot(): void
{
    Event::listen(PipeMessage::class, function (PipeMessage $event): void {
        if ($event->data === ['type' => 'refresh-reports']) {
            // Refresh the reports...
        }
    });
}
```

The listener runs in the custom child process. Since `PipeMessage` contains only the deserialized data, include any routing information your listener needs in the payload. Valid falsy values such as `false`, `null`, `0`, empty strings, and empty arrays are preserved. The serialized message must fit within the process's `receiveLength`.

> [!WARNING]
> Server-process IPC uses PHP serialization and is an internal application boundary. Only send trusted data. The collected process handles and exported sockets are owned by Swoole and should not be closed by application code.
