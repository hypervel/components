# Reverb

- [Introduction](#introduction)
- [Installation](#installation)
- [Configuration](#configuration)
    - [Application Credentials](#application-credentials)
    - [Allowed Origins](#allowed-origins)
    - [Additional Applications](#additional-applications)
    - [Application Options](#application-options)
    - [SSL](#ssl)
- [Running the Server](#running-server)
    - [Logging](#logging)
    - [Restarting](#restarting)
- [Monitoring](#monitoring)
    - [Telescope](#telescope)
    - [HTTP API](#http-api)
- [Webhooks](#webhooks)
    - [Webhook Configuration](#webhook-configuration)
    - [Delivery and Signing](#delivery-and-signing)
    - [Batching](#batching)
    - [Failure Handling](#failure-handling)
- [Running Reverb in Production](#production)
    - [Open Files](#open-files)
    - [Workers](#workers)
    - [Web Server](#web-server)
    - [Ports](#ports)
    - [Process Management](#process-management)
- [Scaling](#scaling)
    - [Single-Instance Multi-Worker Scaling](#single-instance-multi-worker-scaling)
    - [Multi-Instance Redis Scaling](#multi-instance-redis-scaling)
- [Events](#events)

<a name="introduction"></a>
## Introduction

[Hypervel Reverb](https://github.com/hypervel/reverb) brings blazing-fast and scalable real-time WebSocket communication directly to your Hypervel application, and provides seamless integration with Hypervel's existing suite of [event broadcasting tools](/docs/{{version}}/broadcasting).

Reverb is compatible with the Pusher protocol and is built natively on Swoole. When installed, Reverb registers a WebSocket server alongside your application's HTTP server, allowing it to run as part of the normal Hypervel server process. A single Reverb instance can scale across multiple Swoole workers without Redis; Redis is only required when you need to coordinate multiple Reverb instances.

<a name="installation"></a>
## Installation

You may install Reverb using the `install:broadcasting` Artisan command:

```shell
php artisan install:broadcasting --reverb
```

If you would like to install Reverb manually, you may require the package via Composer and then run Reverb's installer:

```shell
composer require hypervel/reverb

php artisan reverb:install
```

<a name="configuration"></a>
## Configuration

Reverb's installer publishes a `config/reverb.php` configuration file with a sensible set of default configuration options. If you would like to make any configuration changes, you may do so by updating Reverb's environment variables or by updating the `config/reverb.php` configuration file.

Reverb may be disabled without removing the package by setting the `REVERB_ENABLED` environment variable to `false`. When disabled, Reverb will not register its WebSocket server, routes, bindings, or background tasks.

<a name="application-credentials"></a>
### Application Credentials

In order to establish a connection to Reverb, a set of Reverb "application" credentials must be exchanged between the client and server. These credentials are configured on the server and are used to verify requests from clients and from the Pusher-compatible HTTP API. You may define these credentials using the following environment variables:

```ini
REVERB_APP_ID=my-app-id
REVERB_APP_KEY=my-app-key
REVERB_APP_SECRET=my-app-secret
```

<a name="allowed-origins"></a>
### Allowed Origins

You may also define the origins from which client requests may originate by updating the value of the `allowed_origins` configuration value within the `apps.apps` section of the `config/reverb.php` configuration file. Any requests from an origin not listed in your allowed origins will be rejected. You may allow all origins using `*`:

```php
'apps' => [
    'provider' => 'config',

    'apps' => [
        [
            'app_id' => 'my-app-id',
            'allowed_origins' => ['hypervel.org'],
            // ...
        ],
    ],
],
```

<a name="additional-applications"></a>
### Additional Applications

Typically, Reverb provides a WebSocket server for the application in which it is installed. However, it is possible to serve more than one application using a single Reverb installation.

For example, you may wish to maintain a single Hypervel application which, via Reverb, provides WebSocket connectivity for multiple applications. This can be achieved by defining multiple applications in your application's `config/reverb.php` configuration file:

```php
'apps' => [
    'provider' => 'config',

    'apps' => [
        [
            'app_id' => 'my-app-one',
            // ...
        ],
        [
            'app_id' => 'my-app-two',
            // ...
        ],
    ],
],
```

<a name="application-options"></a>
### Application Options

Each application may also define client connection options, allowed origins, connection limits, message limits, client-event behavior, and message rate limiting:

```php
'apps' => [
    'provider' => 'config',

    'apps' => [
        [
            'app_id' => env('REVERB_APP_ID'),
            'key' => env('REVERB_APP_KEY'),
            'secret' => env('REVERB_APP_SECRET'),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            'allowed_origins' => ['*'],
            'ping_interval' => env('REVERB_APP_PING_INTERVAL', 60),
            'activity_timeout' => env('REVERB_APP_ACTIVITY_TIMEOUT', 30),
            'max_connections' => env('REVERB_APP_MAX_CONNECTIONS'),
            'max_message_size' => env('REVERB_APP_MAX_MESSAGE_SIZE', 10_000),
            'accept_client_events_from' => env('REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM', 'members'),
            'rate_limiting' => [
                'enabled' => env('REVERB_APP_RATE_LIMITING_ENABLED', false),
                'max_attempts' => env('REVERB_APP_RATE_LIMIT_MAX_ATTEMPTS', 60),
                'decay_seconds' => env('REVERB_APP_RATE_LIMIT_DECAY_SECONDS', 60),
                'terminate_on_limit' => env('REVERB_APP_RATE_LIMIT_TERMINATE', false),
            ],
        ],
    ],
],
```

The `accept_client_events_from` option controls which connections may send client events. The default value, `members`, allows client events from connections subscribed to the target private or presence channel. You may use `all` to allow client events from any connection, or any other value to disable client events.

The `max_message_size` option limits the size of each WebSocket message sent by a connected client. Reverb also has a server-level `max_request_size` option that limits HTTP API request bodies sent to the Reverb port:

```php
'servers' => [
    'reverb' => [
        'max_request_size' => env('REVERB_MAX_REQUEST_SIZE', 10_000),
        // ...
    ],
],
```

<a name="ssl"></a>
### SSL

In most cases, secure WebSocket connections are handled by the upstream web server, such as Nginx, before the request is proxied to your Reverb server.

However, Reverb may also terminate TLS directly. To do so, configure TLS options for the Reverb server in your application's `config/reverb.php` configuration file:

```php
'servers' => [
    'reverb' => [
        'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
        'port' => env('REVERB_SERVER_PORT', 8080),
        'options' => [
            'tls' => [
                'local_cert' => '/path/to/cert.pem',
                'local_pk' => '/path/to/key.pem',
            ],
        ],
    ],
],
```

You should also ensure the connection options used by your broadcasting driver and JavaScript client use the secure WebSocket scheme:

```ini
REVERB_SCHEME=https
REVERB_PORT=443
```

<a name="running-server"></a>
## Running the Server

Reverb runs as part of Hypervel's normal Swoole server. Once Reverb is installed and enabled, the WebSocket server is started with your application:

```shell
php artisan serve
```

By default, Reverb listens on `0.0.0.0:8080`. You may change the server bind address using the `REVERB_SERVER_HOST` and `REVERB_SERVER_PORT` environment variables:

```ini
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080
REVERB_SERVER_PATH=
```

The `REVERB_SERVER_PATH` environment variable may be used to add a path prefix to all WebSocket and HTTP API routes served by the Reverb port.

The `REVERB_SERVER_HOST` and `REVERB_SERVER_PORT` environment variables should not be confused with `REVERB_HOST` and `REVERB_PORT`. The former specify the host and port on which the Reverb server itself listens, while the latter pair instructs Hypervel's broadcasting driver and your JavaScript client where to connect. For example, in a production environment, you may route requests from your public Reverb hostname on port `443` to a Reverb server operating on `0.0.0.0:8080`. In this scenario, your environment variables would be defined as follows:

```ini
REVERB_SERVER_HOST=0.0.0.0
REVERB_SERVER_PORT=8080

REVERB_HOST=ws.hypervel.org
REVERB_PORT=443
REVERB_SCHEME=https
```

If you would like to scale Reverb independently from the rest of your application, you may run a dedicated Hypervel process or deployment with Reverb enabled and route WebSocket traffic to that process.

<a name="worker-recycling"></a>
### Worker Recycling

Swoole counts incoming WebSocket messages toward the same `SERVER_MAX_REQUESTS` limit as HTTP requests. When a worker reaches this limit, its connected WebSocket clients are disconnected while the worker restarts. Swoole adds a random grace of up to half the configured limit so workers do not all restart together.

For a dedicated Reverb deployment, you should set `SERVER_MAX_REQUESTS=0` to keep long-lived connections open. A mixed HTTP and Reverb deployment may retain a nonzero limit when periodic recycling is intentional, but its [`SERVER_MAX_WAIT_TIME` setting](/docs/{{version}}/deployment#graceful-shutdown) should allow enough time to drain its configured Redis, connection-limit, and webhook workload.

<a name="logging"></a>
### Logging

To improve performance, Reverb does not output debug information by default. If you would like Reverb to write connection and message activity to your application logs, bind Reverb's logger contract to the standard logger in one of your application's service providers:

```php
use Hypervel\Reverb\Contracts\Logger;
use Hypervel\Reverb\Loggers\StandardLogger;

/**
 * Register any application services.
 */
public function register(): void
{
    $this->app->bind(Logger::class, StandardLogger::class);
}
```

<a name="restarting"></a>
### Restarting

Since Reverb runs inside Hypervel's long-running Swoole server, changes to your code will not be reflected until the server is reloaded:

```shell
php artisan server:reload
```

When workers exit during a reload, Reverb gracefully drains active WebSocket connections, disconnects its Redis scaling subscriber when scaling is enabled, and flushes pending webhook batches before the worker stops.

<a name="monitoring"></a>
## Monitoring

<a name="telescope"></a>
### Telescope

If your application uses [Telescope](/docs/{{version}}/telescope), Hypervel includes a Reverb watcher that records Reverb connection lifecycle events. The watcher is configured in your application's `config/telescope.php` configuration file:

```php
use Hypervel\Telescope\Watchers;

Watchers\ReverbWatcher::class => [
    'enabled' => env('TELESCOPE_REVERB_WATCHER', true),
    'events' => [
        'connection_established',
        'connection_closed',
        'channel_created',
        'channel_removed',
        'connection_pruned',
        // 'message_received',
        // 'message_sent',
    ],
    'message_size_limit' => env('TELESCOPE_REVERB_MESSAGE_SIZE_LIMIT', 64),
],
```

The `message_received` and `message_sent` events are not recorded by default because they add a Telescope entry for every WebSocket message. You should only enable them for targeted debugging.

<a name="http-api"></a>
### HTTP API

Reverb exposes the Pusher-compatible HTTP API on the Reverb server port. This API may be used by Pusher-compatible SDKs and by your own operational tooling:

- `GET /up`
- `POST /apps/{appId}/events`
- `POST /apps/{appId}/batch_events`
- `GET /apps/{appId}/connections`
- `GET /apps/{appId}/channels`
- `GET /apps/{appId}/channels/{channel}`
- `GET /apps/{appId}/channels/{channel}/users`
- `POST /apps/{appId}/users/{userId}/terminate_connections`

If you configure `REVERB_SERVER_PATH`, all WebSocket and HTTP API routes will be prefixed with the configured path.

In an unscaled multi-worker deployment, event publication and user termination requests return a `500` response if Reverb cannot reach a sibling worker. An event may have already reached other workers when this happens, so callers should treat a failed event request as potentially partially delivered.

<a name="webhooks"></a>
## Webhooks

Reverb can send Pusher-compatible webhook notifications when channel lifecycle events occur. Webhooks are configured per application in the `apps.apps` section of your application's `config/reverb.php` configuration file.

<a name="webhook-configuration"></a>
### Webhook Configuration

To enable webhooks, configure a webhook URL and the events you would like to receive:

```php
'webhooks' => [
    'url' => env('REVERB_WEBHOOK_URL'),

    'events' => [
        'channel_occupied',
        'channel_vacated',
        'member_added',
        'member_removed',
        'client_event',
        'cache_miss',
    ],

    'headers' => [
        'Authorization' => 'Bearer ' . env('REVERB_WEBHOOK_TOKEN'),
    ],

    'filter' => [
        'channel_name_starts_with' => env('REVERB_WEBHOOK_CHANNEL_PREFIX'),
        'channel_name_ends_with' => env('REVERB_WEBHOOK_CHANNEL_SUFFIX'),
    ],

    'subscription_count' => env('REVERB_WEBHOOK_SUBSCRIPTION_COUNT', false),
    'disconnect_smoothing_ms' => env('REVERB_WEBHOOK_DISCONNECT_SMOOTHING_MS', 3000),
],
```

The `events` array acts as an allowlist. If the array is empty, Reverb will send all supported webhook events except `subscription_count`. The `subscription_count` event is controlled by its own configuration option and does not need to be listed in the `events` array. The `disconnect_smoothing_ms` option delays `channel_vacated` and `member_removed` webhooks after a disconnect so brief reconnects do not produce unnecessary remove / add webhook pairs.

<a name="delivery-and-signing"></a>
### Delivery and Signing

Webhook deliveries are queued on the `reverb-webhooks` Redis queue. Each webhook request contains a JSON body with a `webhook_id`, `time_ms`, and an `events` array.

Reverb signs each webhook body using HMAC-SHA256 and your application's Reverb secret. The request includes the following headers:

- `X-Pusher-Key`
- `X-Pusher-Signature`
- `Content-Type: application/json`

Custom headers may be configured, but `X-Pusher-Key`, `X-Pusher-Signature`, and `Content-Type` cannot be overridden.

<a name="batching"></a>
### Batching

For production workloads, you may enable webhook batching to combine many events into fewer HTTP requests:

```php
'webhooks' => [
    'url' => env('REVERB_WEBHOOK_URL'),

    'batching' => [
        'enabled' => env('REVERB_WEBHOOK_BATCHING_ENABLED', false),
        'max_events' => env('REVERB_WEBHOOK_BATCHING_MAX_EVENTS', 50),
        'max_delay_ms' => env('REVERB_WEBHOOK_BATCHING_MAX_DELAY_MS', 250),
        'max_payload_bytes' => env('REVERB_WEBHOOK_BATCHING_MAX_PAYLOAD_BYTES', 262144),
    ],
],
```

When batching is enabled, Reverb buffers events in Redis and schedules flush jobs on the `reverb-webhook-flush` queue. Reverb also checks for stale batches every minute so events claimed by a crashed flush job may be recovered.

<a name="failure-handling"></a>
### Failure Handling

You may configure webhook delivery timeouts and retry behavior using the `timeout`, `retries`, and `retry_delay` options:

```php
'webhooks' => [
    'url' => env('REVERB_WEBHOOK_URL'),
    'timeout' => env('REVERB_WEBHOOK_TIMEOUT', 5),
    'retries' => env('REVERB_WEBHOOK_RETRIES', 3),
    'retry_delay' => env('REVERB_WEBHOOK_RETRY_DELAY', 1),
],
```

If a webhook delivery exhausts all retry attempts, Reverb dispatches the `Hypervel\Reverb\Webhooks\Events\WebhookFailed` event.

<a name="production"></a>
## Running Reverb in Production

Due to the long-running nature of WebSocket servers, you may need to make some optimizations to your server and hosting environment to ensure your Reverb server can effectively handle the optimal number of connections for the resources available on your server.

> [!NOTE]
> [SonicStack](https://sonicstack.io) is the Hypervel team's managed deployment platform and runs Hypervel applications, including their integrated Reverb WebSocket server, without extra WebSocket infrastructure to configure.

<a name="open-files"></a>
### Open Files

Each WebSocket connection is held in memory until either the client or server disconnects. In Unix and Unix-like environments, each connection is represented by a file. However, there are often limits on the number of allowed open files at both the operating system and application level.

<a name="operating-system"></a>
#### Operating System

On a Unix based operating system, you may determine the allowed number of open files using the `ulimit` command:

```shell
ulimit -n
```

This command will display the open file limits allowed for different users. You may update these values by editing the `/etc/security/limits.conf` file. For example, updating the maximum number of open files to 10,000 for the `hypervel` user would look like the following:

```ini
# /etc/security/limits.conf
hypervel        soft  nofile  10000
hypervel        hard  nofile  10000
```

<a name="workers"></a>
### Workers

Reverb runs on Swoole's native WebSocket server and shares the configured WebSocket port across your Swoole workers. Increasing the number of workers increases the amount of parallel work Reverb can perform, but each worker also uses its own memory. You may configure the worker count using the `SERVER_WORKERS` environment variable read by your application's `config/server.php` configuration file.

In single-instance mode, Reverb uses a Swoole table to track channel subscription counts, presence member counts, webhook locks, and per-application connection limits across workers. The default table sizes are suitable for most applications, but you may tune them if you have a large number of channels or presence members:

```ini
REVERB_SWOOLE_SHARED_STATE_ROWS=65536
REVERB_SWOOLE_SHARED_STATE_LOCK_ROWS=8192
```

Reverb will log a warning when either table reaches 80% capacity.

<a name="web-server"></a>
### Web Server

In most cases, Reverb runs on a non web-facing port on your server. So, in order to route traffic to Reverb, you should configure a reverse proxy. Assuming Reverb is running on host `0.0.0.0` and port `8080` and your server utilizes the Nginx web server, a reverse proxy can be defined for your Reverb server using the following Nginx site configuration:

```nginx
server {
    ...

    location / {
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header Scheme $scheme;
        proxy_set_header SERVER_PORT $server_port;
        proxy_set_header REMOTE_ADDR $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";

        proxy_pass http://0.0.0.0:8080;
    }

    ...
}
```

> [!WARNING]
> Reverb listens for WebSocket connections at `/app` and handles API requests at `/apps`. You should ensure the web server handling Reverb requests can serve both of these URIs, including any prefix configured via `REVERB_SERVER_PATH`.

Typically, web servers are configured to limit the number of allowed connections in order to prevent overloading the server. To increase the number of allowed connections on an Nginx web server to 10,000, the `worker_rlimit_nofile` and `worker_connections` values of the `nginx.conf` file should be updated:

```nginx
user hypervel;
worker_processes auto;
pid /run/nginx.pid;
include /etc/nginx/modules-enabled/*.conf;
worker_rlimit_nofile 10000;

events {
  worker_connections 10000;
  multi_accept on;
}
```

The configuration above will allow each Nginx worker process to handle up to 10,000 connections. In addition, this configuration sets Nginx's open file limit to 10,000.

<a name="ports"></a>
### Ports

Unix-based operating systems typically limit the number of ports which can be opened on the server. You may see the current allowed range via the following command:

```shell
cat /proc/sys/net/ipv4/ip_local_port_range
# 32768	60999
```

The output above shows the server can handle a maximum of 28,231 (60,999 - 32,768) connections since each connection requires a free port. Although you may use [multi-instance scaling](#multi-instance-redis-scaling) to increase the number of allowed connections, you may increase the number of available open ports by updating the allowed port range in your server's `/etc/sysctl.conf` configuration file.

<a name="process-management"></a>
### Process Management

In most cases, you should use a process manager such as Supervisor to ensure your Hypervel server is continually running. If you are using Supervisor, you should update the `minfds` setting of your server's `supervisor.conf` file to ensure Supervisor is able to open the files required to handle connections to your Reverb server:

```ini
[supervisord]
...
minfds=10000
```

<a name="scaling"></a>
## Scaling

When a presence channel spans workers or Reverb instances, Reverb gathers its complete membership before sending `subscription_succeeded`. An unavailable worker may delay that response for up to ten seconds and may cause the subscription to fail.

<a name="single-instance-multi-worker-scaling"></a>
### Single-Instance Multi-Worker Scaling

By default, Hypervel Reverb scales across all Swoole workers in the current server process. Channel occupancy, presence member counts, and connection limits are stored in shared memory, while broadcasts are fanned out to other workers through Swoole pipe messages.

This mode does not require Redis and is the best starting point for most applications. If you need more capacity on one server, consider increasing your Swoole worker count before enabling multi-instance scaling.

Reverb drains shared counters when a worker exits normally. If a process crashes or is forcibly terminated before the drain completes, some counts may remain until the full Reverb server is restarted.

<a name="multi-instance-redis-scaling"></a>
### Multi-Instance Redis Scaling

If you need to run multiple Reverb instances behind a load balancer, you may enable Redis scaling. When Redis scaling is enabled, Reverb uses Redis shared state and Redis pub / sub to coordinate connections and broadcasts across instances:

```env
REVERB_SCALING_ENABLED=true
REVERB_SCALING_CONNECTION=reverb
REVERB_SCALING_CHANNEL=reverb
```

The `REVERB_SCALING_CONNECTION` option selects a named Redis connection from your application's `config/database.php` configuration file. Hypervel's default configuration includes a dedicated `reverb` Redis connection that may be configured with the `REDIS_REVERB_*` environment variables.

The scaling connection may use a standalone Redis server or Redis Sentinel. Redis Cluster is not supported for Reverb pub / sub scaling because it cannot provide the exact subscriber count required to gather complete presence and channel information. Redis Cluster remains supported for webhook batching when Reverb scaling is disabled. Because Redis state survives application restarts, an unclean shutdown may require stale Reverb state to be cleared operationally.

Once you have enabled Reverb's scaling option and configured Redis, you may run multiple Hypervel Reverb instances behind a load balancer that distributes incoming WebSocket connections evenly among the instances.

<a name="events"></a>
## Events

Reverb dispatches events during the lifecycle of a connection, channel, message, and webhook delivery. You may [listen for these events](/docs/{{version}}/events) to perform actions when connections are managed or messages are exchanged.

The following events are dispatched by Reverb:

#### `Hypervel\Reverb\Events\ConnectionEstablished`

Dispatched when a connection completes the Pusher handshake. The event receives the `Hypervel\Reverb\Contracts\Connection` instance.

#### `Hypervel\Reverb\Events\ConnectionClosed`

Dispatched when a connection is closed. The event receives the `Hypervel\Reverb\Contracts\Connection` instance.

#### `Hypervel\Reverb\Events\ChannelCreated`

Dispatched when a channel is created. This typically occurs when the first connection subscribes to a specific channel. The event receives the `Hypervel\Reverb\Protocols\Pusher\Channels\Channel` instance.

#### `Hypervel\Reverb\Events\ChannelRemoved`

Dispatched when a channel is removed. This typically occurs when the last connection unsubscribes from a channel. The event receives the `Hypervel\Reverb\Protocols\Pusher\Channels\Channel` instance.

#### `Hypervel\Reverb\Events\ConnectionPruned`

Dispatched when a stale connection is pruned by the server. The event receives the `Hypervel\Reverb\Protocols\Pusher\Channels\ChannelConnection` instance.

#### `Hypervel\Reverb\Events\MessageReceived`

Dispatched when a message is received from a client connection. The event receives the `Hypervel\Reverb\Contracts\Connection` instance and the raw string `$message`.

#### `Hypervel\Reverb\Events\MessageSent`

Dispatched when a message is sent to a client connection. The event receives the `Hypervel\Reverb\Contracts\Connection` instance and the raw string `$message`.

#### `Hypervel\Reverb\Webhooks\Events\WebhookFailed`

Dispatched when a webhook delivery exhausts all retry attempts. The event receives the `Hypervel\Reverb\Webhooks\WebhookPayload` instance, the webhook URL, and the exception that caused the failure.
