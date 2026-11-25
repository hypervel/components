JSON Schema for Hypervel
===

Documentation: https://hypervel.org/docs/json-schema

## Differences From Laravel

Hypervel accepts explicit `null` defaults and provides fluent defaults for union and any-of schemas.

Hypervel applies one merge policy to local `$ref` siblings and collapsed nullable composition branches: outer annotations override, and conflicting assertions are rejected rather than silently replacing the referenced constraint.

`JsonSchema::rules()` throws for validation keywords it cannot convert to rules, such as `$ref`, `oneOf`, and `const`, rather than dropping their constraints. It also treats a whitespace-only string as blank, so an optional property rejects one just as a required property does.

`JsonSchema::rules()` validates the `uri` format as an RFC 3986 URI rather than with the `url` rule. URNs and `mailto:` addresses pass, raw non-ASCII characters fail, and custom messages use the `uri` key.

`ValidationRules::presenceRules()` receives whether the value is nullable as its first argument instead of the schema, and `isNullable()` takes a second argument saying whether a converted `anyOf` branch allows null. Subclasses overriding either method must use these signatures.

Ported from: https://github.com/laravel/framework/tree/13.x/src/Illuminate/JsonSchema
