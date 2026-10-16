# AI benchmarks

Run measurements only in an owner-confirmed idle window. These developer harnesses use local services and dummy credentials; they don't call paid model APIs. Keep raw reports outside the repository. Timing results aren't CI assertions.

See [recorded measurements](results.md) for the streaming, storage, runtime and batching comparisons, including rejected experiments and remaining tradeoffs.

## HTTP integration

`http.php` adapts the existing HTTP transport benchmark to run complete OpenAI agent calls. Its origin uses the existing OpenAI test payload builders and the HTTPS server setup from `HttpTransport/Fixtures/server.php`. Both buffered and streaming calls return the same generated text.

Create a temporary loopback certificate using the commands in [the HTTP benchmark](../HttpTransport/README.md), then start this origin instead:

```shell
php tests/Benchmarks/Ai/Fixtures/server.php \
  /tmp/hypervel-http-benchmark/ca.pem /tmp/hypervel-http-benchmark/key.pem
```

Use the printed `READY` port:

```shell
php -d opcache.enable_cli=1 -d opcache.jit=disable \
  tests/Benchmarks/Ai/http.php \
  --endpoint=https://127.0.0.1:PORT --ca=/tmp/hypervel-http-benchmark/ca.pem \
  --requests=256 --samples=5 --concurrency=8 > /tmp/ai-http.json
```

The first burst is reported separately as `cold`; it includes lazy package initialization and fresh TLS connections. Later samples reuse the same application and named HTTP transport. Streaming retains at most 32 idle connections, so larger bursts open new TLS connections even in warm samples; read `new_origin_connections` alongside latency and CPU. Buffered named requests retain up to 256 idle handles by default. TLS verification remains enabled in every case. CPU and process resources cover the client, not the separate origin.

| Scenario | Options |
|---|---|
| Paced streaming | Defaults: 32 text deltas of 128 bytes, 1 ms between deltas |
| Unpaced streaming | `--pace-us=0` |
| Buffered generation | `--buffered` |
| Direct HTTP comparison | `--http-only`, with the same buffering, payload and pacing options |
| Native transport bulk reads | `--http-only --bulk-reads`; use framework 8 KiB line reads to check bulk-read costs separately |
| Dynamic provider accounts | `--accounts=10000`; the boot-registered resolver reads the current operation's dummy account key |
| Slow consumer | `--chunks=256 --bytes=4096 --pace-us=0 --consumer-us=1000` |
| Sustained concurrent calls | `--requests=4096 --samples=10 --concurrency=64` |

Alternate full-agent and direct-HTTP runs with identical PHP settings and dependencies for at least ten pairs per scenario, and report median and spread. Throughput includes the configured burst gaps; compare runs with identical settings. The direct comparison decodes the same payload and uses byte-at-a-time SSE reads, but doesn't run the agent loop, create SDK events or retain them for replay. The difference measures the cost of those SDK features; it isn't a Laravel comparison or evidence that those features can be removed.

`--bulk-reads` is a separate native-transport diagnostic, not an equivalent agent-overhead comparison. It requires `--http-only` without `--buffered`. The AI parser retains bytewise reads because larger reads can delay delivery on the PHP-stream fallback.

Latency starts before admitting a burst of coroutines. Reports separate the first event, first text and completed request. Buffered calls have no incremental event latency. `peak_active_operations` counts active client callbacks; `peak_origin_requests` records overlapping requests observed by the origin, rather than assuming that scheduled coroutines are simultaneous transfers. The origin also supplies connection IDs and a digest of the received dummy credential; a mismatched account aborts the run.

RSS, heap, descriptors and GC are reported per sample and after handler cleanup. Connection-ID sets and latency arrays are reset each sample, so they don't grow with the number of accounts. Per-sample summary reports remain in memory until output. Agent responses intentionally retain events for replay; large-stream memory is part of that supported behavior. Keep sample sizes consistent when comparing resource growth.

Stop the owned origin and remove its temporary certificate and key after the run. No changes to the application, production transports or provider APIs are needed to run this harness.

## Runtime components

`Fixtures/RuntimeBenchmark.php` measures provider lookup, Bedrock client construction and completed-credential reuse, skill discovery, and response aggregation/context/protocol costs. It uses dummy credentials and makes no network requests. Run its smoke mode before measuring:

```shell
BENCH_SMOKE=1 BENCH_OUT=/tmp/ai-runtime-smoke.jsonl \
  php -d opcache.enable_cli=1 -d opcache.jit=disable vendor/bin/phpunit --no-progress \
  tests/Benchmarks/Ai/Fixtures/RuntimeBenchmark.php
```

