Container for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/container)

Documentation: https://hypervel.org/docs/container

## Differences From Laravel

Hypervel supports Laravel's named container APIs, but not container ArrayAccess or dynamic service properties. Use `make()` / `get()`, `bound()` / `has()`, `bind()`, and `instance()`. For temporary instance overrides, use `forgetInstance()` to restore the original binding. Hypervel does not expose arbitrary binding removal because registrations are worker-wide boot-time state.

Ported from: https://github.com/laravel/framework/tree/13.x/src/Illuminate/Container
