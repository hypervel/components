# Request Lifecycle

- [Introduction](#introduction)
- [Lifecycle Overview](#lifecycle-overview)
    - [Starting the Server](#starting-the-server)
    - [Bootstrapping the Application](#bootstrapping-the-application)
    - [Handling Requests](#handling-requests)
    - [Finishing Requests](#finishing-requests)
    - [Console Commands](#console-commands)
- [Per-Worker vs. Per-Request](#per-worker-vs-per-request)
- [Focus on Service Providers](#focus-on-service-providers)

<a name="introduction"></a>
## Introduction

When using any tool in the "real world", you feel more confident if you understand how that tool works. Application development is no different. When you understand how your development tools function, you feel more comfortable and confident using them.

The goal of this document is to give you a good, high-level overview of how the Hypervel framework works. By getting to know the overall framework better, everything feels less "magical" and you will be more confident building your applications. If you don't understand all of the terms right away, don't lose heart! Just try to get a basic grasp of what is going on, and your knowledge will grow as you explore other sections of the documentation.

Unlike traditional PHP applications that start from a file such as `public/index.php` for every request, Hypervel starts a long-running Swoole server. The application is bootstrapped into memory, and incoming HTTP requests are handled by Swoole workers using coroutines.

<a name="lifecycle-overview"></a>
## Lifecycle Overview

<a name="starting-the-server"></a>
### Starting the Server

The entry point for a Hypervel application is the `artisan` executable. This file loads the Composer generated autoloader definition, configures Swoole hook flags, and retrieves an instance of the Hypervel application from `bootstrap/app.php`.

The `bootstrap/app.php` file creates and configures the application using `Hypervel\Foundation\Application`. This is where routing, middleware, exception handling, service providers, and console command paths are configured before the application instance is returned.

When you run `php artisan serve`, the console kernel resolves the `serve` command, which starts the configured Swoole server. By default, the HTTP server is configured in your application's `config/server.php` file and listens on port `9501`.

<a name="bootstrapping-the-application"></a>
### Bootstrapping the Application

Before Hypervel begins handling HTTP requests, the HTTP server resolves the HTTP kernel, boots the application, and prepares the router. The HTTP kernel defines an array of `bootstrappers` that load environment variables, load configuration, configure error handling, register facades, register and boot service providers, and perform other tasks that need to happen before requests are handled.

One of the most important kernel bootstrapping actions is loading the [service providers](/docs/{{version}}/providers) for your application. Service providers are responsible for bootstrapping all of the framework's various components, such as the database, queue, validation, and routing components.

Hypervel will instantiate and register each provider. Then, once all of the providers have been registered, the `boot` method will be called on each provider. This is so service providers may depend on every container binding being registered and available by the time their `boot` method is executed.

Essentially every major feature offered by Hypervel is bootstrapped and configured by a service provider. While the framework internally uses dozens of service providers, you also have the option to create your own. Your application's service providers are typically listed in the `bootstrap/providers.php` file or registered from `bootstrap/app.php`.

<a name="handling-requests"></a>
### Handling Requests

Once the Swoole server is running, each incoming HTTP request is converted into a `Hypervel\Http\Request` instance and handed to the HTTP kernel. The current request and response are stored in coroutine-local context so concurrent requests handled by the same worker resolve the correct request, response, and related state.

The method signature for the HTTP kernel's `handle` method is quite simple: it receives a `Request` and returns a `Response`. Think of the kernel as being a big black box that represents your entire application. Feed it HTTP requests and it will return HTTP responses.

The HTTP kernel passes the request through the application's middleware stack. These middleware handle reading and writing the [HTTP session](/docs/{{version}}/session), determining if the application is in maintenance mode, [verifying the CSRF token](/docs/{{version}}/csrf), and more. We'll talk more about these soon.

Once the request passes through the global middleware stack, it is handed off to the router for dispatching. The router will dispatch the request to a route or controller, as well as run any route specific middleware.

Middleware provide a convenient mechanism for filtering or examining HTTP requests entering your application. For example, Hypervel includes a middleware that verifies if the user of your application is authenticated. If the user is not authenticated, the middleware will redirect the user to the login screen. However, if the user is authenticated, the middleware will allow the request to proceed further into the application. Some middleware are assigned to all routes within the application, like `PreventRequestsDuringMaintenance`, while some are only assigned to specific routes or route groups. You can learn more about middleware by reading the complete [middleware documentation](/docs/{{version}}/middleware).

If the request passes through all of the matched route's assigned middleware, the route or controller method will be executed and the response returned by the route or controller method will be sent back through the route's chain of middleware.

<a name="finishing-requests"></a>
### Finishing Requests

Once the route or controller method returns a response, the response will travel back outward through the route's middleware, giving the application a chance to modify or examine the outgoing response.

Finally, once the response travels back through the middleware, the HTTP kernel's `handle` method returns the response object to Hypervel's HTTP server. The response is written to the Swoole socket, and then Hypervel runs any terminable middleware and application terminating callbacks.

<a name="console-commands"></a>
### Console Commands

Most Artisan commands are handled by the console kernel. The `artisan` executable loads the application from `bootstrap/app.php`, the console kernel bootstraps the application, and the command is executed.

The `serve` and `watch` commands are special because they start and manage the long-running Swoole server. The `watch` command watches your application files and restarts the server when they change, which is useful during local development because workers keep application code and booted state in memory.

<a name="per-worker-vs-per-request"></a>
## Per-Worker vs. Per-Request

The most important difference between Hypervel and traditional PHP request lifecycles is that Hypervel workers are long-lived. The application, service providers, singletons, facades, configuration repository, and static state remain in memory after a request is finished and are reused by future requests handled by the same worker.

This makes Hypervel very fast, but it also means you should be intentional about where state is stored. Application-wide configuration and framework setup should happen during bootstrapping, typically in service providers. Request-specific data should live in method parameters, the current request, [context](/docs/{{version}}/context), or the lower-level [coroutine context](/docs/{{version}}/coroutine-context).

You should not mutate configuration, service provider state, singleton properties, or static properties while handling an individual request unless that state is deliberately shared for the lifetime of the worker. If you need to perform work after an individual response has been sent, use the `defer` helper rather than registering a new application terminating callback during the request.

<a name="focus-on-service-providers"></a>
## Focus on Service Providers

Service providers are truly the key to bootstrapping a Hypervel application. The application instance is created, the service providers are registered and booted, and the bootstrapped application is used by the Swoole server to handle requests.

Having a firm grasp of how a Hypervel application is built and bootstrapped via service providers is very valuable. Your application's user-defined service providers are stored in the `app/Providers` directory and are typically registered from the `bootstrap/providers.php` file or `bootstrap/app.php`.

By default, the `AppServiceProvider` is fairly empty. This provider is a great place to add your application's own bootstrapping and service container bindings. For large applications, you may wish to create several service providers, each with more granular bootstrapping for specific services used by your application.
