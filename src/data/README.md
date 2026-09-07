# Hypervel Data

Documentation: https://hypervel.org/docs/data-objects

## Differences From Laravel

`Data`, `Dto`, and `Resource` use `OnlyRequests` validation by default, and Hypervel retains the class-level `withValidator()` hook. The `validate()` method ignores named factories and returns validated input. The `validateAndCreate()` method may use a named factory that returns the finished object, in which case that factory is responsible for validation. Each call to `factory()` returns a new factory.

Omitted nullable properties become `null`; use `Optional` to preserve absence and `#[Present]` when a nullable key must be supplied. A Model attribute containing `null` remains an explicit value, even for a non-nullable property with a default. With multiple payloads, the first source containing a property's input key wins, including when the value is `null`.

Hypervel rejects data classes with conflicting input or output mappings when the class is first used. Uniform nested collections use wildcard validation rules, while collections with different item shapes or rules use exact indexed rules.

Constructor injection uses Hypervel contextual attributes, including property extraction through `CurrentUser` and `RouteParameter`. Their resolved value always wins over payload input, including `null`; use a named factory or creation hook when payload values should take precedence.

Spatie's `data:cache-structures` command is not included; Hypervel does not require a structure cache step during deployment.

Spatie's data-specific `From*` attributes, `withOptionalValues()`, `withoutOptionalValues()`, `SerializeTransformer`, and `UnserializeCast` are not included. Use Hypervel's contextual attributes, declared `Optional` unions, native PHP serialization, or an explicit custom cast or transformer.

Named `collect*` methods receive the source's own array, collection, or paginator shape after its values have been converted to data objects, rather than the original source values. An Eloquent collection source is provided as a base `Hypervel\Support\Collection`. When you pass an explicit `$into` target, the method's declared return type must also match that target.

Deprecated collection proxy methods, Livewire integration, and TypeScript generation are not included. Use `toCollection()` for collection operations. TypeScript generation belongs in a general transformer package.

Ported from: https://github.com/spatie/laravel-data
