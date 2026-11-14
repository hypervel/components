Contracts for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/contracts)

Documentation: https://hypervel.org/docs/contracts

## Differences From Laravel

The `Container` contract also requires `isScoped()`, `isScopedInstance()`, `resolvedShared()`, `forgetInstance()`, `makeTransient()`, `build()`, `buildWith()`, `resolveContextualParameters()`, and `rebinding()`. Custom implementations must provide these methods; extending `Hypervel\Container\Container` supplies them. Lifetime inspection lets consumers cache only the instances the container shares for the worker, instead of caching scoped or fresh services across requests.

Ported from: https://github.com/laravel/framework/tree/13.x/src/Illuminate/Contracts
