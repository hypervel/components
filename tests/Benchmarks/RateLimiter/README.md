# Rate Limiter Benchmark

This developer-only harness measures end-to-end rate limiter operations, including Hypervel's application container, manager, store wrapper, backend pool, atomic state update, and result decoding. It is not registered as an Artisan command and is not part of the PHPUnit suite.

Run the default Redis, Swoole, and database workloads from the components repository root:

```shell
php tests/Benchmarks/RateLimiter/benchmark.php
```

The harness loads the repository's `.env` through `tests/bootstrap.php`. Redis uses the connection configured by `rate-limiter.stores.redis.connection`. The database store uses the default database connection unless `--database-connection` selects another one. Its configured `rate_limits` table must already exist, so run the rate limiter migration before benchmarking it.

For example:

```shell
php tests/Benchmarks/RateLimiter/benchmark.php \
    --stores=redis,swoole,database \
    --database-connection=mysql \
    --operations=10000 \
    --concurrency=16 \
    --warmup=100
```

Each output row records operations per second and p50, p95, and p99 operation latency. The heading records the PHP and Swoole versions, workload size, warmup, concurrency, and generated rate limiter prefix. Each store also prints its non-secret connection, driver, and sizing inputs.

The harness measures fixed-window, sliding-window, and leaky-bucket rate limits on both allowed-heavy and denied-heavy paths. It runs each path with one client and with the requested number of clients contending for the same rate limit. Redis and pooled database operations can overlap while awaiting I/O. Swoole operations do not suspend inside one worker, so its concurrent row measures the normal single-worker coroutine workload rather than cross-process lock contention; the forked-worker test suite covers cross-process correctness.

Use a configured MySQL, MariaDB, or PostgreSQL connection when comparing production database behavior. SQLite results are explicitly labeled and should not be treated as representative of a networked database.

## Cache Limiter Baseline

The old cache-backed Redis limiter was measured once from pre-change commit `4c2ea3a9d212aa96016cfe0fa3f7de32ee56aca0` and then removed. Its operation matched the routing middleware path: check the limit, record the hit, and read remaining capacity on acceptance; check the limit and read retry timing on denial. The new operation used `Limiter::consume()` and read the returned decision fields. Both used the same local Redis service and connection configuration.

Indicative environment: PHP 8.4.23, Swoole 6.2.2, 5,000 measured operations per row, 100 warmup operations, Redis on `127.0.0.1`, and one or 16 clients.

| Path | Clients | Old operations/s | New operations/s | Old p50 | New p50 | Old p99 | New p99 |
|---|---:|---:|---:|---:|---:|---:|---:|
| Allowed | 1 | 1,310 | 5,944 | 741.14 µs | 156.78 µs | 1,030.79 µs | 270.22 µs |
| Allowed | 16 | 2,719 | 13,050 | 6,173.58 µs | 1,269.18 µs | 7,716.57 µs | 1,491.76 µs |
| Denied | 1 | 2,246 | 5,709 | 433.14 µs | 157.45 µs | 651.11 µs | 273.17 µs |
| Denied | 16 | 4,919 | 13,155 | 3,317.07 µs | 1,235.62 µs | 4,414.36 µs | 1,433.20 µs |

These are local development measurements, not release claims. Re-run the retained harness on deployment-like Redis, Valkey, Swoole, and database environments before making capacity decisions.
