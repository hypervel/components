# Data Benchmark

This developer-only harness measures Data construction against native constructors and explicit array mapping, plus collection, validation, named-factory, transformation, metadata, and Eloquent relation paths. It is not registered as an Artisan command and is not part of the PHPUnit suite.

Run it from the components repository root:

```shell
php tests/Benchmarks/Data/benchmark.php
```

The harness warms each scenario, records repeated samples, and reports operations per second, median and p95 nanoseconds per operation, database queries per operation, and peak allocated memory. Its heading records the commit, PHP version, operating system, loaded extensions, OPcache/JIT state, and workload size. Expensive 1,000- and 5,000-item scenarios scale their operation counts from the requested baseline.

Raw reports are opt-in and should be written outside the repository so local measurements cannot be committed accidentally:

```shell
php tests/Benchmarks/Data/benchmark.php \
    --operations=20000 \
    --samples=7 \
    --warmup=1000 \
    --json=/tmp/hypervel-data-benchmark.json \
    --csv=/tmp/hypervel-data-benchmark.csv
```

The native constructor is the language floor. The manual mapper represents purpose-built SDK code with no reflection. Warm scenarios measure normal worker-lifetime operation after metadata has been retained, while the first-use and metadata-analysis rows expose cold work separately. Eloquent scenarios use a disposable SQLite database and show the difference between preloaded relations and one batched LoadRelation query.

Compare ratios on the same machine and commit rather than treating one run as a release claim. Re-run the harness after changes to metadata, creation, validation, transformation, or collection internals, and inspect both throughput and memory before retaining a specialized path.
