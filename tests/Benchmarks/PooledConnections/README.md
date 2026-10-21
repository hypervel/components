# Pooled connection benchmark

This developer harness measures how long database sessions remain borrowed while an operation waits for something else. It compares throughput, calling-process CPU, checkout time and session occupancy under pool pressure and with spare capacity. It uses a temporary SQLite database and local services; no provider accounts are needed. It is not a CI performance test or a prediction of production throughput.

See [recorded measurements](results.md) for the framework wait and connection-reuse comparisons.

Run from the repository root in an idle window:

```shell
php -d opcache.enable_cli=1 -d opcache.jit=disable \
  tests/Benchmarks/PooledConnections/benchmark.php \
  --case=cache-lock --requests=64 --concurrency=16 --pool=4 --samples=2 \
  > /tmp/pooled-cache-lock.json
```

Repeat with `--pool=16 --after-query` to give each caller a database slot and measure the cost of borrowing again after the wait. `--transaction` keeps each operation inside a transaction; use a read-only case such as `cache-lock` to check that transaction ownership remains intact. Each process warms up before reporting samples. Operations run in closed batches of `--concurrency`: each batch finishes before the next starts, which affects throughput. A failed operation or leaked database session fails the run.

For before/after comparisons, use independently installed worktrees with the same dependencies, PHP settings and harness. Copy this directory into an older checkout when needed; do not edit its production source. Alternate the worktrees for at least ten fresh-process pairs per scenario. Keep commands, revision IDs, dependency versions and raw JSON outside the repository. Report medians, ranges and paired changes rather than selecting the fastest run. Compare both constrained and spare-capacity cases; returning a connection can add a checkout when application code queries again.

## Cases

`--help` lists all cases and options. Defaults are 16 operations, concurrency eight, two database slots and two measured samples.

| Cases | Operation and setup |
| --- | --- |
| `cache-lock`, `cache-funnel` | Worker-array lock/slot held by another coroutine for 20 ms; retry every 5 ms. |
| `database-lock` | Real database lock with a one-second TTL and 100 ms retries. Expiry rounds to whole seconds, so waits can approach two seconds. |
| `redis-funnel`, `redis-throttle` | Real Redis contention with the same 20 ms hold; requires `--redis-port`. |
| `http-retry` | HTTP fake fails once, with a 20 ms retry delay. Response listeners and the delay callback query the database. |
| `saloon-delay`, `saloon-retry` | Actual Saloon delay/retry orchestration with an HTTP fake and a 20 ms wait. |
| `saloon-rate-limit` | Actual worker-array rate-limit wait, one request per second. |
| `concurrency` | Two independent 20 ms child tasks. |
| `saloon-pool`, `saloon-generator` | Three controlled 20 ms requests at concurrency two. The generator queries before each yield and after its final yield. |
| `process-run`, `process-wait`, `process-stop` | Real local processes. Graceful stop waits for child readiness before borrowing the database session; this case requires a POSIX shell. |
| `mail` | Real SMTP delivery through the pooled mail transport; requires `--smtp-port`. |
| `filesystem-client`, `filesystem-driver` | Real S3 SDK read, or a custom whole-driver pool whose reads use HTTPS. The latter does not measure native FTP/SFTP. |
| `socialite`, `slack`, `sqs` | Default Socialite client, default Slack webhook, or real SQS SDK publication through a queue proxy against local HTTPS. |
| `grpc`, `grpc-retry` | Native HTTP/2 unary call, or retry after querying between call creation and consumption; requires `--grpc`. |
| `scout-algolia`, `scout-meilisearch`, `scout-typesense` | Requests through the actual Guzzle clients configured by Scout, extracted from each SDK. Measures transport wiring and reuse, not provider serialization or indexing. |
| `pusher-release`, `ably-release`, `mercure-release` | Actual framework dispatch with a controlled 20 ms transport wait. These isolate database release; they do not measure provider/network throughput or connection reuse. |
| `scout-join` | Actual import runner joining two 20 ms tasks after the parent queries. Measures the parent join boundary, not full indexing or vector generation. |

## Local services

Start only the services needed for the selected cases. Stop them after measuring. The HTTPS and SMTP fixtures print their automatically assigned ports as `READY <port>`.

For HTTPS cases, create a temporary certificate and start the fixture:

```shell
mkdir -p /tmp/hypervel-pooled-benchmark
openssl req -x509 -newkey rsa:2048 -nodes \
  -keyout /tmp/hypervel-pooled-benchmark/key.pem \
  -out /tmp/hypervel-pooled-benchmark/ca.pem -days 2 \
  -subj '/CN=127.0.0.1' -addext 'subjectAltName=IP:127.0.0.1'
php tests/Benchmarks/PooledConnections/Fixtures/server.php \
  /tmp/hypervel-pooled-benchmark/ca.pem /tmp/hypervel-pooled-benchmark/key.pem
```

Pass `--endpoint=https://127.0.0.1:PORT --ca=/tmp/hypervel-pooled-benchmark/ca.pem`. TLS verification stays enabled. The origin delays each response by 20 ms. Remove the certificate and key when finished.

For mail:

```shell
php tests/Benchmarks/PooledConnections/Fixtures/smtp-server.php 20000
```

Pass its port with `--smtp-port=PORT`. The argument is the acceptance delay in microseconds. The harness checks the fixture's delivered-message count after every sample. Run one benchmark process at a time against this fixture because its counters are shared.

For Redis, start a disposable local instance and supply its port. The harness uses database zero with unique keys and does not flush the database:

```shell
redis-server --bind 127.0.0.1 --port 19752 --save '' --appendonly no
```

For gRPC, reuse the integration fixture:

```shell
GRPC_TEST_SERVER_PORT=19751 php tests/Integration/Grpc/Fixtures/server.php
```

Pass `--grpc=127.0.0.1:19751`. Keep that port free and run without the optional gRPC TLS fixture settings for this plaintext local comparison.

## Reading results

Elapsed time includes coroutine cleanup. CPU measures the calling PHP process, excluding Redis, HTTPS/SMTP/gRPC servers and child processes. Holding fewer database sessions can allow more simultaneous external work; compare equal-capacity runs before attributing a CPU change to the release call itself. With the default 16 operations per sample, `request_ms.p95` is the slowest operation; use larger samples when comparing latency distributions.

`hold_ms_per_operation` sums physical session hold time, including any later checkout. `checkouts_per_operation` counts borrows, not SQL queries. Pool instrumentation, latency arrays and HTTP connection-ID bookkeeping add common overhead; heap usage is not a transport-only memory measurement.

`new_connections` is measured from the HTTPS fixture's accept-time IDs for the Scout clients, Socialite and Slack. It is `null` for other cases. Mail reports separate SMTP counters. Socialite's clients remain coroutine-owned, so this case does not imply sharing connections across requests.

HTTP buffer and general connection-reuse comparisons live in the existing [HTTP transport benchmark](../HttpTransport/README.md). Keep those measurements separate from the AI SDK's response-lifecycle changes.
