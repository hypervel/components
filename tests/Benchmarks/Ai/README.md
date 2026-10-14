# AI benchmarks

Run measurements only in an owner-confirmed idle window. These developer harnesses use local services and dummy credentials; they don't call paid model APIs. Keep raw reports outside the repository. Timing results aren't CI assertions.

## HTTP integration

`http.php` adapts the existing HTTP transport benchmark to run complete OpenAI agent calls. Its origin uses the existing OpenAI test payload builders and the HTTPS server setup from `HttpTransport/Fixtures/server.php`. Both buffered and streaming calls return the same generated text.

Create a temporary loopback certificate using the commands in [the HTTP benchmark](../HttpTransport/README.md), then start this origin instead:

```shell
php tests/Benchmarks/Ai/Fixtures/server.php \
  /tmp/hypervel-http-benchmark/ca.pem /tmp/hypervel-http-benchmark/key.pem
```

Use the printed `READY` port:

```shell
php -d opcache.enable_cli=1 -d opcache.jit=disable \
  tests/Benchmarks/Ai/http.php \
  --endpoint=https://127.0.0.1:PORT --ca=/tmp/hypervel-http-benchmark/ca.pem \
  --requests=256 --samples=5 --concurrency=8 > /tmp/ai-http.json
```

The first burst is reported separately as `cold`; it includes lazy package initialization and fresh TLS connections. Later samples reuse the same application and named HTTP transport. Streaming retains at most 32 idle connections, so larger bursts open new TLS connections even in warm samples; read `new_origin_connections` alongside latency and CPU. Buffered named requests retain up to 256 idle handles by default. TLS verification remains enabled in every case. CPU and process resources cover the client, not the separate origin.

| Scenario | Options |
|---|---|
| Paced streaming | Defaults: 32 text deltas of 128 bytes, 1 ms between deltas |
| Unpaced streaming | `--pace-us=0` |
| Buffered generation | `--buffered` |
| Direct HTTP comparison | `--http-only`, with the same buffering, payload and pacing options |
| Dynamic provider accounts | `--accounts=10000`; the boot-registered resolver reads the current operation's dummy account key |
| Slow consumer | `--chunks=256 --bytes=4096 --pace-us=0 --consumer-us=1000` |
| Sustained concurrent calls | `--requests=4096 --samples=10 --concurrency=64` |

Alternate full-agent and direct-HTTP runs with identical PHP settings and dependencies for at least ten pairs per scenario, and report median and spread. Throughput includes the configured burst gaps; compare runs with identical settings. The direct comparison decodes the same payload and uses byte-at-a-time SSE reads, but doesn't run the agent loop, create SDK events or retain them for replay. The difference measures the cost of those SDK features; it isn't a Laravel comparison or evidence that those features can be removed.

Latency starts before admitting a burst of coroutines. Reports separate the first event, first text and completed request. Buffered calls have no incremental event latency. `peak_active_operations` counts active client callbacks; `peak_origin_requests` records overlapping requests observed by the origin, rather than assuming that scheduled coroutines are simultaneous transfers. The origin also supplies connection IDs and a digest of the received dummy credential; a mismatched account aborts the run.

RSS, heap, descriptors and GC are reported per sample and after handler cleanup. Connection-ID sets and latency arrays are reset each sample, so they don't grow with the number of accounts. Per-sample summary reports remain in memory until output. Agent responses intentionally retain events for replay; large-stream memory is part of that supported behavior. Keep sample sizes consistent when comparing resource growth.

Stop the owned origin and remove its temporary certificate and key after the run. No changes to the application, production transports or provider APIs are needed to run this harness.
