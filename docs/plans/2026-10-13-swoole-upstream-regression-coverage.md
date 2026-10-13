# Swoole Upstream Regression Coverage

## Goal

Keep deterministic Hypervel regressions for upstream Swoole defects that affect real framework and application paths. Tests remain skipped only while Hypervel's minimum Swoole release lacks the corresponding fix.

## Test Scope

- Cover hooked stream shutdown and immediate listener release (#6109 and #6274).
- Cover HTTP client reuse, rejected deferred settings, and closing an unconnected client (#6149, #6173/#6267, and #6186).
- Cover masked/compressed WebSocket frames, fresh masking keys, and invalid compressed control frames (#6158, #6165, and #6209).
- Cover coroutine socket timeout and linger option handling (#6166).
- Cover incremental and multipart request parsing, upload-marker safety, and QUERY requests (#6154, #6161, #6162, #6224, and #6256).
- Cover chunked request boundaries, `100 Continue`, and pipelined response ordering through the real Hypervel HTTP server (#6205 and #6220).
- Cover cancellation during directory iteration and `Coroutine::setTimeLimit()` timer ownership (#6185 and #6270).
- Cover client-certificate enforcement and SNI/default-certificate initialization through TLS integration tests (#6188 and #6214).

## Verification

Run each new regression without its version guard against the current Swoole extension and confirm it fails for the expected defect. Then add the narrow Swoole-version skip, including the upstream pull-request URL, and rerun the affected test class. Run the existing package-level suites for every changed area.

## Deferred Coverage

- #6126 (`Swoole\Table` atomic row operations) is not included for now because Hypervel does not currently use those APIs. Revisit it if the framework adopts them.
- #6174 (Unix datagram address ownership) is not included for now because Hypervel does not currently expose or use Unix datagram sockets. Revisit it if that changes.
