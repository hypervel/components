Support for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/support)

## Differences From Laravel

- Laravel's deferred service-provider metadata APIs are intentionally omitted. Hypervel registers providers once for a long-lived worker, so the per-request bootstrap optimization those APIs support does not apply.
- Node package-manager detection walks from the current directory to the filesystem root, so applications inside a workspace use the nearest ancestor lockfile. Within each directory, detection prefers Bun, pnpm, Yarn, then npm.
- pnpm and Yarn execute installed package binaries with `pnpm exec` and `yarn run`, keeping development tools tied to the project's installed versions.
- Hypervel's `Str` UUID methods, factories, sequences, and freeze callbacks use `Symfony\Component\Uid\Uuid` values. Laravel uses `Ramsey\Uuid\UuidInterface`, so concrete UUID type declarations and package-specific UUID methods must be adapted when porting code.
- Hypervel's `Str::orderedUuid()` returns a UUIDv7. Laravel returns a timestamp-first COMB UUIDv4, so code that checks UUID versions or relies on their ordering must account for the difference.

Ported from: https://github.com/laravel/framework/tree/13.x/src/Illuminate/Support
