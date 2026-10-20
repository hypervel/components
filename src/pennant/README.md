Pennant for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/pennant)

Documentation: https://hypervel.org/docs/pennant

## Differences From Laravel

Eloquent models without a key can't be used as feature scopes. Laravel stores every unsaved model under the same scope identifier, so they all share one stored value. Hypervel throws a `RuntimeException` instead; save the model first or pass an explicit scope identifier.

Stores read their `pennant.stores` configuration when they're first resolved. Laravel's database store rereads its connection and table on every query, so changing config at runtime takes effect there. In Hypervel, config is shared by every request in a worker, so it should only change during boot or in tests, followed by `Feature::forgetDrivers()` and redefining your features. To choose a connection per request, leave the store's `connection` null and use `DB::usingConnection()`. To keep tenants apart in a shared table, use partitioning.

The built-in drivers and the store decorator take different constructor arguments than Laravel's, because feature values live in coroutine state and stores can be partitioned. Subclasses of `ArrayDriver`, `DatabaseDriver` or `Decorator` need updating; custom drivers that implement the `Driver` contract are unaffected.

Ported from: https://github.com/laravel/pennant
