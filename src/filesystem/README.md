Filesystem for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/filesystem)

Ported from: https://github.com/laravel/framework (illuminate/filesystem)

## Differences From Laravel

Hypervel omits Laravel's legacy `Storage::cloud()` / `filesystem.cloud` default-cloud shortcut. Use named disks via `Storage::disk(...)` instead.

Hypervel pools S3 and Google Cloud Storage SDK clients rather than complete disk adapters. Disks with equivalent client construction config share the expensive client pool while retaining their own bucket, root, visibility, and callback behavior. Pooled disks expose raw internals only through borrow-scoped `withClient()`, `withDriver()`, and `withAdapter()` callbacks.

Filesystem construction differs from Laravel at two protected points. `callCustomCreator()` accepts the logical disk name as an optional second parameter, so existing one-argument calls remain valid while overrides must adopt the parameter. `build()` uses a logical-name-aware construction path rather than `resolve()` because anonymous builds must pass a null name to creators; `resolve()` remains the configured-disk seam. Customize on-demand construction through `Storage::extend()` or the public driver creator methods. Creator callbacks may accept the nullable name as a third argument after the application and configuration, while existing two-argument callbacks remain valid. Hypervel carries the name through scoped reconstruction and whole-driver pool fingerprints; configure a matching explicit fingerprint when differently named disks are deliberately construction-equivalent.

Hypervel registers signed file-serving routes for any configured disk whose `serve` option is exactly `true`, while Laravel limits these routes to local disks and accepts truthy values. Every served disk must use a unique URL or application boot will fail. Custom drivers that opt in must provide the filesystem response methods used by these routes.

Hypervel also provides `ScopedFilesystemProxy` and `ScopedCloudFilesystemProxy` for prefixes resolved independently on every operation. The underlying disk may be fixed or resolved once per operation when its configuration varies with the current context. These decorators fail closed on empty prefixes and reject unmapped calls so request- or tenant-scoped boundaries cannot be bypassed.