For measurements, replace `BENCH_SMOKE=1` with `BENCH_IDLE_CONFIRMED=1` and use a fresh output path. Results append JSON lines. Each case records its first operation, then three warmup operations and five measured batches. First-use figures exclude application boot and fixture creation; earlier cases may already have loaded shared classes. Use PHPUnit's `--filter` in a fresh process when measuring isolated first use. Alternate normal and `--order-by=reverse` runs to check ordering effects, and report medians and ranges across processes.

Fixed providers reuse their configured instance; the resolved-provider case changes dummy credentials on each lookup. Report this per-lookup cost alongside the full HTTP account-churn comparison, rather than assuming one lookup per agent call.

Bedrock construction exercises the same factory used once per buffered or streamed model step, so tool loops repeat this cost. Both cases pass the normal 60-second timeout: one uses static keys, while the other uses the default-chain configuration with its credential holder already filled with dummy credentials. Neither performs discovery or contacts metadata services. Signing and request I/O are excluded. The completed-credential case checks the holder's fast path separately.

`testBedrockStep` compares fresh clients against a benchmark-only client constructed before timing. It runs the real Bedrock gateway, AWS serialization/signing/parsing and framework HTTP middleware. Only the final transport is replaced with an in-memory response, without fake request history. Buffered and streamed cases return the same 1 KiB answer; the stream has 32 text deltas and complete terminal usage frames. Its body is seekable, so this measures AWS event decoding without claiming coverage of network streaming or the known non-seekable decoder defects. Each warm batch has 100 complete steps. Assertions check the signed request, timeout, output, usage and client construction behavior outside timing. These cases measure the potential CPU saving of reuse, not a production cache or its concurrency safety.

Compare Bedrock cases with the inherited AWS configuration and with `AWS_CONFIG_FILE` pointing to a nonexistent file. Report both: repeated shared-config parsing can materially affect construction cost, and disabling that supported configuration is not a proposed optimization. Use `--filter testBedrockStep` for the complete-step experiment and `--filter testBedrockClientConstruction` for construction alone. The difference between fresh and reused steps includes amortized SDK model preparation, not just the constructor; keep that distinction when reporting construction as a share of step CPU.

Skill cases use 20 or 100 files with 4 KiB instruction bodies and YAML name, description, license and metadata fields. They create a new tool per operation so its instance cache cannot hide discovery work. Fixture creation is outside measurement, and the operating system's file cache stays warm. Compare retained heap as well as CPU: cached instruction bodies remain in each worker. First-use heap deltas also include any newly loaded classes and the returned description; they aren't a pure cache-size measurement.

Stream cases create and consume 32 or 1,024 text deltas, including event allocation, retained replay state and final aggregation. The context case measures a small coroutine-context save/restore callback, not arbitrary application context setup. Vercel and AG-UI cases include protocol encoding into a discard sink, without network writes. These are component costs, not complete provider or browser round trips. Correctness checks run after each case, outside timing.

`testToolExecution` compares serial and opted-in concurrency of four through the real buffered and streaming text-generation loops. A local step gateway requests four tools, then returns their results in order. Each tool simulates 20 ms of external I/O; no provider HTTP request is made. The measured operation includes both model steps, tool scheduling and completion, but excludes public prompt middleware. Assertions check result order, actual peak tool concurrency and completed cleanup.

`testConversationTitles` compares serial and concurrent title generation through public agent prompt/stream calls, real OpenAI parsing, framework HTTP middleware and SQLite conversation persistence. Only the final HTTP transport is replaced: main and title requests each wait 20 ms and return fixed local responses, with no fake request history or paid API calls. Every operation creates a new conversation. Assertions check both requests, actual overlapping calls, saved title and assistant message. These cases measure scheduling overhead and overlap under a controlled delay, not production model latency or database throughput. Each warm sample runs 20 operations. Use `--filter 'testToolExecution|testConversationTitles'` to run these cases separately.

Reports include CPU, wall time, heap and automatic GC runs. Explicit collection runs only between batches; each sample retains its final result, matching a caller still holding a completed response. The fixture is outside normal test discovery and adds no timing thresholds to CI.

## Broadcasting

`Fixtures/BroadcastBenchmark.php` measures response consumption with no broadcasts, immediate Pusher-compatible delivery, and queued delivery through real Redis jobs. Start `Fixtures/broadcast-server.php` with the same temporary TLS certificate and key used by the HTTP harness. The server accepts signed Pusher SDK requests on loopback; it isn't a hosted Pusher account or a browser client.

