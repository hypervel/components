# Pooled connection measurements

Measured on October 15, 2026. The framework changes reuse the existing idle-session release mechanism and preserve active queries, transactions and explicit pins. They prevent idle database sessions from limiting otherwise independent work and improve HTTP reuse across concurrent bursts. See [PR #669](https://github.com/hypervel/components/pull/669) for the changes and [README.md](README.md) for reproduction instructions.

## Measurement setup

The tables below show before/after medians from ten alternating fresh-process pairs, with warmup and two measured samples per process for pooled-wait cases. The Pusher connection-retention comparison uses five samples per process. Both sides use the same installed dependencies, PHP 8.4.25 and Swoole 6.3.0RC1, with CLI OPcache enabled and JIT disabled. Historical source snapshots isolate the changed classes without changing the checkout between runs. The base is `c5303a340`; the final measurements used `41d8bff2a`. Earlier groups were measured when their respective changes were implemented, using the same method. The reusable harness is committed in `a4112241a`; earlier groups used temporary versions of the corresponding cases.

Most pool-pressure cases use eight concurrent operations and two database slots. Lock/retry cases use sixteen and four; mail uses thirty-two and four. Each operation queries before its external wait. Separate comparisons give every caller a database slot and query again afterward to expose the cost of borrowing another session. Databases and services are local; no paid provider accounts are used.

CPU means CPU in the calling PHP process, excluding external servers and child processes. Database hold time is the total time a physical session remains borrowed per operation. These are controlled comparisons, not production throughput promises. Ranges and paired comparisons were checked as well as the medians shown here.

The host was Linux x86-64 on an Intel Core i7-7700 at 3.60 GHz with six logical CPUs exposed. The final Scout/broadcaster comparisons used sixteen operations per sample. Mail used 128 messages, ordinary lock/retry cases used 64 operations, and rate-limit cases used eight. Earlier mail/backoff/process/external-transport figures precede extraction into the reusable harness; they were not rerun merely for that move.

The separate [HTTP transport results](../HttpTransport/results.md) cover receive-buffer copying, without AI response handling.

## HTTP connection reuse

The HTTP client's bounded synchronous cURL handler is extracted into a shared factory and used by framework-created Pusher, Scout and Slack clients. It retains up to 256 idle handles. Guzzle still selects the transport, and asynchronous, streamed and fallback requests retain their existing paths. Application-supplied clients remain application-owned.

The Pusher comparison uses sixteen simultaneous streams producing 576 individual events, with batching disabled on both sides:

| Pusher receiver delay | Calling-process CPU, before | After | New warm-run HTTPS connections, before | After |
| --- | ---: | ---: | ---: | ---: |
| 5 ms | 379.19 ms | 311.82 ms | 44 | 0 |
| 50 ms | 350.42 ms | 325.26 ms | 13 | 0 |

This removes repeated connection setup. It doesn't make the receiver faster: elapsed time in the 50 ms case was 1958.65 → 1989.36 ms, with overlapping ranges. This comparison enables only retaining HTTP handles, not broadcast batching.

## Broadcast database sessions

Pusher, Ably and Mercure release idle database sessions before their transport waits. Each driver does this at its owning send boundary; Redis broadcasting isn't given an extra release step.

These cases exercise actual framework dispatch with a controlled 20 ms transport wait. They isolate database occupancy, rather than measuring provider throughput or HTTP reuse.

| Dispatch under database pool pressure | Operations/s, before | After | Database hold per operation, before | After |
| --- | ---: | ---: | ---: | ---: |
| Pusher | 95.83 | 365.01 | 20.79 ms | 0.33 ms |
| Ably | 95.54 | 362.52 | 20.79 ms | 0.38 ms |
| Mercure | 95.21 | 357.71 | 20.92 ms | 0.39 ms |

With spare capacity and a later query, throughput stays within 1% in paired comparisons. CPU per operation changes from 307 → 335 µs for Pusher, 312 → 360 µs for Ably, and 374 → 386 µs for Mercure. Database hold per operation falls from 21.35 → 1.13 ms, 21.51 → 1.16 ms, and 21.74 → 1.25 ms respectively, with one extra checkout.

## Scout clients and import waits

Scout's default Algolia, Meilisearch and Typesense clients use the shared retaining handler and idle-session release middleware. A custom Typesense client is left alone. The import runner returns each child's idle session before making its concurrency slot available, and returns the parent's idle session before joining the remaining children. Existing failure and cancellation ownership is preserved.

The transport measurements use the actual Guzzle clients configured by Scout against a local HTTPS origin with a 20 ms response delay. They don't include provider serialization or full indexing. The import measurement isolates the parent joining two 20 ms tasks.

| Pool-pressure case | Operations/s, before | After | Database hold per operation, before | After |
| --- | ---: | ---: | ---: | ---: |
| Algolia transport | 87.17 | 323.20 | 22.83 ms | 0.61 ms |
| Meilisearch transport | 87.42 | 323.01 | 22.79 ms | 0.60 ms |
| Typesense transport | 87.03 | 324.93 | 22.89 ms | 0.61 ms |
| Import parent join | 95.93 | 360.88 | 20.76 ms | 0.37 ms |

Giving every caller a database slot exposes the HTTP reuse benefit:

| Transport with spare capacity and a later query | CPU per operation, before | After | New HTTPS connections per 16 operations, before | After |
| --- | ---: | ---: | ---: | ---: |
| Algolia | 1488 µs | 652 µs | 10 | 0 |
| Meilisearch | 1418 µs | 686 µs | 10 | 0 |
| Typesense | 1460 µs | 655 µs | 10 | 0 |

The parent-join comparison has no HTTP reuse benefit. With spare capacity its CPU changes from 364 → 402 µs per operation, while throughput remains effectively unchanged and database hold falls from 21.75 → 1.32 ms.

## Mail delivery

`Mailer` releases idle sessions after message preparation and `MessageSending` listeners, immediately before transport creation/send. This covers pooled mailers, direct mailers and custom transports without separate provider wrappers. SMTP connections already reuse correctly; this PR doesn't add another retention mechanism to mail.

These measurements use real SMTP delivery to a local receiver, with controlled acknowledgement delays:

| Pool-pressure case | Messages/s, before | After | Database hold per message, before | After |
| --- | ---: | ---: | ---: | ---: |
| SQLite, 5 ms acknowledgement | 580.31 | 1345.38 | 6.75 ms | 2.03 ms |
| SQLite, 50 ms acknowledgement | 76.05 | 284.64 | 52.48 ms | 1.53 ms |
| PostgreSQL, 5 ms acknowledgement | 540.19 | 1206.18 | 7.25 ms | 2.45 ms |

With spare PostgreSQL capacity and another query after sending, throughput was 1315.71 → 1297.25 messages/s and CPU was 706 → 715 µs/message. Database hold fell from 20.98 → 8.23 ms. Transactions retain their sessions; their measured hold time remains unchanged.

## Locks, rate limits and retry delays

Idle sessions are released immediately before positive waits in cache locks/funnels, Redis funnels/throttles, Saloon delays and the shared `retry()` helper. An operation that doesn't wait doesn't release. Retry callbacks still run in their existing order, so sessions borrowed by a delay callback can be returned before sleeping.

| Wait under database pool pressure | Operations/s, before | After | Database hold per operation, before | After |
| --- | ---: | ---: | ---: | ---: |
| Cache lock | 170.2 | 571.8 | 22.81 ms | 0.96 ms |
| Cache funnel | 169.9 | 574.7 | 23.03 ms | 1.00 ms |
| Redis funnel | 165.1 | 562.9 | 23.73 ms | 1.27 ms |
| Redis throttle | 166.3 | 567.9 | 23.69 ms | 1.19 ms |
| Saloon request delay | 178.2 | 582.0 | 22.21 ms | 0.85 ms |
| Saloon retry delay | 172.8 | 524.1 | 22.84 ms | 1.91 ms |
| Saloon rate limit | 2.00 | 7.96 | 1002.43 ms | 0.60 ms |
| Database-backed lock | 1.00 | 3.97 | 1994.16 ms | 80.79 ms |
| HTTP retry delay | 165.8 | 535.8 | 24.04 ms | 2.92 ms |

Cache/Redis contention and ordinary delays use 20 ms waits. Saloon rate limits allow one request per second. The database lock uses a one-second TTL whose rounded expiry can produce waits approaching two seconds. Saloon and HTTP retry use HTTP fakes to isolate their wait logic.

The database lock has the largest measured reacquisition cost: with spare capacity, CPU rises from 18.22 → 19.05 ms/operation and borrows rise from one to about eighteen because each retry queries the database. It performs the same SQL and reduces database hold from 1885.68 → 206.62 ms. HTTP retry adds about 58 µs per operation in its spare-capacity case. These changes improve shared capacity, rather than making every uncontended operation faster.

## Child tasks and process waits

The coroutine concurrency driver releases idle sessions before starting its tasks. Saloon releases before admitting requests and before its final join, including when a request generator fails. Process run/wait/waitUntil and positive graceful-stop waits release at entry. Already-finished processes and zero-grace stops don't release unnecessarily.

| Pool-pressure case | Operations/s, before | After | Database hold per operation, before | After |
| --- | ---: | ---: | ---: | ---: |
| Two concurrent tasks | 96.22 | 363.95 | 20.68 ms | 0.32 ms |
| Saloon fixed requests | 46.47 | 167.25 | 42.88 ms | 1.10 ms |
| Saloon querying generator | 46.99 | 175.55 | 42.43 ms | 2.86 ms |
| Process run | 26.86 | 58.29 | 73.95 ms | 4.88 ms |
| Process start/wait | 26.93 | 57.67 | 73.77 ms | 6.34 ms |
| Process start through graceful stop | 62.25 | 153.99 | 27.23 ms | 0.45 ms |

Tasks and Saloon requests use controlled 20 ms waits; process cases launch real local processes. Under pool pressure, process run/wait uses more calling-process CPU as more processes run concurrently. With equal capacity, CPU is effectively unchanged; process-run throughput is about 3% lower in paired comparisons. The querying Saloon generator adds about 94 µs per operation with spare capacity because its intermediate queries require fresh borrows.

The recorded stop case resolved its database connection before starting the child, which already borrowed a session even though its first query followed readiness. These figures therefore include startup and shutdown. The retained harness now resolves after readiness, except when transaction mode deliberately establishes ownership first. That correction was smoke-tested, not remeasured; the table does not claim isolated shutdown timings.

## Pooled filesystem operations

Both pooled filesystem wrappers release at shared dispatch boundaries, including stream acquisition and raw-access callbacks. Local path formatting and temporary-URL capability checks are excluded. URL generation remains covered because credentials and custom URL providers can perform remote work.

| Pool-pressure case, local HTTPS with 20 ms delay | Operations/s, before | After | Database hold per operation, before | After |
| --- | ---: | ---: | ---: | ---: |
| S3 SDK read | 87.98 | 278.20 | 22.51 ms | 1.64 ms |
| Custom whole-driver pool | 87.25 | 323.99 | 22.82 ms | 0.60 ms |

The custom-driver case exercises the shared wrapper; it isn't an FTP/SFTP benchmark. With spare capacity and another query, CPU changes from 1498 → 1516 µs for S3 and 646 → 668 µs for the custom driver, with effectively unchanged throughput.

## Socialite and Slack

Socialite adds release middleware to its default Guzzle stack, preserving its transport options and coroutine-owned client lifetime. Custom handlers and supplied clients remain untouched. The default Slack webhook client also gains bounded HTTP connection reuse.

| Pool-pressure case, local HTTPS with 20 ms delay | Operations/s, before | After | Database hold per operation, before | After |
| --- | ---: | ---: | ---: | ---: |
| Socialite | 77.28 | 236.93 | 25.67 ms | 2.09 ms |
| Slack webhook | 86.87 | 318.55 | 22.87 ms | 0.71 ms |

With spare capacity, Slack opens zero new warm-run connections instead of ten per sixteen messages; CPU falls from 1520 → 699 µs/message. Socialite's connection count remains sixteen on both sides, matching its unchanged ownership model. Its paired spare-capacity CPU change is +0.9%; it doesn't gain cross-coroutine HTTP reuse.

## Queue admission and SQS

Pooled queue dispatch releases idle database sessions before borrowing a backend connection. Framework-created SQS clients also release before SDK commands, covering the client used by jobs. Job connection ownership and terminal operations are unchanged.

| Real SQS SDK publication to local HTTPS with 20 ms delay | Operations/s, before | After | Database hold per operation, before | After |
| --- | ---: | ---: | ---: | ---: |
| Database pool pressure | 89.42 | 294.74 | 22.17 ms | 1.36 ms |
| Spare capacity, then another query | 301.96 | 302.81 | 24.38 ms | 3.18 ms |

This measures publication through the queue proxy, not worker job throughput. Spare-capacity CPU is 1210 → 1211 µs per publication.

## gRPC calls and retries

Native gRPC releases before initial and retry transport startup, and before positive retry delays. It doesn't add a release call to every streamed message.

| Local native HTTP/2 call under database pool pressure | Operations/s, before | After | Database hold per operation, before | After |
| --- | ---: | ---: | ---: | ---: |
| Unary call | 90.73 | 329.04 | 21.97 ms | 0.41 ms |
| Retry after an intervening database query | 91.86 | 306.57 | 21.17 ms | 0.74 ms |

With spare capacity and another query, CPU changes from 483 → 484 µs for unary calls and 708 → 714 µs for retries; throughput remains effectively unchanged.

The recorded retry case includes initial call creation with an already resolved database connection. The retained harness now defers that resolution until the intervening query. These numbers describe complete call/retry operations before that harness correction; they are not replacement measurements of the corrected case.

## Behavior, coverage and reproduction

Releasing an idle session doesn't add SQL. A later query borrows again, using the existing session configuration and sticky-read behavior. Transactions, active operations and explicit pins keep their sessions. Applications relying on raw session state should use the documented pinning APIs.

Release happens at owning operation boundaries, not every yield or stream chunk. A process output callback or stream consumer that queries the database can explicitly release before its next external wait. The database documentation lists automatic boundaries and custom-transport exceptions in one place.

Regression coverage checks ordinary, transactional and pinned execution, callback ordering, failure cleanup, custom clients and connection reuse. Single-slot database/process handshakes verify that another coroutine can make progress without relying on timing thresholds. Formatting, static analysis and affected package tests pass locally.

`tests/Benchmarks/PooledConnections` contains the reusable harness and local HTTPS/SMTP fixtures. Its README explains workloads, service setup, repeated comparisons and measurement limits. The existing `tests/Benchmarks/HttpTransport` harness covers the native stream buffer separately. Historical snapshots and raw measurement output aren't shipped as framework code.
