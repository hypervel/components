# Redis batch results benchmark

The benchmark runs `find()->recordSuccessfulJob()` on the selected SQL or Redis repository, including real batch callbacks. It excludes container routing, queue delivery, business logic and cleanup. Redis batches start with a certified total. The separate enrollment control measures the cost of invalidating it.

## Results

Median aggregate jobs/s (observed minimum–maximum), five rotated repetitions. Columns show SQL, Redis AOF `appendfsync always`, and Redis AOF `appendfsync everysec`. The latter has a weaker crash-durability guarantee. All SQL engines flush at commit. MySQL enables synchronous binary logging; MariaDB has the binary log disabled. The raw records include versions, settings, CPU count, elapsed times, SQL statement and transaction counts, Redis script calls and callback counts. [Raw production samples](samples/production.jsonl)

| SQL engine | Workload | sql | always | everysec |
|---|---|---:|---:|---:|
| pgsql | clean | 352 (298–359) | 1,711 (1,177–2,168) | 2,625 (2,510–2,928) |
| pgsql | heavy | 335 (293–352) | 2,614 (2,496–2,902) | 3,570 (3,296–3,694) |
| pgsql | independent | 1,716 (1,468–1,947) | 2,250 (1,919–2,583) | 3,132 (2,900–3,366) |
| pgsql | progress | 347 (288–359) | 1,447 (1,382–1,706) | 2,186 (1,974–2,218) |
| pgsql | singleton | 1,686 (1,670–1,761) | 1,040 (866–1,098) | 1,348 (1,302–1,401) |
| pgsql | single_worker | 255 (232–263) | 146 (141–173) | 1,366 (1,271–1,424) |
| mysql | clean | 84 (35–105) | 2,278 (1,847–2,385) | 3,154 (3,069–3,818) |
| mysql | heavy | 91 (91–112) | 2,537 (2,258–2,687) | 3,747 (3,573–3,967) |
| mysql | independent | 851 (819–1,101) | 2,249 (2,094–2,804) | 3,329 (2,076–4,057) |
| mysql | progress | 91 (84–117) | 1,453 (1,270–1,649) | 2,291 (2,113–2,509) |
| mysql | singleton | 787 (761–1,080) | 591 (570–706) | 984 (913–1,056) |
| mysql | single_worker | 76 (28–83) | 137 (127–158) | 1,462 (1,413–1,537) |
| mariadb | clean | 928 (881–970) | 2,179 (2,102–2,553) | 3,629 (3,492–4,247) |
| mariadb | heavy | 942 (930–981) | 2,869 (2,487–3,275) | 4,572 (3,232–4,599) |
| mariadb | independent | 1,442 (1,357–1,469) | 2,544 (2,171–2,995) | 3,828 (3,327–4,100) |
| mariadb | progress | 873 (861–882) | 1,613 (1,455–1,826) | 2,410 (2,195–2,599) |
| mariadb | singleton | 1,289 (1,245–1,460) | 856 (793–972) | 1,477 (1,368–1,554) |
| mariadb | single_worker | 202 (69–213) | 167 (34–176) | 1,557 (1,504–1,599) |

All cases process 1,000 successful jobs. **clean** uses one batch and 50 workers. **heavy** starts with 5,000 distinct outstanding failures in that batch. **progress** adds a real callback reading fresh progress to the heavy workload. **independent** gives each worker a 20-job batch. **singleton** gives every job a separate batch. **single_worker** uses one worker and one batch. Workers fork, connect and warm their connections before the timer. Each sample starts from a fresh schema and verifies final counts, success and failure cardinalities, finish projection and callback counts. Both variants use the same SQL parent schema and UUID values in string columns. Redis uses the framework's pooled phpredis connection with first-read progress loading.

With synchronous flushing, Redis won the shared-batch workload on all three engines and lost the one-job workload on all three. On a shared batch, only the finish projection wrote SQL in the Redis variant. Initialization can send several concurrent workers to the SQL lock before the first one installs the cached total. A one-job batch always has a terminal escalation. The ranges include all runs, including observed slow runs.

## Enrollment and planning controls

On PostgreSQL, adding 20 jobs to an existing batch took a median of **3.90 ms with SQL** and **10.40 ms with Redis** across five alternating runs of 100 additions. The respective runs ranged from 3.51 to 37.69 ms and 8.30 to 108.36 ms. Each Redis addition invalidated an existing total with AOF flushing every write. Enrollment is outside the throughput table.

The [original comparison](samples/comparison.jsonl) and [eager versus lazy hydration control](samples/lazy.jsonl) used the prototype at `9b67ea190` and the framework baseline at `01ce457f8`. Those planning measurements are separate from the production table.

## Reproduce

Install the worktree's Composer dependencies and the container images listed in `run.py`, including `redis:latest`. From the components root on an idle machine with PHP PDO MySQL and PostgreSQL, phpredis and PCNTL:

```sh
cd tests/Benchmarks/JobBatches/RedisResults
python3 run.py > /tmp/batch-results-production.jsonl
python3 summarize.py /tmp/batch-results-production.jsonl
```

The runner provisions private containers on loopback and removes their volumes on exit. Each sample starts with a fresh schema. The output records Git blob IDs for the measured source, image and server versions, and persistence settings.