```shell
php tests/Benchmarks/Ai/Fixtures/broadcast-server.php /tmp/hypervel-http-benchmark/ca.pem /tmp/hypervel-http-benchmark/key.pem

REDIS_HOST=127.0.0.1 REDIS_DB=0 BENCH_REDIS_PORT=REDIS_PORT \
  BENCH_BROADCAST_PORT=PRINTED_PORT BENCH_CA=/tmp/hypervel-http-benchmark/ca.pem \
  BENCH_SMOKE=1 BENCH_OUT=/tmp/ai-broadcast-smoke.jsonl \
  php -d opcache.enable_cli=1 -d opcache.jit=disable vendor/bin/phpunit --no-progress \
  tests/Benchmarks/Ai/Fixtures/BroadcastBenchmark.php
```

Use disposable Redis: the integration trait flushes its selected test database. Cluster is rejected before setup. For measurements, replace smoke mode with `BENCH_IDLE_CONFIRMED=1`; each case warms once then records five samples. Add `BENCH_PROBE=1` for one full-size sample without warmup before repeated runs. Alternate normal and reverse order across fresh processes. Stop the owned origin afterward.

Cases consume one or sixteen simultaneous responses, each with 32 eight-byte text deltas available every 20 ms plus stream/text start and end events. Stream starts are evenly staggered across that 20 ms interval. Availability uses fixed monotonic deadlines, so slow broadcasts cannot silently slow the simulated provider. This measures the response/event/broadcast path, not model generation or browser delivery. The local receiver waits 5 or 50 ms before accepting each event. TLS verification and the real Pusher SDK serialization, signing and HTTP client remain enabled; its per-request timeout is ten seconds.

Queued cases run one real Hypervel worker with concurrency sixteen in the benchmark process. Its normal pop loop, Redis-backed pause/restart checks, job events, timeout monitor, dispatch and cleanup remain active; only process signal registration is disabled. The application default cache and the worker cache both use the disposable Redis instance. Redis uses a one-second blocking queue pop and a forty-connection queue pool for concurrent jobs, producers and the parent. The worker stops after admitting the expected job count and joins running jobs, with a thirty-second run limit and one attempt per job. Producers and the worker share one event loop here; high CPU use can delay both, unlike deployments with separate worker processes. Use queued rows to assess combined CPU per event, send/job counts and callback costs; latency near CPU saturation does not predict production worker capacity. Assertions require every event exactly once, the full text payload, correct channels, no failed jobs, and an empty queue after the worker finishes.

Reports count events accepted behind a later event from the same stream. The receiver's constant delay limits what this says about ordering: overlapping sends on one channel can complete out of order when real delivery times vary, even if this fixture records zero inversions. Immediate delivery waits for each send and preserves that stream's order.

One additional backlog case queues all sixteen streams before starting the same worker against the 5 ms receiver. It separates producer CPU from worker CPU and reports worker drain time, jobs per second and receiver concurrency. No producer runs during that drain. This measures an isolated worker under these local settings, not a universal capacity limit or event delivery latency; availability-based latency is omitted for this case. Compare its drain rate with the scheduled arrival rate of the concurrent cases, retaining the different initial queue state. The steady delta rate is 800 events/second; start/end events raise the average over this short fixture to about 902 events/second.

Reports separate producer completion, the receiver's last acceptance, remaining delivery time, and worker shutdown. Acceptance latency starts when each event became available, including any producer or queue backlog. It does not measure arrival at a browser. CPU includes producers and the in-process worker, but excludes the separate receiver and Redis server. Divide `client_cpu_ms` by `total_ms` to report the fraction of one core used; compare CPU per event after subtracting the matching no-broadcast baseline. Request-body bytes exclude HTTP/TLS framing; queue bytes count serialized job payloads. Receiver connection counts cover broadcast requests only and distinguish reused connections from those accepted after the sample reset. Read them alongside actual send/job counts, concurrency and latency: connection churn also incurs TCP/TLS work. These cases establish the cost of existing per-event delivery; they introduce no batching or production configuration.

### Batching experiment

Set `BENCH_RETAIN_CONNECTIONS=1` to compare the same cases using a synchronous cURL handler retaining up to 256 idle handles, matching the framework HTTP client's default limit. The normal broadcaster retains three. Run matched controls and batching cases both ways to separate connection churn from batching benefits; each result records `retain_connections`. This switch is benchmark-only and bypasses the broadcaster's asynchronous handler selection; these cases send synchronously.

