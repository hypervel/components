<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Schema;

use Hypervel\Ai\Schema\SchemaNormalizer;
use Hypervel\JsonSchema\JsonSchema as JsonSchemaFactory;
use Hypervel\JsonSchema\Serializer;
use Hypervel\JsonSchema\Types\ObjectType;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Assert that a raw schema normalizes into something the deserializer accepts.
 */
function normalizesWithoutThrowing(array $raw): array
{
    $normalized = SchemaNormalizer::normalize($raw);

    JsonSchemaFactory::fromArray($normalized);

    return $normalized;
}

class SchemaNormalizerTest extends TestCase
{
    public function testItPassesAPlainObjectSchemaThroughUnchanged(): void
    {
        $raw = [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string', 'description' => 'A name.']],
            'required' => ['name'],
        ];

        $this->assertSame($raw, SchemaNormalizer::normalize($raw));
    }

    public function testItPreservesANullableAnyOfWhenTheFrameworkSupportsItNatively(): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => [
                'nickname' => ['anyOf' => [['type' => 'string', 'minLength' => 1], ['type' => 'null']]],
            ],
        ]);

        $this->assertSame([
            'anyOf' => [
                ['type' => 'string', 'minLength' => 1],
                ['type' => 'null'],
            ],
        ], $normalized['properties']['nickname']);
    }

    // Upstream's fallback cases cover root projection here; nested compositions retain their alternatives.
    public function testItPreservesANullableOneOfAsAnyOf(): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => [
                'nickname' => ['oneOf' => [['type' => 'string', 'minLength' => 1], ['type' => 'null']]],
            ],
        ]);

        $this->assertSame([
            'anyOf' => [['type' => 'string', 'minLength' => 1], ['type' => 'null']],
        ], $normalized['properties']['nickname']);
    }

    public function testItPreservesConstraintsOnScalarOneOfAlternatives(): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => [
                'choice' => ['oneOf' => [['type' => 'string', 'maxLength' => 5], ['type' => 'integer']]],
            ],
        ]);

        $this->assertSame(['anyOf' => [['type' => 'string', 'maxLength' => 5], ['type' => 'integer']]], $normalized['properties']['choice']);

        // A root with no object alternatives stays unchanged.
        $this->assertSame($normalized['properties']['choice'], normalizesWithoutThrowing($normalized['properties']['choice']));
    }

    public function testItStripsTypeSpecificKeywordsFromAMultiTypeUnionSoItDeserializes(): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => [
                'value' => ['type' => ['string', 'number'], 'minLength' => 3, 'minimum' => 1],
            ],
        ]);

        $this->assertSame(['string', 'number'], $normalized['properties']['value']['type']);
        $this->assertArrayNotHasKey('minLength', $normalized['properties']['value']);
        $this->assertArrayNotHasKey('minimum', $normalized['properties']['value']);
    }

    public function testItMergesAllOfBranchesIntoTheNode(): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => [
                'merged' => ['allOf' => [['type' => 'string'], ['description' => 'Merged.', 'minLength' => 2]]],
            ],
        ]);

        $this->assertSame([
            'type' => 'string',
            'description' => 'Merged.',
            'minLength' => 2,
        ], $normalized['properties']['merged']);
    }

    public function testItDropsKeywordsTheDeserializerCannotRepresent(): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'properties' => [
                'a' => [
                    'type' => 'string',
                    'not' => ['const' => 'x'],
                    'patternProperties' => ['^x' => ['type' => 'string']],
                    'exclusiveMinimum' => 1,
                    'examples' => ['foo'],
                    'propertyNames' => ['pattern' => '^[a-z]+$'],
                    'unevaluatedItems' => false,
                    '$dynamicRef' => '#node',
                ],
            ],
        ]);

        $this->assertArrayNotHasKey('$schema', $normalized);
        $this->assertSame(['type' => 'string'], $normalized['properties']['a']);
    }

    public function testItDropsBooleanPropertySchemas(): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => [
                'anything' => true,
                'name' => ['type' => 'string'],
            ],
            'required' => ['anything', 'name'],
        ]);

        $this->assertArrayHasKey('name', $normalized['properties']);
        $this->assertArrayNotHasKey('anything', $normalized['properties']);
        $this->assertSame(['name'], $normalized['required']);
    }

    public function testItDropsTupleAndBooleanArrayItems(): void
    {
        $tuple = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => ['list' => ['type' => 'array', 'items' => [['type' => 'string'], ['type' => 'integer']]]],
        ]);

        $this->assertArrayNotHasKey('items', $tuple['properties']['list']);

        $single = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => ['list' => ['type' => 'array', 'items' => ['type' => 'string']]],
        ]);

        $this->assertSame(['type' => 'string'], $single['properties']['list']['items']);
    }

    public function testItInlinesLocalRefAndDropsRemoteOrUnresolvableRef(): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => [
                'local' => ['$ref' => '#/$defs/Tag', 'description' => 'A tag.'],
                'remote' => ['$ref' => 'https://example.com/schema.json'],
                'missing' => ['$ref' => '#/$defs/Nope'],
            ],
            '$defs' => [
                'Tag' => ['type' => 'string', 'enum' => ['a', 'b']],
            ],
        ]);

        $this->assertEquals([
            'type' => 'string',
            'enum' => ['a', 'b'],
            'description' => 'A tag.',
        ], $normalized['properties']['local']);
        $this->assertSame(['type' => 'string'], $normalized['properties']['remote']);
        $this->assertSame(['type' => 'string'], $normalized['properties']['missing']);
        $this->assertArrayNotHasKey('$defs', $normalized);
    }

    public function testItBreaksCircularRefWithoutInfiniteRecursion(): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => ['node' => ['$ref' => '#/$defs/Node']],
            '$defs' => [
                'Node' => [
                    'type' => 'object',
                    'properties' => ['child' => ['$ref' => '#/$defs/Node']],
                ],
            ],
        ]);

        $this->assertSame('object', $normalized['properties']['node']['type']);
        $this->assertSame(['type' => 'string'], $normalized['properties']['node']['properties']['child']);
    }

    public function testItPreservesANullDefault(): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => ['flag' => ['type' => 'boolean', 'default' => null]],
        ]);

        $this->assertArrayHasKey('default', $normalized['properties']['flag']);
        $this->assertNull($normalized['properties']['flag']['default']);
    }

    public function testItKeepsAdditionalPropertiesFalseButDropsPermissiveForms(): void
    {
        $normalized = SchemaNormalizer::normalize([
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'open' => ['type' => 'object', 'additionalProperties' => true, 'properties' => ['x' => ['type' => 'string']]],
            ],
        ]);

        $this->assertFalse($normalized['additionalProperties']);
        $this->assertArrayNotHasKey('additionalProperties', $normalized['properties']['open']);
    }

    public function testItAssignsATypeToAnOtherwiseTypelessAllOfRootSoItDoesNotThrow(): void
    {
        $normalized = normalizesWithoutThrowing([
            'allOf' => [['description' => 'x'], ['title' => 'y']],
        ]);

        $this->assertSame('string', $normalized['type']);
        $this->assertArrayNotHasKey('allOf', $normalized);
    }

    public function testItUnionsRequiredAcrossAllOfBranchesAndTheOuterNode(): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'required' => ['c'],
            'properties' => ['c' => ['type' => 'string']],
            'allOf' => [
                ['properties' => ['a' => ['type' => 'string']], 'required' => ['a']],
                ['properties' => ['b' => ['type' => 'integer']], 'required' => ['b']],
            ],
        ]);

        $this->assertEqualsCanonicalizing(['a', 'b', 'c'], $normalized['required']);
        $this->assertArrayHasKey('a', $normalized['properties']);
        $this->assertArrayHasKey('b', $normalized['properties']);
        $this->assertArrayHasKey('c', $normalized['properties']);
    }

    public function testItStripsUnsupportedKeywordsPulledInFromAnAllOfBranch(): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => [
                'n' => ['allOf' => [['type' => 'integer'], ['exclusiveMinimum' => 5, 'patternProperties' => ['^x' => []]]]],
            ],
        ]);

        $this->assertSame(['type' => 'integer'], $normalized['properties']['n']);
    }

    public function testItPreservesObjectShapeInsideNullableAnyOfWhenTheFrameworkSupportsItNatively(): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => [
                'addr' => ['anyOf' => [
                    ['properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
                    ['type' => 'null'],
                ]],
            ],
        ]);

        $this->assertSame('object', $normalized['properties']['addr']['anyOf'][0]['type']);
        $this->assertArrayHasKey('city', $normalized['properties']['addr']['anyOf'][0]['properties']);
    }

    public function testItPreservesObjectShapeAndRequirementsForANullableRootObjectBranch(): void
    {
        $normalized = normalizesWithoutThrowing([
            'anyOf' => [
                ['properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
                ['type' => 'null'],
            ],
        ]);

        $this->assertSame(['object', 'null'], $normalized['type']);
        $this->assertArrayHasKey('city', $normalized['properties']);
        $this->assertSame(['city'], $normalized['required']);
    }

    public function testItPreservesAnyOfCompositionsWhenTheFrameworkDeserializerSupportsThem(): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => [
                'content' => ['anyOf' => [
                    [
                        'type' => 'object',
                        'properties' => ['type' => ['const' => 'article'], 'title' => ['type' => 'string']],
                        'required' => ['type', 'title'],
                    ],
                    [
                        'type' => 'object',
                        'properties' => ['type' => ['const' => 'image'], 'url' => ['type' => 'string']],
                        'required' => ['type', 'url'],
                    ],
                ]],
            ],
        ]);

        $this->assertCount(2, $normalized['properties']['content']['anyOf']);
        $this->assertSame([
            'enum' => ['article'],
            'type' => 'string',
        ], $normalized['properties']['content']['anyOf'][0]['properties']['type']);
    }

    public function testItFoldsOuterObjectSiblingsIntoEachBranchSoTheDeserializerKeepsThem(): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => ['value' => [
                'type' => 'object',
                'properties' => ['kind' => ['type' => 'string']],
                'required' => ['kind'],
                'anyOf' => [
                    ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']],
                    ['type' => 'object', 'properties' => ['age' => ['type' => 'integer']], 'required' => ['age']],
                ],
            ]],
        ]);

        $normalized = $normalized['properties']['value'];

        $this->assertArrayNotHasKey('properties', $normalized);
        $this->assertCount(2, $normalized['anyOf']);

        $this->assertArrayHasKey('kind', $normalized['anyOf'][0]['properties']);
        $this->assertArrayHasKey('name', $normalized['anyOf'][0]['properties']);
        $this->assertEqualsCanonicalizing(['kind', 'name'], $normalized['anyOf'][0]['required']);
        $this->assertArrayHasKey('kind', $normalized['anyOf'][1]['properties']);
        $this->assertArrayHasKey('age', $normalized['anyOf'][1]['properties']);
        $this->assertEqualsCanonicalizing(['kind', 'age'], $normalized['anyOf'][1]['required']);
    }

    public function testItKeepsTheSharedBasePropertyAfterRoundTrippingASiblingAnyOfThroughTheDeserializer(): void
    {
        $type = JsonSchemaFactory::fromArray(SchemaNormalizer::normalize([
            'type' => 'object',
            'properties' => ['value' => [
                'type' => 'object',
                'properties' => ['kind' => ['type' => 'string']],
                'required' => ['kind'],
                'anyOf' => [
                    ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']],
                    ['type' => 'object', 'properties' => ['age' => ['type' => 'integer']], 'required' => ['age']],
                ],
            ]],
        ]));

        $this->assertStringContainsString('"kind"', json_encode(Serializer::serialize($type)));
    }

    public function testItKeepsTheOuterDescriptionOnAPreservedAnyOfComposition(): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => ['value' => [
                'description' => 'A union.',
                'anyOf' => [['type' => 'string'], ['type' => 'integer']],
            ]],
        ]);

        $this->assertSame('A union.', $normalized['properties']['value']['description']);
        $this->assertCount(2, $normalized['properties']['value']['anyOf']);
    }

    public function testItDropsEmptyBranchesFromAPreservedAnyOfInsteadOfInventingAStringBranch(): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => [
                'v' => ['anyOf' => [[], ['type' => 'string'], ['type' => 'integer']]],
            ],
        ]);

        $this->assertSame([
            ['type' => 'string'],
            ['type' => 'integer'],
        ], $normalized['properties']['v']['anyOf']);
    }

    public function testItDropsACyclicAnyOfBranchInsteadOfInventingAStringBranch(): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => ['node' => ['$ref' => '#/$defs/Node']],
            '$defs' => [
                'Node' => ['anyOf' => [['$ref' => '#/$defs/Node'], ['type' => 'integer']]],
            ],
        ]);

        $this->assertSame([['type' => 'integer']], $normalized['properties']['node']['anyOf']);
    }

    public function testItDropsAPreservedAnyOfMadeUpEntirelyOfNullBranches(): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => [
                'v' => ['anyOf' => [['type' => 'null'], ['type' => 'null']]],
            ],
        ]);

        $this->assertArrayNotHasKey('anyOf', $normalized['properties']['v']);
        $this->assertSame('string', $normalized['properties']['v']['type']);
    }

    #[DataProvider('enumTypes')]
    public function testItTypesHeterogeneousEmptyAndHomogeneousEnumsWithoutThrowing(array $enum, string $expected): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => ['e' => ['enum' => $enum]],
        ]);

        $this->assertSame($expected, $normalized['properties']['e']['type']);
    }

    /**
     * Provide enum types.
     */
    public static function enumTypes(): array
    {
        return [
            'heterogeneous' => [['low', 0, true], 'string'],
            'empty' => [[], 'string'],
            'homogeneous int' => [[1, 2, 3], 'integer'],
        ];
    }

    #[DataProvider('nullTypes')]
    public function testItReplacesANullOnlyTypeWithAScalarSoItDeserializes(array $raw): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => ['n' => $raw],
        ]);

        $this->assertSame('string', $normalized['properties']['n']['type']);
    }

    /**
     * Provide null-only type declarations.
     */
    public static function nullTypes(): array
    {
        return [
            'scalar null' => [['type' => 'null']],
            'null-only array' => [['type' => ['null']]],
        ];
    }

    public function testItReInfersATypeWhenTheDeclaredTypeIsNotAKnownJsonSchemaType(): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => [
                'a' => ['type' => 'frobnicate'],
                'b' => ['type' => 'widget', 'properties' => ['x' => ['type' => 'string']]],
            ],
        ]);

        $this->assertSame('string', $normalized['properties']['a']['type']);
        $this->assertSame('object', $normalized['properties']['b']['type']);
        $this->assertArrayHasKey('x', $normalized['properties']['b']['properties']);
    }

    public function testItDropsUnknownMembersFromAMultiTypeUnionButKeepsTheRest(): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => [
                'v' => ['type' => ['string', 'frobnicate', 'integer']],
                'n' => ['type' => ['string', 'frobnicate', 'null']],
                'only' => ['type' => ['frobnicate']],
            ],
        ]);

        $this->assertSame(['string', 'integer'], $normalized['properties']['v']['type']);
        $this->assertSame(['string', 'null'], $normalized['properties']['n']['type']);
        $this->assertSame('string', $normalized['properties']['only']['type']);
    }

    public function testItProducesAnObjectTypeForAGnarlyRealWorldSchema(): void
    {
        $type = JsonSchemaFactory::fromArray(SchemaNormalizer::normalize([
            '$schema' => 'http://json-schema.org/draft-07/schema#',
            'type' => 'object',
            'properties' => [
                'mode' => ['oneOf' => [['const' => 'fast'], ['const' => 'slow']]],
                'value' => ['type' => ['string', 'number', 'boolean']],
                'tags' => ['type' => 'array', 'items' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]]],
                'opts' => ['allOf' => [['type' => 'object'], ['properties' => ['x' => ['type' => 'integer']]]]],
            ],
            'required' => ['value'],
            'additionalProperties' => false,
        ]));

        $this->assertInstanceOf(ObjectType::class, $type);
    }

    public function testItTerminatesOnARecursiveAllOfInsteadOfHanging(): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => ['node' => ['$ref' => '#/$defs/Node']],
            '$defs' => [
                'Node' => [
                    'type' => 'object',
                    'allOf' => [['$ref' => '#/$defs/Node']],
                    'properties' => ['v' => ['type' => 'string']],
                ],
            ],
        ]);

        $this->assertSame('object', $normalized['properties']['node']['type']);
        $this->assertArrayHasKey('v', $normalized['properties']['node']['properties']);
    }

    public function testItTerminatesOnANullableRecursiveUnionInsteadOfHanging(): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => ['node' => ['$ref' => '#/$defs/Node']],
            '$defs' => [
                'Node' => [
                    'type' => 'object',
                    'properties' => [
                        'child' => ['anyOf' => [['$ref' => '#/$defs/Node'], ['type' => 'null']]],
                    ],
                ],
            ],
        ]);

        $this->assertArrayHasKey('child', $normalized['properties']['node']['properties']);
    }

    public function testItResolvesArbitraryLocalJsonPointersIncludingEscapedKeys(): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => [
                'a' => ['$ref' => '#/$defs/a~1b'],
                'b' => ['$ref' => '#/properties/a'],
            ],
            '$defs' => [
                'a/b' => ['type' => 'integer', 'minimum' => 1],
            ],
        ]);

        $this->assertSame(['type' => 'integer', 'minimum' => 1], $normalized['properties']['a']);
        $this->assertSame(['type' => 'integer', 'minimum' => 1], $normalized['properties']['b']);
    }

    public function testItConvertsConstToASingleValueEnum(): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => ['mode' => ['const' => 'fast']],
        ]);

        $this->assertSame(['enum' => ['fast'], 'type' => 'string'], $normalized['properties']['mode']);
    }

    #[DataProvider('additionalProperties')]
    public function testItInfersObjectTypeFromAdditionalProperties(array $raw): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => ['opts' => $raw],
        ]);

        $this->assertSame('object', $normalized['properties']['opts']['type']);
    }

    /**
     * Provide additional-property declarations that imply an object.
     */
    public static function additionalProperties(): array
    {
        return [
            'schema form' => [['additionalProperties' => ['type' => 'string']]],
            'false form' => [['additionalProperties' => false]],
        ];
    }

    public function testItMergesAllRootObjectVariantsInsteadOfDiscardingAllButTheFirst(): void
    {
        $item = normalizesWithoutThrowing([
            'anyOf' => [
                [
                    'type' => 'object',
                    'properties' => ['type' => ['type' => 'string', 'enum' => ['open']], 'text' => ['type' => 'string']],
                    'required' => ['type', 'text'],
                ],
                [
                    'type' => 'object',
                    'properties' => ['type' => ['type' => 'string', 'enum' => ['multiple_choice']], 'choices' => ['type' => 'array']],
                    'required' => ['type', 'choices'],
                ],
                [
                    'type' => 'object',
                    'properties' => ['type' => ['type' => 'string', 'enum' => ['rating']], 'scale' => ['type' => 'integer']],
                    'required' => ['type'],
                ],
            ],
        ]);

        $this->assertSame('object', $item['type']);
        $this->assertArrayHasKey('type', $item['properties']);
        $this->assertArrayHasKey('text', $item['properties']);
        $this->assertArrayHasKey('choices', $item['properties']);
        $this->assertArrayHasKey('scale', $item['properties']);
        $this->assertSame(['type'], $item['required']);
    }

    public function testItFallsBackToTheSingleObjectBranchWhenFewerThanTwoObjectVariantsArePresent(): void
    {
        $normalized = normalizesWithoutThrowing([
            'anyOf' => [
                ['type' => 'object', 'properties' => ['x' => ['type' => 'string']], 'required' => ['x']],
                ['type' => 'string'],
            ],
        ]);

        $this->assertSame('object', $normalized['type']);
        $this->assertArrayHasKey('x', $normalized['properties']);
        $this->assertArrayNotHasKey('y', $normalized['properties']);
        $this->assertSame(['x'], $normalized['required']);
    }

    public function testItMergesTheObjectVariantsAndIgnoresScalarBranchesWhenTwoOrMoreObjectsArePresent(): void
    {
        $normalized = normalizesWithoutThrowing([
            'anyOf' => [
                ['type' => 'object', 'properties' => ['x' => ['type' => 'string']]],
                ['type' => 'object', 'properties' => ['y' => ['type' => 'integer']]],
                ['type' => 'string'],
            ],
        ]);

        $this->assertSame('object', $normalized['type']);
        $this->assertArrayHasKey('x', $normalized['properties']);
        $this->assertArrayHasKey('y', $normalized['properties']);
    }

    public function testItPreservesBranchRefinementsOfAGenericOuterProperty(): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => ['kind' => ['type' => 'string']],
            'anyOf' => [
                ['type' => 'object', 'properties' => ['kind' => ['const' => 'a']]],
                ['type' => 'object', 'properties' => ['kind' => ['const' => 'b']]],
            ],
        ]);

        $this->assertSame([
            ['type' => 'string', 'enum' => ['a']],
            ['type' => 'string', 'enum' => ['b']],
        ], $normalized['properties']['kind']['anyOf']);
    }

    public function testItPreservesNestedObjectAlternativesAcrossRootVariants(): void
    {
        $normalized = normalizesWithoutThrowing([
            'anyOf' => [
                ['type' => 'object', 'properties' => ['payload' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]]],
                ['type' => 'object', 'properties' => ['payload' => ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]]]],
            ],
        ]);

        $this->assertSame([
            ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]],
            ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]],
        ], $normalized['properties']['payload']['anyOf']);
    }

    public function testItPreservesRequiredNamesOnEachNestedObjectAlternative(): void
    {
        $normalized = normalizesWithoutThrowing([
            'anyOf' => [
                ['type' => 'object', 'properties' => ['payload' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'string']], 'required' => ['a']]]],
                ['type' => 'object', 'properties' => ['payload' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'c' => ['type' => 'string']], 'required' => ['a']]]],
                ['type' => 'object', 'properties' => ['payload' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'd' => ['type' => 'string']]]]],
            ],
        ]);

        $this->assertSame(['a'], $normalized['properties']['payload']['anyOf'][0]['required']);
        $this->assertSame(['a'], $normalized['properties']['payload']['anyOf'][1]['required']);
        $this->assertArrayNotHasKey('required', $normalized['properties']['payload']['anyOf'][2]);
    }

    public function testItPreservesOpenAndClosedNestedObjectAlternatives(): void
    {
        $normalized = normalizesWithoutThrowing([
            'anyOf' => [
                ['type' => 'object', 'properties' => ['payload' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'additionalProperties' => false]]],
                ['type' => 'object', 'properties' => ['payload' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]]],
            ],
        ]);

        $this->assertFalse($normalized['properties']['payload']['anyOf'][0]['additionalProperties']);
        $this->assertArrayNotHasKey('additionalProperties', $normalized['properties']['payload']['anyOf'][1]);
    }

    public function testItPreservesClosedNestedObjectAlternatives(): void
    {
        $normalized = normalizesWithoutThrowing([
            'anyOf' => [
                ['type' => 'object', 'properties' => ['payload' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'additionalProperties' => false]]],
                ['type' => 'object', 'properties' => ['payload' => ['type' => 'object', 'properties' => ['b' => ['type' => 'string']], 'additionalProperties' => false]]],
            ],
        ]);

        $this->assertFalse($normalized['properties']['payload']['anyOf'][0]['additionalProperties']);
        $this->assertFalse($normalized['properties']['payload']['anyOf'][1]['additionalProperties']);
    }

    public function testItPreservesScalarPropertyAlternativesWhenTheSameKeyDiffersAcrossRootVariants(): void
    {
        $normalized = normalizesWithoutThrowing([
            'anyOf' => [
                ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
                ['type' => 'object', 'properties' => ['id' => ['type' => 'integer']]],
            ],
        ]);

        $this->assertSame([['type' => 'string'], ['type' => 'integer']], $normalized['properties']['id']['anyOf']);
    }

    public function testItKeepsBothNestedObjectAndScalarPropertyAlternatives(): void
    {
        $normalized = normalizesWithoutThrowing([
            'anyOf' => [
                ['type' => 'object', 'properties' => ['id' => ['type' => 'object', 'properties' => ['n' => ['type' => 'string']]]]],
                ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
            ],
        ]);

        $this->assertSame([
            ['type' => 'object', 'properties' => ['n' => ['type' => 'string']]],
            ['type' => 'string'],
        ], $normalized['properties']['id']['anyOf']);
    }

    public function testItDoesNotThrowWhenAnObjectVariantHasAMalformedScalarPropertiesValue(): void
    {
        $normalized = normalizesWithoutThrowing([
            'anyOf' => [
                ['type' => 'object', 'properties' => 'malformed-scalar'],
                ['type' => 'object', 'properties' => ['b' => ['type' => 'string']]],
            ],
        ]);

        $this->assertArrayHasKey('b', $normalized['properties']);
    }

    public function testItPreservesOuterSchemaPropertiesAndRequiredWhenMergingRootObjectVariants(): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => ['kind' => ['type' => 'string']],
            'required' => ['kind'],
            'anyOf' => [
                ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']],
                ['type' => 'object', 'properties' => ['age' => ['type' => 'integer']], 'required' => ['age']],
            ],
        ]);

        $this->assertArrayHasKey('kind', $normalized['properties']);
        $this->assertArrayHasKey('name', $normalized['properties']);
        $this->assertArrayHasKey('age', $normalized['properties']);
        $this->assertContains('kind', $normalized['required']);
    }

    public function testItDoesNotRequirePropertiesWhenARootAlternativeHasNoRequiredKey(): void
    {
        $item = normalizesWithoutThrowing([
            'anyOf' => [
                ['type' => 'object', 'properties' => ['type' => ['type' => 'string'], 'text' => ['type' => 'string']], 'required' => ['type', 'text']],
                ['type' => 'object', 'properties' => ['type' => ['type' => 'string'], 'choices' => ['type' => 'array']], 'required' => ['type']],
                ['type' => 'object', 'properties' => ['type' => ['type' => 'string'], 'scale' => ['type' => 'integer']]],
            ],
        ]);

        $this->assertSame('object', $item['type']);
        $this->assertArrayHasKey('type', $item['properties']);
        $this->assertArrayHasKey('text', $item['properties']);
        $this->assertArrayHasKey('choices', $item['properties']);
        $this->assertArrayHasKey('scale', $item['properties']);
        $this->assertArrayNotHasKey('required', $item);
    }

    public function testItMergesNullableObjectVariantsAndMarksResultNullable(): void
    {
        $normalized = normalizesWithoutThrowing([
            'anyOf' => [
                ['type' => ['object', 'null'], 'properties' => ['x' => ['type' => 'string']]],
                ['type' => 'object', 'properties' => ['y' => ['type' => 'integer']]],
            ],
        ]);

        $this->assertSame(['object', 'null'], $normalized['type']);
        $this->assertArrayHasKey('x', $normalized['properties']);
        $this->assertArrayHasKey('y', $normalized['properties']);
    }

    public function testItDoesNotPromoteRequiredFieldsWhenOnlyOneOfMultipleBranchesDeclaresRequired(): void
    {
        $normalized = normalizesWithoutThrowing([
            'anyOf' => [
                ['type' => 'object', 'properties' => ['id' => ['type' => 'string'], 'name' => ['type' => 'string']], 'required' => ['id']],
                ['type' => 'object', 'properties' => ['code' => ['type' => 'string']]],
            ],
        ]);

        $this->assertArrayNotHasKey('required', $normalized);
        $this->assertArrayHasKey('id', $normalized['properties']);
        $this->assertArrayHasKey('name', $normalized['properties']);
        $this->assertArrayHasKey('code', $normalized['properties']);
    }

    public function testItPreservesEnumAlternativesForOverlappingDiscriminatorProperties(): void
    {
        $normalized = normalizesWithoutThrowing([
            'anyOf' => [
                ['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'enum' => ['open']], 'text' => ['type' => 'string']]],
                ['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'enum' => ['choice']], 'choices' => ['type' => 'array']]],
                ['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'enum' => ['rating']], 'scale' => ['type' => 'integer']]],
            ],
        ]);

        $this->assertSame([
            ['type' => 'string', 'enum' => ['open']],
            ['type' => 'string', 'enum' => ['choice']],
            ['type' => 'string', 'enum' => ['rating']],
        ], $normalized['properties']['kind']['anyOf']);
        $this->assertArrayHasKey('kind', $normalized['properties']);
        $this->assertArrayHasKey('text', $normalized['properties']);
        $this->assertArrayHasKey('choices', $normalized['properties']);
        $this->assertArrayHasKey('scale', $normalized['properties']);
    }

    public function testItDoesNotBleedBranchSpecificKeysLikeAdditionalPropertiesIntoTheMergedResult(): void
    {
        $normalized = normalizesWithoutThrowing([
            'anyOf' => [
                ['type' => 'object', 'properties' => ['x' => ['type' => 'string']], 'additionalProperties' => false],
                ['type' => 'object', 'properties' => ['y' => ['type' => 'integer']]],
            ],
        ]);

        $this->assertArrayHasKey('x', $normalized['properties']);
        $this->assertArrayHasKey('y', $normalized['properties']);
        $this->assertArrayNotHasKey('additionalProperties', $normalized);
    }

    public function testItPrefersTheObjectBranchOverAScalarBranchRegardlessOfBranchOrder(): void
    {
        $stringFirst = normalizesWithoutThrowing([
            'anyOf' => [
                ['type' => 'string'],
                ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']],
            ],
        ]);

        $objectFirst = normalizesWithoutThrowing([
            'anyOf' => [
                ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id']],
                ['type' => 'string'],
            ],
        ]);

        $this->assertSame('object', $stringFirst['type']);
        $this->assertArrayHasKey('id', $stringFirst['properties']);
        $this->assertSame(['id'], $stringFirst['required']);
        $this->assertSame($stringFirst, $objectFirst);
    }

    public function testItSkipsAnEmptyNoOpinionBranchWhenIntersectingRequiredAcrossObjectVariants(): void
    {
        $normalized = normalizesWithoutThrowing([
            'anyOf' => [
                ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'enum' => ['behavioral']], 'key' => ['type' => 'string'], 'value' => ['type' => 'string'], 'event_type' => ['type' => 'string']], 'required' => ['type', 'key', 'value', 'event_type']],
                ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'enum' => ['person']], 'key' => ['type' => 'string'], 'value' => ['type' => 'string']], 'required' => ['type', 'key', 'value']],
                ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'enum' => ['cohort']], 'key' => ['type' => 'string']], 'required' => ['type', 'key']],
                [],
            ],
        ]);

        $this->assertSame('object', $normalized['type']);
        $this->assertSame(['type', 'key'], $normalized['required']);
        $this->assertSame([
            ['type' => 'string', 'enum' => ['behavioral']],
            ['type' => 'string', 'enum' => ['person']],
            ['type' => 'string', 'enum' => ['cohort']],
        ], $normalized['properties']['type']['anyOf']);
    }

    public function testItSkipsALeadingEmptyBranchInsteadOfDegradingTheUnionToAString(): void
    {
        $normalized = normalizesWithoutThrowing([
            'anyOf' => [
                [],
                ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']],
                ['type' => 'object', 'properties' => ['b' => ['type' => 'integer']], 'required' => ['b']],
            ],
        ]);

        $this->assertSame('object', $normalized['type']);
        $this->assertArrayHasKey('a', $normalized['properties']);
        $this->assertArrayHasKey('b', $normalized['properties']);
    }

    public function testItCarriesTheFirstObjectBranchDescriptionIntoTheMergedResult(): void
    {
        $normalized = normalizesWithoutThrowing([
            'anyOf' => [
                ['type' => 'object', 'description' => 'Variant A', 'properties' => ['a' => ['type' => 'string']]],
                ['type' => 'object', 'properties' => ['b' => ['type' => 'integer']]],
            ],
        ]);

        $this->assertSame('Variant A', $normalized['description']);
        $this->assertArrayHasKey('a', $normalized['properties']);
        $this->assertArrayHasKey('b', $normalized['properties']);
    }

    public function testItKeepsAdditionalPropertiesFalseOnlyWhenEveryMergedObjectBranchAgrees(): void
    {
        $unanimous = normalizesWithoutThrowing([
            'anyOf' => [
                ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'additionalProperties' => false],
                ['type' => 'object', 'properties' => ['b' => ['type' => 'integer']], 'additionalProperties' => false],
            ],
        ]);

        $mixed = normalizesWithoutThrowing([
            'anyOf' => [
                ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'additionalProperties' => false],
                ['type' => 'object', 'properties' => ['b' => ['type' => 'integer']]],
            ],
        ]);

        $this->assertFalse($unanimous['additionalProperties']);
        $this->assertArrayNotHasKey('additionalProperties', $mixed);
    }

    public function testItDeepMergesOverlappingPropertiesAcrossAllOfBranches(): void
    {
        $normalized = normalizesWithoutThrowing([
            'allOf' => [
                ['properties' => ['value' => ['type' => 'string', 'minLength' => 5]]],
                ['properties' => ['value' => ['type' => 'string', 'maxLength' => 10]]],
            ],
        ]);

        $this->assertSame([
            'type' => 'string',
            'minLength' => 5,
            'maxLength' => 10,
        ], $normalized['properties']['value']);
    }

    public function testItInlinesARefSharedBySiblingPropertiesAtEveryUse(): void
    {
        $normalized = SchemaNormalizer::normalize([
            'type' => 'object',
            'properties' => [
                'billing' => ['$ref' => '#/$defs/Address'],
                'shipping' => ['$ref' => '#/$defs/Address', 'description' => 'Ship to.'],
                'returns' => ['type' => 'array', 'items' => ['$ref' => '#/$defs/Address']],
            ],
            '$defs' => [
                'Address' => [
                    'type' => 'object',
                    'properties' => ['city' => ['type' => 'string']],
                    'required' => ['city'],
                ],
            ],
        ]);

        $address = [
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string']],
            'required' => ['city'],
        ];

        $this->assertSame([
            'type' => 'object',
            'properties' => [
                'billing' => $address,
                'shipping' => [...$address, 'description' => 'Ship to.'],
                'returns' => ['type' => 'array', 'items' => $address],
            ],
        ], $normalized);
    }

    public function testItRejectsASchemaWhoseSharedRefExpansionExceedsTheNodeBudget(): void
    {
        $defs = ['a13' => ['type' => 'string']];

        for ($i = 0; $i < 13; ++$i) {
            $defs["a{$i}"] = [
                'type' => 'object',
                'properties' => [
                    'left' => ['$ref' => '#/$defs/a' . ($i + 1)],
                    'right' => ['$ref' => '#/$defs/a' . ($i + 1)],
                ],
            ];
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Schema expands to more than 10000 nodes.');

        SchemaNormalizer::normalize(['$ref' => '#/$defs/a0', '$defs' => $defs]);
    }

    public function testItPreservesANestedNullableAnyOf(): void
    {
        $raw = [
            'type' => 'object',
            'properties' => [
                'location' => ['anyOf' => [
                    ['anyOf' => [
                        ['type' => 'object', 'properties' => ['city' => ['type' => 'string']]],
                        ['type' => 'object', 'properties' => ['country' => ['type' => 'string']]],
                    ]],
                    ['type' => 'null'],
                ]],
            ],
        ];

        $this->assertSame($raw, normalizesWithoutThrowing($raw));
    }

    public function testItPrunesRequiredPropertiesWithoutPropertySchemas(): void
    {
        $normalized = normalizesWithoutThrowing([
            'type' => 'object',
            'properties' => [
                'options' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'string'],
                    'required' => ['source'],
                ],
            ],
        ]);

        $this->assertSame(['type' => 'object', 'required' => []], $normalized['properties']['options']);
    }

    public function testRequiredOnlyBranchesUseSharedPropertiesBeforePruning(): void
    {
        $schema = [
            'type' => 'object',
            'properties' => [
                'url' => ['type' => 'string'],
                'path' => ['type' => 'string'],
                'ref' => ['type' => 'string'],
            ],
            'oneOf' => [['required' => ['url', 'ref']], ['required' => ['path', 'ref']]],
        ];

        $root = normalizesWithoutThrowing($schema);
        $this->assertInstanceOf(ObjectType::class, JsonSchemaFactory::fromArray($root));
        $this->assertSame($schema['properties'], $root['properties']);
        $this->assertSame(['ref'], $root['required']);

        $nested = normalizesWithoutThrowing(['type' => 'object', 'properties' => ['source' => $schema]]);
        $this->assertSame(['url', 'ref'], $nested['properties']['source']['anyOf'][0]['required']);
        $this->assertSame(['path', 'ref'], $nested['properties']['source']['anyOf'][1]['required']);
    }

    public function testRootProjectionKeepsParametersThroughNestedAlternatives(): void
    {
        $root = normalizesWithoutThrowing(['anyOf' => [
            ['anyOf' => [
                ['type' => 'object', 'properties' => ['url' => ['type' => 'string']], 'required' => ['url']],
                ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']],
            ]],
            ['type' => 'null'],
        ]]);

        $this->assertInstanceOf(ObjectType::class, JsonSchemaFactory::fromArray($root));
        $this->assertSame(['object', 'null'], $root['type']);
        $this->assertSame(['url' => ['type' => 'string'], 'path' => ['type' => 'string']], $root['properties']);
        $this->assertArrayNotHasKey('required', $root);
    }

    public function testRootProjectionPreservesDistinctComposedPropertyAlternatives(): void
    {
        $composed = ['anyOf' => [['type' => 'string'], ['type' => 'integer']]];
        $root = normalizesWithoutThrowing(['anyOf' => [
            ['type' => 'object', 'properties' => ['value' => $composed]],
            ['type' => 'object', 'properties' => ['value' => ['type' => 'boolean']]],
            ['type' => 'object', 'properties' => ['value' => $composed]],
        ]]);

        $this->assertInstanceOf(ObjectType::class, JsonSchemaFactory::fromArray($root));
        $this->assertSame(['anyOf' => [$composed, ['type' => 'boolean']]], $root['properties']['value']);
    }

    public function testNestedOneOfKeepsValuesDescriptionsAndNonObjectAlternatives(): void
    {
        $normalized = normalizesWithoutThrowing(['type' => 'object', 'properties' => [
            'mode' => ['oneOf' => [
                ['type' => 'string', 'const' => 'fast', 'description' => 'Fast mode.'],
                ['type' => 'string', 'const' => 'slow', 'description' => 'Slow mode.'],
            ]],
            'target' => ['oneOf' => [
                ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
                ['type' => 'array', 'items' => ['type' => 'string']],
            ]],
        ]]);

        $this->assertSame([
            ['type' => 'string', 'description' => 'Fast mode.', 'enum' => ['fast']],
            ['type' => 'string', 'description' => 'Slow mode.', 'enum' => ['slow']],
        ], $normalized['properties']['mode']['anyOf']);
        $this->assertSame([
            ['type' => 'object', 'properties' => ['id' => ['type' => 'string']]],
            ['type' => 'array', 'items' => ['type' => 'string']],
        ], $normalized['properties']['target']['anyOf']);
    }
}
