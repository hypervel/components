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

Each call creates a new schema builder, so you may build schemas independently without sharing state between them.

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

If you prefer to use the provided schema factory, you may pass a closure to the `object` method:

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

You may pass `false` to the `unique` method to remove the unique-items constraint.

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

The `default` method adds the JSON Schema `default` annotation. Default values are not validated against the schema, and an explicit `null` default is preserved even when the schema is not nullable.

<a name="required-and-nullable-properties"></a>
### Required and Nullable Properties

Although they are often used together, the `required` and `nullable` methods control different behavior. The `required` method indicates that an object property must be present, while the `nullable` method indicates that its value may be `null`:

```php
$schema = JsonSchema::object([
    'name' => JsonSchema::string()->required(),
    'nickname' => JsonSchema::string()->nullable(),
]);
```

In this example, `name` must be present. The optional `nickname` property may be omitted, or it may contain a string or `null` value. Passing `false` to either method removes that setting.

<a name="union-types"></a>
### Union Types

The `union` method accepts an array of JSON Schema type names and allows a value to match any of them. Supported types are `string`, `integer`, `number`, `boolean`, `object`, and `array`:

```php
$schema = JsonSchema::union(['string', 'integer']);
```

You may also include `null` as a union member, which has the same effect as calling the `nullable` method.

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

If a default, enum value, or other schema value cannot be encoded as JSON, the `toString` method and string casting will throw a `JsonException`.

An empty union or any-of schema cannot be serialized unless the `nullable` method adds `null` as a valid alternative. Otherwise, the `toArray` and `toString` methods, as well as string casting, will throw an `InvalidArgumentException`.

<a name="reconstructing-schemas"></a>
## Reconstructing Schemas

If you already have a JSON Schema represented as a PHP array, you may use the `fromArray` method to create a schema builder from it:

```php
$schema = JsonSchema::fromArray([
    'type' => 'object',
    'properties' => [
        'name' => ['type' => 'string'],
    ],
    'required' => ['name'],
]);
```

This can be useful when loading a schema from configuration or another system and you would like to continue working with it through the fluent builder.

<a name="local-references"></a>
### Local References

The `fromArray` method also resolves local JSON Pointer references, including references into `$defs`:

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

Only local references are supported. An `InvalidArgumentException` will be thrown if the schema contains a remote or circular reference, a reference path with more than 256 references, or more than 20,000 expanded schema fragments.

<a name="supported-schema-subset"></a>
### Supported Schema Subset

The `fromArray` method can reconstruct schemas that use the same types, metadata, and constraints available through the fluent builder. It also accepts schemas whose only allowed type is `null`, whether the type is written as a string or as an array.

When working with schemas represented as PHP arrays, setting `items` to `true` or `[]` is treated the same as omitting the item constraint. Likewise, setting `additionalProperties` to `true` or `[]` preserves the default behavior of allowing additional properties.

A `oneOf` schema may only be reconstructed when it contains one schema and a branch whose only keyword is `"type": "null"`. In this case, the schema is reconstructed as nullable.

Annotations and vendor extensions that are not represented by the builder are ignored.

> [!WARNING]
> The `fromArray` method will throw an `InvalidArgumentException` when a schema contains a recognized JSON Schema 2020-12 validation rule that cannot be represented by the fluent builder. This prevents the rule from being silently discarded.

The unsupported JSON Schema 2020-12 validation keywords are `const`, `not`, `allOf`, `if`, `dependentSchemas`, `dependentRequired`, `prefixItems`, `contains`, `patternProperties`, `propertyNames`, `unevaluatedItems`, `unevaluatedProperties`, `exclusiveMinimum`, `exclusiveMaximum`, `minProperties`, `maxProperties`, and `$dynamicRef`.

An `InvalidArgumentException` will also be thrown when a supported keyword contains a malformed value, such as a non-string `pattern`, a non-array `required` value, or an empty `anyOf` or `oneOf` array.

Some valid JSON Schema forms cannot be represented by the fluent builder. These include an `additionalProperties` value that contains another schema, tuple or `false` values for `items`, boolean schemas used as object properties or composition branches, type-specific constraints on a multi-type union, and `anyOf` or `oneOf` schemas that carry incompatible type or composition rules. A nullable composition also cannot be reconstructed when its non-null branch and the keywords beside the composition give different values for the same keyword. Attempting to reconstruct these forms will throw an `InvalidArgumentException`.
