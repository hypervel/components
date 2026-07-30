Reverb for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/reverb)

Ported from: https://github.com/laravel/reverb

Hypervel Reverb provides the Pusher-compatible WebSocket protocol and HTTP API through Hypervel's Swoole server.

## Differences From Laravel

- Reverb runs inside Hypervel's Swoole server, including its TLS and worker lifecycle, instead of using a standalone ReactPHP server.
- Workers in one Reverb instance coordinate through Swoole shared memory and pipe messages. Redis scaling coordinates multiple instances and requires a standalone or Sentinel connection; Redis Cluster is not supported for pub / sub scaling.
- Reverb activity is recorded by Telescope instead of Laravel Pulse.
