# MCP conformance harness

This opt-in project runs the official MCP conformance suite against Hypervel's server and client. It is for maintainers and contributors and uses local fixtures, without AI provider credentials or paid services.

Run from `dogfood/mcp`, with the framework's PHP extensions, Node.js and npm installed:

```sh
composer install
bash conformance/run.sh server
bash conformance/run.sh client
```

Use `bash conformance/run.sh all` to run both. The server command starts an isolated Testbench application on `127.0.0.1:8001` and stops it when finished. The client command runs against mock servers started by the conformance runner.

The runner downloads `@modelcontextprotocol/conformance@alpha` through `npx`. Set `CONFORMANCE_PACKAGE` to select a particular package version, `CONFORMANCE_PORT` to change the server port, or `CONFORMANCE_REVISION` to select the requirements revision (default `2026-07-28`). The server readiness probe uses modern discovery.

Each run prints its output directory. Logs, raw results and summary badges go into a new OS temporary directory. Set `CONFORMANCE_OUTPUT_DIR` to choose a different, empty output directory; reusing one mixes its results with previous runs.

## Expected failures

`conformance/conformance-baseline.yml` lists known failures. A successful command means no unexpected failures or stale baseline entries were reported; it does not mean every protocol check passed. The score includes passing and failing checks, excluding warnings and skipped checks.

The server fixtures exercise the package's supported tools, prompts, resources, completions and progress notifications. Several runner scenarios require optional features the package does not implement, such as Tasks, multi-round input requests and embedded-resource content. Swoole's trailing HTTP header whitespace defect also remains an expected failure until fixed upstream.

The client fixture exercises discovery, tool calls, schema preservation, HTTP header mirroring and OAuth against the runner's local servers. Authorization runs headlessly: the mock authorization endpoint redirects directly to a callback, which the fixture passes to the package's OAuth client. An array session holds the pending exchange. The fixture allows initial authorization and one reauthorization, including scope step-up and authorization-server migration.

The baseline distinguishes unsupported OAuth extensions from fixture limitations. The CIMD scenario requires a fixed `.local` metadata URL that the package rejects for public discovery. The version-retry scenario rejects the same modern version it offers as supported; the client retries when a different mutually supported version is available.

Keep the baseline tied to observed results. Remove an entry when its scenario passes and investigate new failures before adding entries. This harness is separate from the normal PHPUnit and JavaScript CI suites.
