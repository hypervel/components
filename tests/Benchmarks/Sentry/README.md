# Sentry benchmark

This developer harness measures Sentry's HTTP overhead, telemetry delivery, Guzzle instrumentation, request-data collection and child-coroutine propagation. It compares independently installed baseline and modified checkouts. It is not part of the normal test suite, a CI performance gate or a prediction of production throughput.

See [recorded measurements](results.md) for the Sentry reconciliation and optimization results.

Confirm the machine is idle before running measurements. Smoke mode uses small workloads to check correctness; its timings are not performance evidence. The harness requires Linux `/proc`, PHP with Swoole, SQLite, cURL, PCNTL, POSIX and OPcache, Composer, Git and Bash. CPU pinning additionally requires `taskset`.

## Prepare two applications

Use a baseline worktree containing the same framework changes except those being measured. Install each checkout's development dependencies independently for the component benchmarks. Keep unchanged dependencies at the same versions on both sides, including the Sentry SDK and Symfony; record any dependency replacements being measured. Optimize both autoloaders with `composer dump-autoload -o`.

The HTTP workloads use disposable copies of the application skeleton with only production dependencies. This avoids measuring unrelated providers discovered in a full components checkout. Use the same skeleton revision and framework metapackage checkout for both applications:

```sh
bash tests/Benchmarks/Sentry/setup-app.sh \
  /tmp/sentry-benchmark/app-baseline /absolute/path/to/baseline \
  /absolute/path/to/framework /absolute/path/to/hypervel-skeleton

bash tests/Benchmarks/Sentry/setup-app.sh \
  /tmp/sentry-benchmark/app-current /absolute/path/to/current \
  /absolute/path/to/framework /absolute/path/to/hypervel-skeleton
```

The setup script archives the skeleton's committed `HEAD`, copies the fixture app, configures per-package Composer path repositories, seeds a disposable SQLite database and records the source locations. It refuses to overwrite an existing directory. Only source packages are symlinked; vendor directories, autoloaders, caches and databases remain independent. The configured development version is `0.4.x-dev`; update it if comparing another framework line.

Run `composer update --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader` inside each prepared application. Keep Composer's normal scripts enabled. Compare their installed third-party versions before running, pinning the same compatible versions through Composer when necessary. Do not copy or share vendor directories. Preparation may download dependencies; measurements use only loopback traffic and never a real Sentry DSN.

If either source checkout changes, refresh its app's path-package installation with Composer, rebuild its optimized autoloader, and rerun smoke checks. Source paths alone do not refresh package discovery metadata. Configuration, route and view caches are rebuilt for each measured configuration.

## Smoke checks

```sh
php tests/Benchmarks/Sentry/benchmark.php matrix \
  --workspace=/tmp/sentry-benchmark --smoke

bash tests/Benchmarks/Sentry/components.sh \
  /absolute/path/to/baseline /absolute/path/to/current \
  /tmp/sentry-benchmark/components-smoke smoke

bash tests/Benchmarks/Sentry/components.sh \
  /absolute/path/to/baseline /absolute/path/to/current \
  /tmp/sentry-benchmark/inventory inventory
```

HTTP smoke checks validate response bodies, delivered telemetry, runtime settings and cleanup. The component cases assert finished spans, breadcrumbs, origin behavior and scope propagation. The separate inventory records listeners, HTTP middleware, Guzzle trace-header propagation, decorators and coroutine hooks for each configuration and individual toggle; compare those records when assessing disabled instrumentation. It also asserts that disabled Sentry installs no listeners, HTTP middleware, propagation hook or Blade decorator, and that outgoing Guzzle requests carry no trace header.

The explicit PHPUnit fixtures intentionally do not end in `Test.php`, so ordinary test discovery does not run measurements. The component runner selects the target checkout's PHPUnit, autoloader and configuration. Its small test-base alias supports comparisons with the pre-reconciliation Sentry package; it does not change production classes.

## HTTP measurements

```sh
php tests/Benchmarks/Sentry/benchmark.php matrix \
  --workspace=/tmp/sentry-benchmark --repetitions=3 --idle-confirmed
```

The runner owns the application servers and telemetry sink, checks ports before starting, and stops its processes after completion or failure. Defaults are application port 18000, statistics port 18001 and sink port 18080. Change them with `--app-port`, `--stats-port` and `--sink-port` when needed; it never adopts an existing sink. Leave generated apps and reports in scratch storage for inspection, then remove them and any temporary worktrees when finished.

CPU affinity is optional. On a machine with those CPUs available, add `--server-cpus=1,2 --sink-cpus=3 --loadgen-cpus=4,5`. The server mask covers its process tree; the load-generator list selects one process per CPU, limited by concurrency. Without affinity, two unpinned load-generator processes are used for concurrent workloads. Use identical settings on both sides. Reports record affinity, revisions, dirty tracked files, installed packages and runtime versions.

