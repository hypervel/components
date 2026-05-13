# Deployment

- [Introduction](#introduction)
- [Server Requirements](#server-requirements)
- [Server Configuration](#server-configuration)
    - [Nginx](#nginx)
    - [Nginx and WebSockets](#nginx-and-websockets)
    - [Running the Hypervel Server](#running-the-hypervel-server)
    - [Directory Permissions](#directory-permissions)
- [Optimization](#optimization)
    - [Caching Configuration](#optimizing-configuration-loading)
    - [Caching Events](#caching-events)
    - [Caching Routes](#optimizing-route-loading)
    - [Caching Views](#optimizing-view-loading)
- [Reloading Services](#reloading-services)
- [Debug Mode](#debug-mode)
- [The Health Route](#the-health-route)
- [Deploying With SonicStack](#deploying-with-sonicstack)

<a name="introduction"></a>
## Introduction

When you're ready to deploy your Hypervel application to production, there are some important things you can do to make sure your application is running as efficiently as possible. In this document, we'll cover some great starting points for making sure your Hypervel application is deployed properly.

<a name="server-requirements"></a>
## Server Requirements

The Hypervel framework has a few system requirements. Hypervel ships with its own Swoole-based application server, so your production server should run the long-running Hypervel server process instead of PHP-FPM. You should ensure that your server has the following minimum PHP version and commonly required extensions:

<div class="content-list" markdown="1">

- PHP >= 8.4
- Ctype PHP Extension
- cURL PHP Extension
- DOM PHP Extension
- Fileinfo PHP Extension
- Filter PHP Extension
- Hash PHP Extension
- Intl PHP Extension
- JSON PHP Extension
- Mbstring PHP Extension
- OpenSSL PHP Extension
- PCNTL PHP Extension
- PCRE PHP Extension
- PDO PHP Extension
- POSIX PHP Extension
- Redis PHP Extension >= 6.1
- Session PHP Extension
- Swoole PHP Extension >= 6.2
- Tokenizer PHP Extension
- XML PHP Extension

</div>

<a name="server-configuration"></a>
## Server Configuration

<a name="nginx"></a>
### Nginx

If you are deploying your application to a server that is running Nginx, you may use the following configuration file as a starting point for configuring your web server. Most likely, this file will need to be customized depending on your server's configuration. **If you would like assistance in managing your server, consider using a fully-managed Hypervel platform like [SonicStack](https://sonicstack.io).**

Please ensure, like the configuration below, your web server proxies dynamic requests to your Hypervel server. The default Hypervel HTTP server listens on port `9501` and may be configured in your application's `config/server.php` file:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name example.com;
    root /srv/example.com/public;

    location / {
        try_files $uri @hypervel;
    }

    location @hypervel {
        proxy_http_version 1.1;
        proxy_set_header Host $http_host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        proxy_pass http://127.0.0.1:9501;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

When using a web server such as Nginx, you should let the web server serve your application's static files directly and only proxy dynamic requests to Hypervel. This is more efficient than serving static files through PHP. Hypervel also includes a built-in static file handler for direct-to-client deployments; when using a web server to serve static files as shown above, you may disable it by setting the `SERVER_STATIC_FILE_HANDLER` environment variable to `false`.

<a name="nginx-and-websockets"></a>
### Nginx and WebSockets

If you are proxying WebSocket connections, your Nginx configuration should include the WebSocket upgrade headers. For example, Hypervel Reverb listens on port `8080` by default:

```nginx
server {
    listen 80;
    listen [::]:80;
    server_name websocket.example.com;

    location / {
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host $http_host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 60s;

        proxy_pass http://127.0.0.1:8080;
    }
}
```

<a name="running-the-hypervel-server"></a>
### Running the Hypervel Server

In production, your Hypervel server should be kept running by a process monitor, container orchestrator, or managed platform. The server may be started using the `serve` Artisan command:

```shell
php artisan serve
```

By default, the HTTP server binds to `0.0.0.0:9501`. You may configure the server host, port, worker count, and other Swoole settings using the `HTTP_SERVER_HOST`, `HTTP_SERVER_PORT`, and `SERVER_WORKERS_NUMBER` environment variables read by `config/server.php`.

<a name="directory-permissions"></a>
### Directory Permissions

Hypervel will need to write to the `bootstrap/cache` and `storage` directories, so you should ensure the user running the Hypervel server has permission to write to these directories.

<a name="optimization"></a>
## Optimization

When deploying your application to production, there are a variety of files that should be cached, including your configuration, events, routes, and views. Hypervel provides a single, convenient `optimize` Artisan command that will cache all of these files. This command should typically be invoked as part of your application's deployment process:

```shell
php artisan optimize
```

The `optimize:clear` command may be used to remove all of the cache files generated by the `optimize` command as well as all keys in the default cache driver:

```shell
php artisan optimize:clear
```

In the following documentation, we will discuss each of the granular optimization commands that are executed by the `optimize` command.

<a name="optimizing-configuration-loading"></a>
### Caching Configuration

When deploying your application to production, you should make sure that you run the `config:cache` Artisan command during your deployment process:

```shell
php artisan config:cache
```

This command will combine all of Hypervel's configuration files into a single, cached file, which greatly reduces the number of trips the framework must make to the filesystem when loading your configuration values.

> [!WARNING]
> If you execute the `config:cache` command during your deployment process, you should be sure that you are only calling the `env` function from within your configuration files. Once the configuration has been cached, the `.env` file will not be loaded and all calls to the `env` function for `.env` variables will return `null`.

<a name="caching-events"></a>
### Caching Events

You should cache your application's auto-discovered event to listener mappings during your deployment process. This can be accomplished by invoking the `event:cache` Artisan command during deployment:

```shell
php artisan event:cache
```

<a name="optimizing-route-loading"></a>
### Caching Routes

If you are building a large application with many routes, you should make sure that you are running the `route:cache` Artisan command during your deployment process:

```shell
php artisan route:cache
```

This command reduces all of your route registrations into a single method call within a cached file, improving the performance of route registration when registering hundreds of routes.

<a name="optimizing-view-loading"></a>
### Caching Views

When deploying your application to production, you should make sure that you run the `view:cache` Artisan command during your deployment process:

```shell
php artisan view:cache
```

This command precompiles all your Blade views so they are not compiled on demand, improving the performance of each request that returns a view.

<a name="reloading-services"></a>
## Reloading Services

> [!NOTE]
> When deploying to [SonicStack](https://sonicstack.io), it is not necessary to use the `reload` command, as gracefully reloading all services is handled automatically.

After deploying a new version of your application, any long-running services such as the Hypervel server (which serves both HTTP and Hypervel Reverb), queue workers, and scheduler should be reloaded / restarted to use the new code. Hypervel provides a single `reload` Artisan command that will signal these services:

```shell
php artisan reload
```

The `reload` command gracefully reloads the Hypervel server and signals queue workers and the scheduler to restart. If you are not using [SonicStack](https://sonicstack.io), you should manually configure a process monitor that can detect when your reloadable processes exit and automatically restart them.

<a name="debug-mode"></a>
## Debug Mode

The debug option in your `config/app.php` configuration file determines how much information about an error is actually displayed to the user. By default, this option is set to respect the value of the `APP_DEBUG` environment variable, which is stored in your application's `.env` file.

> [!WARNING]
> **In your production environment, this value should always be `false`. If the `APP_DEBUG` variable is set to `true` in production, you risk exposing sensitive configuration values to your application's end users.**

<a name="the-health-route"></a>
## The Health Route

Hypervel includes a built-in health check route that can be used to monitor the status of your application. In production, this route may be used to report the status of your application to an uptime monitor, load balancer, or orchestration system such as Kubernetes.

When your application's `bootstrap/app.php` file enables the health route, it is typically served at `/up`, which will return a 200 HTTP response if the application has booted without exceptions. Otherwise, a 500 HTTP response will be returned. You may configure the URI for this route in your application's `bootstrap/app.php` file:

```php
->withRouting(
    web: __DIR__.'/../routes/web.php',
    commands: __DIR__.'/../routes/console.php',
    health: '/up', // [tl! remove]
    health: '/status', // [tl! add]
)
```

When HTTP requests are made to this route, Hypervel will also dispatch a `Hypervel\Foundation\Events\DiagnosingHealth` event, allowing you to perform additional health checks relevant to your application. Within a [listener](/docs/{{version}}/events) for this event, you may check your application's database or cache status. If you detect a problem with your application, you may simply throw an exception from the listener.

<a name="deploying-with-sonicstack"></a>
## Deploying With SonicStack

If you would like a fully-managed, auto-scaling deployment platform tuned for Hypervel, check out [SonicStack](https://sonicstack.io). SonicStack is a robust deployment platform for Hypervel applications, offering managed compute, databases, caches, and object storage.

Launch your Hypervel application on SonicStack and fall in love with the scalable simplicity. Because SonicStack is built and maintained by the Hypervel team, it works seamlessly with the framework and directly supports the framework's ongoing development.
