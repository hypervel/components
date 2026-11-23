Contracts for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/contracts)

Documentation: https://hypervel.org/docs/contracts

## Differences From Laravel

The `Config\Repository` contract requires `string()`, `integer()`, `float()`, `boolean()`, `array()`, `collection()`, `nullableString()`, `nullableInteger()`, `nullableFloat()`, `nullableBoolean()` and `nullableArray()`. Code that type-hints the contract can use these getters directly. Extending `Hypervel\Config\Repository` supplies them.

The `Container` contract also requires `isScoped()`, `isScopedInstance()`, `resolvedShared()`, `forgetInstance()`, `makeTransient()`, `build()`, `buildWith()`, `resolveContextualParameters()`, and `rebinding()`. Custom implementations must provide these methods; extending `Hypervel\Container\Container` supplies them. Lifetime inspection lets consumers cache only the instances the container shares for the worker, instead of caching scoped or fresh services across requests.

The `View` contract also requires `gatherData(): array`, which exposes the data used to render the view, including shared values, for response assertions. The standard `Hypervel\View\View` already provides it.

The `ExceptionHandler` contract also requires `shouldStopRetries(Throwable $e): bool`, so handlers that wrap another handler, such as the exception fake, keep its `dontRetry()` and `dontRetryWhen()` rules for queued jobs. Extending `Hypervel\Foundation\Exceptions\Handler` supplies it.

Ported from: https://github.com/laravel/framework/tree/13.x/src/Illuminate/Contracts
