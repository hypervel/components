# Differences from Laravel

If you know Laravel, this page lists the places where Hypervel intentionally does things differently, and what to use in their place. Each entry is a short summary linking to the detailed explanation in the relevant feature doc.

## Container

- **Unbound concrete classes are auto-singletoned** for the worker's lifetime. The first `make($class)` on an unbound class caches the instance; every subsequent call returns the same one. Use `build()` or `buildWith()` when you need a guaranteed-fresh instance. → [Resolution Lifecycles](/docs/{{version}}/container#resolution-lifecycles)
- **`scoped()` is per-coroutine**. Each HTTP request and each queued job runs in its own coroutine, and scoped instances live in `CoroutineContext` which is destroyed at the end of each PHP request. → [Binding Scoped Singletons](/docs/{{version}}/container#binding-scoped)

## HTTP Client

- **`Http::pool` / `Http::batch`** — use `parallel()` from `Hypervel\Coroutine` instead. Hypervel's coroutine architecture makes dedicated pool and batch methods unnecessary. → [Concurrent Requests](/docs/{{version}}/http-client#concurrent-requests)

## Scout

- **`Searchable` trait requires `SearchableInterface`** — searchable models must declare `implements SearchableInterface` alongside `use Searchable`. → [Installation](/docs/{{version}}/scout#installation)
