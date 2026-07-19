Server Process for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/server-process)

Ported from: https://github.com/hyperf/hyperf/tree/master/src/process

## Defining Server Processes

Server processes are custom Swoole child processes attached to the application
server. Define one by extending `AbstractProcess` and implementing `handle()`:

```php
use Hypervel\ServerProcess\AbstractProcess;

class ReportProcess extends AbstractProcess
{
    public string $name = 'reports';

    public int $processCount = 2;

    public function handle(): void
    {
        // Run the process workload...
    }
}
```

`isEnabled()` may be overridden when a process should only run for a particular
server configuration.

## Registering Server Processes

Register process classes in `config/server.php`:

```php
'processes' => [
    ReportProcess::class,
],
```

Classes are resolved through the service container and attached before the
server starts. When multiple distinctly configured instances of the same class
are needed, register those instances with `ProcessManager::register()` from a
service provider during boot.

## Lifecycle and IPC

Swoole owns each process after it is attached to the server. Hypervel dispatches
`BeforeProcessHandle` and `AfterProcessHandle` around `handle()`, sends uncaught
exceptions to the framework exception handler, completes child-local timer and
coordinator teardown, and applies `restartInterval` before the process callback
returns. When the Signal package is installed, process-scoped handlers
configured in `signal.handlers` are active for the same lifecycle.

Coroutine-enabled processes listen for serialized values written through the
native handles exposed by `ProcessCollector`. Each valid value dispatches a
`PipeMessage`, including `false`, `null`, zero, empty strings, and empty arrays.
IPC is an internal application boundary: only write trusted serialized data,
and do not close collected handles owned by the server.
