# Native receive-buffer measurements

Measured on October 15, 2026, using the unchanged [HTTP transport harness](README.md) at `41d8bff2a`. The comparison substitutes only `CurlStreamingBody` from baseline `c5303a340`; both processes use the same installed dependencies. It isolates receive-buffer copying, without AI response aggregation or lifecycle changes.

The revised body drains each received buffer once and keeps an offset into the resulting string. A small read no longer copies the entire unread remainder.

## Method

PHP 8.4.25, Swoole 6.3.0RC1, CLI OPcache enabled and JIT disabled. A separate local HTTPS origin supplies complete SSE records, with TLS verification enabled. Each case has ten alternating fresh-process pairs, a warmup and two measured samples per process. Samples contain four requests at concurrency two, with no gap between bursts. Both sides use `--bytewise`.

The host was Linux x86-64 on an Intel Core i7-7700 at 3.60 GHz with six logical CPUs exposed.

The small case uses `--chunks=32 --bytes=128 --pace-us=1000`. The large case uses `--chunks=256 --bytes=4096 --pace-us=0`. Response count and contents are checked. CPU covers the calling PHP process, not the origin. Values are medians of process sample medians; paired changes and spread were checked separately.

## Results

| Case | CPU ms/request, before | After | Requests/s, before | After |
| --- | ---: | ---: | ---: | ---: |
| Small paced stream | 3.158 | 2.925 | 55.83 | 55.36 |
| Unpaced 1 MiB, bytewise reader | 394.92 | 219.61 | 2.53 | 4.54 |

The large case reduces CPU by 44.3% and increases throughput by 81.2% in median paired comparisons. The small case reduces CPU by 8.0%, with a −0.3% paired throughput change. Heap peaks and warm connection counts are effectively unchanged.

The large case deliberately stresses bytewise consumption. These figures aren't general HTTP capacity estimates, and the small paced case remains limited by its sender. No timing value is a CI assertion. Connection reuse and database occupancy measurements are recorded separately in [PooledConnections/results.md](../PooledConnections/results.md).
