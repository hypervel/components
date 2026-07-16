Events for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/events)

Ported from: https://github.com/laravel/framework

## Differences From Laravel

Hypervel queued listeners accept the current array payload produced by the event dispatcher. Laravel's pre-2017 serialized-string compatibility path is intentionally omitted because Hypervel 0.4 has no legacy listener payloads and every supported producer uses the array form.
