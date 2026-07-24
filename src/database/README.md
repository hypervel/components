Database for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/database)

## Differences From Laravel

- Laravel's external database pooler support uses a `::direct` connection suffix. Hypervel instead uses normal named connections for each endpoint and `migrations_connection` for schema and migration paths. This keeps direct and pooled endpoints as normal configured connections with their own pool settings, so Hypervel does not support Laravel's `::direct` suffix.
- Laravel's deprecated database-inspection forwarding helpers are intentionally not ported. Extensions can call `ConnectionInterface::getDriverTitle()` and `threadCount()` directly.
- `make:migration` omits Laravel's deprecated `--fullpath` option and obsolete Composer constructor dependency because migration creation no longer dumps autoload files.
