Filesystem for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/filesystem)

Ported from: https://github.com/laravel/framework (illuminate/filesystem)

## Differences From Laravel

Hypervel omits Laravel's legacy `Storage::cloud()` / `filesystem.cloud` default-cloud shortcut. Use named disks via `Storage::disk(...)` instead.

Hypervel pools S3 and Google Cloud Storage SDK clients rather than complete disk adapters. Disks with equivalent client construction config share the expensive client pool while retaining their own bucket, root, visibility, and callback behavior. Pooled disks expose raw internals only through borrow-scoped `withClient()`, `withDriver()`, and `withAdapter()` callbacks.

Hypervel also provides `ScopedFilesystemProxy` and `ScopedCloudFilesystemProxy` for prefixes resolved independently on every operation. The underlying disk may be fixed or resolved once per operation when its configuration varies with the current context. These decorators fail closed on empty prefixes and reject unmapped calls so request- or tenant-scoped boundaries cannot be bypassed.
