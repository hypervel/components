# JSON Schema

- [Introduction](#introduction)
- [Building Schemas](#building-schemas)
    - [Primitive Types](#primitive-types)
    - [Object Schemas](#object-schemas)
    - [Array Schemas](#array-schemas)
    - [Metadata and Constraints](#metadata-and-constraints)
    - [Required and Nullable Properties](#required-and-nullable-properties)
    - [Union Types](#union-types)
    - [Any-Of Schemas](#any-of-schemas)
- [Serializing Schemas](#serializing-schemas)
- [Reconstructing Schemas](#reconstructing-schemas)
    - [Local References](#local-references)
    - [Supported Schema Subset](#supported-schema-subset)

<a name="introduction"></a>
## Introduction

Hypervel's JSON Schema builder provides a fluent way to describe structured data. The generated schemas follow JSON Schema 2020-12 and may be passed to APIs, AI tools, validators, or any other service that accepts JSON Schema:

```php
use Hypervel\JsonSchema\JsonSchema;

$schema = JsonSchema::object([
    'name' => JsonSchema::string()->required(),
    'age' => JsonSchema::integer()->min(0),
]);
```

Each builder is a fresh, independent object, so schemas may be safely constructed for individual requests or operations.

<a name="building-schemas"></a>
## Building Schemas

<a name="primitive-types"></a>
### Primitive Types

You may create string, integer, number, and boolean schemas using their corresponding methods:

```php
$name = JsonSchema::string();
$age = JsonSchema::integer();
$price = JsonSchema::number();
$enabled = JsonSchema::boolean();
```

<a name="object-schemas"></a>
### Object Schemas

The `object` method accepts an array of named property schemas:

```php
$schema = JsonSchema::object([
    'name' => JsonSchema::string()->required(),
    'email' => JsonSchema::string()->format('email')->required(),
    'profile' => JsonSchema::object([
        'bio' => JsonSchema::string()->max(500),
    ]),
]);
```

A closure may be used when you prefer to build properties from the provided factory:

```php
use Hypervel\JsonSchema\JsonSchemaTypeFactory;

$schema = JsonSchema::object(fn (JsonSchemaTypeFactory $schema) => [
    'name' => $schema->string()->required(),
    'active' => $schema->boolean()->default(true),
]);
```

By default, object schemas allow properties that are not explicitly declared. You may prevent additional properties using the `withoutAdditionalProperties` method:

```php
$schema = JsonSchema::object([
    'name' => JsonSchema::string(),
])->withoutAdditionalProperties();
```

<a name="array-schemas"></a>
### Array Schemas

The `array` method creates an array schema. You may describe its items and restrict its size using the `items`, `min`, and `max` methods:

```php
$schema = JsonSchema::array()
    ->items(JsonSchema::string())
    ->min(1)
    ->max(10);
```

The `unique` method requires every item to be unique:

```php
$schema = JsonSchema::array()
    ->items(JsonSchema::integer())
    ->unique();
```

<a name="metadata-and-constraints"></a>
### Metadata and Constraints

Every schema type supports `title`, `description`, `default`, and `enum`:

```php
$schema = JsonSchema::string()
    ->title('Status')
    ->description('The current publication status.')
    ->default('draft')
    ->enum(['draft', 'published']);
```

You may also provide a backed enum class. Its backed values will be used as the allowed values:

```php
$schema = JsonSchema::string()->enum(Status::class);
```

String schemas also provide `min`, `max`, `pattern`, and `format`. Integer and number schemas provide `min`, `max`, and `multipleOf`. Array schemas provide `min`, `max`, `items`, and `unique`.

Defaults are annotations and are not validated against the schema. An explicit `null` default is preserved even when the schema itself is not nullable.

<a name="required-and-nullable-properties"></a>
### Required and Nullable Properties

The `required` and `nullable` methods control different behavior. Calling `required` means an object property must be present. Calling `nullable` means its value may be `null`:

```php
$schema = JsonSchema::object([
    'name' => JsonSchema::string()->required(),
    'nickname' => JsonSchema::string()->nullable(),
]);
```

In this example, `name` must be present. The optional `nickname` property may be omitted, or it may contain a string or `null` value. Passing `false` to either method removes that setting.

<a name="union-types"></a>
### Union Types

The `union` method accepts JSON Schema primitive type names and allows a value to match any of them:

```php
$schema = JsonSchema::union(['string', 'integer']);
```

Union schemas may also be nullable or carry shared metadata:

```php
$schema = JsonSchema::union(['string', 'number'])
    ->title('Identifier')
    ->nullable();
```

<a name="any-of-schemas"></a>
### Any-Of Schemas

Use the `anyOf` method when each alternative needs its own constraints:

```php
$schema = JsonSchema::anyOf([
    JsonSchema::string()->format('uuid'),
    JsonSchema::integer()->min(1),
]);
```

You may also provide a closure that receives the schema factory:

```php
$schema = JsonSchema::anyOf(fn ($schema) => [
    $schema->string(),
    $schema->integer(),
]);
```

<a name="serializing-schemas"></a>
## Serializing Schemas

The `toArray` method returns the schema as an array, while `toString` returns formatted JSON. Schema builders may also be cast directly to a string:

```php
$array = $schema->toArray();
$json = $schema->toString();
$json = (string) $schema;
```

String conversion throws a `JsonException` when a default, enum value, or other schema value cannot be encoded as JSON. Calling `toArray` or converting to JSON throws an `InvalidArgumentException` for an empty union or any-of builder unless `nullable` adds a valid `null` alternative.

<a name="reconstructing-schemas"></a>
## Reconstructing Schemas

The `fromArray` method reconstructs a builder from a supported JSON Schema array:

```php
$schema = JsonSchema::fromArray([
    'type' => 'object',
    'properties' => [
        'name' => ['type' => 'string'],
    ],
    'required' => ['name'],
]);
```

This is useful when a schema is stored as configuration or received from another trusted source and you need to extend it or serialize it through the builder.

<a name="local-references"></a>
### Local References

`fromArray` resolves local JSON Pointer references, including references into `$defs`:

```php
$schema = JsonSchema::fromArray([
    'type' => 'object',
    'properties' => [
        'author' => ['$ref' => '#/$defs/user'],
    ],
    '$defs' => [
        'user' => [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
            ],
        ],
    ],
]);
```

Remote references are not supported. Circular references, excessive reference depth, and excessive total expansion throw an `InvalidArgumentException`.

<a name="supported-schema-subset"></a>
### Supported Schema Subset

The builder reconstructs the primitive, object, array, union, any-of, nullable, metadata, enum, default, and constraint keywords exposed by its fluent API. Null-only schemas are accepted using either the scalar or array form. A permissive `items: true` behaves like an omitted item constraint. Standard annotations and vendor extensions that are not modeled by the builder are ignored.

Unsupported JSON Schema 2020-12 assertions are rejected instead of being silently removed. These include `const`, `not`, `allOf`, `if`, `dependentSchemas`, `dependentRequired`, `prefixItems`, `contains`, `patternProperties`, `propertyNames`, `unevaluatedItems`, `unevaluatedProperties`, `exclusiveMinimum`, `exclusiveMaximum`, `minProperties`, `maxProperties`, and `$dynamicRef`.

An `InvalidArgumentException` is also thrown for malformed recognized keywords, empty input compositions, schema-valued `additionalProperties`, tuple or false `items`, boolean property schemas, type-specific assertions on unions, and competing compositions. This prevents a reconstructed builder from silently accepting data that the original schema rejected.