The eight additional sixteen-stream cases compare the same immediate/queued paths and receiver delays with a benchmark-only `BroadcastBatcher`. It uses one sender and a bounded 128-entry channel per stream. A zero-millisecond window drains compatible events already available without deliberately waiting; a 50 ms window waits only until the first constituent's arrival plus that bound. An overdue sender drains available events without starting another window. Structural events and changed message/invocation identities end a batch. The 4 KiB bound counts JSON-encoded text bytes, not raw UTF-8 bytes or the entire event payload. Individually oversized events pass through unchanged.

The helper constructs new text events and preserves original events for response replay. It batches only `TextDelta`, the type emitted by this fixture. Both producer and sender close their channel on exit, and joined children cannot outlive the operation. A synchronization check verifies flushing while the producer waits for the first send; another checks encoded Unicode size, structural/identity boundaries and unchanged replay. This preserves the measured candidate rather than running production `Hypervel\Ai\Streaming\BroadcastBatcher`, which also handles reasoning deltas, passes reasoning summaries through separately, propagates operation/application context, and leaves generation in the calling coroutine.

Reports retain every original event's availability through constituent-ID bookkeeping outside the wire payload. Compare per-constituent acceptance latency and actual sends, not just combined packet latency. `events_consumed_ms` ends when producers consume all original events; `producer_ms` includes joining their senders. Queued job totals are known only after all senders finish, so the worker's maximum job count is updated then. A blocking pop can add shutdown time, reported separately; it is not event-delivery latency. For shared-process CPU utilization, also compare CPU with `max(producer_ms, last_accept_ms)` to exclude an idle shutdown tail.

Run matched sixteen-stream controls and candidates with `--filter 'testBroadcasts.*16 '`. That includes the no-broadcast and backlog controls. The small bookkeeping changes apply to controls too, so repeat those controls alongside candidates rather than comparing only with older fixture results. These comparisons isolate the batching tradeoff; production behavior is covered by the AI broadcast tests.

## Redis embedding cache

`Fixtures/EmbeddingCacheBenchmark.php` compares the existing JSON cache format with phpredis connection compression disabled or set to LZ4. Both use the default `SERIALIZER_NONE`, so the cache store handles PHP serialization and its allowed-class policy. It calls the real pending-generation, provider and cache APIs; a local gateway returns deterministic, distinct 1,536-dimensional vectors without HTTP calls or recorded fake history. The vectors use synthetic values with up to eight decimal places, so compression ratios describe this fixture, not every model's output.

Use a disposable standalone Redis instance; the fixture rejects `REDIS_CLUSTER_HOSTS_AND_PORTS`, including values loaded from `.env`, before Redis setup. The existing Redis test trait flushes its selected test database before and after each case; miss/partial cases also clear it between operations. Never point this harness at an application cache:

```shell
docker run -d --rm --name ai-cache-benchmark -p 127.0.0.1::6379 redis:8 redis-server --save '' --appendonly no
docker port ai-cache-benchmark 6379

REDIS_HOST=127.0.0.1 REDIS_DB=0 BENCH_REDIS_PORT=PORT \
  BENCH_SMOKE=1 BENCH_OUT=/tmp/ai-cache-smoke.jsonl \
  php -d opcache.enable_cli=1 -d opcache.jit=disable vendor/bin/phpunit --no-progress \
  tests/Benchmarks/Ai/Fixtures/EmbeddingCacheBenchmark.php

docker stop ai-cache-benchmark
```

Use the printed port. LZ4 support must be enabled in phpredis. For full runs, replace `BENCH_SMOKE=1` with `BENCH_IDLE_CONFIRMED=1` and select a fresh report path; alternate normal and reverse case order across processes. Each case records five batches. Single-vector cases use 500 operations per batch; 100-vector cases use 20.

Cases cover individual single-vector hits/misses, individual 100-vector hits/misses/50% hits, and shared 100-vector hits/misses. Priming, clearing and correctness assertions are outside each timed operation. Wall time includes the SDK/cache path and Redis I/O, but no model generation latency. CPU covers only the client process, including JSON processing and LZ4 compression/decompression; Redis server CPU is excluded. Peak heap describes the fixture process, including its precomputed vectors and checks. Stored bytes are Redis value lengths, including serialization/compression framing but excluding keys and server allocation overhead.

