# Introduction

- [What Is Hypervel?](#what-is-hypervel)
- [Why Hypervel?](#why-hypervel)
- [Built for Async I/O](#built-for-async-io)
- [Familiar, Productive APIs](#familiar-productive-apis)
- [Long-Running Workers](#long-running-workers)
- [Performance Benchmarks](#performance-benchmarks)
    - [Simple API Endpoint](#simple-api-endpoint)
    - [Simulated I/O Wait](#simulated-io-wait)
- [Next Steps](#next-steps)

<a name="what-is-hypervel"></a>
## What Is Hypervel?

Hypervel is a Laravel-style async PHP framework powered by Swoole coroutines. It provides Laravel's expressive, familiar APIs while running on a high-performance, non-blocking runtime built for HTTP servers, queues, scheduled jobs, WebSockets, and I/O-heavy applications.

Hypervel is designed for applications that need the productivity of a modern full-stack PHP framework and the throughput of an async runtime. It is a great fit for traditional web applications, API gateways, microservices, real-time applications, background workers, and services that spend meaningful time waiting on databases, caches, queues, HTTP APIs, or other external systems.

<a name="why-hypervel"></a>
## Why Hypervel?

Many modern applications spend much of their time waiting on I/O. A request might query a database, call Redis, talk to another HTTP API, write to storage, dispatch jobs, or broadcast WebSocket messages before it can return a response.

In a traditional blocking runtime, the worker handling that request waits while each I/O operation completes. Running more processes can help, but concurrency is still bounded by worker count and server resources.

Hypervel is built around Swoole coroutines. When one coroutine is waiting on I/O, the worker can continue running other coroutines instead of sitting idle. This lets Hypervel handle high-concurrency workloads efficiently while keeping application code expressive and familiar.

<a name="built-for-async-io"></a>
## Built for Async I/O

Consider an AI-powered chat application where each upstream model request takes three to five seconds to respond. In a blocking runtime, every worker handling one of those requests remains occupied until the upstream service responds.

In Hypervel, those waiting periods do not have to block the whole worker. The runtime can continue serving other requests, processing jobs, or handling WebSocket traffic while coroutines wait for I/O to complete.

This is the core advantage of Hypervel's runtime model: the framework is built for applications where network and storage latency dominate the total request time.

<a name="familiar-productive-apis"></a>
## Familiar, Productive APIs

Hypervel provides Laravel's expressive APIs for routing, middleware, controllers, service providers, configuration, queues, events, notifications, validation, Eloquent, Blade, Inertia, testing, and more.

That means you can build Hypervel applications using familiar framework patterns while targeting a coroutine-first runtime. Hypervel's internals are refactored for long-running workers and coroutine safety, while the application-facing APIs remain productive and easy to read.

<a name="long-running-workers"></a>
## Long-Running Workers

Hypervel applications run inside long-lived Swoole workers. This avoids rebuilding the entire framework for every request and allows Hypervel to keep useful framework state in memory between requests.

Because workers are long-lived, application code should avoid storing request-specific state in global variables, static properties, or singletons. Request-specific state should be passed through the current request flow or stored in [context](/docs/{{version}}/context) (or the lower-level [coroutine context](/docs/{{version}}/coroutine-context)).

<a name="performance-benchmarks"></a>
## Performance Benchmarks

The benchmarks below compare Hypervel to Laravel Octane for a simple API endpoint and an I/O-bound endpoint that waits for one second before responding. They are intended to show how Hypervel behaves under both raw request handling and coroutine-friendly I/O wait workloads.

The benchmark tests cover two scenarios:

<div class="content-list" markdown="1">

- A simple API endpoint that responds with `hello world`.
- A simulated I/O wait endpoint that sleeps for one second before responding with `hello world`.

</div>

The worker count was configured to match the number of CPU cores by default.

Test environment:

| Resource | Value |
| --- | --- |
| Hardware | Apple M1 Pro 2021 |
| CPU | 8 cores |
| RAM | 16 GB |

<a name="simple-api-endpoint"></a>
### Simple API Endpoint

| Runtime | Workers | Requests / second | Average latency | Transfer / second |
| --- | ---: | ---: | ---: | ---: |
| Laravel Octane | 8 | 8,230.97 | 15.93ms | 1.69MB |
| Hypervel | 8 | 96,562.80 | 7.66ms | 15.10MB |

Laravel Octane:

```text
Running 10s test @ http://127.0.0.1:8000/api
  4 threads and 100 connections
  Thread Stats   Avg      Stdev     Max   +/- Stdev
    Latency    15.93ms   16.86ms 155.82ms   87.02%
    Req/Sec     2.07k   420.46     3.10k    66.00%
  82661 requests in 10.04s, 16.95MB read
Requests/sec:   8230.97
Transfer/sec:      1.69MB
```

Hypervel:

```text
Running 10s test @ http://127.0.0.1:9501/api
  4 threads and 100 connections
  Thread Stats   Avg      Stdev     Max   +/- Stdev
    Latency     7.66ms   17.85ms 249.92ms   90.25%
    Req/Sec    24.42k    10.47k   54.37k    68.53%
  971692 requests in 10.06s, 151.98MB read
Requests/sec:  96562.80
Transfer/sec:     15.10MB
```

<a name="simulated-io-wait"></a>
### Simulated I/O Wait

| Runtime | Workers | Requests / second | Average latency | Transfer / second |
| --- | ---: | ---: | ---: | ---: |
| Laravel Octane | 8 | 7.92 | 1.03s | 1.66KB |
| Hypervel | 8 | 10,842.71 | 1.02s | 1.96MB |

Laravel Octane:

```text
Running 10s test @ http://127.0.0.1:8000/api
  4 threads and 100 connections
  Thread Stats   Avg      Stdev     Max   +/- Stdev
    Latency     1.03s   184.92us   1.03s    87.50%
    Req/Sec     1.52      1.29     5.00     54.84%
  80 requests in 10.10s, 16.80KB read
  Socket errors: connect 0, read 0, write 0, timeout 72
Requests/sec:      7.92
Transfer/sec:      1.66KB
```

Hypervel:

```text
Running 10s test @ http://10.10.4.12:9501/api
  16 threads and 15000 connections
  Thread Stats   Avg      Stdev     Max   +/- Stdev
    Latency     1.02s    64.72ms   1.87s    93.62%
    Req/Sec     1.16k     1.68k    9.15k    87.59%
  109401 requests in 10.09s, 19.82MB read
Requests/sec:  10842.71
Transfer/sec:      1.96MB
```

> [!NOTE]
> The Hypervel I/O wait benchmark was run with `wrk` on another machine so that `wrk` could use enough resources to keep more connections open during the test.

<a name="next-steps"></a>
## Next Steps

To start building with Hypervel, read the [installation](/docs/{{version}}/installation), [request lifecycle](/docs/{{version}}/lifecycle), [configuration](/docs/{{version}}/configuration), [coroutines](/docs/{{version}}/coroutines), and [deployment](/docs/{{version}}/deployment) documentation.
