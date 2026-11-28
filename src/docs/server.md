# Server

- [Introduction](#introduction)
- [Starting the Server](#starting-the-server)
    - [Overriding the Host and Port](#overriding-the-host-and-port)
    - [Process Titles](#process-titles)
- [Configuration](#configuration)
    - [Server Mode](#server-mode)
    - [Environment Variables](#environment-variables)
    - [Swoole Settings](#swoole-settings)
    - [Static Files](#static-files)
    - [HTTP Compression](#http-compression)
    - [Multiple Servers](#multiple-servers)
    - [TLS / HTTPS](#tls-https)
- [HTTP/2](#http2)
    - [Stream Cancellation](#http2-stream-cancellation)
- [Reloading Workers](#reloading-workers)
    - [When a Full Restart Is Required](#when-a-full-restart-is-required)
- [Graceful Shutdown](#graceful-shutdown)
- [Lifecycle Events](#lifecycle-events)
    - [Startup and Fork Events](#startup-and-fork-events)
    - [Server and Worker Events](#server-and-worker-events)
    - [Optional Swoole Callbacks](#optional-swoole-callbacks)
    - [HTTP Request Events](#http-request-events)
- [Task Workers and Pipe Messages](#task-workers-and-pipe-messages)
    - [Task Workers](#task-workers)
    - [Pipe Messages](#pipe-messages)
- [Accessing the Server Instance](#accessing-the-server-instance)
- [Credits](#credits)

<a name="introduction"></a>
## Introduction

Unlike traditional PHP applications that rely on PHP-FPM to boot your application on every request, Hypervel includes a built-in Swoole application server. Your application boots into memory once and handles incoming HTTP, HTTP/2, WebSocket, and TCP / UDP traffic concurrently inside long-running Swoole workers using coroutines.

Hypervel's server configuration is stored in your application's `config/server.php` file, allowing you to configure worker counts, multiple listeners, TLS termination, static file serving, graceful shutdown behavior, custom [server processes](/docs/{{version}}/server-processes), and lifecycle callbacks.

<a name="starting-the-server"></a>
## Starting the Server

To start your application's Swoole server, you may use the `serve` Artisan command:

```shell
php artisan serve
```

By default, Hypervel starts an HTTP server bound to `0.0.0.0:8000` with HTTP/2 enabled. You may also pass the `--env` option when starting the server to select a specific environment:

```shell
php artisan serve --env=staging
```

Because workers keep your booted application in memory, changes to your application code require restarting the server. During local development, you may use the `watch` Artisan command to start the server and restart it automatically whenever files change:

```shell
php artisan watch
```

For more information on configuring watched paths and drivers, consult the [file watching documentation](/docs/{{version}}/watcher).

In production, you should keep `php artisan serve` running under a process monitor, container orchestrator, or managed platform such as [SonicStack](https://sonicstack.io). For Nginx reverse proxy configuration, [production PHP and OPcache settings](/docs/{{version}}/deployment#php-configuration), and other optimizations, consult the [deployment documentation](/docs/{{version}}/deployment).

<a name="overriding-the-host-and-port"></a>
### Overriding the Host and Port

You may override the primary HTTP server's bind address for the current process using the `--host` and `--port` options:

```shell
php artisan serve --host=127.0.0.1 --port=8001
```

The `--host` option also accepts a port, such as `127.0.0.1:8001` or `[::1]:8001`. If both options specify a port, `--port` takes precedence.

These options override the first HTTP server entry in your `config/server.php` file before workers start. In production, you should configure the host and port in `config/server.php` or via environment variables instead.

<a name="process-titles"></a>
### Process Titles

On operating systems that support changing process titles (such as Linux), Hypervel automatically names each server process using your application's `app.name` configuration value:

<div class="overflow-auto">

| Process               | Title Format                  |
| --------------------- | ----------------------------- |
| Master                | `{app}.Master`                |
| Manager               | `{app}.Manager`               |
| Event Worker          | `{app}.Worker.{workerId}`     |
| Task Worker           | `{app}.TaskWorker.{workerId}` |
| Custom Server Process | `{app}.{processName}.{index}` |

</div>

<a name="configuration"></a>
## Configuration

All of the configuration options for your application's Swoole server are stored in your application's `config/server.php` configuration file. This file defines the server's execution `mode`, network `servers`, custom [server `processes`](/docs/{{version}}/server-processes), global Swoole `settings`, and server-wide `callbacks`. We'll review each of these options below.

<a name="server-mode"></a>
### Server Mode

By default, Hypervel runs in `SWOOLE_PROCESS` mode:

```php
'mode' => SWOOLE_PROCESS,
```

In `SWOOLE_PROCESS` mode, Swoole runs a master process, a manager process, and forked worker processes. The master process holds client connections and forwards requests to workers, so a worker crash does not drop active client connections while the manager restarts the worker. Most applications should leave this at the default `SWOOLE_PROCESS` mode.

Alternatively, you may set `mode` to `SWOOLE_BASE`. In base mode, workers accept and hold client connections directly without a separate master process, avoiding inter-process communication overhead. Base mode suits stateless HTTP or gRPC services behind a load balancer. Keep `SWOOLE_PROCESS` for WebSocket servers and other long-lived connections, since a base-mode worker crash drops every connection it holds, and pushes to connections held by other workers go through [pipe messages](/docs/{{version}}/websockets#sending-messages).

<a name="environment-variables"></a>
### Environment Variables

The default `config/server.php` file reads the following environment variables:

<div class="overflow-auto">

| Variable                              | Default            | Description                                                               |
| ------------------------------------- | ------------------ | ------------------------------------------------------------------------- |
| `SERVER_HOST`                         | `0.0.0.0`          | Host address for the default HTTP listener.                               |
| `SERVER_PORT`                         | `8000`             | Port for the default HTTP listener.                                       |
| `SERVER_WORKERS`                      | `swoole_cpu_num()` | Number of Swoole event workers (`worker_num`).                            |
| `SERVER_STATIC_FILE_HANDLER`          | `true`             | Enables Swoole's built-in static file handler for `public/`.              |
| `SERVER_HTTP2`                        | `true`             | Enables HTTP/2 protocol support (`open_http2_protocol`).                  |
| `SERVER_HTTP2_CANCEL_ON_STREAM_RESET` | `false`            | Cancels HTTP/2 request coroutines when a client resets its stream.        |
| `SERVER_MAX_REQUESTS`                 | `100000`           | Maximum requests a worker handles before recycling (`0` disables it).     |
| `SERVER_MAX_WAIT_TIME`                | `3`                | Seconds a stopping or recycled worker has to finish (`max_wait_time`).    |
| `SERVER_HTTP_COMPRESSION`             | `false`            | Enables Swoole's built-in HTTP response compression.                      |

</div>

<a name="swoole-settings"></a>
### Swoole Settings

The `settings` array in `config/server.php` is passed directly to Swoole's `$server->set()` method. In addition to the environment-backed options above, Hypervel configures defaults that are suitable for most applications:

<div class="overflow-auto">

| Option                                 | Default                                  | Description                                                                           |
| -------------------------------------- | ---------------------------------------- | ------------------------------------------------------------------------------------- |
| `document_root`                        | `base_path('public')`                    | Root directory used by Swoole's static file handler.                                  |
| `Constant::OPTION_ENABLE_COROUTINE`    | `true`                                   | Runs request and server callbacks inside Swoole coroutines.                           |
| `Constant::OPTION_MAX_COROUTINE`       | `100000`                                 | Maximum concurrent coroutines allowed per worker.                                     |
| `Constant::OPTION_TASK_WORKER_NUM`     | `0`                                      | Number of Swoole [task workers](#task-workers) to spawn.                              |
| `Constant::OPTION_TASK_ENABLE_COROUTINE` | `false`                                | Enables coroutine mode inside task workers.                                           |
| `Constant::OPTION_PID_FILE`            | `storage_path('framework/hypervel.pid')` | File where Swoole writes the master PID (read by `server:reload`).                    |
| `Constant::OPTION_DAEMONIZE`           | `false`                                  | Runs the server in the background as a daemon process.                                |
| `Constant::OPTION_OPEN_TCP_NODELAY`    | `true`                                   | Disables Nagle's algorithm to reduce small-response latency.                          |
| `Constant::OPTION_SOCKET_BUFFER_SIZE`  | `2 * 1024 * 1024`                        | Maximum output buffer size per connection (2 MB).                                     |

</div>

Most applications should leave these core settings at their defaults and tune behavior through the environment variables above. Keep `enable_coroutine` enabled, because Hypervel's runtime and connection pools rely on coroutines. Keep `daemonize` disabled when running under containers, systemd, Supervisor, or other process monitors. Start with the default `SERVER_WORKERS` count (`swoole_cpu_num()`): unlike PHP-FPM, each Swoole worker handles many concurrent requests via coroutines, so setting more workers than CPU cores rarely helps and increases memory usage.

You may add any other valid Swoole server option to the `settings` array, or override options for an individual listener using that listener's `settings` key. For a complete list of supported options, consult the [official Swoole documentation](https://github.com/swoole/docs/tree/main/public/en).

> [!WARNING]
> Swoole's `event_object` setting is not supported in global or per-listener settings because Hypervel dispatches its own event objects from the native callbacks. Leave this setting disabled and listen to Hypervel's [lifecycle events](#lifecycle-events) instead.

When `SERVER_MAX_REQUESTS` is non-zero, Swoole recycles each worker once it has processed that many requests to help keep long-lived memory usage bounded. Swoole counts incoming WebSocket messages toward the same limit, so dedicated [Reverb](/docs/{{version}}/reverb#worker-recycling) or WebSocket deployments should typically set `SERVER_MAX_REQUESTS=0`.

<a name="static-files"></a>
### Static Files

By default, Hypervel enables Swoole's static file handler (`enable_static_handler`) with `document_root` set to your application's `public` directory. This allows local development and direct-to-client deployments to serve images, stylesheets, and compiled JavaScript assets without a separate web server.

When running behind a reverse proxy such as Nginx in production, you should let the web server serve static files directly and proxy only dynamic requests to Hypervel. You may disable Swoole's static file handler via the `SERVER_STATIC_FILE_HANDLER` environment variable so Swoole does not check the filesystem on every request:

```ini
SERVER_STATIC_FILE_HANDLER=false
```

<a name="http-compression"></a>
### HTTP Compression

Swoole's built-in HTTP response compression (`http_compression`) is disabled by default. When your Hypervel server sits behind a reverse proxy like Nginx or a CDN, compressing responses inside Swoole wastes CPU because the proxy typically decompresses and re-compresses responses for clients.

If clients connect directly to your Hypervel server without a reverse proxy, you may enable Swoole's HTTP compression using the `SERVER_HTTP_COMPRESSION` environment variable:

```ini
SERVER_HTTP_COMPRESSION=true
```

<a name="multiple-servers"></a>
### Multiple Servers

A single Hypervel server process can listen on multiple ports and protocols simultaneously by adding entries to the `servers` array in `config/server.php`. Each entry needs a unique `name` and defines its `type`, `host`, `port`, `sock_type`, and `callbacks`:

<div class="overflow-auto">

| Server Type | Constant                   | Use Case                                                                                       |
| ----------- | -------------------------- | ---------------------------------------------------------------------------------------------- |
| HTTP        | `Server::SERVER_HTTP`      | Standard HTTP / HTTP/2 and gRPC listeners.                                                     |
| WebSocket   | `Server::SERVER_WEBSOCKET` | Native Swoole [WebSocket](/docs/{{version}}/websockets) and [Reverb](/docs/{{version}}/reverb) listeners. |
| Base        | `Server::SERVER_BASE`      | Raw TCP or UDP listeners.                                                                      |

</div>

For example, you may expose an HTTP server alongside a custom TCP listener:

```php
use Hypervel\Core\Bootstrap\CloseCallback;
use Hypervel\Core\Bootstrap\ConnectCallback;
use Hypervel\Core\Bootstrap\ReceiveCallback;
use Hypervel\HttpServer\Server as HttpServer;
use Hypervel\Server\Event;
use Hypervel\Server\Server;

'servers' => [
    [
        'name' => 'http',
        'type' => Server::SERVER_HTTP,
        'host' => env('SERVER_HOST', '0.0.0.0'),
        'port' => (int) env('SERVER_PORT', 8000),
        'sock_type' => SWOOLE_SOCK_TCP,
        'callbacks' => [
            Event::ON_REQUEST => [HttpServer::class, 'onRequest'],
        ],
    ],
    [
        'name' => 'tcp',
        'type' => Server::SERVER_BASE,
        'host' => '0.0.0.0',
        'port' => 9502,
        'sock_type' => SWOOLE_SOCK_TCP,
        'callbacks' => [
            Event::ON_CONNECT => [ConnectCallback::class, 'onConnect'],
            Event::ON_RECEIVE => [ReceiveCallback::class, 'onReceive'],
            Event::ON_CLOSE => [CloseCallback::class, 'onClose'],
        ],
    ],
],
```

You may list servers in any order; Hypervel handles initialization order automatically during startup.

Per-listener `settings` are merged on top of the global `settings` array for that port. For `SERVER_BASE` listeners, Hypervel automatically sets `open_http_protocol` and `open_http2_protocol` to `false` unless you explicitly override them on that listener. Each HTTP listener should have its own `Event::ON_REQUEST` handler class so server names and callbacks do not overwrite one another.

<a name="tls-https"></a>
### TLS / HTTPS

For internet-facing production deployments, terminating TLS at a dedicated reverse proxy or load balancer is recommended. When you need direct TLS termination, such as for an internal service, a local HTTPS endpoint, or a direct HTTP/2 listener, combine `SWOOLE_SSL` into the listener's `sock_type` and configure Swoole's SSL certificate paths in its `settings`:

```php
use Hypervel\HttpServer\Server as HttpServer;
use Hypervel\Server\Event;
use Hypervel\Server\Server;

'servers' => [
    [
        'name' => 'https',
        'type' => Server::SERVER_HTTP,
        'host' => env('SERVER_HOST', '0.0.0.0'),
        'port' => (int) env('SERVER_PORT', 8443),
        'sock_type' => SWOOLE_SOCK_TCP | SWOOLE_SSL,
        'settings' => [
            'ssl_cert_file' => storage_path('certs/server.crt'),
            'ssl_key_file' => storage_path('certs/server.key'),
        ],
        'callbacks' => [
            Event::ON_REQUEST => [HttpServer::class, 'onRequest'],
        ],
    ],
],
```

Hypervel automatically marks requests received on TLS listeners as secure so `$request->isSecure()` and generated URLs use `https`. Swoole also supports mutual TLS (`ssl_verify_peer`, `ssl_client_cert_file` / `ssl_cafile`) and SNI (`ssl_sni_certs`) within the listener's `settings` array.

<a name="http2"></a>
## HTTP/2

HTTP/2 is enabled by default via `SERVER_HTTP2=true` (`Constant::OPTION_OPEN_HTTP2_PROTOCOL`). If your deployment only serves HTTP/1.1 traffic behind an HTTP/1.1 reverse proxy and does not need HTTP/2, you may disable it by setting `SERVER_HTTP2=false`.

<a name="http2-stream-cancellation"></a>
### Stream Cancellation

When a client cancels a request by resetting its HTTP/2 stream, Hypervel can stop handling that request while other streams on the same connection continue. This behavior is off by default, and most web applications should leave it off. Cancellation can interrupt a handler waiting for a database query or an HTTP response. For example, your application might save an order and then stop before sending the confirmation email.

For a [gRPC](/docs/{{version}}/grpc) or streaming service, you may want to stop producing a response as soon as the client resets its stream. If all your HTTP/2 handlers can safely stop this way, enable the option and restart the server:

```ini
SERVER_HTTP2_CANCEL_ON_STREAM_RESET=true
```

The default `config/server.php` reads this variable in its global `settings`. Adding the option to an individual listener's settings, including a gRPC listener, has no effect. Clients must reach Hypervel over HTTP/2, either directly or through a proxy that forwards stream resets, such as Envoy. An Nginx `proxy_pass` connection using HTTP/1.1 cannot forward them.

> [!NOTE]
> This option requires a Swoole build with [HTTP/2 stream reset cancellation](https://github.com/swoole/swoole-src/pull/6436), such as a recent nightly. The tagged 6.3.0RC2 release predates it.

If you want opt-in cancellation on streamed responses when an HTTP/1.1 or HTTP/2 connection closes, consult the [streamed response documentation](/docs/{{version}}/responses#canceling-disconnected-streams).

<a name="reloading-workers"></a>
## Reloading Workers

To gracefully recycle the server's worker processes, you may use the `server:reload` Artisan command. Each worker gets up to [`SERVER_MAX_WAIT_TIME`](#graceful-shutdown) seconds to finish its active requests. In `SWOOLE_PROCESS` mode, client connections stay open in the master process, while in `SWOOLE_BASE` mode each replaced worker closes its client connections:

```shell
php artisan server:reload
```

This command reads the master PID from `server.settings.pid_file` and gracefully replaces event workers along with any configured task workers. The command fails if the configured PID file cannot be read, does not contain a valid process ID, or a signal cannot be delivered.

Before each replacement worker starts, Hypervel reloads `.env` (when configuration is not cached), rebuilds the configuration repository, and reapplies configuration changes your service providers made during boot.

If the reloaded configuration is invalid, replacement workers fail before accepting traffic and the server logs each worker error. Swoole immediately starts another replacement, so failures continue until you fix the configuration. Once corrected, the next worker starts normally without needing to run `server:reload` again.

To reload queue workers, the scheduler, and the server together, you may use the [`reload` command](/docs/{{version}}/deployment#reloading-services).

<a name="when-a-full-restart-is-required"></a>
### When a Full Restart Is Required

`server:reload` only replaces worker processes; it does **not** rebuild the booted application inherited from the master process. You must restart the full server process whenever you change any of the following:

<div class="content-list" markdown="1">

- Application or vendor PHP code
- Environment variables or configuration values that services already copied into their own state during boot
- TLS certificates
- Routes, event listeners, service providers, or package registration
- Swoole server settings (`config/server.php`)
- Shared Swoole tables, such as Cache or Rate Limiter Swoole tables (shared tables can only be created before workers are forked; referencing a newly configured table after a reload produces an error)
- Custom [server processes](/docs/{{version}}/server-processes)

</div>

<a name="graceful-shutdown"></a>
## Graceful Shutdown

Sending `SIGTERM` or `SIGINT` to the server's main process starts a graceful shutdown. However, pressing `Ctrl+C` in a terminal sends `SIGINT` to the entire terminal process group and can interrupt active requests in workers. To allow requests to finish, signal the main process only.

The `SERVER_MAX_WAIT_TIME` environment variable controls Swoole's server-wide graceful drain allowance in seconds. It defaults to `3` and applies during server shutdown as well as when `server:reload` or `SERVER_MAX_REQUESTS` replaces workers. Increase this value when long-running requests, WebSocket connections, telemetry drains, or [server-process cleanup](/docs/{{version}}/signals#server-process-signals) need more time to finish. Swoole may forcefully terminate work that exceeds the configured allowance.

A value of `0` does not provide unlimited shutdown time. Workers receive no graceful drain period, while Swoole's final timeout for custom server processes is disabled.

<a name="lifecycle-events"></a>
## Lifecycle Events

Hypervel translates Swoole's server and worker callbacks into standard framework events under the `Hypervel\Core\Events` namespace. You may register [event listeners](/docs/{{version}}/events#registering-events-and-listeners) for any of these events in the `boot` method of your application's `App\Providers\AppServiceProvider` class.

<a name="startup-and-fork-events"></a>
### Startup and Fork Events

These events fire in the process that runs `php artisan serve` during startup, before Swoole forks worker processes:

<div class="overflow-auto">

| Event                   | Properties                 | When It Fires                                                                                                    |
| ----------------------- | -------------------------- | ---------------------------------------------------------------------------------------------------------------- |
| `BeforeMainServerStart` | `$server`, `$serverConfig` | Once after the main `Swoole\Server` instance is created and configured. Hypervel attaches custom server processes here. |
| `BeforeServerStart`     | `$serverName`              | Once for each configured listener in `servers` before `$server->start()`.                                        |
| `BeforeServerFork`      | `$server`                  | Immediately before `$server->start()` forks workers.                                                             |

</div>

Resolve services that create shared Swoole tables from a `BeforeServerStart` listener so they are allocated before workers fork and are not created unnecessarily in Artisan commands or queue workers (see [Static Analysis: Swoole Tables](/docs/{{version}}/static-analysis#swoole-tables)). `BeforeServerStart` fires once for each configured server, so resolving a singleton ensures the container creates the table only once:

```php
use App\Support\PresenceTable;
use Hypervel\Core\Events\BeforeServerStart;
use Hypervel\Support\Facades\Event;

public function boot(): void
{
    Event::listen(BeforeServerStart::class, function (): void {
        $this->app->make(PresenceTable::class);
    });
}
```

Use `BeforeServerFork` to flush or release parent-only resources (such as buffered telemetry or open sockets) right before fork. Listeners for `BeforeServerFork` must not open new sockets, timers, or connection pools that forked workers would inherit.

<a name="server-and-worker-events"></a>
### Server and Worker Events

Hypervel enables the core Swoole server and worker lifecycle events by default, so you may listen for any of the following events without modifying `config/server.php`:

<div class="overflow-auto">

| Event               | Properties                          | When It Fires                                                                                                      |
| ------------------- | ----------------------------------- | ------------------------------------------------------------------------------------------------------------------ |
| `OnStart`           | `$server`                           | When the master process starts (`SWOOLE_PROCESS` mode only).                                                       |
| `OnManagerStart`    | `$server`                           | When the manager process starts.                                                                                   |
| `BeforeWorkerStart` | `$server`, `$workerId`              | At the start of every worker and task worker. Hypervel reloads `.env` / config and resets inherited connection pools here. |
| `MainWorkerStart`   | `$server`, `$workerId`              | Only in worker `0`, after `BeforeWorkerStart`.                                                                     |
| `OtherWorkerStart`  | `$server`, `$workerId`              | Only in workers with `$workerId > 0`, after `BeforeWorkerStart`.                                                   |
| `AfterWorkerStart`  | `$server`, `$workerId`              | After worker startup completes. Requests that arrive during startup wait until these listeners finish.             |
| `OnWorkerExit`      | `$server`, `$workerId`              | Once per worker when Swoole begins graceful worker exit (drain connections or flush buffered work here).           |
| `OnWorkerStop`      | `$server`, `$workerId`              | When a worker process stops.                                                                                       |
| `OnPipeMessage`     | `$server`, `$fromWorkerId`, `$data` | When a worker receives an inter-worker pipe message.                                                               |

</div>

For example, to register a periodic maintenance timer only once per server inside worker `0`, you may listen for `MainWorkerStart` and use `Hypervel\Coordinator\Timer` so the timer stops automatically when the worker exits:

```php
use Hypervel\Coordinator\Timer;
use Hypervel\Core\Events\MainWorkerStart;
use Hypervel\Support\Facades\Event;

public function boot(): void
{
    Event::listen(MainWorkerStart::class, function (): void {
        $this->app->make(Timer::class)->tick(60.0, function (bool $isClosing): void {
            if ($isClosing) {
                return;
            }

            // Run periodic worker-0 maintenance...
        });
    });
}
```

<a name="optional-swoole-callbacks"></a>
### Optional Swoole Callbacks

Additional Swoole callbacks are available under `Hypervel\Core\Bootstrap`. They are not registered by default to avoid unnecessary callback overhead, and may be enabled in the `callbacks` array of your `config/server.php` file (or on an individual listener for connection events):

<div class="overflow-auto">

| Config Key               | Bootstrap Callback                       | Dispatched Event           | Event Properties                                        |
| ------------------------ | ---------------------------------------- | -------------------------- | ------------------------------------------------------- |
| `Event::ON_SHUTDOWN`     | `[ShutdownCallback::class, 'onShutdown']` | `OnShutdown`              | `$server`                                               |
| `Event::ON_MANAGER_STOP` | `[ManagerStopCallback::class, 'onManagerStop']` | `OnManagerStop`     | `$server`                                               |
| `Event::ON_WORKER_ERROR` | `[WorkerErrorCallback::class, 'onWorkerError']` | `OnWorkerError`     | `$server`, `$workerId`, `$workerPid`, `$exitCode`, `$signal` |
| `Event::ON_TASK`         | `[TaskCallback::class, 'onTask']`        | `OnTask`, `TaskTerminated` | `$server`, `$task`                                      |
| `Event::ON_FINISH`       | `[FinishCallback::class, 'onFinish']`    | `OnFinish`                 | `$server`, `$taskId`, `$data`                           |
| `Event::ON_CONNECT`      | `[ConnectCallback::class, 'onConnect']`  | `OnConnect`                | `$server`, `$fd`, `$reactorId`                          |
| `Event::ON_RECEIVE`      | `[ReceiveCallback::class, 'onReceive']`  | `OnReceive`                | `$server`, `$fd`, `$reactorId`, `$data`                 |
| `Event::ON_PACKET`       | `[PacketCallback::class, 'onPacket']`    | `OnPacket`                 | `$server`, `$data`, `$clientInfo`                       |
| `Event::ON_CLOSE`        | `[CloseCallback::class, 'onClose']`      | `OnClose`                  | `$server`, `$fd`, `$reactorId`                          |

</div>

For example, to observe abnormal worker exits and full server shutdown, you may add `WorkerErrorCallback` and `ShutdownCallback` to the `callbacks` array in your `config/server.php` file:

```php
use Hypervel\Core\Bootstrap\ShutdownCallback;
use Hypervel\Core\Bootstrap\WorkerErrorCallback;
use Hypervel\Server\Event;

'callbacks' => [
    // ...
    Event::ON_WORKER_ERROR => [WorkerErrorCallback::class, 'onWorkerError'],
    Event::ON_SHUTDOWN => [ShutdownCallback::class, 'onShutdown'],
],
```

When a worker exhausts PHP's memory limit, any requests it is handling are interrupted. Hypervel attempts to [report the fatal error](/docs/{{version}}/errors#reporting-exceptions) as the worker shuts down. If the operating system kills the worker with `SIGKILL`, for example when a container runs out of memory, the worker cannot run PHP shutdown handlers. To detect either kind of exit, enable `WorkerErrorCallback` as shown above and listen for `OnWorkerError`. Swoole's manager process dispatches this event when it survives the worker, so your listener can log the worker's PID, exit code, and signal. Use host or container monitoring to detect an out-of-memory kill that also terminates the manager.

All coroutines in a worker share its memory limit. Run memory-intensive jobs on a [separate queue worker](/docs/{{version}}/queues#running-the-queue-worker) so they cannot exhaust an HTTP worker's PHP memory limit.

<a name="http-request-events"></a>
### HTTP Request Events

For per-request HTTP lifecycle events (`RequestReceived`, `RequestHandled`, `ResponseSent`, `RequestTerminated`, and `RequestCanceled` in `Hypervel\HttpServer\Events`), consult the [request lifecycle documentation](/docs/{{version}}/lifecycle#finishing-requests).

<a name="task-workers-and-pipe-messages"></a>
## Task Workers and Pipe Messages

<a name="task-workers"></a>
### Task Workers

Swoole task workers allow event workers to offload synchronous or CPU-heavy work to a separate pool of processes inside the server. Most applications do not need task workers and should leave `task_worker_num` at `0`; for ordinary background jobs or async work that needs retries, persistence, or scaling across machines, use [queues](/docs/{{version}}/queues) or [concurrency](/docs/{{version}}/concurrency) instead.

To enable task workers, set `Constant::OPTION_TASK_WORKER_NUM` in `config/server.php` and register `TaskCallback` (and optionally `FinishCallback` if you want to receive task results back in the calling worker):

```php
use Hypervel\Core\Bootstrap\FinishCallback;
use Hypervel\Core\Bootstrap\TaskCallback;
use Hypervel\Server\Event;
use Swoole\Constant;

'settings' => [
    // ...
    Constant::OPTION_TASK_WORKER_NUM => 4,
],

'callbacks' => [
    // ...
    Event::ON_TASK => [TaskCallback::class, 'onTask'],
    Event::ON_FINISH => [FinishCallback::class, 'onFinish'],
],
```

Once task workers are enabled, you may dispatch a task from an event worker using the [`Swoole\Server`](#accessing-the-server-instance) instance:

```php
$server->task(['report_id' => $report->id]);
```

To handle incoming tasks, register a listener for the `Hypervel\Core\Events\OnTask` event. If you would like to return a result to an `OnFinish` listener, pass it to the event's `setResult` method:

```php
use Hypervel\Core\Events\OnTask;
use Hypervel\Support\Facades\Event;

Event::listen(OnTask::class, function (OnTask $event): void {
    $payload = $event->task->data;

    $event->setResult(processHeavyPayload($payload));
});
```

Hypervel isolates coroutine context for every task execution, even when `task_enable_coroutine` is `false`, and dispatches `Hypervel\Core\Events\TaskTerminated` after each task finishes.

<a name="pipe-messages"></a>
### Pipe Messages

Swoole workers can send messages directly to another worker using the `sendMessage` method on the [`Swoole\Server`](#accessing-the-server-instance) instance:

```php
$server->sendMessage('clear-local-cache', $workerId);
```

You may handle incoming messages by listening for the `Hypervel\Core\Events\OnPipeMessage` event:

```php
use Hypervel\Core\Events\OnPipeMessage;
use Hypervel\Support\Facades\Event;

Event::listen(OnPipeMessage::class, function (OnPipeMessage $event): void {
    if ($event->data === 'clear-local-cache') {
        // Clear worker-local cache...
    }
});
```

Uncaught exceptions in `OnPipeMessage` and `OnFinish` listeners are reported through your application's exception handler so a delivery failure does not terminate a healthy serving worker.

To communicate with custom server processes instead of event workers, consult the [server processes IPC documentation](/docs/{{version}}/server-processes#inter-process-communication).

<a name="accessing-the-server-instance"></a>
## Accessing the Server Instance

Once the server has been initialized, Hypervel binds the running `Swoole\Server` instance as a singleton in the service container. You may type-hint `Swoole\Server` in listeners, controllers, or services running inside the server:

```php
use Swoole\Server;

public function stats(Server $server): array
{
    return $server->stats();
}
```

<a name="credits"></a>
## Credits

Hypervel Server began as a port of [Hyperf Server](https://github.com/hyperf/hyperf/tree/master/src/server) and has been adapted for Hypervel's framework architecture and coroutine runtime.
