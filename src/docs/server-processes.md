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
    - [Reloading Server Processes](#reloading-server-processes)
    - [Process Health](#process-health)
    - [Signals](#signals)
- [Inter-Process Communication](#inter-process-communication)
    - [Sending Messages](#sending-messages)
    - [Receiving Messages](#receiving-messages)

<a name="introduction"></a>
## Introduction

Server processes are custom Swoole child processes that run alongside your Hypervel application server. You may use them for long-running application tasks that need a separate operating system process and should start and stop with the server.

Server processes differ from the [Process facade](/docs/{{version}}/processes), which invokes external commands on demand. For background jobs, scheduled tasks, or short-lived concurrent work, you should use [queues](/docs/{{version}}/queues), [task scheduling](/docs/{{version}}/scheduling), or [concurrency](/docs/{{version}}/concurrency) instead.

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

The `handle` method runs inside each child process. If your process has a custom constructor, be sure to call the parent constructor so Hypervel can initialize it.

<a name="process-options"></a>
### Process Options

You may customize a process by overriding the following properties on your process class:

| Property | Default | Description |
|---|---:|---|
| `name` | `process` | Name used in process titles and logs. This name also identifies the process when sending IPC messages. |
| `processCount` | `1` | Number of child process instances to attach to the server. |
| `redirectStdinStdout` | `false` | Determines whether Swoole redirects standard input and output to the process pipe. Leave this disabled unless you need Swoole's native redirection. |
| `pipeType` | `SOCK_DGRAM` | The Swoole process pipe type. Keep the default when using Hypervel's IPC features. |
| `enableCoroutine` | `true` | Determines whether the child process uses Swoole coroutine mode. Hypervel's IPC features require this option to be enabled. |
| `receiveLength` | `65535` | Maximum number of bytes that may be read from a single IPC message. |
| `receiveTimeout` | `10.0` | Number of seconds to wait for an IPC message before checking whether the process is stopping. |
| `restartInterval` | `5` | Number of seconds to wait after `handle` returns or throws before Swoole restarts the process. |

<a name="conditionally-enabling-processes"></a>
### Conditionally Enabling Processes

By default, registered processes are enabled for every application server. You may override the `isEnabled` method when a process should only run with a particular server or application configuration. For example, the following process will only run with a WebSocket server:

```php
use Swoole\Server;
use Swoole\WebSocket\Server as WebSocketServer;

/**
 * Determine if the process should start.
 */
public function isEnabled(Server $server): bool
{
    return $server instanceof WebSocketServer;
}
```

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

Before the server starts, Hypervel resolves each class through the service container and attaches it to the server if it is enabled.

<a name="registering-process-instances"></a>
### Registering Process Instances

When you need to register the same process class more than once with different settings, you may register each instance through `ProcessManager` from a service provider's `boot` method:

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

Do not also list the class in your `config/server.php` file. Doing so will register another instance with the class's default settings.

> [!WARNING]
> Process registration is boot-time configuration. Register processes from a service provider before the server starts, not from requests, jobs, or other runtime application code.

<a name="process-lifecycle"></a>
## Process Lifecycle

When the server starts, Hypervel resolves each registered process and calls its `isEnabled` method. For each enabled process, Hypervel creates the configured number of child processes and calls `handle` inside each one.

If `handle` throws an exception, Hypervel reports it through your application's exception handler. When `handle` returns or throws, Swoole restarts the child process after the configured `restartInterval`.

Your `handle` method should keep running as long as the process is needed and only return when the child should exit and be restarted.

<a name="lifecycle-events"></a>
### Lifecycle Events

Hypervel dispatches a `Hypervel\ServerProcess\Events\BeforeProcessHandle` event before calling `handle` and a `Hypervel\ServerProcess\Events\AfterProcessHandle` event as the child process finishes. Both events provide the process instance and its zero-based child index.

You may register listeners for these events in the same way as other Hypervel [event listeners](/docs/{{version}}/events#registering-events-and-listeners).

<a name="reloading-server-processes"></a>
### Reloading Server Processes

The `server:reload` command reloads the server's event and task workers, but it does not reload custom server processes. Restart the server when server-process code or configuration changes.

<a name="process-health"></a>
### Process Health

Server processes do not have a built-in startup timeout, readiness check, heartbeat, or health status. The application's normal `/up` route does not inspect them automatically.

If your application depends on a server process, the process may publish suitable shared state for its workload. You may then check that state from a listener for the `Hypervel\Foundation\Events\DiagnosingHealth` event. For more information, see the [health route documentation](/docs/{{version}}/deployment#the-health-route).

<a name="signals"></a>
### Signals

If the Signal package is installed, coroutine-enabled server processes use the server-process signal handlers listed in the `signal.handlers` configuration value. You do not need to register these handlers again in your process class.

Graceful shutdown is opt-in. Your application must register the framework's stop handler and ensure the process returns from `handle` when the server is stopping. See the [Signal documentation](/docs/{{version}}/signals#server-process-signals) for the complete setup.

<a name="inter-process-communication"></a>
## Inter-Process Communication

Inter-process communication (IPC) allows a server worker to send data to a long-running server process. Hypervel supports IPC for coroutine-enabled server processes.

After attaching a coroutine-enabled process to the server, Hypervel stores its handle in `ProcessCollector` under the process `name`.

<a name="sending-messages"></a>
### Sending Messages

`ProcessCollector` is populated when the application server starts and is available to the server's workers. Standalone processes, such as Artisan commands, run separately from the application server and cannot use the collector to send messages.

To send a message to every child with a given process name, retrieve its handles, export each IPC socket, and send the serialized payload:

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

Inside the child process, Hypervel deserializes each valid message and dispatches a `PipeMessage` event. You may register a listener during application boot and read the message from the event's `data` property:

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

The listener runs inside the server process that received the message. Since `PipeMessage` only contains the deserialized data, include a message type or any other routing information your listener needs in the payload.

Values such as `false`, `null`, `0`, empty strings, and empty arrays are delivered unchanged. Each serialized message must be no larger than the process's `receiveLength`.

> [!WARNING]
> Server-process IPC uses PHP serialization, so you should only send data created by code you trust. Swoole owns the collected process handles and exported sockets, so you should not close them in application code.
