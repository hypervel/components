<?php

declare(strict_types=1);

namespace Hypervel\Tests\JsonSchema;

use Hypervel\JsonSchema\Deserializer;
use Hypervel\JsonSchema\JsonSchema;
use Hypervel\JsonSchema\Serializer;
use Hypervel\JsonSchema\Types\AnyOfType;
use Hypervel\JsonSchema\Types\ArrayType;
use Hypervel\JsonSchema\Types\BooleanType;
use Hypervel\JsonSchema\Types\IntegerType;
use Hypervel\JsonSchema\Types\NumberType;
use Hypervel\JsonSchema\Types\ObjectType;
use Hypervel\JsonSchema\Types\StringType;
use Hypervel\JsonSchema\Types\UnionType;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

class DeserializerTest extends TestCase
{
    public function testItRoundTripsATypeBuiltWithTheFactory(): void
    {
        $type = JsonSchema::object([
            'name' => JsonSchema::string()->min(1)->max(50)->pattern('^[a-z]+$')->required(),
            'age' => JsonSchema::integer()->min(0)->max(120)->default(18),
            'score' => JsonSchema::number()->min(0)->max(100)->multipleOf(0.5),
            'active' => JsonSchema::boolean()->default(true),
            'tags' => JsonSchema::array()->items(JsonSchema::string()->max(20))->min(1)->max(5)->unique(),
            'meta' => JsonSchema::object([
                'created' => JsonSchema::string()->format('date-time')->required(),
            ])->withoutAdditionalProperties(),
            'status' => JsonSchema::string()->enum(['draft', 'published'])->nullable(),
        ])->title('User')->description('A user payload');

        $array = Serializer::serialize($type);

        $rebuilt = JsonSchema::fromArray($array);

        $this->assertInstanceOf(ObjectType::class, $rebuilt);
        $this->assertSame($array, Serializer::serialize($rebuilt));
        $this->assertEquals($type, $rebuilt);
    }

    public function testItMapsEverySupportedType(): void
    {
        $this->assertInstanceOf(ObjectType::class, JsonSchema::fromArray(['type' => 'object']));
        $this->assertInstanceOf(ArrayType::class, JsonSchema::fromArray(['type' => 'array']));
        $this->assertInstanceOf(StringType::class, JsonSchema::fromArray(['type' => 'string']));
        $this->assertInstanceOf(IntegerType::class, JsonSchema::fromArray(['type' => 'integer']));
        $this->assertInstanceOf(NumberType::class, JsonSchema::fromArray(['type' => 'number']));
        $this->assertInstanceOf(BooleanType::class, JsonSchema::fromArray(['type' => 'boolean']));
    }

    public function testItAppliesStringConstraints(): void
    {
        $type = JsonSchema::fromArray([
            'type' => 'string',
            'minLength' => 2,
            'maxLength' => 8,
            'pattern' => '^foo.*$',
            'format' => 'email',
        ]);

        $this->assertEquals([
            'type' => 'string',
            'minLength' => 2,
            'maxLength' => 8,
            'pattern' => '^foo.*$',
            'format' => 'email',
        ], $type->toArray());
    }

    public function testItAppliesIntegerConstraints(): void
    {
        $type = JsonSchema::fromArray([
            'type' => 'integer',
            'minimum' => 0,
            'maximum' => 100,
            'multipleOf' => 5,
        ]);

        $this->assertInstanceOf(IntegerType::class, $type);
        $this->assertEquals([
            'type' => 'integer',
            'minimum' => 0,
            'maximum' => 100,
            'multipleOf' => 5,
        ], $type->toArray());
    }

    public function testItPreservesPhpIntegerConstraintBoundaries(): void
    {
        $array = JsonSchema::fromArray([
            'type' => 'integer',
            'minimum' => PHP_INT_MIN,
            'maximum' => PHP_INT_MAX,
        ])->toArray();

        $this->assertSame(PHP_INT_MIN, $array['minimum']);
        $this->assertSame(PHP_INT_MAX, $array['maximum']);
    }

    public function testItPreservesTheRepresentableIntegralFloatBoundary(): void
    {
        $array = JsonSchema::fromArray([
            'type' => 'integer',
            'minimum' => (float) PHP_INT_MIN,
        ])->toArray();

        $this->assertSame(PHP_INT_MIN, $array['minimum']);
    }

    #[DataProvider('outOfRangeIntegerConstraintProvider')]
    public function testItRejectsIntegerConstraintsOutsideThePhpIntegerRange(int|float|string $value): void
    {
        $this->expectExceptionObject(new InvalidArgumentException(
            'The JSON Schema integer constraint is outside the PHP integer range.'
        ));

        JsonSchema::fromArray([
            'type' => 'integer',
            'minimum' => $value,
        ]);
    }

    public static function outOfRangeIntegerConstraintProvider(): array
    {
        return [
            'positive integral float' => [(float) PHP_INT_MAX],
            'negative integral float' => [-1e20],
            'large exponent' => [1e100],
            'numeric string' => ['9223372036854775808'],
        ];
    }

    public function testItAppliesNumberConstraintsAndPreservesFloats(): void
    {
        $type = JsonSchema::fromArray([
            'type' => 'number',
            'minimum' => 0.5,
            'maximum' => 9.9,
            'multipleOf' => 0.1,
        ]);

        $this->assertInstanceOf(NumberType::class, $type);

        $array = $type->toArray();

        $this->assertSame(0.5, $array['minimum']);
        $this->assertSame(9.9, $array['maximum']);
        $this->assertSame(0.1, $array['multipleOf']);
    }

    public function testItAppliesArrayConstraintsAndNestedItems(): void
    {
        $type = JsonSchema::fromArray([
            'type' => 'array',
            'items' => ['type' => 'string', 'maxLength' => 3],
            'minItems' => 1,
            'maxItems' => 4,
            'uniqueItems' => true,
        ]);

        $this->assertInstanceOf(ArrayType::class, $type);
        $this->assertEquals([
            'type' => 'array',
            'minItems' => 1,
            'maxItems' => 4,
            'items' => [
                'type' => 'string',
                'maxLength' => 3,
            ],
            'uniqueItems' => true,
        ], $type->toArray());
    }

