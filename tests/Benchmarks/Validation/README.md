# Validation benchmarks

This developer-only harness uses the real validation factory and a disposable file-backed SQLite database. It is not an application command or part of the PHPUnit suite. SQLite isolates framework and query preparation costs; these numbers do not predict a remote database's throughput.

See [recorded measurements](results.md) for the native-rule comparison.

Run from the components root on an idle machine:

```shell
php tests/Benchmarks/Validation/benchmark.php --verify
php -d opcache.enable_cli=1 -d opcache.jit=disable tests/Benchmarks/Validation/benchmark.php \
    --operations=1000 --samples=3 --warmup=100 --json=/tmp/validation.json
php -d opcache.enable_cli=1 -d opcache.jit=disable tests/Benchmarks/Validation/benchmark.php \
    --scenarios=retention --json=/tmp/validation-retention.json
```

`--scenarios` accepts comma-separated names or `all`. `--operations` is the number of validations per sample for flat workloads; larger fixtures divide it by their item/field scale, with at least one operation. `--samples` controls repeated measurements and `--warmup` scales similarly. `--verify` checks correctness without timing; retention uses a short execution check instead of filling the cache. `--help` lists the options. Keep raw JSON reports outside the repository.

## Coverage

| Scenarios | Purpose |
|---|---|
| `simple`, `nested`, `conditional`, `flat`, `typeless-size` | Original string-rule, wildcard, conditional and size workloads |
| `fluent`, `fluent-reused` | Shared wildcard membership declarations, fresh versus reused builders; includes integer allowlists with form-string input |
| `numeric-floats`, `numeric-strings`, `numeric-precision` | Fresh Numeric builders, numeric conversions and an exact-boundary correctness check |
| `database-flat` | Small form with string unique, fluent unique with ignore, and exists |
| `database-hits`, `database-mixed`, `database-chunked` | Deduplication, failed exists/unique checks and more than one 1000-candidate chunk |
| `database-conditions` | Comparable scalar, null and membership predicates |
| `database-subclass`, `database-callback` | Rule subclass compilation and explicit callback fallback |
| `database-floats`, `database-precision` | Float binding and batch lookup; the precision fixture is seeded from text so insertion rounding cannot hide a lookup defect |
| `database-fixed-conditions` | Literal sentinel-like values, repeated columns and JSON booleans, with independent expected outcomes |
| `flat-cold`, `fluent-cold`, `database-flat-cold`, `database-conditions-cold` | Plan-cache-cold versions of representative workloads |
| `retention` | Fixed rules and changing declarations beyond the bounded cache's capacity |

Database builders are fresh on every validation. Input generation, schema creation and seeding are outside timing; fresh rule construction, validator creation and `passes()` are inside. Expected pass/fail and failed-attribute sets are checked before measuring, and measured pass/fail results are checked too. A failing scenario writes an error row without a timing and makes the command exit unsuccessfully; other rows remain available for diagnosis.

Warm samples time a loop. Cold samples clear the parser and plan caches before each validation, outside its own timer, then sum those timings. “Cold” refers only to those caches, not the process, connection or database. Median and p95 describe sample-average validation latency, not individual-request tail latency. SQL counts exclude fixture setup and warmup; missed values and callbacks can legitimately require additional queries.

Retention runs last under `all`; run it alone for comparisons. It releases validators and collects cycles between blocks, recording actual heap usage rather than allocated pages. Its second changing-rule pass measures growth after cache saturation. Report storage and runtime bookkeeping contribute small amounts of memory; there are no byte or timing thresholds. Deterministic query/export-count unit tests protect work-count guarantees separately.

## Comparing revisions

Use the exact starting revision of the change, not a moving branch tip. Create a temporary worktree under `/tmp`, copy this harness and its fixtures, copy the current `composer.lock`, and independently run `composer install` there. Never share vendor directories. Both revisions must receive identical harness files, inputs, dependencies and PHP settings. The JSON records revision, source and working-tree status, harness and lockfile hashes, runtime configuration and raw timing samples.

Start with short alternating baseline/current pairs, sequentially on the same idle machine. Increase operations or samples only when needed to distinguish a meaningful difference from noise; do not repeat the whole suite to investigate a few workloads. Keep sufficient operations to amortize timer overhead. Compare medians and spread, and weigh practical impact against correctness, memory use and maintenance cost. Incorrect old behavior is not a fast baseline: mark those rows noncomparable and report current correctness separately. Do not adapt production code or add compatibility shims to make an old revision pass.

Also run [the Data harness](../Data/README.md) on both revisions with identical settings and the same copied script. Its validation and collection controls include static membership/presence attributes and class-level `rules()` declarations; the latter exercises per-item rule comparison before wildcard sharing. Retain raw reports for future comparisons and remove the temporary worktree after use. There is no simulated legacy implementation.
