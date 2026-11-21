# Redis batch results benchmark

The SQL baseline uses the production `DatabaseBatchRepository` from the recorded commit. The PHP prototype keeps the parent in SQL, reads a Redis snapshot when hydrating the batch, and uses Lua to record a success or escalate to the SQL writer. An escalation rechecks the total under the parent row lock, applies the result in Redis and projects a new finish time into SQL. Both variants execute `find()->recordSuccessfulJob()` and the real batch callback logic. The harness times bookkeeping only. It does not time queue dispatch, the SQL outbox relay, job execution, or cleanup.

## Results

Median aggregate jobs/s (observed minimum–maximum), five rotated repetitions. Columns show SQL, Redis AOF `appendfsync always`, and Redis AOF `appendfsync everysec`. The latter has a weaker crash-durability guarantee. All SQL engines flush at commit. MySQL enables synchronous binary logging; MariaDB has the binary log disabled. The raw records include versions, settings, CPU count, elapsed times, SQL statement and transaction counts, successful Redis script calls and escalation counts.

| SQL engine | Workload | sql | always | everysec |
|---|---|---:|---:|---:|
| pgsql | clean | 293 (280–298) | 1,161 (789–1,235) | 2,585 (2,334–2,643) |
| pgsql | heavy | 291 (275–301) | 1,509 (1,331–1,594) | 3,455 (2,945–3,640) |
| pgsql | independent | 1,745 (1,566–1,852) | 1,448 (1,329–1,519) | 2,802 (2,600–3,269) |
| pgsql | progress | 298 (284–308) | 1,081 (1,033–1,112) | 2,231 (2,019–2,423) |
| pgsql | singleton | 1,745 (1,140–1,944) | 851 (833–862) | 1,290 (1,194–1,396) |
| pgsql | single_worker | 234 (222–268) | 136 (22–143) | 1,217 (1,182–1,241) |
| mysql | clean | 90 (86–111) | 1,341 (1,218–1,588) | 3,325 (2,831–3,698) |
| mysql | heavy | 83 (20–106) | 1,447 (1,432–1,671) | 3,678 (3,566–3,977) |
| mysql | independent | 791 (764–1,113) | 1,335 (1,154–1,731) | 2,976 (2,865–3,276) |
| mysql | progress | 90 (89–113) | 1,043 (1,015–1,177) | 2,177 (2,045–2,408) |
| mysql | singleton | 846 (543–1,199) | 593 (370–729) | 994 (825–1,089) |
| mysql | single_worker | 80 (39–99) | 134 (101–156) | 1,269 (1,250–1,319) |
| mariadb | clean | 965 (897–992) | 1,368 (948–1,762) | 3,465 (3,244–3,865) |
| mariadb | heavy | 955 (925–1,009) | 1,676 (968–1,818) | 4,072 (3,466–4,167) |
| mariadb | independent | 1,513 (1,444–1,664) | 1,490 (1,389–1,717) | 2,986 (2,895–3,554) |
| mariadb | progress | 881 (841–913) | 1,262 (1,064–1,350) | 2,481 (2,327–2,594) |
| mariadb | singleton | 1,406 (1,312–1,534) | 823 (797–900) | 1,434 (1,279–1,591) |
| mariadb | single_worker | 202 (191–219) | 146 (138–171) | 1,345 (1,270–1,363) |

All cases process 1,000 successful jobs. **clean** uses one batch and 50 workers. **heavy** starts with 5,000 distinct outstanding failures in that batch. **progress** adds a real callback reading fresh progress to the heavy workload. **independent** gives each worker a 20-job batch. **singleton** gives every job a separate batch. **single_worker** uses one worker and one batch. Workers fork, connect and warm their connections before the timer. Each sample starts from a fresh schema and verifies final counts, success and failure cardinalities, finish projection and callback counts. The SQL and Redis variants use the same SQL parent schema with representative UUID keys (native PostgreSQL UUID or CHAR(36)); shipped migrations currently use string keys. Redis uses local phpredis and cached scripts. This is a prototype comparison, not an end-to-end production performance guarantee.

With synchronous flushing, Redis won the shared-batch workload on all three engines and lost the one-job workload on all three. On a shared batch, only the finish projection wrote SQL in the Redis variant. Initialization can send several concurrent workers to the SQL lock before the first one installs the cached total. A one-job batch always has a terminal escalation. The ranges include all runs, including observed slow runs.

## PostgreSQL controls

Paired eager and lazy hydration on a new isolated PostgreSQL 17/Redis instance, AOF `always`. In the lazy variant the first access to a progress property loads one snapshot for the object. The no-progress worker path skips a Redis round trip. Each of these three workloads ran five times per variant. Separate smoke cases exercise the property access and the callback path.

| SQL engine | Workload | always | lazy |
|---|---|---:|---:|
| pgsql | clean | 1,032 (900–1,238) | 1,498 (1,361–2,217) |
| pgsql | independent | 1,324 (1,156–1,447) | 1,995 (1,772–2,165) |
| pgsql | singleton | 822 (748–851) | 863 (686–896) |

Loading counters on first access improved shared throughput by 45% and independent throughput by 51% in the paired control. The one-job medians differed by 5% with overlapping ranges. A deferred snapshot in a user-retained `Batch` can observe later changes; these throughput results do not establish API equivalence.

The isolated enrollment control measured one transaction adding 20 jobs, excluding delivery. Across five alternating 100-add runs, median mean time was 2.86 ms for SQL enrollment alone and 9.93 ms with an actual Redis certificate invalidation at AOF `always`. The extra time is per addition. The shared-batch throughput figures exclude it. Functional checks covered a committed and rolled-back addition, duplicate delivery, reading a Redis finish after a missing SQL projection, and deletion with rollback and post-commit cleanup.

## Reproduce

The samples used framework revision `01ce457f8`. The corresponding prototype and runner are available at `9b67ea190`. Install the worktree's Composer dependencies and the SQL images named in `run.py` plus `redis:latest`. On an idle machine with PHP PDO MySQL and PostgreSQL, Redis and PCNTL, run from the components root:

```sh
cd tests/Benchmarks/JobBatches/RedisResults
python3 run.py > /tmp/batch-results-comparison.jsonl
python3 run.py --lazy > /tmp/batch-results-lazy.jsonl
python3 summarize.py /tmp/batch-results-comparison.jsonl
python3 summarize.py /tmp/batch-results-lazy.jsonl
```

The runner provisions private containers on loopback, removes containers and volumes on exit, and recreates the tables for each sample. The fixed-membership timed variant uses the total on the hydrated batch and a cached total for the lock decision. These totals agree in the measured workloads. The enrollment and rollback probes exercise invalidation separately. The archived runs also collected unused client-side lock timings. The runner now collects only the metrics it reports.