The report also records Redis command counts for one operation after warmup. `SETEX` counts include commands run inside Lua: an individual cache miss uses one `MGET` and one `EVALSHA` request, with the script performing the individual writes. Do not report those script-internal writes as client round trips. These measurements cover standalone Redis, not Cluster routing or cross-region latency.

## Conversation storage

`Fixtures/ConversationBenchmark.php` uses the production conversation migration and store against a disposable PostgreSQL or MySQL database. It creates and drops the conversation tables and deletes fixture rows between cases. The database must be named `ai_benchmark`, with password `benchmark` and the default `postgres`/`root` user. It always connects to loopback at the explicitly supplied port; it does not inherit application database connections.

```shell
docker run -d --rm --name ai-storage-benchmark -p 127.0.0.1::5432 \
  -e POSTGRES_DB=ai_benchmark -e POSTGRES_PASSWORD=benchmark postgres:17
docker port ai-storage-benchmark 5432

BENCH_SMOKE=1 BENCH_DB_DRIVER=pgsql BENCH_DB_PORT=PORT \
  BENCH_OUT=/tmp/ai-storage-smoke.jsonl \
  php -d opcache.enable_cli=1 -d opcache.jit=disable vendor/bin/phpunit --no-progress \
  tests/Benchmarks/Ai/Fixtures/ConversationBenchmark.php

docker stop ai-storage-benchmark
```

Wait until the database is ready before running. For MySQL use a disposable `mysql:9` container with `MYSQL_DATABASE=ai_benchmark`, `MYSQL_ROOT_PASSWORD=benchmark`, port 3306, and `BENCH_DB_DRIVER=mysql`. Keep normal durability settings and record the server version and storage environment with results. For full runs use `BENCH_IDLE_CONFIRMED=1` instead of smoke mode, selecting a fresh report path each time. Alternate normal and reverse case order across processes.

The cases cover:

- Atomic turns versus separate calls to the current public user/assistant write methods, at concurrency 1 and 16 with the default ten-connection pool. Concurrent calls use distinct conversations or contend on one conversation. Atomic turns perform one activity update instead of two, and one commit instead of three (two autocommit writes plus the assistant transaction). Commit counts do not imply a fixed number of physical log flushes, since the database can group commits. The separate-method path is not an exact Laravel baseline.
- Claiming a pause and durably recording 1, 5 or 20 tool results, with a larger-result case to expose JSON rewrite costs. Each outcome retains its own transaction; fixture preparation is outside timing.
- Loading the default 100-message window from 20- or 200-turn histories with small or 8 KiB tool results. Each assistant row expands into an assistant message and a tool-result message, giving 60 or 150 hydrated messages respectively. The returned history is consumed and released per operation, rather than retained across the batch.
- Completing a turn with 20 existing paused messages, of which 0, 5 or 20 contain eligible 4 KiB replay blocks. The fixture checks that cleanup updates only eligible rows.

Every case has an unreported warmup batch followed by five measured batches. Turn batches contain 160 operations, approval/cleanup batches eight, and history batches 20. Migrations, seeding, clearing and correctness assertions are excluded. History and pause seeds are grouped in setup transactions committed before measurement. There is no enclosing test transaction: measured commits and real index/foreign-key maintenance occur normally. Schema costs are included in complete operations, not measured independently.

Each operation has its own coroutine and database ownership. Wall time and client CPU include scheduling, statement counters, pool instrumentation and deferred cleanup; database server CPU is excluded. Callback latency begins when a coroutine enters its operation, so it excludes admission to the concurrency limit. Pool checkout includes queueing and connection creation; hold time ends before a physical slot is returned. Reports include observed peak active operations, throughput, p50/p95 callback/checkout/hold times, framework query/transaction event counts, autocommit write counts, and peak heap. For batches with fewer than 20 observations, the tail value is labeled `max`, since the nearest-rank p95 is the maximum. Statement counts exclude driver-internal connection setup/reset commands. Checks require all borrowed sessions and waiters to be cleared after each batch. These local measurements describe the fixture and server configuration, not production throughput or old-conversation query-planner behavior.

## Scout vector imports

`Fixtures/ScoutVectorBenchmark.php` compares complete database imports against a disposable PostgreSQL 17+ database with pgvector. Use the same connection setup as the conversation harness, with an image such as `pgvector/pgvector:0.8.7-pg17`. It recreates `benchmark_scout_vectors` and requires a user permitted to create the extension and issue `CHECKPOINT`. The host's Docker data directory must have at least 2 GiB free; the fixture checks before and after setup and after each sample. Measure the smoke footprint before committing disk space to full runs.

