# Hypervel Data

Documentation: https://hypervel.org/docs/data-objects

## Differences From Laravel

`Resource` authorizes and validates request input under the default `OnlyRequests` strategy, as `Data` and `Dto` do, so request input is validated whichever base class receives it; Spatie's `Resource` never validates. When a named factory accepts a request, Hypervel authorizes the request but does not validate it first, so a factory that returns the finished object is responsible for validation.

With multiple payloads, Hypervel validates the combined input once rather than a request payload on its own, so the rules check the values the object will receive. Later payloads take precedence, and a later explicit `null` replaces an earlier value where Spatie keeps the earlier value, so a later payload can clear a value. An `Optional` value never replaces an earlier value.

The `casts`, `transformers`, `normalizers`, and `rule_inferrers` options only hold your own extensions. Built-in source normalization, casting, transformation, and rule inference are fixed and need no configuration, so Spatie's default normalizer and built-in rule inferrer classes are not included. Its optional `FormRequestNormalizer` is included. Typed iterable items are always cast and transformed, and an array given to a collection property becomes that collection, as with Spatie's `cast_and_transform_iterables` feature. Spatie's published configuration turns that feature off; Hypervel has no option for it. Scalar items follow PHP's weak typing, so an item PHP cannot convert, such as `'abc'` for an `int`, fails with a `TypeError` where Spatie's item cast turns it into `0`.

Responses use the `200` status code for every request method. Spatie returns `201 Created` for every `POST` request, including searches and other requests that create nothing, and provides a `calculateResponseStatus()` override. Set `201` in `withResponse()` when the request actually created something, as Laravel's API resources do for newly created models.

Hypervel rejects data classes with conflicting input or output mappings when the class is first used, rather than letting one value silently overwrite another. An `int` property infers the `integer` rule where Spatie infers `numeric`, so a fractional value fails validation instead of being truncated. Uniform nested collections use wildcard validation rules, while collections with different item shapes or rules use exact indexed rules, so each item is checked against the rules for its own shape. A required collection of data objects must be present but may be empty, as in Spatie; a nullable one may be omitted and becomes `null`, where Spatie still requires its key.

When a union property already accepts a value, Hypervel keeps it instead of converting it to another declared type: a string given to `string|SongData` stays a string, and an array given to `array|Collection` stays an array. Spatie first tries to build the data object and keeps the original value only if that throws, which also hides genuine construction failures; it also converts an array to the collection type of a container union. Explicit casts still run first, the declared item type of the kept container is still cast, and validation uses the rules of the type that holds the value, where Spatie requires an array for any union with a data type. Declare `Collection` alone when the property should always hold a collection. In a union with more than one container type, a value that several of them accept, or that no declared type accepts, is rejected rather than guessed. See [type conversion](https://hypervel.org/docs/data-objects#type-conversion).

A model attribute holding `null` is passed as `null`, so it fails for a property that does not accept `null`. Spatie treats it as missing and substitutes the property's default or `Optional`, so the object no longer matches the stored data. Columns that were not selected are treated as missing.

An Eloquent cast to an abstract data class only reads a stored alias or class name that resolves to a concrete subtype of that class; Spatie creates whatever class the stored value names. Data casts encode stored values with Eloquent's JSON codec, so a collection class's own `toJson()`, such as one that pretty-prints, doesn't change what is stored as it does in Spatie.

A required property declared outside the constructor that receives no input, and no value from its default or the constructor, fails creation with `CannotCreateData`. Spatie leaves it uninitialized, so the error only appears when the property is read. Nullable and `Optional` properties still receive `null` or `Optional`.

Constructor injection uses Hypervel contextual attributes, including property extraction through `CurrentUser` and `RouteParameter`. Their resolved value always wins over payload input and creation hooks, including `null`, so client input cannot replace a server-resolved value such as the current user. When input should take precedence, use a named factory that returns the finished object, or remove the contextual attribute and supply the value through a creation hook.

Spatie's configurable `pipeline()` and custom `DataPipe` classes are not included. Hypervel uses fixed creation phases over shared per-operation state, with metadata cached for the worker lifetime. Validation and construction use the same prepared values and recorded type decisions, preserving consistency while supporting optimized execution. Configurable pipeline ordering would undermine those guarantees. Use [named factories](https://hypervel.org/docs/data-objects#named-factories), [`prepareForPipeline()`](https://hypervel.org/docs/data-objects#preparing-input), or [factory hooks](https://hypervel.org/docs/data-objects#creation-factories).

Spatie's `ContextableData` and `getDataContext()` are not included. An object's partial selections are available from `getPartialsDefinition()` and its wrapping from `getWrap()`.

Looping over a data collection copies its partial selections onto the items, as in Spatie, but doesn't consume the collection's temporary selections; only the collection's own transformation does. Spatie's loop consumes them from the collection, leaving only the copies on its items. See [including and excluding properties](https://hypervel.org/docs/data-objects#including-and-excluding-properties).

A malformed partial path given in code, such as `''`, `*.name` or `{name, age}.name`, throws when the object is transformed. Spatie ignores an empty path and anything after a `*` or a group, so `*.name` includes every lazy property at every depth. Malformed paths in the request query string are skipped.

A custom cast's `$properties` argument holds the object's declared property values keyed by PHP property name. Unlike Spatie's, it excludes undeclared input, raw input names, and contextual and computed values. See [custom casts](https://hypervel.org/docs/data-objects#custom-casts).

Casts and named methods receive one `CreationContext` for the whole creation, and it does not change while nested objects are created. Its `dataClass` is the class the creation started with, where Spatie updates it to the nested class being created. It has no `from()`, `collect()`, or `currentPath`; pass it to the target class's `factory()` to create another object with the same options.

A few members of the `DataProperty`, `DataClass`, and `TransformationContext` metadata that casts, transformers, and other extensions read differ from Spatie's, such as `DataProperty` having no `defaultValue` because defaults are read from reflection when needed. `TransformationContext` is a final immutable class, so it can't be subclassed to carry extra state into a transformation; give a transformer instance its own inputs and pass it to `withTransformer()` instead. See the [porting guide](https://hypervel.org/docs/porting-from-laravel#data-objects).

`make:data` takes the complete namespace through Hypervel's `--target-namespace` generator option instead of Spatie's `--namespace` option.

Spatie's `data:cache-structures` command is not included; Hypervel does not require a structure cache step during deployment.

Spatie's data-specific `From*` attributes are not included. Use Hypervel's contextual attributes.

With `withoutOptionalValues()`, a missing `Optional` property that cannot hold `null` keeps `Optional`, where Spatie leaves it uninitialized. A missing nullable `Optional` property receives `null`, as in Spatie.

Spatie's `UnserializeCast` is not included because it unserializes property input, which may come from a request, without restricting the classes it creates. Write a custom cast that passes `allowed_classes` to `unserialize()` when a property holds trusted serialized values.

Named `collect*` methods receive the source's own array, collection, or paginator shape after its values have been converted to data objects, rather than the original source values. An Eloquent collection source is provided as a base `Hypervel\Support\Collection`. When you pass an explicit `$into` target, the method's declared return type must also match that target.

Collecting an array or collection into a paginator target, or giving one to a paginator property, is rejected because it has no pagination details to keep. For an array, Spatie creates a first page that uses the item count as the total and 15 items per page.

Deprecated collection proxy methods, Livewire integration, and TypeScript generation are not included. Use `toCollection()` for collection operations. TypeScript generation belongs in a general transformer package.

Ported from: https://github.com/spatie/laravel-data
