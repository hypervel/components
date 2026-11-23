# SQL batch accounting results

## Final matched comparison

Each cell shows median aggregate jobs/s (minimum–maximum) across five runs. Each run uses 50 workers recording 1,000 successes. On MySQL and MariaDB the final result table has only its composite primary key. PostgreSQL uses the partial failure index and the combined insert and update. Every variant makes the normal handler lookup.

| Engine | Workload | Current | JSON plan | Final result rows |
|---|---|---:|---:|---:|
| MySQL 9.5 | Shared, zero failures | 94 (87–117) | 97 (75–113) | 90 (84–119) |
| MySQL 9.5 | Shared, 5,000 failures | 48 (22–58) | 46 (24–47) | 91 (82–112) |
| MySQL 9.5 | Independent, zero failures | 1,103 (838–1,216) | 1,001 (739–1,155) | 958 (818–1,182) |
| MariaDB 10.11 | Shared, zero failures | 1,023 (1,018–1,041) | 940 (886–958) | 959 (934–969) |
| MariaDB 10.11 | Shared, 5,000 failures | 276 (177–278) | 261 (192–262) | 938 (145–983) |
| MariaDB 10.11 | Independent, zero failures | 1,824 (344–1,909) | 1,464 (216–1,665) | 1,510 (255–1,570) |
| PostgreSQL 17 | Shared, zero failures | 258 (241–267) | 294 (281–306) | 292 (275–299) |
| PostgreSQL 17 | Shared, 5,000 failures | 122 (109–129) | 209 (204–212) | 292 (276–298) |
| PostgreSQL 17 | Independent, zero failures | 1,552 (1,062–1,622) | 1,549 (1,497–1,648) | 1,718 (1,574–1,807) |

MySQL full-row binary logging with 5,000 outstanding failures produced 420,551 bytes per success on current code, 420,720 on the JSON plan and 702 on the final design. The independent case uses one 20-job batch per worker. These are bookkeeping measurements. The run ranges show the observed variability, not confidence intervals.

MySQL's failure-free ranges overlap heavily. MariaDB's shared, failure-free batch was consistently slower than current code with the final design. In repetition two, all three MariaDB variants slowed on both the failure-heavy and independent workloads. Transaction times rose for all three during that repetition. All five runs are included; the measurements did not establish the cause of the slowdown.

## Earlier indexed prototype and controls

Median aggregate jobs/s over five local runs with durable commits. A is the current Hypervel repository with Laravel-style counting. B is the duplicate-safe JSON plan. C stores outcomes in indexed rows and reads failure IDs on demand. All three include the handler's batch lookup; these are bookkeeping rates.

| Engine | Workload | A current | B success table + JSON | C outcome rows |
|---|---|---:|---:|---:|
| MySQL 9.5 | Shared, zero failures | 95 | 94 | 89 |
| MySQL 9.5 | Shared, 5,000 failures | 45 | 43 | 86 |
| MySQL 9.5 | Independent, zero failures | 1,208 | 1,075 | 823 |
| MariaDB 10.11 | Shared, zero failures | 1,011 | 907 | 947 |
| MariaDB 10.11 | Shared, 5,000 failures | 273 | 261 | 919 |
| MariaDB 10.11 | Independent, zero failures | 1,738 | 1,392 | 1,360 |
| PostgreSQL 17 | Shared, zero failures | 257 | 305 | 301 |
| PostgreSQL 17 | Shared, 5,000 failures | 123 | 218 | 305 |
| PostgreSQL 17 | Independent, zero failures | 1,602 | 1,696 | 1,665 |

With 5,000 outstanding failures, B emitted 420,720 MySQL full-row binary-log bytes per success and C emitted 702. PostgreSQL WAL was 24,213 B/job on A, 608 on B and 520 on C. A and B both read the list at batch lookup and during accounting. B's PostgreSQL update can avoid rewriting an unchanged list. C keeps that list off the batch row entirely.

C also beat B with a progress callback on every success. When a callback explicitly read all 5,000 IDs, C still led B at 96 vs 47 jobs/s on MySQL, 354 vs 238 on MariaDB and 320 vs 221 on PostgreSQL. That control used 10 workers and 100 timed jobs. C issued an extra query per callback that read the list.

## Separate optimization controls

- Removing the preliminary batch lookup saved one SQL statement. With independent batches, the medians rose from 865 to 918 jobs/s on MySQL, 1,311 to 1,443 on MariaDB, and 1,545 to 1,982 on PostgreSQL. The MySQL ranges overlap substantially. This is a built-in-path prototype. Custom batch routing, fakes and the pre-update snapshot given to a BatchStarted listener still need an explicit compatibility design.
- Combining the PostgreSQL insert and parent update inside a transaction increased independent-batch throughput from 1,545 to 1,802 jobs/s locally, or from 927 to 1,151 through the latency proxy. It had little effect on the shared batch. Using both optimizations reached 2,239 jobs/s locally on independent batches.
- On MySQL, a separate 5,000-job independent-batch test gave 1,074 jobs/s for B, 916 for indexed C, and 1,272 for C with its failure-lookup index removed. MySQL timings varied widely. The index also served a real read: finding one outstanding failure among 100,001 outcomes in the same batch took a median 0.55 ms via the secondary index versus 66.76 ms via the clustered primary key.
- First-access failure IDs can reflect a newer commit than the parent counters. The benchmark validates duplicate accounting, recovery and reading the list; a production implementation would need to document that behavior and the absence of insertion-order guarantees.

See the [method and commands](README.md), [all medians and ranges](measurements.md), and [raw samples and server settings](samples/).