```shell
BENCH_SMOKE=1 BENCH_DB_PORT=PORT BENCH_SCOUT_VARIANT=current \
  BENCH_OUT=/tmp/ai-scout-smoke.jsonl \
  php -d opcache.enable_cli=1 -d opcache.jit=disable vendor/bin/phpunit --no-progress \
  --default-time-limit=600 tests/Benchmarks/Ai/Fixtures/ScoutVectorBenchmark.php
```

Use `BENCH_IDLE_CONFIRMED=1` for full measurements. First add `BENCH_PROBE=1` to run one full-size sample per input mode without warmup, checking capacity and disk footprint before repeated measurements. Probe output is explicitly labeled. The variant label selects correctness expectations; it does not modify the engine. `current` expects per-row autocommit. `batch-transaction` requires a separately reviewed experimental checkout that commits each multi-model batch together, after generation, without adding a transaction inside an existing one. Copy the identical fixture to both checkouts, verify dependency versions and relevant source differences, and alternate run order. Preserve failed runs and their settings rather than increasing timeouts to hide capacity limits. Stop on a pool timeout and investigate import capacity before continuing comparisons.

Each input mode has a warmup followed by three measured samples. Full samples import 5,000 target rows with the shipped chunk size of 500 and database pool maximum of ten. Command concurrency inherits Scout configuration (shipped default 50); its effective value is reported. `BENCH_SCOUT_CONCURRENCY` optionally sets a positive limit for a controlled comparison. For example, nine jobs leave one of the ten pool slots for the parent reading chunks. Use the same value in both checkouts and report any default-settings failure separately; a passing comparison at a lower concurrency does not resolve that failure. There are 1,000 indexed background rows, and targets start with NULL vectors in both modes. Smoke uses 1,000 targets, 100 background rows and one measured sample. The fixture observes actual simultaneous import jobs. Generated inputs use the real SDK and an empty array cache, with a local gateway returning deterministic 1,536-dimensional vectors after a 20 ms simulated service wait. The gateway releases idle database leases before waiting, matching the real HTTP boundary. Precomputed models return the same fixture vectors from memory and make no generation call; the map is cleared on teardown. Both modes therefore insert new vectors into the same starting index. Neither case calls a paid provider.

Concurrent writers use a separate named pool to the same database. They increment a non-vector column on randomly selected target rows, with arrivals scheduled every 20 ms from measurement start (50 writes/second). Each write runs independently, up to the writer pool's capacity (ten by default). A blocked write does not block unrelated writes unless all slots are occupied. Admission can wait, but scheduled arrival times never move; all writes scheduled before import completion are admitted and drained, including any backlog. `writer_ms` measures scheduled arrival to completion, including queueing and checkout; `writer_service_ms` measures actual execution start to completion. Admission (including coroutine startup) and checkout are reported separately. Reports include p50/p95/p99/max, peak active writers, completions during import, and time spent draining afterward. Assertions verify that every scheduled write ran. This exposes both row-lock delays and saturation without serializing independent requests. The chosen rate is below the baseline rate observed in the initial probes; it is a controlled workload, not a claim about application traffic.

Each writer samples the import pool's borrowed and waiting counts when it starts; the reported peaks are sampled observations, not guaranteed maxima between observations. The import parent also retains a connection while its child jobs run, so ten full-size jobs can exceed the pool's ten slots. Report writer operation count and rate alongside import duration: a slower import receives more scheduled writes. CPU and overall wall time cover the combined import-and-writer client process, including backlog drain, not isolated import CPU; database CPU is excluded. WAL measurements also include the drained writer work. Inclusive UPDATE durations contain network, locks, heap/TOAST and index work; autocommit also includes its commit. Summed durations across concurrent jobs can exceed elapsed time. The candidate still performs one UPDATE round trip per model, so these loopback results do not predict performance against a remote database.

Every sample recreates and seeds the table, builds its HNSW index, analyzes it and runs `CHECKPOINT` before timing. Autovacuum is disabled only on this disposable table and its TOAST relation. Reports record table/index sizes, WAL positions and completed checkpoint counters before and after each sample. This limits setup drift and exposes checkpoints that still occur during measurement. The index has at most 6,000 live vectors, much smaller than many production indexes; larger-index work may reduce the relative benefit of fewer commits. Seeding, index construction, statistics reads and result checks are outside timing. A separate correctness case checks rollback on a bad vector and verifies that single-model updates and existing transactions gain no transaction/savepoint overhead. Pool borrowers and waiters must be zero after each measured run.
