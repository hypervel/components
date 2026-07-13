Collections for Hypervel
===

[![Ask DeepWiki](https://deepwiki.com/badge.svg)](https://deepwiki.com/hypervel/collections)

Ported from: https://github.com/laravel/framework/tree/13.x/src/Illuminate/Collections

## Differences From Laravel

Laravel's deprecated `containsOneItem()` and `containsManyItems()` aliases are intentionally not ported. Use `hasSole()` and `hasMany()` instead.

`Enumerable::random()` exposes the optional `preserveKeys` argument supported by both concrete collection implementations and reports the preserved key type accurately. Laravel exposes this argument only on the concrete classes and always reports integer keys through the contract.

`Arr::push()` accepts arrays only. Laravel also advertises `ArrayAccess`, but dot notation requires nested by-reference mutation that `ArrayAccess` cannot provide.
