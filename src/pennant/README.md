Pennant for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/pennant)

Documentation: https://hypervel.org/docs/pennant

## Differences From Laravel

Eloquent models without a key can't be used as feature scopes. Laravel stores every unsaved model under the same scope identifier, so they all share one stored value. Hypervel throws a `RuntimeException` instead; save the model first or pass an explicit scope identifier.

Stores read their `pennant.stores` configuration once, when they're first resolved, while Laravel's database store rereads its connection and table on every query. Config is shared by every request in a Hypervel worker, so change it only during boot or in tests, then call `Feature::forgetDrivers()` and define your features again. See the [configuration documentation](https://hypervel.org/docs/pennant#configuration) for per-request connections and partitioning.

The built-in drivers and the store decorator take different constructor arguments than Laravel's, and some of their protected methods differ, because feature values live in coroutine state and stores can be partitioned. Subclasses of `ArrayDriver`, `DatabaseDriver`, `Decorator` or `FeatureManager` that override them need updating; custom drivers that implement the `Driver` contract are unaffected.

Ported from: https://github.com/laravel/pennant
