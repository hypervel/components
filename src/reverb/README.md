Reverb for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/reverb)

Documentation: https://hypervel.org/docs/reverb

## Differences From Laravel

- Reverb runs inside Hypervel's Swoole server, including its TLS and worker lifecycle, instead of using a standalone ReactPHP server. There are no `reverb:start` or `reverb:restart` commands and no `Reverb::registerDevCommands()`: Reverb starts and restarts with the application server, including under `php artisan dev`.
- Workers in one Reverb instance coordinate through Swoole shared memory and pipe messages. Redis scaling coordinates multiple instances and requires a standalone or Sentinel connection; Redis Cluster is not supported for pub / sub scaling.
- Reverb activity is recorded by Telescope instead of Laravel Pulse.
- In `members` mode, `accept_client_events_from` only accepts client events on private and presence channels, as in the Pusher protocol. Laravel also accepts them from subscribed members of public channels, but anyone can subscribe to a public channel, so that membership doesn't authorize publishing and would let anonymous clients inject client events.

Ported from: https://github.com/laravel/reverb
