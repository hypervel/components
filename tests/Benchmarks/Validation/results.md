# Validation measurements

Structured conditions use one query instead of 100, and Data class-rule collections are about 8% faster. Flat forms, flat database forms and float candidates are slightly slower; the old revision also failed three correctness fixtures.

Measured November 15, 2026 (UTC), on Linux x86-64 with an Intel Core i7-7700 at 3.60 GHz and six exposed logical CPUs. See [README.md](README.md) for the harness and reproduction procedure.

The reviewed implementation is `854568763`, compared with starting revision `5ccadc4ce`. Measurements used the performance working tree based on `f06ac821f`, before the final comment/PHPDoc cleanup and removal of the redundant per-run reset. Those review changes do not alter the fresh-validator workloads.

Both sides used PHP 8.4.26 / Swoole 6.3.0RC1, with CLI opcache enabled and JIT disabled. Dependencies and harness files matched. After an initial broad comparison, targeted checks used two alternating pairs: 1,000 scaled operations, 100 scaled warmups, and three samples per Validation process or one per Data process. These short-run medians are a reference, not throughput guarantees; small differences need proportionate investigation before drawing conclusions.

| Workload | Before | Native rules | Queries before → after |
|---|---:|---:|---:|
| Flat form | 0.052 ms | 0.054 ms | 0 → 0 |
| Conditional fields | 39.16 ms | 38.03 ms | 0 → 0 |
| Fluent membership, 500 items | 10.04 ms | 9.97 ms | 0 → 0 |
| Flat database form | 0.715 ms | 0.739 ms | 3 → 3 |
| Float database candidates, 100 items | 4.07 ms | 4.26 ms | 1 → 1 |
| Structured database conditions, 100 items | 34.53 ms | 5.01 ms | 100 → 1 |
| Data attributes, 1,000 items | 56.79 ms | 55.90 ms | 1 → 1 |
| Data class rules, 1,000 items | 99.89 ms | 91.54 ms | 1 → 1 |

Both revisions showed zero additional retained heap after cache saturation; the native implementation retained approximately 0.28 MB more at saturation.

The old revision failed the numeric precision, database float precision and literal/repeated-condition correctness fixtures. Those cases have no speed ratio. The native implementation passes them.

## Spread in the slower rows

These ranges cover the six sample-average timings per side, not individual request latency.

| Workload | Before min–max | Native rules min–max | Median difference |
|---|---:|---:|---:|
| Flat form | 0.05179–0.05343 ms | 0.05383–0.05480 ms | +1.7 µs |
| Flat database form | 0.6688–0.7493 ms | 0.6582–0.7594 ms | +24 µs |
| Float database candidates, 100 items | 3.7396–4.2012 ms | 4.0966–4.4124 ms | +0.191 ms |

The flat-form ranges do not overlap in this run; the database ranges overlap. Separate query-hook measurements found about 18 µs more PHP work outside the query pipeline for the flat database form and 62 µs more per 100 float candidates, with no consistent increase inside the pipeline. This is phase timing, not a per-function profile. These small remaining costs did not justify additional binding or declaration caches.
