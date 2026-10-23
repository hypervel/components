# Queue outbox measurements

Measured on October 23, 2026. The results support keeping the production outbox design unchanged. Ordinary single dispatch was effectively unchanged, bulk dispatch improved in the paired comparison, and the PostgreSQL reservation-column alternative added scan work without a consistent statement-WAL saving. See [README.md](README.md) for the retained harness.

## Setup and interpretation

- Baseline: `a67e2c9143078d4feca709869803be576604d9e4`. Measured feature branch: `29a8f847484ea23c5d2141f5746e33b18e0febfe`. Separate worktrees had independent Composer installs using the same lockfile. The comparison covers the complete feature branch, including its framework correctness changes, not only the outbox-selection check.
- Linux x86-64 VM exposing six CPUs, Intel Core i7-7700 at 3.60 GHz; PHP 8.4.26, Swoole 6.3.0RC1, CLI OPcache enabled, JIT disabled.
- Local PostgreSQL 17.11, MySQL 9.5.0, MariaDB 10.11.19 and Redis 8.2.1. PostgreSQL `fsync`, `synchronous_commit` and `full_page_writes` remained enabled. No forced checkpoints, statistics resets, fillfactor or autovacuum changes.
- Job contents: 256 bytes; stored outbox envelope: 1,246 bytes. Relay restart cache: `worker-array`, so these runs exclude external restart-cache round trips. Jobs were published, not executed.
- Each sample ran in a fresh process after warmup. Tables show median throughput with observed minimum–maximum, including outliers. CPU covers only the calling PHP process. Database/Redis server CPU is not included; heap growth is not an allocation count.

These are local workload measurements, not production capacity promises. Database-backed wall times varied substantially while PHP CPU stayed steadier. Diagnostic PostgreSQL monitoring observed simultaneous `WalSync` and `WALWrite` waits, consistent with storage delays. It did not capture the exact cause of every outlier. Do not infer small throughput differences or choose a schema from wall time alone.

Every successful sample checked exact completed counts, remaining lease counts where applicable, zero borrowed connections after completion, and removal of its own tables/queue. The failed oversubscribed run is recorded below rather than discarded. Raw reports, diagnostic logs and run scripts are retained outside Git.

## Ordinary Redis dispatch

Ten alternating baseline/current pairs per case. Single samples dispatched 20,000 jobs; bulk samples dispatched 100,000 in batches of 1,000. Warmup: 1,000 jobs.

| Dispatch | Baseline jobs/s, median (range) | Feature jobs/s, median (range) |
| --- | ---: | ---: |
| Single | 5,074 (4,992–5,210) | 5,073 (4,981–5,180) |
| Bulk, 1,000 per call | 40,074 (39,107–41,351) | 43,491 (40,829–44,366) |

Single throughput changed by −0.01% using the ratio of medians. Bulk's ratio of medians improved by 8.5%; all ten matched pairs improved, with a median paired change of 9.3% and a 1.1–12.8% range. The pooled bulk ranges overlap, so this is a measured paired improvement, not a claim that every future run will be faster.

Median PHP CPU was 2.113 → 2.129 seconds for single samples and 1.990 → 1.809 seconds for bulk samples. Ending heap growth was 5,216 bytes on both revisions in both cases. The peak heap difference was about 24 KB in both cases.

## Transactional staging

Three samples per engine/batch size. Single samples staged 2,000 jobs; bulk samples staged 10,000. Warmup: 100 jobs. Each dispatch call had its own transaction, so bulk amortizes commit cost rather than providing the same workload as single dispatch.

| Database | Jobs per call | Jobs/s, median (range) |
| --- | ---: | ---: |
| PostgreSQL | 1 | 266 (260–266) |
| PostgreSQL | 1,000 | 10,964 (9,425–11,241) |
| PostgreSQL | 10,000 | 11,312 (11,007–12,124) |
| MySQL | 1 | 86 (82–89) |
| MySQL | 1,000 | 1,046 (378–4,581) |
| MySQL | 10,000 | 7,504 (7,426–7,508) |
| MariaDB | 1 | 283 (276–284) |
| MariaDB | 1,000 | 11,281 (8,455–11,428) |
| MariaDB | 10,000 | 11,237 (9,021–11,657) |

MySQL's 1,000-job batch samples took 2.18, 26.44 and 9.56 seconds while PHP CPU stayed around 0.4 seconds. The outliers are included; these runs do not establish an engine ranking. Bulk storage and binding-limit chunking completed correctly on all three engines.

## Real relay worker

PostgreSQL source, Redis or PostgreSQL database-queue target, four database/Redis pool slots, 30,000 jobs per sample and 1,000 warmup jobs. Three samples per configuration. Claims, publication, settlement and worker lifecycle were timed; initial seeding was not.

