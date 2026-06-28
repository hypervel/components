Filesystem for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/filesystem)

## Differences From Laravel

Hypervel omits Laravel's legacy `Storage::cloud()` / `filesystem.cloud` default-cloud shortcut. Use named disks via `Storage::disk(...)` instead.
