# Hypervel Data

Documentation: https://hypervel.org/docs/data-objects

## Differences From Laravel

`Resource` authorizes and validates request input under the default `OnlyRequests` strategy, as `Data` and `Dto` do; Spatie's `Resource` never validates. When a named factory accepts a request, Hypervel authorizes the request but does not validate it first, so a factory that returns the finished object is responsible for validation.

With multiple payloads, Hypervel validates the combined input once rather than a request payload on its own. Later payloads take precedence, and a later explicit `null` replaces an earlier value where Spatie keeps the earlier value. An `Optional` value never replaces an earlier value.

The `casts`, `transformers`, `normalizers`, and `rule_inferrers` options only hold your own extensions. Built-in source normalization, casting, transformation, and rule inference are fixed and need no configuration, so Spatie's built-in normalizer and rule inferrer classes are not included. Typed iterables are always cast and transformed, as with Spatie's `cast_and_transform_iterables` feature, which is not a configuration option.

Responses use the `200` status code for every request method, while Spatie uses `201` for `POST` requests and provides a `calculateResponseStatus()` override. Set a different status in `withResponse()`.

Hypervel rejects data classes with conflicting input or output mappings when the class is first used. Uniform nested collections use wildcard validation rules, while collections with different item shapes or rules use exact indexed rules.

Constructor injection uses Hypervel contextual attributes, including property extraction through `CurrentUser` and `RouteParameter`. Their resolved value always wins over payload input and creation hooks, including `null`. When input should take precedence, use a named factory that returns the finished object, or remove the contextual attribute and supply the value through a creation hook.

Spatie's configurable `pipeline()` and custom `DataPipe` classes are not included. Use [named factories](https://hypervel.org/docs/data-objects#named-factories), [`prepareForPipeline()`](https://hypervel.org/docs/data-objects#preparing-input), or [factory hooks](https://hypervel.org/docs/data-objects#creation-factories).

Spatie's `ContextableData` and `getDataContext()` are not included. An object's partial selections are available from `getPartialsDefinition()` and its wrapping from `getWrap()`.

A custom cast's `$properties` argument holds the object's declared property values keyed by PHP property name. Unlike Spatie's, it excludes undeclared input, raw input names, and contextual and computed values. See [casts and transformers](https://hypervel.org/docs/data-objects#casts-and-transformers).

`make:data` takes the complete namespace through Hypervel's `--target-namespace` generator option instead of Spatie's `--namespace` option.

Spatie's `data:cache-structures` command is not included; Hypervel does not require a structure cache step during deployment.

Spatie's data-specific `From*` attributes, `withOptionalValues()`, `withoutOptionalValues()`, `SerializeTransformer`, and `UnserializeCast` are not included. Use Hypervel's contextual attributes, declared `Optional` unions, native PHP serialization, or an explicit custom cast or transformer.

Named `collect*` methods receive the source's own array, collection, or paginator shape after its values have been converted to data objects, rather than the original source values. An Eloquent collection source is provided as a base `Hypervel\Support\Collection`. When you pass an explicit `$into` target, the method's declared return type must also match that target.

Deprecated collection proxy methods, Livewire integration, and TypeScript generation are not included. Use `toCollection()` for collection operations. TypeScript generation belongs in a general transformer package.

Ported from: https://github.com/spatie/laravel-data
