## Introduction

**Hypervel** is a Laravel-style PHP framework with native coroutine support for ultra-high performance.

Hypervel ports many core components from Laravel while maintaining familiar usage patterns, making it instantly accessible to Laravel developers. The framework combines the elegant and expressive development experience of Laravel with the powerful performance benefits of coroutine-based programming. If you're a Laravel developer, you'll feel right at home with this framework, requiring minimal learning curve.

This is an ideal choice for building microservices, API gateways, and high-concurrency applications where traditional PHP frameworks often encounter performance constraints.

## Why Hypervel?

While Laravel Octane impressively enhances your Laravel application's performance, it's crucial to understand the nature of modern web applications. In most cases, the majority of latency stems from I/O operations, such as file operations, database queries, and API requests.

However, Laravel doesn't support coroutines - the entire framework is designed for a blocking I/O environment. Applications heavily dependent on I/O operations will still face performance bottlenecks. Consider this scenario:

Imagine building an AI-powered chatbot where each conversation API takes 3-5 seconds to respond. With 10 workers in Laravel Octane receiving 10 concurrent requests, all workers would be blocked until these requests complete.

For I/O-intensive scenarios, even with Laravel Octane's improvements, your application's ability to handle concurrent requests is still limited by the duration of these I/O operations. Hypervel addresses this issue by leveraging coroutines, allowing for efficient handling of concurrent I/O operations without blocking workers. This approach can significantly improve the performance and concurrency of applications with heavy I/O requirements.

Moreover, it's unlikely that Laravel Octane will support coroutines in the near future (see [this issue](https://github.com/laravel/octane/issues/765)), given that only Swoole runtime currently supports this feature and considering backward compatibility with the framework and third-party packages.

::: important
Even if Laravel Octane supported coroutines, these coroutines would still be limited to a single request, with workers remaining blocked until all I/O operations within that request completed. That means your Laravel application can't get better QPS results in this kind of scenario.

See [this pull request](https://github.com/swoole/swoole-src/pull/4330) for more information.
:::

## Laravel Octane

Laravel is renowned for its expressive and elegant syntax, powerful package ecosystem, and thriving community. However, due to the traditional lifecycle in PHP-FPM, Laravel's performance is limited despite various optimization techniques applied to the framework.

At LaraCon 2021, Taylor Otwell announced Laravel Octane — a first-party package maintained by the Laravel team. Octane significantly enhances Laravel application performance by running your code in a long-lived process on high-powered application servers such as Swoole, RoadRunner, and FrankenPHP.

> For more information, see [Laravel Octane](https://laravel.com/docs/master/octane).

## Hyperf

Hypervel is built on the Hyperf ecosystem, similar to how Laravel relates to Symfony. Hyperf is a high-performance framework powered by Swoole and Swow, with all components natively supporting coroutines and strictly adhering to PSR standards. It allows developers to easily build high-concurrency applications with built-in support for non-blocking I/O.

The Hyperf project maintains high activity levels on GitHub, with regular feature updates and version releases. This demonstrates strong community engagement and consistent development progress. With over 6,000 stars on GitHub and more than 350 contributors since 2019, Hyperf is a top choice for developers seeking a modern web framework to build high-performance PHP projects with asynchronous I/O support.

> For more details, visit [Hyperf's official website](https://hyperf.io/).

## Benchmark

The benchmark tests cover two distinct scenarios to evaluate performance under different conditions:

1. Simple API Test:
   - A basic `hello world` API endpoint
   - No middleware applied
   - Tests raw response speed

2. Simulated I/O Wait Test:
   - Sleep for one second to simulate I/O wait time
   - Respond with `hello world` after the delay
   - Tests performance under I/O-bound conditions

> worker numbers are configured as CPU cores by default

Test Environment Specifications:
- Hardware: Apple M1 Pro 2021
- CPU: 8 cores
- RAM: 16 GB

### Simple API Test

* Laravel Octane (8 workers)

```text:no-line-numbers
Running 10s test @ http://127.0.0.1:8000/api
  4 threads and 100 connections
  Thread Stats   Avg      Stdev     Max   +/- Stdev
    Latency    15.93ms   16.86ms 155.82ms   87.02%
    Req/Sec     2.07k   420.46     3.10k    66.00%
  82661 requests in 10.04s, 16.95MB read
Requests/sec:   8230.97
Transfer/sec:      1.69MB
```

* Hypervel (8 workers)

```text:no-line-numbers
Running 10s test @ http://127.0.0.1:9501/api
  4 threads and 100 connections
  Thread Stats   Avg      Stdev     Max   +/- Stdev
    Latency     7.66ms   17.85ms 249.92ms   90.25%
    Req/Sec    24.42k    10.47k   54.37k    68.53%
  971692 requests in 10.06s, 151.98MB read
Requests/sec:  96562.80
Transfer/sec:     15.10MB
```

::: chart

```json
{
  "type": "bar",
  "data": {
    "labels": ["Hello World API"],
    "datasets": [
      {
        "label": "Laravel Octane",
        "data": [8230.97],
        "backgroundColor": "rgba(255, 182, 193, 0.8)",
        "borderColor": "rgba(255, 182, 193, 1)",
        "borderWidth": 1
      },
      {
        "label": "Hypervel",
        "data": [96562.80],
        "backgroundColor": "rgba(173, 216, 230, 0.8)",
        "borderColor": "rgba(173, 216, 230, 1)",
        "borderWidth": 1
      }
    ]
  },
  "options": {
    "responsive": true,
    "scales": {
      "x": {
        "stacked": false
      },
      "y": {
        "stacked": false,
        "title": {
          "display": true,
          "text": "QPS"
        }
      }
    },
    "plugins": {
      "title": {
        "display": true,
        "text": "Laravel Octane vs Hypervel"
      },
      "legend": {
        "position": "top"
      }
    }
  }
}
```
:::

### Simulated I/O Wait Test

* Laravel Octane (8 workers)

```text:no-line-numbers
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

* Hypervel (8 workers)

```text:no-line-numbers
Running 10s test @ http://10.10.4.12:9501/api
  16 threads and 15000 connections
  Thread Stats   Avg      Stdev     Max   +/- Stdev
    Latency     1.02s    64.72ms   1.87s    93.62%
    Req/Sec     1.16k     1.68k    9.15k    87.59%
  109401 requests in 10.09s, 19.82MB read
Requests/sec:  10842.71
Transfer/sec:      1.96MB
```

> In this case I run wrk in another machine to make sure wrk can use as much resource for keeping more connections.

::: chart

```json
{
  "type": "bar",
  "data": {
    "labels": ["1s wait I/O"],
    "datasets": [
      {
        "label": "Laravel Octane",
        "data": [7.92],
        "backgroundColor": "rgba(255, 182, 193, 0.8)",
        "borderColor": "rgba(255, 182, 193, 1)",
        "borderWidth": 1
      },
      {
        "label": "Hypervel",
        "data": [10842.71],
        "backgroundColor": "rgba(173, 216, 230, 0.8)",
        "borderColor": "rgba(173, 216, 230, 1)",
        "borderWidth": 1
      }
    ]
  },
  "options": {
    "responsive": true,
    "scales": {
      "x": {
        "stacked": false
      },
      "y": {
        "stacked": false,
        "title": {
          "display": true,
          "text": "QPS"
        }
      }
    },
    "plugins": {
      "title": {
        "display": true,
        "text": "Laravel Octane vs Hypervel"
      },
      "legend": {
        "position": "top"
      }
    }
  }
}
```
:::

> Laravel Octane's QPS number is close to 8, but because it differs so significantly from Hypervel's numbers, it appears this way when generated in the chart.