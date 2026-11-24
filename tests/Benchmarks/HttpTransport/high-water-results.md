# Streaming readiness and high-water buffering on Swoole 6.3.0RC2

The native readiness regression test passes on RC2. When the provider sends a byte between `curl_multi_exec()` and `curl_multi_select()`, select reports a ready handle and the next exec delivers the byte. The test checks this for the first and final bytes of a fixed-length response while the connection stays open. No one-second stall appeared in the paired measurements below. [Swoole fix](https://github.com/swoole/swoole-src/commit/bf4d6510eb60bdb8bcac3e6a5cb113cb0b4ed69c) · [Hypervel regression test](../../Http/Client/CurlStreamingHandlerTest.php)

## Comparison

Measured November 24, 2026, on Linux x86-64 with six logical CPUs available on an Intel Core i7-7700. Both checkouts used PHP 8.4.27, Swoole 6.3.0RC2, libcurl 8.5.0 with OpenSSL 3.0.13, CLI OPcache enabled, JIT disabled and GC enabled.

The baseline, `0085a640f`, paused cURL after each received chunk. Its immediate successor, `7c0550d03`, paused when the receive buffer reached 16 KiB and resumed after draining it. Both kept the original three-idle-connection limit and used independently installed copies of the same Composer lock, including Guzzle 8.6.0. Both used the same harness, `Response::lines()` and a named connection to a separate, certificate-verified local HTTPS origin.

Each workload ran ten pairs, alternating which checkout ran first. Each process began with one cold burst at the selected concurrency. Measured bursts had a 5 ms gap. The slow consumer waited 1 ms after each event. [Harness and options](README.md)

| Workload | Events per response | Producer interval | Concurrency | Requests per sample × measured samples |
| --- | ---: | ---: | ---: | ---: |
| Paced | 32 × 128 B | 1 ms | 8 | 64 × 2 |
| Bulk | 256 × 4 KiB | 0 | 8 | 64 × 2 |
| Slow consumer | 256 × 4 KiB | 0 | 8 | 64 × 2 |
| Sustained | 32 × 128 B | 1 ms | 128 | 512 × 10 |

## Results

Arrows show baseline → high-water buffering. Each value is the median of the ten process medians, excluding cold bursts. Percentage changes are calculated within each pair before taking the median. Brackets give the minimum and maximum paired changes.

| Workload | Client CPU ms/request | Paired CPU change | Requests/s | Paired throughput change |
| --- | ---: | ---: | ---: | ---: |
| Paced | 2.30 → 2.14 | −5.7% [−12.7%, +4.6%] | 157.3 → 158.4 | +0.5% [−2.2%, +6.6%] |
| Bulk | 4.98 → 3.96 | −20.7% [−26.2%, −15.0%] | 175.3 → 218.7 | +22.8% [−16.3%, +94.6%] |
| Slow consumer | 7.25 → 6.01 | −17.4% [−21.3%, −14.7%] | 22.5 → 23.5 | +4.2% [+1.1%, +6.4%] |
| Sustained | 2.02 → 1.85 | −8.5% [−9.3%, −7.6%] | 486.6 → 531.3 | +9.2% [+8.0%, +10.3%] |

Latencies are measured from the start of each request, in milliseconds. The p95 columns use the same median-of-process-medians calculation. The maximum columns give the slowest individual warm observation across all pairs.

| Workload | First event p95 | First event maximum | Completion p95 | Completion maximum |
| --- | ---: | ---: | ---: | ---: |
| Paced | 10.7 → 9.7 | 43.4 → 26.9 | 45.9 → 45.7 | 78.3 → 61.1 |
| Bulk | 30.2 → 21.3 | 42.6 → 34.8 | 39.4 → 30.7 | 223.1 → 230.9 |
| Slow consumer | 11.5 → 11.3 | 16.0 → 14.0 | 352.1 → 336.7 | 364.4 → 528.6 |
| Sustained | 197.9 → 178.2 | 256.8 → 219.6 | 242.4 → 223.4 | 278.1 → 270.9 |

Bulk CPU fell in every pair. Six of 20 baseline bulk samples and three of 20 high-water samples had a completion over 200 ms. Those samples delivered 75.5–131.5 requests/s, while the others delivered 166.7–229.0 requests/s. Sustained throughput improved in every pair. One high-water slow-consumer request took 529 ms. The other nine run maxima were 334–344 ms. Across all cold bursts, the slowest first event was 296 ms and the slowest completion was 400 ms.

Connection reuse matched in every warm sample. Both sides opened 40 new connections per 64 requests at concurrency 8 and 500 per 512 at concurrency 128. Warm samples ended with 18 client descriptors. All 80 processes returned to their starting count of six after cleanup. Median peak PHP heap rose from 4.62 to 4.74 MiB for slow consumers and was 11.88 MiB on both sides under sustained load. Median end-of-sample RSS under sustained load rose from 82.54 to 84.73 MiB.

## Where the 200 ms gaps occurred

The bulk delay recurred in both historical builds at concurrency 8 and on Hypervel `ebaf41990` at concurrency 40. In all twelve traced waits, the origin had finished writing and the client had read everything it had received. The sender still had 60–63 KiB queued. That fit in one TCP segment on loopback, but the receive window advertised by the client was 64–4,032 bytes too small for that segment.

Linux waited for its TCP window probe, then sent the portion that fit. The client received more data and `curl_multi_select()` returned ready after 201–209 ms. On a network with a 1,500-byte MTU, many full segments fit in the same window. The large loopback segment explains why these delays affected the local bulk measurements. [Linux 6.8 send-window check](https://github.com/torvalds/linux/blob/v6.8/net/ipv4/tcp_output.c#L2137-L2147) · [Window probe](https://github.com/torvalds/linux/blob/v6.8/net/ipv4/tcp_output.c#L4285-L4322)

The slow-consumer workload reproduced a 530 ms completion. After event 240 of 256, the sender had a 63,680-byte segment queued against a 63,360-byte window. Select returned ready after 198 ms. The original paired runs recorded latency without per-connection traces, so individual requests from those runs cannot be attributed retrospectively.

The raw JSON for all 40 pairs, build manifest, Composer lock and run scripts are retained outside Git in `2026-11-24-rc2-high-water.tar.gz`. The follow-up traces are in `2026-11-24-rc2-high-water-diagnostics.tar.gz`.
