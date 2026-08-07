JSON Schema for Hypervel
===

Documentation: https://hypervel.org/docs/json-schema

## Differences From Laravel

Hypervel accepts explicit `null` defaults and provides fluent defaults for union and any-of schemas. Invalid JSON values and finally-empty compositions throw instead of producing unusable output. `fromArray()` also rejects malformed or unsupported JSON Schema 2020-12 assertions it cannot preserve, supports scalar and array-form null-only schemas and permissive `items: true`, and bounds both reference depth and total expansion.

Ported from: https://github.com/laravel/framework/tree/13.x/src/Illuminate/JsonSchema
