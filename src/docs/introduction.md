# Introduction

- [What Is Hypervel?](#what-is-hypervel)
- [Why Hypervel?](#why-hypervel)
    - [Built for Concurrent I/O](#built-for-concurrent-io)
    - [A Full-Stack Framework](#a-full-stack-framework)
- [Long-Running Applications](#long-running-applications)
- [Laravel Compatibility](#laravel-compatibility)
- [Hypervel's Direction](#hypervels-direction)
- [Next Steps](#next-steps)
    - [New Applications](#new-applications)
    - [Existing Laravel Applications](#existing-laravel-applications)
    - [Package Development](#package-development)

<a name="what-is-hypervel"></a>
## What Is Hypervel?

Hypervel is a modern, opinionated PHP framework built for Swoole. Applications run in long-lived workers and use coroutines to handle many requests, jobs, and connections concurrently.

When one coroutine is waiting on a database query, cache lookup, queue operation, file access, or HTTP request, the worker can continue doing other work instead of remaining idle.

It is a full-stack framework for traditional web applications, APIs, microservices, real-time services, background workers, and other applications that spend meaningful time waiting on external systems.

<a name="why-hypervel"></a>
## Why Hypervel?

Many applications spend a significant portion of each request waiting on input and output. A request may query a database, call Redis, communicate with another HTTP service, write to storage, dispatch a job, or broadcast a WebSocket message before it can return a response.

Hypervel is designed to use that waiting time efficiently. Its coroutine runtime allows a worker to run other coroutines while supported I/O operations are in progress, without requiring you to organize ordinary application code around callbacks or manually manage an event loop.

<a name="built-for-concurrent-io"></a>
### Built for Concurrent I/O

In a traditional blocking runtime, a worker remains occupied while an I/O operation completes. Additional worker processes can increase concurrency, but each process has its own memory and can only handle one blocking operation at a time.

Consider an endpoint that waits one second for an upstream service. In a blocking runtime with eight workers that each handle one request at a time, only about eight of these requests can complete each second before additional requests begin waiting for a worker. In Hypervel, Swoole coroutines allow those waits to overlap within each worker, so the number of worker processes does not impose the same limit on concurrent I/O. When a coroutine reaches an operation that can yield, Swoole may pause that coroutine and resume another one. The original coroutine continues from the same point when its operation is ready.

Coroutines are especially useful for applications that make frequent database, Redis, HTTP, filesystem, queue, or timer calls. However, coroutines do not make CPU-intensive PHP code run in parallel. This work, along with PHP extensions that Swoole cannot hook, should run in a separate process. To learn more about these considerations, consult the [coroutine documentation](/docs/{{version}}/coroutines#common-pitfalls).

<a name="a-full-stack-framework"></a>
### A Full-Stack Framework

Hypervel provides the features expected from a modern full-stack framework, including [routing](/docs/{{version}}/routing), [middleware](/docs/{{version}}/middleware), [dependency injection](/docs/{{version}}/container), [Eloquent](/docs/{{version}}/eloquent), [database migrations](/docs/{{version}}/migrations), [sessions](/docs/{{version}}/session), [caching](/docs/{{version}}/cache), [queues](/docs/{{version}}/queues), [scheduling](/docs/{{version}}/scheduling), [broadcasting](/docs/{{version}}/broadcasting), [authentication](/docs/{{version}}/authentication), [validation](/docs/{{version}}/validation), [Blade templates](/docs/{{version}}/blade), and [testing](/docs/{{version}}/testing).

Hypervel also includes [coroutine APIs](/docs/{{version}}/coroutines), the [Concurrency facade](/docs/{{version}}/concurrency), persistent [database](/docs/{{version}}/database#connection-pooling) and [Redis](/docs/{{version}}/redis#connection-pooling) connection pools, [WebSocket servers](/docs/{{version}}/websockets), [gRPC services](/docs/{{version}}/grpc), and [custom server processes](/docs/{{version}}/server-processes).

<a name="long-running-applications"></a>
## Long-Running Applications

Hypervel boots the application before the Swoole server begins handling requests. The application, service providers, singletons, facades, configuration repository, and other framework state remain in memory and are reused by future requests handled by the same worker. Service providers are registered and booted during application startup, not once for every request.

This lifecycle avoids rebuilding the framework for every request and allows connections and other resources to be reused efficiently. For example, Hypervel's database and Redis integrations reuse established connections from worker-level pools instead of opening a new connection for every operation.

Since multiple requests or jobs may run concurrently inside the same worker, request- or job-specific mutable state must not be stored in global variables, static properties, service providers, or shared singletons. Keep that state in method parameters, the current request when handling HTTP, [context](/docs/{{version}}/context), or the lower-level [coroutine context](/docs/{{version}}/coroutine-context).

For a complete overview of application startup and request handling, consult the [request lifecycle documentation](/docs/{{version}}/lifecycle).

<a name="laravel-compatibility"></a>
## Laravel Compatibility

Hypervel aims for Laravel API compatibility wherever it fits. However, Hypervel is not a Laravel clone or drop-in replacement. Many Hypervel components are ports of Laravel packages, adapted for Hypervel's asynchronous runtime, performance requirements, and coroutine safety, but the framework itself has its own architecture, features, supported integrations, and direction.

Moving an existing Laravel application or package to Hypervel is a deliberate port, not a namespace replacement. Hypervel differs in its runtime, service lifecycles, supported drivers, package structure, and some public APIs. The [porting guide](/docs/{{version}}/porting-from-laravel) explains the differences that commonly affect application and package code.

Hypervel actively monitors Laravel changes and ports compatible additions when they make sense for Hypervel's architecture. If your application or package depends on a recent Laravel API that is not available, check the current Hypervel source and raise the concrete use case with the maintainers.

<a name="hypervels-direction"></a>
## Hypervel's Direction

When a different design is better suited to long-running workers, coroutines, or Hypervel's performance requirements, Hypervel uses it. The dedicated [rate limiter](/docs/{{version}}/rate-limiting), [dual-mode Redis cache tags](/docs/{{version}}/cache#redis-tag-modes), [layered cache stores](/docs/{{version}}/cache#building-cache-stacks), and [Redis session system with user-session management](/docs/{{version}}/session#managing-user-sessions) are examples of features developed for Hypervel. The framework will continue tracking and porting Laravel features where they fit while also developing its own features and integrations.

First-party ClickHouse support and built-in integration with [SonicStack](https://sonicstack.io) are also planned. SonicStack is Hypervel's fully managed deployment platform, built specifically for running and maintaining Hypervel applications. It is also how we plan to fund ongoing framework development.

<a name="next-steps"></a>
## Next Steps

Whether you are starting a new application, moving an existing Laravel project, or developing a reusable package, the following guides are a good place to start.

<a name="new-applications"></a>
### New Applications

If you are creating a new Hypervel application, begin with the [installation guide](/docs/{{version}}/installation). You may then want to explore the [directory structure](/docs/{{version}}/structure), [configuration](/docs/{{version}}/configuration), [request lifecycle](/docs/{{version}}/lifecycle), [coroutines](/docs/{{version}}/coroutines), and [deployment](/docs/{{version}}/deployment) documentation.

<a name="existing-laravel-applications"></a>
### Existing Laravel Applications

If you are moving an existing Laravel application to Hypervel, begin with the [porting guide](/docs/{{version}}/porting-from-laravel). Create a fresh Hypervel application and port the code deliberately instead of treating the migration as a namespace replacement.

<a name="package-development"></a>
### Package Development

If you are developing a Hypervel package, read the [package development](/docs/{{version}}/packages) and [Testbench](/docs/{{version}}/testbench) documentation. These guides explain package discovery, service providers, configuration, and testing packages inside a Hypervel application. If you are porting an existing Laravel package, read the [porting guide](/docs/{{version}}/porting-from-laravel) first.