    public function testItPreservesNumericStringIntegerValuedConstraints(): void
    {
        $array = JsonSchema::fromArray([
            'type' => 'array',
            'minItems' => '3',
        ])->toArray();
        $string = JsonSchema::fromArray([
            'type' => 'string',
            'maxLength' => '4',
        ])->toArray();

        $this->assertSame(3, $array['minItems']);
        $this->assertSame(4, $string['maxLength']);
    }

    #[DataProvider('malformedIntegerValuedConstraintProvider')]
    public function testItRejectsMalformedIntegerValuedConstraints(array $schema, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        JsonSchema::fromArray($schema);
    }

    public static function malformedIntegerValuedConstraintProvider(): array
    {
        return [
            'minItems overflow' => [['type' => 'array', 'minItems' => 1e20], 'The JSON Schema integer constraint is outside the PHP integer range.'],
            'maxItems overflow' => [['type' => 'array', 'maxItems' => 1e100], 'The JSON Schema integer constraint is outside the PHP integer range.'],
            'minLength overflow' => [['type' => 'string', 'minLength' => 1e20], 'The JSON Schema integer constraint is outside the PHP integer range.'],
            'maxLength overflow' => [['type' => 'string', 'maxLength' => 1e100], 'The JSON Schema integer constraint is outside the PHP integer range.'],
            'fractional' => [['type' => 'array', 'minItems' => 2.7], 'The JSON Schema integer constraint [2.7] must be an integer.'],
            'nonnumeric' => [['type' => 'array', 'minItems' => 'abc'], 'The JSON Schema [minItems] constraint must be an integer.'],
            'boolean' => [['type' => 'string', 'minLength' => true], 'The JSON Schema [minLength] constraint must be an integer.'],
            'array' => [['type' => 'string', 'maxLength' => ['x']], 'The JSON Schema [maxLength] constraint must be an integer.'],
            'null' => [['type' => 'array', 'maxItems' => null], 'The JSON Schema [maxItems] constraint must be an integer.'],
        ];
    }

    #[DataProvider('permissiveItemsProvider')]
    public function testItAcceptsRepresentablePermissiveItems(mixed $items): void
    {
        $this->assertSame(['type' => 'array'], JsonSchema::fromArray([
            'type' => 'array',
            'items' => $items,
        ])->toArray());
    }

    public static function permissiveItemsProvider(): array
    {
        return [
            'true' => [true],
            'empty schema' => [[]],
        ];
    }

    public function testItRejectsFalseItems(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException(
            'The JSON Schema [items] keyword must be true or a single object schema.'
        ));

