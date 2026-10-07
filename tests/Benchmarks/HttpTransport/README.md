# HTTP transport benchmark

This developer harness compares the public HTTP client against a separate local HTTPS origin. TLS verification stays enabled. It measures cold startup and repeated bursts, named connection reuse, unnamed streams, buffered requests, incremental event delivery and slow consumers. It is not a CI performance test or a prediction of remote provider throughput.

Run only in an owner-confirmed idle window. Use independently installed baseline and modified worktrees with identical dependencies, PHP settings, opcache, JIT and GC. Copy this harness into an older checkout when necessary; do not change its production source.

Create a temporary certificate trusted only by the benchmark client:

```shell
mkdir -p /tmp/hypervel-http-benchmark
openssl req -x509 -newkey rsa:2048 -nodes \
  -keyout /tmp/hypervel-http-benchmark/key.pem \
  -out /tmp/hypervel-http-benchmark/ca.pem -days 2 \
  -subj '/CN=127.0.0.1' -addext 'subjectAltName=IP:127.0.0.1'
php tests/Benchmarks/HttpTransport/Fixtures/server.php \
  /tmp/hypervel-http-benchmark/ca.pem /tmp/hypervel-http-benchmark/key.pem
```

The origin listens on an automatically assigned loopback port and prints `READY <port>`. Keep it running in a separate terminal, then use that port below. Stop it when measurements finish and remove the temporary certificate and key.

```shell
php -d opcache.enable_cli=1 -d opcache.jit=disable \
  tests/Benchmarks/HttpTransport/benchmark.php \
  --endpoint=https://127.0.0.1:PORT --ca=/tmp/hypervel-http-benchmark/ca.pem \
  --requests=256 --samples=1 --concurrency=8 > /tmp/http-benchmark.json
```

Alternate baseline/current order within each scenario for at least ten pairs and report median and spread. Each process reports its initial cold burst separately; that burst includes lazy PHP initialization as well as new TLS connections. Measured samples follow that warmup. Throughput includes the configured gap between bursts. CPU measures the client process, excluding the separate origin.

Useful scenarios:

| Scenario | Options |
|---|---|
| Paced events | Defaults: 32 events of 128 bytes, 1 ms between events, 5 ms between bursts |
| Unpaced response | `--pace-us=0` |
| Serial warm reuse | `--concurrency=1 --pace-us=0` |
| Operation-owned transport | `--unnamed` |
| Ordinary buffered request | `--buffered` |
| Slow consumer, 1 MiB per response | `--chunks=256 --bytes=4096 --pace-us=0 --consumer-us=1000` |
| Sustained high concurrency | `--concurrency=128 --requests=512 --samples=10` |

The default reader is `Response::lines()`. On older StreamHandler implementations it can withhold paced events until the response finishes. Compare that API behavior directly, but do not describe its lower CPU as equivalent incremental delivery. `--bytewise` reproduces the byte-at-a-time reader used by applications to avoid that batching; compare baseline bytewise delivery with current `lines()` separately. The origin emits complete ASCII SSE records, and the harness checks their count and length.

Reports include start-to-first-event and complete-request p50/p95, client CPU, throughput, actual peak client and origin requests, and distinct/new connection IDs assigned by the origin at accept time. Named streaming transfers can retain 32 idle transports, so larger bursts still create new connections; this does not cap active requests. Buffered requests use Guzzle's separate idle easy-handle cache, whose synchronous default is three handles, not three physical connections. Per-request credentials change to exercise transport reuse without retaining a single credential identity.

Linux `/proc` supplies current RSS and open descriptors before and after each sample and after explicit handler cleanup. On the measured Linux build, each idle streaming transport retains three descriptors: a connection socket and both ends of a wakeup pipe. Include this cost when comparing retention bounds and sizing worker descriptor limits. Heap peaks and GC counts cover the client. The latency arrays and cumulative set of observed connection IDs also consume memory; account for that bookkeeping when comparing samples rather than treating total process memory as transport-only allocation. No collection runs inside the measured request loop. Keep raw reports outside the repository, and do not turn timing values into test assertions.
