# Hypervel Data

Documentation: https://hypervel.org/docs/data-objects

## Differences From Laravel

Hypervel Data keeps the familiar `spatie/laravel-data` vocabulary with fixed, coroutine-safe internals for long-lived workers. Metadata is analyzed once per used class and retained in worker memory; there is no discovery or deploy cache command.

`Data`, `Dto`, and `Resource` use `OnlyRequests` validation by default, and Hypervel retains the class-level `withValidator()` hook. `validate()` disables named factories and returns validated input, while `validateAndCreate()` may use a direct-returning factory that owns its validation. Each `factory()` call starts a fresh operation.

Omitted nullable properties become `null`; use `Optional` to preserve absence and `#[Present]` when a nullable key must be supplied. A Model attribute containing `null` remains an explicit value, even for a non-nullable property with a default. With multiple payloads, the first source containing a property's input key wins, including when the value is `null`.

Input and output mapping collisions are rejected when metadata is built. Hypervel's compiled wildcard validation is used for uniform nested collections, with concrete indexed rules for dynamic shapes.

Constructor injection uses Hypervel contextual attributes, including property extraction through `CurrentUser` and `RouteParameter`. Their resolved value always wins over payload input, including `null`; use a named factory or creation hook when payload values should take precedence.

Data-specific `From*` aliases, optional-value factory switches, `SerializeTransformer`, and `UnserializeCast` are not included. Use Hypervel contextual attributes, declared `Optional` unions, native PHP serialization, or an explicit custom cast or transformer.

Named `collect*` methods receive the normalized container of created data objects rather than the raw source. An exact Eloquent collection parameter therefore does not match an Eloquent source after it has been normalized to a base collection.

Deprecated collection forwarding, Livewire integration, and TypeScript generation are not included. Use `toCollection()` for collection operations; TypeScript generation belongs in a general transformer package.

Ported from: https://github.com/spatie/laravel-data