| Target | Claim batch | Concurrency | Jobs/s, median (range) |
| --- | ---: | ---: | ---: |
| Redis | 100 | 1 | 8,328 (7,417–8,801) |
| Redis | 100 | 4 | 8,211 (7,770–8,421) |
| Redis | 100 | 8 | 7,301 (7,080–8,199) |
| Redis | 1,000 | 1 | 16,983 (7,971–17,519) |
| Redis | 1,000 | 4 | 22,387 (14,396–22,700) |
| Redis | 1,000 | 8 | 22,655 (21,515–23,118) |
| Database | 100 | 1 | 5,136 (723–6,395) |
| Database | 100 | 4 | 10,963 (9,450–11,153) |
| Database | 100 | 8 | 7,498 (7,101–11,011) |
| Database | 1,000 | 1 | 10,749 (8,376–10,952) |
| Database | 1,000 | 4 | 11,754 (6,867–14,457) |
| Database | 1,000 | 8 | 13,740 (10,850–14,373) |

Larger batches helped on this setup. Pool capacity stayed fixed at four slots, so the concurrency-8 results include any checkout contention rather than isolating scaling with matched capacity. Size pools for relay concurrency as the guide describes; tasks release each phase's connection before starting the next. All relay samples drained completely and released their borrowed resources. The database-target, batch-100, concurrency-1 group includes a 41.48-second sample, versus 4.69 and 5.84 seconds; PHP CPU was 0.86–0.94 seconds.

## Claims under churn

Claims were followed by accepted settlement and replacement inserts, keeping the mixed backlog populated. Each sample completed 30,000 rows after 5,000 warmup rows, with batch 100, eight clients and eight pool slots. Claim latency includes checkout, materialization and child cleanup; total cycle throughput additionally includes settlement/refill, UUID generation and payload encoding.

PostgreSQL used five alternating pairs per backlog/lease group. The temporary alternative added an unindexed `reserved_until` column and left indexed `available_at` unchanged, filtering live reservations during selection. Both used the production accepted-delete settlement. The comparison fixture and selector were removed after measurement; their measured source remains available at the revision above.

| Actual backlog | Seeded live leases | Current cycle jobs/s, median (range) | Reservation cycle jobs/s, median (range) |
| --- | ---: | ---: | ---: |
| 10,000 | 800 | 16,789 (2,830–16,952) | 16,603 (11,843–17,079) |
| 13,800 | 8,000 | 16,582 (1,656–21,312) | 15,331 (14,535–18,607) |
| 100,000 | 800 | 10,732 (4,533–21,376) | 12,120 (5,775–19,676) |
| 100,000 | 8,000 | 10,437 (3,710–20,024) | 10,026 (5,695–18,311) |

The 13,800-row group requested `--backlog=10000 --leased=8000`; the harness preserves 2,500 delayed and 2,500 retrying rows plus at least 800 eligible rows. Other groups match the requested backlog. The noisy cycle medians do not uniformly favor the current design.

Executing PostgreSQL plans give the more direct scan comparison. Times include the claim statement's update, not just selection. Buffer hits below are on the eligibility-index scan; WAL is statement-local bytes, not the cluster-wide workload delta.

| Backlog / live leases | Current statement ms, median (range) | Reservation statement ms, median (range) | Eligibility buffer hits, current / reservation | Statement WAL bytes, current / reservation |
| --- | ---: | ---: | ---: | ---: |
| 10,000 / 800 | 2.086 (1.898–2.197) | 2.505 (2.348–9.599) | 158 / 657 | 170,017 / 173,709 |
| 13,800 / 8,000 | 2.066 (1.805–2.153) | 4.948 (4.646–5.028) | 166 / 2,219 | 173,675 / 172,678 |
| 100,000 / 800 | 1.857 (1.653–2.392) | 2.309 (2.094–2.993) | 158 / 642 | 174,282 / 174,592 |
| 100,000 / 8,000 | 1.732 (1.631–1.873) | 4.581 (4.341–4.691) | 158 / 2,109 | 174,053 / 174,674 |

The current query used bounded index scans without filtering live leases. The alternative filtered all 800 or 8,000 seeded live leases per statement, increased scan work and showed no consistent statement-WAL saving. An unindexed deadline alone does not guarantee HOT updates; free page space also matters. Keep the existing indexed eligibility/lease deadline and avoid another schema column.

MySQL and MariaDB each had three samples at backlog 100,000 and 800 live leases:

| Database | Cycle jobs/s, median (range) |
| --- | ---: |
| MySQL | 1,885 (1,083–2,050) |
| MariaDB | 3,635 (946–7,317) |

Their executing SELECT plans used the eligibility index and returned 100 rows. Inspected examples took about 0.25 ms on MySQL and 0.17 ms on MariaDB; these SELECT times exclude the update and transaction commit. All 46 matched-capacity samples passed counts and resource cleanup.

### Oversubscribed pool diagnostic

Earlier PostgreSQL comparisons used eight clients and four slots. One 100,000-row, 8,000-live-lease sample failed with four checkout timeouts under the unchanged three-second `wait_timeout`. That failed sample has no throughput result. The completed earlier samples remain contention evidence, not the basis for the claim-design conclusion above.

One trace-assisted repeat and five further monitored repeats passed with zero borrowed connections afterward. Server monitoring observed simultaneous `WalSync`/`WALWrite` waits up to about 1.44 seconds per statement. A settlement/refill phase includes both the settlement commit and the replacement insert's commit, so these waits are consistent with exceeding checkout time under pool contention. They do not prove the original failure's exact cause. No connection-lifetime defect was confirmed, and no timeout increase, retry workaround or production change was introduced.
