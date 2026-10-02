Horizon for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/horizon)

Documentation: https://hypervel.org/docs/horizon

## Differences From Laravel

- The deprecated `horizon:publish` command is not included. Use `horizon:install` instead.
- `horizon:listen` uses Hypervel's file watcher instead of a Node.js `chokidar` process, so Node is not required. A nonempty `horizon.watch` list replaces the `watcher` configuration's watch list, and `--poll` selects its scanning driver. See [automatically restarting Horizon](https://hypervel.org/docs/horizon#automatically-restarting-horizon).
- Supervisors accept a `concurrency` option that lets each worker process run several jobs at once in coroutines. See [concurrency](https://hypervel.org/docs/horizon#concurrency).
- Redis Cluster is configured on the named Redis connection selected by `horizon.use`. Laravel's top-level `database.redis.clusters` entries are not read.

Ported from: https://github.com/laravel/horizon
