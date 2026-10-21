# MCP concurrency benchmark

This maintainer harness compares four sequential and four concurrent tool calls through one MCP HTTP client. It starts a local mock server on an automatically assigned loopback port, verifies matching replies and actual server overlap, and stops the server when finished. No AI account or external service is needed.

See [measured results](results.md) for the concurrency comparison and the response-cache and annotation decisions.

Run from the components repository root. First check the harness with a short smoke run; its timings are not performance results:

```sh
php tests/Benchmarks/Mcp/concurrency.php --smoke
```

For measurements, run it on an idle machine:

```sh
php -d opcache.enable_cli=1 -d opcache.jit=disable \
  tests/Benchmarks/Mcp/concurrency.php \
  > /tmp/mcp-concurrency.json
```

The defaults are five samples, 25 groups per sample and a 25 ms simulated service wait per call. Each group has four calls. Change these with `--samples`, `--rounds` and `--delay-ms`. Both paths receive an unreported warmup, and their order alternates between samples. Use multiple fresh processes when comparing results.

Reports include whole-sample throughput, individual call and four-call group latency, client CPU per call, observed server concurrency, and PHP heap usage. Call latency includes request preparation, HTTP I/O, response parsing and exchange cleanup. Group latency also includes coroutine scheduling. Sample throughput and CPU include reply checks and result bookkeeping; server startup, protocol negotiation, warmup, garbage collection and the final server-idle check are outside measurement. CPU excludes the server process. Heap figures include the small retained measurement arrays and are not a standalone leak test.

Every case requires matching replies, the expected peak of one or four server calls, and no active server calls after completion. The local plain HTTP fixture isolates client scheduling and exchange ownership; it does not model TLS, internet latency, authentication services or production server throughput. The server counts calls in progress, not open connections. Ordinary tests separately cover cancellation, response-body closure and failed sibling isolation.

This is an opt-in script, not a PHPUnit test or a CI timing assertion. Keep raw output outside the repository and retain only reviewed summaries and their limits here.
