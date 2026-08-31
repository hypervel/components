JSON Schema for Hypervel
===

Documentation: https://hypervel.org/docs/json-schema

## Differences From Laravel

Hypervel accepts explicit `null` defaults and provides fluent defaults for union and any-of schemas.

Hypervel applies one merge policy to local `$ref` siblings and nullable composition branches: outer annotations override, and conflicting assertions are rejected rather than silently replacing the referenced constraint.

Ported from: https://github.com/laravel/framework/tree/13.x/src/Illuminate/JsonSchema