| Configuration | Sentry setup |
| --- | --- |
| C0 | Package excluded from discovery |
| C1 | Installed, no DSN |
| C2 | Error reporting enabled, tracing unset |
| C3 | Tracing enabled with sample rate zero |
| C4 | Sample rate one, shipped instrumentation defaults |
| C5 | Sample rate one, optional breadcrumb and tracing instrumentation disabled |

C5 is not zero instrumentation: request transactions, runtime boundaries and trace propagation still have work to do. The structural inventory explains which registrations remain. Request/transaction profiling stays at its shipped disabled default; this harness does not enable Excimer profiling or resolve its coroutine-attribution limitation.

Each configuration uses a fresh single-worker server. `/plain` returns a string. `/work` runs three SQLite queries, array-cache reads and writes, one log event, two child coroutines and a small Blade view. Logging uses the `null` channel, checked in the cached config, to exercise breadcrumbs without file I/O. The statistics endpoint bypasses the HTTP kernel so measuring resources does not create Sentry events.

The runner sets `opcache.file_update_protection=0` because it starts workers immediately after building their immutable caches. Otherwise, OPcache can leave freshly compiled views uncached for the worker's lifetime, measuring repeated PHP compilation instead of the intended cached path.

| Workload | Concurrency | Warmup requests | Measured requests |
| --- | ---: | ---: | ---: |
| `/plain` | 32 | 2,000 | 20,000 |
| `/work` | 32 | 1,000 | 10,000 |
| `/work` | 1 | 300 | 3,000 |

The sink must receive no envelopes for these successful C0–C3 requests and exactly one transaction per request for C4/C5. Mismatches fail the run. For a focused rerun, use `launch current C4` instead of `matrix`.

The saturation case uses C4, a 200 ms sink delay, concurrency 64 and two 5,000-request waves on the same worker. Each request reports one handled exception as well as its transaction. It retains the shipped transport-pool settings and records received versus not-received items; the sink cannot distinguish a skipped send from a failed delivery. Resources are recorded after each wave drains, exposing accumulation without inventing memory thresholds. After the final resource snapshot, the runner removes the delay and sends one request at concurrency one, requiring exactly one error and one transaction to prove delivery still works. This recovery check is recorded separately from the measured windows. Run it separately with `saturation current`.

Reports include throughput, p50/p95/p99 latency, server-process-tree CPU, worker RSS, PHP heap, coroutine counts, pool state and delivered telemetry. A final snapshot after explicit garbage collection separates live retention from collectible cycles; collection happens after all measured workloads or saturation waves, never inside their measurement windows. CPU excludes the separate sink and load generators. CPU windows both before and after draining are recorded: HTTP responses can finish before Sentry's deferred work. Concurrency-one response latency therefore does not represent total instrumentation cost. RSS peaks are sampled, not exact allocation peaks. SQLite and loopback traffic isolate framework overhead; they do not represent remote databases or Sentry network latency.

## Component measurements

```sh
bash tests/Benchmarks/Sentry/components.sh \
  /absolute/path/to/baseline /absolute/path/to/current \
  /tmp/sentry-benchmark/components idle-confirmed
```

An optional fifth argument pins the process to a CPU list. The cases cover sampled and unsampled Guzzle requests, breadcrumb-only requests, header propagation with recording off, explicit opt-out, inactive Sentry, origin collection, outstanding async-request memory, and child creation with an inherited scope. Origin-only cases are explicitly skipped on revisions without that option. MockHandler isolates instrumentation from network I/O; its memory rows measure pending promise callbacks, not native transport buffers. Finished-span assertions keep workloads below the SDK's recorder limit and ensure the benchmark is still recording real work.

`request-fetcher.php` separately compares request fetching and processing transaction request data for a GET and a small JSON POST, with legacy and `data_collection` settings. Its `processor_us` measurement includes creating a fresh transaction event for each invocation. These are component costs, not whole-request latency. It can also be run directly with `--root=/checkout --smoke` or `--idle-confirmed`.

## Compare results

Start with three alternating baseline/current process pairs. The HTTP runner reverses order between configurations and repetitions; the component runner reverses order between repetitions. Use medians and spread, absolute differences and percentages. Add repetitions only to resolve a specific uncertainty. Keep runtime, dependencies, payloads, OPcache, JIT, GC and CPU assignments consistent. Check delivered work and cleanup before interpreting a faster result as an improvement.

Separate startup retention, warm runtime cost, pending-request memory and post-drain retention. Report the exact measured revisions and settings. Do not interpret smoke runs, busy-machine runs or small differences within observed variation as performance results. Keep raw JSON, logs, generated applications and dependency installations outside the repository. A concise `results.md` may record verified comparisons once measurements are complete; timing values never become CI assertions.
