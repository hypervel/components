# MCP performance measurements

Measured on October 17, 2026, against `5eca0369e` and the annotation change described below. The idle Linux x86-64 host exposed six logical CPUs on an Intel Core i7-7700 at 3.60 GHz under KVM. PHP 8.4.25 and Swoole 6.3.0RC1 used CLI OPcache with JIT disabled. All services were local; no AI account was used.

These measurements support three decisions: retain concurrent HTTP exchanges, retain private-first response-cache reads, and simplify annotation resolution without adding another cache. They are not a Laravel comparison or a prediction of remote service latency.

## Concurrent HTTP calls

The [harness and instructions](README.md) compare four sequential and four concurrent tool calls through the same client implementation. Each call waits 25 ms in a local HTTP fixture. Three fresh processes each ran five alternating samples per mode, with 25 four-call groups per sample after warmup. The table shows medians across the 15 samples per mode; percentile rows are medians of sample percentiles, not pooled percentiles.

| Metric | Sequential | Concurrent |
| --- | ---: | ---: |
| Four-call group p50 | 106.44 ms | 27.95 ms |
| Four-call group p95 | 107.20 ms | 28.50 ms |
| Completed calls/second | 37.58 | 142.89 |
| Client CPU/call | 1,274.81 µs | 918.84 µs |
| Peak PHP heap above sample start | 79.48 KiB | 326.59 KiB |
| Observed simultaneous server calls | 1 | 4 |

Every response matched its call, and the fixture had no active calls after each sample. Concurrent groups completed about 74% sooner, at the cost of keeping four requests in flight. Heap includes measurement arrays and excludes native allocations; it is not a leak test. CPU excludes the server process. The plain HTTP fixture does not model TLS, authentication, internet latency or production server throughput.

The lower measured CPU is an observation, not a general concurrency guarantee. Follow-up runs using the same five-sample settings measured sequential/concurrent CPU at 828/749 µs per call with 5 ms waits and 1,255/916 µs with 100 ms waits. CPU did not keep increasing with the wait from 25 to 100 ms. Separate diagnostic traces with one sample and five groups recorded 113 `poll` calls at both 5 and 100 ms waits, and 111/115 `epoll_wait` calls respectively. These traces were excluded from performance timings and found no wait-proportional polling in that range.

## Response-cache lookup

Keep the upstream `get(private) ?? get(public)` lookup. A candidate using `many([private, public])` preserved returned values but also read and emitted cache hit/miss events for the public key when a private hit already served the request. That changes cache observability, including Telescope records. Private is the default cache scope.

The one-off comparison called the real `ResponseCache::remember()` with `ListTools`, an HTTP transport recipe and the normal Hypervel Redis repository. A disposable loopback Redis 8.10.1 instance used PHP cache serialization without PhpRedis serialization or compression. Each result contained one tool with a 1 KiB or 128 KiB description. Both implementations received the same keys and values, and all results were checked for equality. Misses returned a result with zero TTL to remain misses.

Two fresh processes each ran five alternating samples per case: 500 operations per sample for small values and 100 for large values. Values below are medians across the ten samples, in microseconds per operation.

| Description size / cache state | Private-first reads | Batched reads |
| --- | ---: | ---: |
| 1 KiB / private hit | 78.64 | 90.61 |
| 1 KiB / public hit | 148.74 | 90.34 |
| 1 KiB / both scopes populated | 80.13 | 95.96 |
| 1 KiB / miss | 145.71 | 89.34 |
| 128 KiB / private hit | 143.77 | 161.08 |
| 128 KiB / public hit | 193.47 | 142.35 |
| 128 KiB / both scopes populated | 129.92 | 171.08 |
| 128 KiB / miss | 152.00 | 89.17 |

Redis command counters confirmed one `GET` on private hits, two otherwise, versus one `MGET` for every batched lookup. Networked Redis can make the saved round trip on public hits and misses more valuable; these loopback timings do not establish a universal winner.

If both scopes remain populated after a scope change within the TTL, batching also fetches and deserializes an unused public value. In that uncommon large-value case, serialized cached payload returned per operation rises from 131,162 to 262,323 bytes. These are cached value bytes, excluding keys and protocol framing. Avoiding the extra reads and events preserves existing behavior without adding driver-specific logic or configuration.

## Annotation resolution

The previous collection chain constructed every class attribute before filtering for annotations. Besides unnecessary work, that failed when a tool carried an unrelated development-only attribute whose class was unavailable at runtime. Reflection now selects implementations of the annotation contract, and a direct loop validates and maps them. Annotation instances remain fresh, allowed annotation types are checked on every call, and no new worker-lifetime cache is introduced.

The one-off fixture used `Name`, `Title`, `Description`, `IsReadOnly` and `IsIdempotent` attributes, plus a real query/limit input schema. It measured repeated `annotations()` calls, which the AI server-tool adapter delegates to, and complete `Tool::toArray()` calls. Two fresh processes each ran seven alternating samples of 10,000 calls after warming existing attribute metadata. Outputs were checked for equality. The table reports medians across the 14 samples in microseconds per call.

| Operation | Collection chain | Filtered loop | Filtered loop with experimental reflection cache |
| --- | ---: | ---: | ---: |
| `annotations()` | 5.201 | 0.986 | 0.750 |
| `Tool::toArray()` | 28.303 | 22.582 | 22.209 |

The filtered loop reduces annotation time by about 81% and complete serialization time by about 20% in this fixture. Peak extra PHP heap for annotation calls falls from 2,424 to 1,072 bytes; the complete serialization peak remains 8,560 bytes. These are allocation peaks during repeated calls, not retained memory per tool.

The reflection-cache candidate still constructed fresh annotations. Its additional saving was only about 0.24 µs per annotation call and 0.37 µs per serialization, so it was not adopted. The loop fixes the unrelated-attribute failure and removes collection overhead without extra cache state or invalidation rules. Existing tool/resource coverage and a regression for an unavailable unrelated attribute check behavior; timing is not asserted in CI.
