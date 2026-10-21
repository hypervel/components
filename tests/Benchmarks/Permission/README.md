# Permission lookup benchmark

`Fixtures/PermissionLookupBenchmark.php` measures public `findByName()` and `findById()` lookups and the memory held by the permission catalog. It is not part of the `*Test.php` suite, so run it explicitly on an idle machine:

```shell
php -d opcache.enable_cli=1 -d opcache.jit=disable vendor/bin/phpunit --no-progress \
  tests/Benchmarks/Permission/Fixtures/PermissionLookupBenchmark.php
BENCH_COUNT=3000 php -d opcache.enable_cli=1 -d opcache.jit=disable vendor/bin/phpunit --no-progress \
  tests/Benchmarks/Permission/Fixtures/PermissionLookupBenchmark.php
```

`BENCH_COUNT` is the number of additional permissions (at least 20); the test fixture
already contains five. Compare revisions in independently installed worktrees with the
same PHP binary, dependency versions and cache store. Disable coverage and Xdebug, run
revisions sequentially, and repeat the comparison to check for machine noise.

Warm timings are microseconds per public `findByName()` or `findById()` call:
the median of five batches of 500 calls, after 20 warm-up calls per batch.
Database seeding and catalog hydration are outside those batches. Misses include
catching `PermissionDoesNotExist`. `mixed-20` cycles over the last 20 names. The
`all-exposed` scenarios first call `getPermissions()`, so every model has been
returned before the timed lookups.

Hydration timings include one miss after clearing the in-memory catalog, with the
cache store already warm, and report the median of five runs. They do not measure a
cold database load. Memory figures use `memory_get_usage(false)`: the loaded catalog
and its indexes, peak allocation during hydration, all models returned, and residual
allocation after clearing. They exclude the pre-warmed cache store and the application.
Peak and retained figures are from the last run. The results do not describe
application-level page latency.