        JsonSchema::fromArray([
            'type' => 'array',
            'items' => false,
        ]);
    }

    public function testItBuildsNestedObjectsAndMarksRequiredChildren(): void
    {
        $type = JsonSchema::fromArray([
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1],
                'age' => ['type' => 'integer', 'minimum' => 0],
                'address' => [
                    'type' => 'object',
                    'properties' => [
                        'city' => ['type' => 'string'],
                    ],
                    'required' => ['city'],
                ],
            ],
            'required' => ['name'],
        ]);

        $this->assertEquals([
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'minLength' => 1],
                'age' => ['type' => 'integer', 'minimum' => 0],
                'address' => [
                    'type' => 'object',
                    'properties' => [
                        'city' => ['type' => 'string'],
                    ],
                    'required' => ['city'],
                ],
            ],
            'required' => ['name'],
        ], $type->toArray());
    }

    public function testItPreservesNumericStringPropertyNamesWhenMarkingRequired(): void
    {
        $type = JsonSchema::fromArray([
            'type' => 'object',
            'properties' => [
                '1' => ['type' => 'string'],
                '4' => ['type' => 'string'],
            ],
            'required' => ['1', '4'],
        ]);

        $array = $type->toArray();

        $this->assertEquals(['1', '4'], $array['required']);
        $this->assertIsString($array['required'][0]);
    }

    public function testItDisallowsAdditionalPropertiesWhenFalse(): void
    {
        $type = JsonSchema::fromArray([
            'type' => 'object',
            'additionalProperties' => false,
        ]);

        $this->assertEquals([
            'type' => 'object',
            'additionalProperties' => false,
        ], $type->toArray());
    }

    #[DataProvider('permissiveAdditionalPropertiesProvider')]
    public function testItAcceptsRepresentablePermissiveAdditionalProperties(array $schema): void
    {
        $this->assertSame(['type' => 'object'], JsonSchema::fromArray($schema)->toArray());
    }

    public static function permissiveAdditionalPropertiesProvider(): array
    {
        return [
            'absent' => [['type' => 'object']],
            'true' => [['type' => 'object', 'additionalProperties' => true]],
            'empty schema' => [['type' => 'object', 'additionalProperties' => []]],
        ];
    }

    #[DataProvider('unsupportedAdditionalPropertiesProvider')]
    public function testItRejectsAdditionalPropertiesItCannotRepresent(mixed $additionalProperties): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Schema-valued or malformed JSON Schema [additionalProperties] cannot be represented.');

        JsonSchema::fromArray([
            'type' => 'object',
            'additionalProperties' => $additionalProperties,
        ]);
    }

    public static function unsupportedAdditionalPropertiesProvider(): array
    {
        return [
            'schema' => [['type' => 'string']],
            'object' => [new stdClass],
            'null' => [null],
            'scalar' => ['no'],
        ];
    }

    public function testItRejectsARequiredNameWithoutAPropertySchema(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to represent required property [missing] because it has no property schema.');

        JsonSchema::fromArray([
            'type' => 'object',
            'required' => ['missing'],
        ]);
    }

    public function testItAcceptsSerializerEmittedObjectPropertyMaps(): void
    {
        $properties = new stdClass;
        $properties->{'0'} = ['type' => 'string'];

        $type = JsonSchema::fromArray([
            'type' => 'object',
            'properties' => $properties,
            'required' => ['0'],
        ]);

        $serialized = $type->toArray();

        $this->assertInstanceOf(stdClass::class, $serialized['properties']);
        $this->assertSame(['0'], $serialized['required']);
    }

    public function testItRoundTripsSerializerEmittedObjectMapsAndDefaults(): void
    {
        $serialized = JsonSchema::object([
            '0' => JsonSchema::string()->required(),
            '1' => JsonSchema::integer(),
        ])->default(['zero', 1])->toArray();

        $rebuilt = JsonSchema::fromArray($serialized)->toArray();

        $this->assertEquals($serialized, $rebuilt);
        $this->assertInstanceOf(stdClass::class, $rebuilt['properties']);
        $this->assertInstanceOf(stdClass::class, $rebuilt['default']);
    }

    public function testItNormalizesNullableFromATypeArray(): void
    {
        $type = JsonSchema::fromArray([
            'type' => ['string', 'null'],
            'minLength' => 1,
        ]);

        $this->assertInstanceOf(StringType::class, $type);
        $this->assertEquals([
            'type' => ['string', 'null'],
            'minLength' => 1,
        ], $type->toArray());
    }

    public function testItNormalizesNullableFromAnAnyOfNullBranch(): void
    {
        $type = JsonSchema::fromArray([
            'title' => 'Nickname',
            'anyOf' => [
                ['type' => 'string', 'minLength' => 1],
                ['type' => 'null'],
            ],
        ]);

        $this->assertInstanceOf(StringType::class, $type);
        $this->assertEquals([
            'title' => 'Nickname',
            'minLength' => 1,
            'type' => ['string', 'null'],
        ], $type->toArray());
    }

    public function testItNormalizesNullableFromAOneOfNullBranch(): void
    {
        $type = JsonSchema::fromArray([
            'oneOf' => [
                ['type' => 'null'],
                ['type' => 'integer', 'minimum' => 0],
            ],
        ]);

        $this->assertInstanceOf(IntegerType::class, $type);
        $this->assertEquals([
            'minimum' => 0,
            'type' => ['integer', 'null'],
        ], $type->toArray());
    }

    public function testItResolvesALocalRefAgainstDefs(): void
    {
        $type = JsonSchema::fromArray([
            'type' => 'object',
            'properties' => [
                'author' => ['$ref' => '#/$defs/User'],
            ],
            'required' => ['author'],
            '$defs' => [
                'User' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                    ],
                    'required' => ['name'],
                ],
            ],
        ]);

        $this->assertEquals([
            'type' => 'object',
            'properties' => [
                'author' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                    ],
                    'required' => ['name'],
                ],
            ],
            'required' => ['author'],
        ], $type->toArray());
    }

    public function testItResolvesALocalRefAgainstDefinitions(): void
    {
        $type = JsonSchema::fromArray([
            '$ref' => '#/definitions/Tag',
            'definitions' => [
                'Tag' => ['type' => 'string', 'minLength' => 1],
            ],
        ]);

        $this->assertInstanceOf(StringType::class, $type);
        $this->assertEquals([
            'type' => 'string',
            'minLength' => 1,
        ], $type->toArray());
    }

    public function testItMergesSiblingKeysOverARef(): void
    {
        $type = JsonSchema::fromArray([
            'type' => 'object',
            'properties' => [
                'handle' => [
                    '$ref' => '#/$defs/Name',
                    'description' => 'Overridden description',
                ],
            ],
            '$defs' => [
                'Name' => [
                    'type' => 'string',
                    'description' => 'Original description',
                    'minLength' => 1,
                ],
            ],
        ]);

        $this->assertEquals([
            'type' => 'object',
            'properties' => [
                'handle' => [
                    'description' => 'Overridden description',
                    'minLength' => 1,
                    'type' => 'string',
                ],
            ],
        ], $type->toArray());
    }

    public function testItMergesEveryLevelOfAReferenceChainWithOuterSiblingsWinning(): void
    {
        $type = JsonSchema::fromArray([
            '$ref' => '#/$defs/outer',
            'title' => 'Outermost title',
            '$defs' => [
                'outer' => [
                    '$ref' => '#/$defs/target',
                    'title' => 'Intermediate title',
                    'description' => 'Intermediate description',
                ],
                'target' => [
                    'type' => 'string',
                    'title' => 'Target title',
                    'description' => 'Target description',
                    'minLength' => 1,
                ],
            ],
        ]);

        $this->assertSame([
            'title' => 'Outermost title',
            'description' => 'Intermediate description',
            'minLength' => 1,
            'type' => 'string',
        ], $type->toArray());
    }

    public function testItResolvesEscapedJsonPointerSegments(): void
    {
        $type = JsonSchema::fromArray([
            '$ref' => '#/$defs/a~1b~0c',
            '$defs' => [
                'a/b~c' => ['type' => 'string'],
            ],
        ]);

        $this->assertSame(['type' => 'string'], $type->toArray());
    }

    public function testItThrowsForAnUnresolvableRef(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Unable to resolve JSON Schema $ref [#/$defs/Missing].'));

        JsonSchema::fromArray([
            '$ref' => '#/$defs/Missing',
            '$defs' => [],
        ]);
    }

    public function testItThrowsForARemoteRef(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Unable to resolve non-local JSON Schema $ref [https://example.com/user.json].'));

        JsonSchema::fromArray([
            '$ref' => 'https://example.com/user.json',
        ]);
    }

    public function testItInfersObjectTypeFromProperties(): void
    {
        $type = JsonSchema::fromArray([
            'properties' => [
                'name' => ['type' => 'string'],
            ],
        ]);

        $this->assertInstanceOf(ObjectType::class, $type);
    }

    public function testItInfersArrayTypeFromItems(): void
    {
        $type = JsonSchema::fromArray([
            'items' => ['type' => 'integer'],
        ]);

        $this->assertInstanceOf(ArrayType::class, $type);
        $this->assertEquals([
            'type' => 'array',
            'items' => ['type' => 'integer'],
        ], $type->toArray());
    }

    public function testItInfersScalarTypeFromAHomogeneousEnum(): void
    {
        $this->assertInstanceOf(StringType::class, JsonSchema::fromArray([
            'enum' => ['draft', 'published'],
        ]));

        $this->assertInstanceOf(IntegerType::class, JsonSchema::fromArray([
            'enum' => [1, 2, 3],
        ]));

        $this->assertInstanceOf(NumberType::class, JsonSchema::fromArray([
            'enum' => [1, 2.5, 3],
        ]));

        $this->assertInstanceOf(BooleanType::class, JsonSchema::fromArray([
            'enum' => [true, false],
        ]));
    }

    public function testItAppliesEnumAndDefault(): void
    {
        $type = JsonSchema::fromArray([
            'type' => 'string',
            'enum' => ['draft', 'published'],
            'default' => 'draft',
        ]);

        $this->assertEquals([
            'type' => 'string',
            'default' => 'draft',
            'enum' => ['draft', 'published'],
        ], $type->toArray());
    }

    public function testItIgnoresUnknownKeywords(): void
    {
        $type = JsonSchema::fromArray([
            'type' => 'string',
            'minLength' => 1,
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$comment' => 'ignore me',
            'readOnly' => true,
            'contentEncoding' => 'base64',
        ]);

        $this->assertEquals([
            'type' => 'string',
            'minLength' => 1,
        ], $type->toArray());
    }

    public function testItThrowsWhenTheTypeCannotBeDetermined(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Unable to determine the JSON Schema type for the given schema.'));

        JsonSchema::fromArray([
            'title' => 'Mystery',
        ]);
    }

    public function testItDetectsACircularRefInsteadOfRecursing(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Circular JSON Schema $ref [#/$defs/node] detected.'));

        JsonSchema::fromArray([
            'type' => 'object',
            'properties' => [
                'children' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/node']],
            ],
            '$defs' => [
                'node' => [
                    'type' => 'object',
                    'properties' => [
                        'children' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/node']],
                    ],
                ],
            ],
        ]);
    }

    public function testItResolvesTheSameRefUsedInSiblingPositions(): void
    {
        $type = JsonSchema::fromArray([
            'type' => 'object',
            'properties' => [
                'home' => ['$ref' => '#/$defs/address'],
                'work' => ['$ref' => '#/$defs/address'],
            ],
            '$defs' => [
                'address' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
            ],
        ]);

        $this->assertEquals([
            'type' => 'object',
            'properties' => [
                'home' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
                'work' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
            ],
        ], $type->toArray());
    }

    public function testReferenceDepthIsScopedToOneActivePath(): void
    {
        $type = JsonSchemaDepthLimitedDeserializer::deserialize([
            'type' => 'object',
            'properties' => [
                'left' => ['$ref' => '#/$defs/value'],
                'right' => ['$ref' => '#/$defs/value'],
            ],
            '$defs' => [
                'value' => ['type' => 'string'],
            ],
        ]);

        $this->assertInstanceOf(ObjectType::class, $type);
    }

    #[DataProvider('overlyDeepReferenceProvider')]
    public function testItRejectsOverlyDeepActiveReferencePaths(array $schema): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON Schema reference paths may not contain more than 1 distinct references.');

        JsonSchemaDepthLimitedDeserializer::deserialize($schema);
    }

    public static function overlyDeepReferenceProvider(): array
    {
        $definitions = [
            'first' => ['$ref' => '#/$defs/second'],
            'second' => ['type' => 'string'],
        ];

        return [
            'direct' => [[
                '$ref' => '#/$defs/first',
                '$defs' => $definitions,
            ]],
            'nested' => [[
                'type' => 'object',
                'properties' => [
                    'value' => ['$ref' => '#/$defs/first'],
                ],
                '$defs' => $definitions,
            ]],
        ];
    }

    public function testReferenceFollowsConsumeTheTotalExpansionBudget(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON Schema reconstruction exceeded the maximum expansion of 1 schema fragments.');

        JsonSchemaNodeLimitedDeserializer::deserialize([
            '$ref' => '#/$defs/value',
            '$defs' => [
                'value' => ['type' => 'string'],
            ],
        ]);
    }

    public function testItDeserializesAMultiTypeUnion(): void
    {
        $type = JsonSchema::fromArray([
            'type' => ['string', 'number', 'boolean'],
        ]);

        $this->assertInstanceOf(UnionType::class, $type);
        $this->assertSame(['string', 'number', 'boolean'], $type->types());
        $this->assertSame(['type' => ['string', 'number', 'boolean']], $type->toArray());
    }

    public function testItDeserializesANullableMultiTypeUnion(): void
    {
        $type = JsonSchema::fromArray([
            'type' => ['string', 'number', 'null'],
        ]);

        $this->assertInstanceOf(UnionType::class, $type);
        $this->assertSame(['string', 'number'], $type->types());
        $this->assertSame(['type' => ['string', 'number', 'null']], $type->toArray());
    }

    public function testItDoesNotTreatASingleTypePlusNullAsAUnion(): void
    {
        $type = JsonSchema::fromArray([
            'type' => ['string', 'null'],
        ]);

        $this->assertInstanceOf(StringType::class, $type);
        $this->assertSame(['type' => ['string', 'null']], $type->toArray());
    }

    public function testItDedupesAndPreservesOrderOfUnionMembers(): void
    {
        $type = JsonSchema::fromArray([
            'type' => ['number', 'string', 'number', 'boolean', 'string'],
        ]);

        $this->assertInstanceOf(UnionType::class, $type);
        $this->assertSame(['number', 'string', 'boolean'], $type->types());
    }

    public function testItDeserializesAUnionNestedInAnObjectProperty(): void
    {
        $type = JsonSchema::fromArray([
            'type' => 'object',
            'properties' => [
                'value' => ['type' => ['string', 'number']],
            ],
        ]);

        $this->assertInstanceOf(ObjectType::class, $type);
        $this->assertEquals([
            'type' => 'object',
            'properties' => [
                'value' => ['type' => ['string', 'number']],
            ],
        ], $type->toArray());
    }

    public function testItDeserializesAUnionNestedInArrayItems(): void
    {
        $type = JsonSchema::fromArray([
            'type' => 'array',
            'items' => ['type' => ['string', 'integer', 'null']],
        ]);

        $this->assertInstanceOf(ArrayType::class, $type);
        $this->assertEquals([
            'type' => 'array',
            'items' => ['type' => ['string', 'integer', 'null']],
        ], $type->toArray());
    }

    public function testItDeserializesAnAnyOfComposition(): void
    {
        $type = JsonSchema::fromArray([
            'title' => 'Identifier',
            'anyOf' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
        ]);

        $this->assertInstanceOf(AnyOfType::class, $type);
        $this->assertEquals([
            'title' => 'Identifier',
            'anyOf' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
        ], $type->toArray());
    }

    public function testItDeserializesANullableAnyOfComposition(): void
    {
        $type = JsonSchema::fromArray([
            'anyOf' => [
                ['type' => 'string'],
                ['type' => 'integer'],
                ['type' => 'null'],
            ],
        ]);

        $this->assertInstanceOf(AnyOfType::class, $type);
        $this->assertEquals([
            'anyOf' => [
                ['type' => 'string'],
                ['type' => 'integer'],
                ['type' => 'null'],
            ],
        ], $type->toArray());
    }

    public function testItDeserializesANullOnlyAnyOfComposition(): void
    {
        $type = JsonSchema::fromArray([
            'anyOf' => [
                ['type' => 'null'],
            ],
        ]);

        $this->assertInstanceOf(AnyOfType::class, $type);
        $this->assertSame([
            'anyOf' => [
                ['type' => 'null'],
            ],
        ], $type->toArray());
    }

    public function testItPreservesAnnotationsOnAGeneralAnyOf(): void
    {
        $type = JsonSchema::fromArray([
            'title' => 'Identifier',
            'description' => 'A string or integer identifier.',
            'default' => null,
            'enum' => ['default', 1],
            'anyOf' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
        ]);

        $this->assertSame([
            'title' => 'Identifier',
            'description' => 'A string or integer identifier.',
            'default' => null,
            'enum' => ['default', 1],
            'anyOf' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
        ], $type->toArray());
    }

    public function testNullableSingleSchemaCompositionsKeepSupportedSiblingConstraints(): void
    {
        foreach (['anyOf', 'oneOf'] as $keyword) {
            $type = JsonSchema::fromArray([
                $keyword => [
                    ['type' => 'string'],
                    ['type' => 'null'],
                ],
                'minLength' => 2,
            ]);

            $this->assertSame([
                'minLength' => 2,
                'type' => ['string', 'null'],
            ], $type->toArray());
        }
    }

    #[DataProvider('emptyInputCompositionProvider')]
    public function testItRejectsEmptyInputCompositions(string $keyword): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("The JSON Schema [{$keyword}] keyword must be a non-empty array.");

        JsonSchema::fromArray([$keyword => []]);
    }

    public static function emptyInputCompositionProvider(): array
    {
        return [
            'anyOf' => ['anyOf'],
            'oneOf' => ['oneOf'],
        ];
    }

    #[DataProvider('booleanOneOfBranchProvider')]
    public function testItRejectsBooleanOneOfBranches(bool $branch): void
    {
        $this->expectExceptionObject(new InvalidArgumentException(
            'Unable to represent the schema for a oneOf branch; boolean schemas are not supported.'
        ));

        JsonSchema::fromArray([
            'oneOf' => [
                $branch,
                ['type' => 'string'],
                ['type' => 'null'],
            ],
        ]);
    }

    public static function booleanOneOfBranchProvider(): array
    {
        return [
            'true' => [true],
            'false' => [false],
        ];
    }

    #[DataProvider('survivingCompositionProvider')]
    public function testItRejectsACompositionThatSurvivesNullableCollapse(array $schema, string $keyword, string $composition): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Structural keywords [{$keyword}] are not supported alongside a nullable \"{$composition}\"."
        );

        JsonSchema::fromArray($schema);
    }

    public static function survivingCompositionProvider(): array
    {
        return [
            'oneOf sibling of anyOf' => [[
                'anyOf' => [['type' => 'string'], ['type' => 'null']],
                'oneOf' => [['type' => 'integer'], ['type' => 'null']],
            ], 'oneOf', 'anyOf'],
            'oneOf inside anyOf branch' => [[
                'anyOf' => [
                    ['type' => 'string', 'oneOf' => [['type' => 'integer']]],
                    ['type' => 'null'],
                ],
            ], 'oneOf', 'anyOf'],
            'anyOf inside oneOf branch' => [[
                'oneOf' => [
                    ['type' => 'string', 'anyOf' => [['type' => 'integer']]],
                    ['type' => 'null'],
                ],
            ], 'anyOf', 'oneOf'],
            'anyOf sibling of oneOf' => [[
                'oneOf' => [['type' => 'string'], ['type' => 'null']],
                'anyOf' => [['type' => 'integer'], ['type' => 'null']],
            ], 'oneOf', 'anyOf'],
            'oneOf inside referenced anyOf branch' => [[
                'anyOf' => [['$ref' => '#/$defs/value'], ['type' => 'null']],
                '$defs' => [
                    'value' => ['type' => 'string', 'oneOf' => [['type' => 'integer']]],
                ],
            ], 'oneOf', 'anyOf'],
            'anyOf inside anyOf branch' => [[
                'anyOf' => [
                    ['type' => 'string', 'anyOf' => [['type' => 'integer']]],
                    ['type' => 'null'],
                ],
            ], 'anyOf', 'anyOf'],
        ];
    }

    #[DataProvider('unsupportedAnyOfSiblingProvider')]
    public function testGeneralAnyOfRejectsCompetingStructuralKeywords(string $keyword, mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Structural keywords [{$keyword}] are not supported alongside a general JSON Schema anyOf.");

        JsonSchema::fromArray([
            'anyOf' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
            $keyword => $value,
        ]);
    }

    public static function unsupportedAnyOfSiblingProvider(): array
    {
        return [
            'type-specific keyword' => ['minLength', 1],
            'type' => ['type', 'string'],
            'oneOf' => ['oneOf', [['type' => 'string']]],
        ];
    }

    public function testItDeserializesAnAnyOfNestedInAnObjectProperty(): void
    {
        $type = JsonSchema::fromArray([
            'type' => 'object',
            'properties' => [
                'value' => [
                    'anyOf' => [
                        ['type' => 'string'],
                        ['type' => 'integer'],
                    ],
                ],
            ],
            'required' => ['value'],
        ]);

        $this->assertInstanceOf(ObjectType::class, $type);
        $this->assertEquals([
            'type' => 'object',
            'properties' => [
                'value' => [
                    'anyOf' => [
                        ['type' => 'string'],
                        ['type' => 'integer'],
                    ],
                ],
            ],
            'required' => ['value'],
        ], $type->toArray());
    }

    public function testItThrowsForAnUnsupportedUnionMember(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Unsupported JSON Schema type [wat] in a multi-type union.'));

        JsonSchema::fromArray([
            'type' => ['string', 'wat'],
        ]);
    }

    public function testItThrowsForANonStringUnionMember(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException(
            'The JSON Schema [type] keyword must be a string or an array of strings.'
        ));

        JsonSchema::fromArray([
            'type' => ['string', 123],
        ]);
    }

    public function testItThrowsWhenAUnionCarriesTypeSpecificKeywords(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Type-specific keywords [items] are not supported on a JSON Schema union.'));

        JsonSchema::fromArray([
            'type' => ['array', 'string'],
            'items' => ['type' => 'integer'],
        ]);
    }

    public function testItRejectsAnEmptyTypeArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A JSON Schema [type] array must contain at least one type.');

        JsonSchema::fromArray(['type' => []]);
    }

    public function testItReconstructsABareNullOnlyTypeArray(): void
    {
        $type = JsonSchema::fromArray(['type' => ['null']]);

        $this->assertInstanceOf(UnionType::class, $type);
        $this->assertSame(['type' => ['null']], $type->toArray());
    }

    public function testItReconstructsAScalarNullType(): void
    {
        $type = JsonSchema::fromArray(['type' => 'null']);

        $this->assertInstanceOf(UnionType::class, $type);
        $this->assertSame(['type' => ['null']], $type->toArray());
    }

    public function testItReconstructsAScalarNullObjectProperty(): void
    {
        $type = JsonSchema::fromArray([
            'type' => 'object',
            'properties' => [
                'value' => ['type' => 'null'],
            ],
            'required' => ['value'],
        ]);

        $this->assertSame([
            'properties' => [
                'value' => ['type' => ['null']],
            ],
            'type' => 'object',
            'required' => ['value'],
        ], $type->toArray());
    }

    public function testItReconstructsScalarNullArrayItems(): void
    {
        $type = JsonSchema::fromArray([
            'type' => 'array',
            'items' => ['type' => 'null'],
        ]);

        $this->assertSame([
            'items' => ['type' => ['null']],
            'type' => 'array',
        ], $type->toArray());
    }

    public function testItReconstructsAScalarNullRefTarget(): void
    {
        $type = JsonSchema::fromArray([
            '$ref' => '#/$defs/nothing',
            '$defs' => [
                'nothing' => ['type' => 'null'],
            ],
        ]);

        $this->assertSame(['type' => ['null']], $type->toArray());
    }

    public function testItPreservesAnnotationsOnANullOnlyTypeArray(): void
    {
        $type = JsonSchema::fromArray([
            'type' => ['null'],
            'title' => 'No value',
            'default' => null,
        ]);

        $this->assertSame([
            'title' => 'No value',
            'default' => null,
            'type' => ['null'],
        ], $type->toArray());
    }

    public function testItPreservesAnnotationsOnAScalarNullType(): void
    {
        $type = JsonSchema::fromArray([
            'type' => 'null',
            'title' => 'No value',
            'default' => null,
        ]);

        $this->assertSame([
            'title' => 'No value',
            'default' => null,
            'type' => ['null'],
        ], $type->toArray());
    }

    public function testItRejectsTypeSpecificConstraintsOnANullOnlyTypeArray(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Type-specific keywords [minLength] are not supported on a JSON Schema union.');

        JsonSchema::fromArray([
            'type' => ['null'],
            'minLength' => 1,
        ]);
    }

    public function testItRejectsTypeSpecificConstraintsOnAScalarNullType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Type-specific keywords [minLength] are not supported on a JSON Schema union.');

        JsonSchema::fromArray([
            'type' => 'null',
            'minLength' => 1,
        ]);
    }

    public function testItPreservesAConstrainedNullBranchInAGeneralAnyOf(): void
    {
        $type = JsonSchema::fromArray([
            'anyOf' => [
                ['type' => 'string'],
                ['type' => 'null', 'title' => 'No value', 'enum' => ['never']],
            ],
        ]);

        $this->assertInstanceOf(AnyOfType::class, $type);
        $this->assertSame([
            'anyOf' => [
                ['type' => 'string'],
                ['title' => 'No value', 'enum' => ['never'], 'type' => ['null']],
            ],
        ], $type->toArray());
    }

    public function testItRejectsANonBareNullBranchInOneOf(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only a nullable "oneOf" (a single schema plus a bare "null" branch) is supported.');

        JsonSchema::fromArray([
            'oneOf' => [
                ['type' => 'string'],
                ['type' => 'null', 'title' => 'No value'],
            ],
        ]);
    }

    public function testItRejectsATypeSpecificKeywordOnANonBareNullBranch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Type-specific keywords [minLength] are not supported on a JSON Schema union.');

        JsonSchema::fromArray([
            'anyOf' => [
                ['type' => 'string'],
                ['type' => 'null', 'minLength' => 2],
            ],
        ]);
    }

    #[DataProvider('unsupportedAssertionProvider')]
    public function testItRejectsUnsupportedJsonSchema202012Assertions(array $schema, string $keyword): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported JSON Schema assertion keywords [{$keyword}] cannot be represented.");

        JsonSchema::fromArray($schema);
    }

    public static function unsupportedAssertionProvider(): array
    {
        return [
            'const' => [['type' => 'string', 'const' => 'fixed'], 'const'],
            'not' => [['type' => 'string', 'not' => ['const' => 'x']], 'not'],
            'allOf' => [['type' => 'string', 'allOf' => [['minLength' => 2]]], 'allOf'],
            'if' => [['type' => 'string', 'if' => ['minLength' => 2]], 'if'],
            'dependentSchemas' => [['type' => 'object', 'dependentSchemas' => ['a' => ['required' => ['b']]]], 'dependentSchemas'],
            'dependentRequired' => [['type' => 'object', 'dependentRequired' => ['a' => ['b']]], 'dependentRequired'],
            'prefixItems' => [['type' => 'array', 'prefixItems' => [['type' => 'string']]], 'prefixItems'],
            'contains' => [['type' => 'array', 'contains' => ['type' => 'string'], 'minContains' => 2], 'contains'],
            'patternProperties' => [['type' => 'object', 'patternProperties' => []], 'patternProperties'],
            'propertyNames' => [['type' => 'object', 'propertyNames' => ['pattern' => '^[a-z]+$']], 'propertyNames'],
            'unevaluatedItems' => [['type' => 'array', 'unevaluatedItems' => false], 'unevaluatedItems'],
            'unevaluatedProperties' => [['type' => 'object', 'unevaluatedProperties' => false], 'unevaluatedProperties'],
            'exclusiveMinimum' => [['type' => 'number', 'exclusiveMinimum' => 0], 'exclusiveMinimum'],
            'exclusiveMaximum' => [['type' => 'number', 'exclusiveMaximum' => 10], 'exclusiveMaximum'],
            'minProperties' => [['type' => 'object', 'minProperties' => 1], 'minProperties'],
            'maxProperties' => [['type' => 'object', 'maxProperties' => 1], 'maxProperties'],
            '$dynamicRef' => [['$dynamicRef' => '#/$defs/value', '$defs' => ['value' => ['type' => 'string']]], '$dynamicRef'],
        ];
    }

    #[DataProvider('ignoredNoOpOrAnnotationProvider')]
    public function testItContinuesToIgnoreNoOpCompanionKeywordsAnnotationsAndExtensions(array $schema, array $expected): void
    {
        $this->assertSame($expected, JsonSchema::fromArray($schema)->toArray());
    }

    public static function ignoredNoOpOrAnnotationProvider(): array
    {
        return [
            'then' => [['type' => 'string', 'then' => ['maxLength' => 1]], ['type' => 'string']],
            'else' => [['type' => 'string', 'else' => ['maxLength' => 1]], ['type' => 'string']],
            'minContains' => [['type' => 'array', 'minContains' => 2], ['type' => 'array']],
            'maxContains' => [['type' => 'array', 'maxContains' => 2], ['type' => 'array']],
            'annotation' => [['type' => 'string', 'readOnly' => true], ['type' => 'string']],
            'extension' => [['type' => 'string', 'x-internal' => true], ['type' => 'string']],
        ];
    }

    public function testItRejectsAnUnsupportedAssertionReachedThroughARef(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported JSON Schema assertion keywords [const] cannot be represented.');

        JsonSchema::fromArray([
            '$ref' => '#/$defs/value',
            '$defs' => [
                'value' => ['type' => 'string', 'const' => 'fixed'],
            ],
        ]);
    }

    public function testItRejectsAnUnsupportedAssertionMergedFromANullableCompositionBranch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported JSON Schema assertion keywords [const] cannot be represented.');

        JsonSchema::fromArray([
            'anyOf' => [
                ['type' => 'string', 'const' => 'fixed'],
                ['type' => 'null'],
            ],
        ]);
    }

    public function testItRejectsAnUnsupportedAssertionOnAGeneralAnyOfBranch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported JSON Schema assertion keywords [const] cannot be represented.');

        JsonSchema::fromArray([
            'anyOf' => [
                ['type' => 'string', 'const' => 'fixed'],
                ['type' => 'integer'],
            ],
        ]);
    }

    public function testItRejectsAnExplicitNullTypeInsteadOfInferringAnotherType(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException(
            'The JSON Schema [type] keyword must be a string or an array of strings.'
        ));

        JsonSchema::fromArray([
            'type' => null,
            'minLength' => 2,
        ]);
    }

    #[DataProvider('nonStringReferenceProvider')]
    public function testItRejectsANonStringReferenceInsteadOfDroppingIt(array $schema): void
    {
        $this->expectExceptionObject(new InvalidArgumentException(
            'The JSON Schema [$ref] keyword must be a string.'
        ));

        JsonSchema::fromArray($schema);
    }

    public static function nonStringReferenceProvider(): array
    {
        return [
            'direct null reference' => [[
                '$ref' => null,
                'type' => 'string',
            ]],
            'non-string reference revealed by a preceding reference' => [[
                '$ref' => '#/$defs/value',
                '$defs' => [
                    'value' => ['$ref' => 123, 'type' => 'string'],
                ],
            ]],
        ];
    }

    #[DataProvider('malformedRecognizedKeywordProvider')]
    public function testItRejectsMalformedRecognizedKeywordValues(array $schema, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        JsonSchema::fromArray($schema);
    }

    public static function malformedRecognizedKeywordProvider(): array
    {
        return [
            'type array member' => [['type' => ['string', 123]], 'The JSON Schema [type] keyword must be a string or an array of strings.'],
            'pattern' => [['type' => 'string', 'pattern' => ['^a']], 'The JSON Schema [pattern] constraint must be a string.'],
            'format' => [['type' => 'string', 'format' => ['date']], 'The JSON Schema [format] annotation must be a string.'],
            'title' => [['type' => 'string', 'title' => ['Name']], 'The JSON Schema [title] annotation must be a string.'],
            'description' => [['type' => 'string', 'description' => ['Name']], 'The JSON Schema [description] annotation must be a string.'],
            'uniqueItems' => [['type' => 'array', 'uniqueItems' => 'false'], 'The JSON Schema [uniqueItems] constraint must be a boolean.'],
            'required' => [['type' => 'object', 'required' => 'name'], 'The JSON Schema [required] keyword must be an array of strings.'],
            'required member' => [[
                'type' => 'object',
                'properties' => ['0' => ['type' => 'string']],
                'required' => [0],
            ], 'The JSON Schema [required] keyword must be an array of strings.'],
            'properties' => [['type' => 'object', 'properties' => 'oops'], 'The JSON Schema [properties] keyword must be an object.'],
            'anyOf' => [['type' => 'string', 'anyOf' => 'oops'], 'The JSON Schema [anyOf] keyword must be a non-empty array.'],
            'oneOf' => [['type' => 'string', 'oneOf' => 'oops'], 'The JSON Schema [oneOf] keyword must be a non-empty array.'],
            'numeric null' => [['type' => 'number', 'minimum' => null], 'The JSON Schema [minimum] constraint must be a number.'],
            'items null' => [['type' => 'array', 'items' => null], 'The JSON Schema [items] keyword must be true or a single object schema.'],
        ];
    }

    #[DataProvider('nullRecognizedKeywordWithoutTypeProvider')]
    public function testItRoutesNullRecognizedKeywordsWithoutATypeToTheirOwningGuard(array $schema, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        JsonSchema::fromArray($schema);
    }

    public static function nullRecognizedKeywordWithoutTypeProvider(): array
    {
        return [
            'properties' => [['properties' => null, 'minLength' => 2], 'The JSON Schema [properties] keyword must be an object.'],
            'required' => [['required' => null, 'minLength' => 2], 'The JSON Schema [required] keyword must be an array of strings.'],
            'items' => [['items' => null, 'minLength' => 2], 'The JSON Schema [items] keyword must be true or a single object schema.'],
            'uniqueItems' => [['uniqueItems' => null, 'minLength' => 2], 'The JSON Schema [uniqueItems] constraint must be a boolean.'],
            'minLength' => [['minLength' => null], 'The JSON Schema [minLength] constraint must be an integer.'],
            'minimum' => [['minimum' => null], 'The JSON Schema [minimum] constraint must be a number.'],
            'enum' => [['enum' => null, 'minLength' => 2], 'The JSON Schema [enum] keyword must be an array.'],
        ];
    }

    public function testItThrowsForABooleanPropertySchema(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Unable to represent the schema for property [meta]; boolean schemas are not supported.'));

        JsonSchema::fromArray([
            'type' => 'object',
            'properties' => [
                'meta' => true,
            ],
        ]);
    }

    public function testItThrowsForANonNumericNumericConstraint(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('The JSON Schema [minimum] constraint must be a number.'));

        JsonSchema::fromArray([
            'type' => 'number',
            'minimum' => 'oops',
        ]);
    }

    public function testItThrowsForANonIntegerIntegerConstraint(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('The JSON Schema integer constraint [1.9] must be an integer.'));

        JsonSchema::fromArray([
            'type' => 'integer',
            'minimum' => 1.9,
        ]);
    }

    public function testItThrowsForTupleItems(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException(
            'The JSON Schema [items] keyword must be true or a single object schema.'
        ));

        JsonSchema::fromArray([
            'type' => 'array',
            'items' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
        ]);
    }

    public function testItThrowsWhenAUnionBranchConflictsWithSiblingKeys(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Conflicting [type] between a "anyOf" branch and its sibling keys.'));

        JsonSchema::fromArray([
            'type' => 'integer',
            'anyOf' => [
                ['type' => 'string', 'minLength' => 3],
                ['type' => 'null'],
            ],
        ]);
    }

    public function testItThrowsForAnUnsupportedOneOfUnion(): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Only a nullable "oneOf" (a single schema plus a bare "null" branch) is supported.'));

        JsonSchema::fromArray([
            'oneOf' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
        ]);
    }

    public function testItPreservesAnExplicitNullDefault(): void
    {
        $type = JsonSchema::fromArray([
            'type' => 'string',
            'default' => null,
        ]);

        $this->assertSame([
            'default' => null,
            'type' => 'string',
        ], $type->toArray());
    }

    public function testItNormalizesAnObjectDefaultEmittedAsAJsonObject(): void
    {
        $type = JsonSchema::fromArray([
            'type' => 'object',
            'default' => (object) [],
        ]);

        $serialized = $type->toArray();

        $this->assertInstanceOf(stdClass::class, $serialized['default']);
        $this->assertSame([], (array) $serialized['default']);
    }

    #[DataProvider('malformedEnumProvider')]
    public function testItRejectsAMalformedEnum(mixed $enum): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The JSON Schema [enum] keyword must be an array.');

        JsonSchema::fromArray([
            'type' => 'string',
            'enum' => $enum,
        ]);
    }

    public static function malformedEnumProvider(): array
    {
        return [
            'string' => ['draft'],
            'object' => [new stdClass],
            'integer' => [1],
            'null' => [null],
        ];
    }

    public function testItPreservesAnEmptyEnum(): void
    {
        // Opis requires a non-empty enum, but JSON Schema 2020-12 permits an empty unsatisfiable enum.
        $this->assertSame([
            'enum' => [],
            'type' => 'string',
        ], JsonSchema::fromArray([
            'type' => 'string',
            'enum' => [],
        ])->toArray());
    }

    public function testItResolvesTheRootRefPointer(): void
    {
        // "#" resolves to the root, so a self-reference is detected as circular...
        $this->expectExceptionObject(new InvalidArgumentException('Circular JSON Schema $ref [#] detected.'));

        JsonSchema::fromArray(['$ref' => '#']);
    }
}

class JsonSchemaDepthLimitedDeserializer extends Deserializer
{
    protected const MAX_REFERENCE_DEPTH = 1;
}

class JsonSchemaNodeLimitedDeserializer extends Deserializer
{
    protected const MAX_NODES = 1;
}
