<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Gateway\Anthropic;

use Hypervel\Ai\Gateway\Anthropic\AnthropicSchemaSanitizer;
use Hypervel\Ai\ObjectSchema;
use Hypervel\JsonSchema\JsonSchema;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class AnthropicSchemaSanitizerTest extends TestCase
{
    public function testStripsNumericConstraintsAndFoldsThemIntoTheDescription(): void
    {
        $result = AnthropicSchemaSanitizer::sanitize([
            'type' => 'integer',
            'minimum' => 1,
            'maximum' => 10,
        ]);

        $this->assertArrayNotHasKey('minimum', $result);
        $this->assertArrayNotHasKey('maximum', $result);
        $this->assertSame('integer', $result['type']);
        $this->assertSame('Must be at least 1. Must be at most 10.', $result['description']);
    }

    public function testStripsMultipleOfAndExclusiveBounds(): void
    {
        $result = AnthropicSchemaSanitizer::sanitize([
            'type' => 'number',
            'multipleOf' => 5,
            'exclusiveMinimum' => 0,
            'exclusiveMaximum' => 100,
        ]);

        $this->assertArrayNotHasKey('multipleOf', $result);
        $this->assertArrayNotHasKey('exclusiveMinimum', $result);
        $this->assertArrayNotHasKey('exclusiveMaximum', $result);
        $this->assertSame('Must be greater than 0. Must be less than 100. Must be a multiple of 5.', $result['description']);
    }

    public function testDropsNonNumericExclusiveBoundsWithoutDescribingAWrongBound(): void
    {
        $result = AnthropicSchemaSanitizer::sanitize([
            'type' => 'number',
            'minimum' => 1,
            'exclusiveMinimum' => true,
        ]);

        $this->assertArrayNotHasKey('minimum', $result);
        $this->assertArrayNotHasKey('exclusiveMinimum', $result);
        $this->assertSame('Must be at least 1.', $result['description']);
    }

    public function testStripsStringLengthConstraintsWithCorrectPluralization(): void
    {
        $result = AnthropicSchemaSanitizer::sanitize([
            'type' => 'string',
            'minLength' => 1,
            'maxLength' => 255,
        ]);

        $this->assertArrayNotHasKey('minLength', $result);
        $this->assertArrayNotHasKey('maxLength', $result);
        $this->assertSame('Must be at least 1 character. Must be at most 255 characters.', $result['description']);
    }

    public function testStripsPatternAndFoldsItIntoTheDescription(): void
    {
        $result = AnthropicSchemaSanitizer::sanitize([
            'type' => 'string',
            'pattern' => '^[a-z]+$',
        ]);

        $this->assertArrayNotHasKey('pattern', $result);
        $this->assertSame('Must match the pattern ^[a-z]+$.', $result['description']);
    }

    public function testStripsMaxItemsAndFoldsItIntoTheDescription(): void
    {
        $result = AnthropicSchemaSanitizer::sanitize([
            'type' => 'array',
            'maxItems' => 5,
            'items' => ['type' => 'string'],
        ]);

        $this->assertArrayNotHasKey('maxItems', $result);
        $this->assertSame('Must contain at most 5 items.', $result['description']);
    }

    public function testClampsMinItemsGreaterThanOneDownToOne(): void
    {
        $result = AnthropicSchemaSanitizer::sanitize([
            'type' => 'array',
            'minItems' => 3,
            'maxItems' => 7,
            'items' => ['type' => 'string'],
        ]);

        $this->assertSame(1, $result['minItems']);
        $this->assertSame('Must contain at least 3 items. Must contain at most 7 items.', $result['description']);
    }

    #[DataProvider('supportedMinimumItems')]
    public function testLeavesSupportedMinItemsValuesUntouched(int $value): void
    {
        $result = AnthropicSchemaSanitizer::sanitize([
            'type' => 'array',
            'minItems' => $value,
            'items' => ['type' => 'string'],
        ]);

        $this->assertSame($value, $result['minItems']);
        $this->assertArrayNotHasKey('description', $result);
    }

    /**
     * Provide supported minimum item counts.
     */
    public static function supportedMinimumItems(): array
    {
        return [[0], [1]];
    }

    public function testStripsUniqueItemsAndNotesItWhenTrue(): void
    {
        $result = AnthropicSchemaSanitizer::sanitize([
            'type' => 'array',
            'uniqueItems' => true,
            'items' => ['type' => 'string'],
        ]);

        $this->assertArrayNotHasKey('uniqueItems', $result);
        $this->assertSame('All items must be unique.', $result['description']);
    }

    public function testStripsUnsupportedFormatsButKeepsSupportedOnes(): void
    {
        $unsupported = AnthropicSchemaSanitizer::sanitize([
            'type' => 'string',
            'format' => 'phone',
        ]);

        $this->assertArrayNotHasKey('format', $unsupported);
        $this->assertSame('Format: phone.', $unsupported['description']);

        $supported = AnthropicSchemaSanitizer::sanitize([
            'type' => 'string',
            'format' => 'email',
        ]);

        $this->assertSame('email', $supported['format']);
        $this->assertArrayNotHasKey('description', $supported);
    }

    public function testStripsObjectConstraintsAndFoldsThemIntoTheDescription(): void
    {
        $result = AnthropicSchemaSanitizer::sanitize([
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'minProperties' => 2,
            'maxProperties' => 4,
            'patternProperties' => ['^x' => ['type' => 'string']],
        ]);

        $this->assertArrayNotHasKey('minProperties', $result);
        $this->assertArrayNotHasKey('maxProperties', $result);
        $this->assertArrayNotHasKey('patternProperties', $result);
        $this->assertSame('Must have at least 2 properties. Must have at most 4 properties.', $result['description']);
    }

    public function testStripsArrayConstraintsBeyondASupportedMinItems(): void
    {
        $result = AnthropicSchemaSanitizer::sanitize([
            'type' => 'array',
            'items' => ['type' => 'string'],
            'contains' => ['type' => 'string'],
            'minContains' => 2,
            'maxContains' => 4,
            'prefixItems' => [['type' => 'string']],
            'unevaluatedItems' => false,
        ]);

        $this->assertArrayNotHasKey('contains', $result);
        $this->assertArrayNotHasKey('minContains', $result);
        $this->assertArrayNotHasKey('maxContains', $result);
        $this->assertArrayNotHasKey('prefixItems', $result);
        $this->assertArrayNotHasKey('unevaluatedItems', $result);
        $this->assertSame('Must contain at least 2 matching items. Must contain at most 4 matching items.', $result['description']);
    }

    public function testStripsSubschemaAndAnnotationKeywordsWithoutDescribingThem(): void
    {
        $result = AnthropicSchemaSanitizer::sanitize([
            'type' => 'string',
            'not' => ['const' => 'x'],
            'if' => ['const' => 'y'],
            'then' => ['const' => 'z'],
            'else' => ['const' => 'w'],
            'examples' => ['a'],
            'deprecated' => true,
            'readOnly' => true,
            '$comment' => 'internal',
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
        ]);

        $this->assertSame(['type' => 'string'], $result);
    }

    public function testForcesAdditionalPropertiesToFalse(): void
    {
        $result = AnthropicSchemaSanitizer::sanitize([
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'additionalProperties' => true,
        ]);

        $this->assertFalse($result['additionalProperties']);
    }

    public function testLeavesKeywordsOutsideTheRejectedSetUntouched(): void
    {
        $schema = [
            'type' => 'string',
            'x-internal' => 'kept',
            'unknownKeyword' => ['nested' => true],
        ];

        $this->assertSame($schema, AnthropicSchemaSanitizer::sanitize($schema));
    }

    public function testKeepsAnEnumOfScalarsButFoldsAComplexEnumIntoTheDescription(): void
    {
        $scalars = ['type' => 'string', 'enum' => ['active', 'inactive']];

        $this->assertSame($scalars, AnthropicSchemaSanitizer::sanitize($scalars));

        $complex = AnthropicSchemaSanitizer::sanitize([
            'type' => 'object',
            'enum' => [['a' => 'x']],
        ]);

        $this->assertArrayNotHasKey('enum', $complex);
        $this->assertSame('Must be one of: [{"a":"x"}].', $complex['description']);
    }

    public function testRewritesAUnionTypeIntoAnyOfBranches(): void
    {
        $result = AnthropicSchemaSanitizer::sanitize([
            'type' => ['string', 'null'],
            'description' => 'The nickname.',
            'maxLength' => 20,
        ]);

        $this->assertArrayNotHasKey('type', $result);
        $this->assertArrayNotHasKey('maxLength', $result);
        $this->assertSame([
            ['type' => 'string', 'description' => 'Must be at most 20 characters.'],
            ['type' => 'null'],
        ], $result['anyOf']);
        $this->assertSame('The nickname.', $result['description']);
    }

    public function testCollapsesASingleMemberUnionTypeBackToAScalarType(): void
    {
        $this->assertSame(['type' => 'string'], AnthropicSchemaSanitizer::sanitize(['type' => ['string']]));
    }

    public function testRewritesOneOfIntoTheSupportedAnyOfForm(): void
    {
        $result = AnthropicSchemaSanitizer::sanitize([
            'oneOf' => [
                ['type' => 'integer', 'minimum' => 1],
                ['type' => 'string', 'maxLength' => 5],
            ],
        ]);

        $this->assertArrayNotHasKey('oneOf', $result);
        $this->assertSame(['type' => 'integer', 'description' => 'Must be at least 1.'], $result['anyOf'][0]);
        $this->assertSame(['type' => 'string', 'description' => 'Must be at most 5 characters.'], $result['anyOf'][1]);
    }

    public function testAppendsConstraintNotesToAnExistingDescription(): void
    {
        $result = AnthropicSchemaSanitizer::sanitize([
            'type' => 'integer',
            'description' => 'The score.',
            'minimum' => 1,
        ]);

        $this->assertSame('The score. Must be at least 1.', $result['description']);
    }

    public function testTerminatesAnExistingDescriptionBeforeAppendingNotes(): void
    {
        $result = AnthropicSchemaSanitizer::sanitize([
            'type' => 'integer',
            'description' => 'The score',
            'minimum' => 1,
        ]);

        $this->assertSame('The score. Must be at least 1.', $result['description']);
    }

    public function testLeavesSupportedKeywordsUntouched(): void
    {
        $schema = [
            'type' => 'object',
            'title' => 'Status',
            'properties' => [
                'status' => ['type' => 'string', 'enum' => ['active', 'inactive']],
                'code' => ['type' => 'string', 'const' => 'X'],
                'role' => ['type' => 'string', 'default' => 'member'],
                'when' => ['type' => 'string', 'format' => 'date-time'],
            ],
            'required' => ['status'],
            'additionalProperties' => false,
        ];

        $this->assertSame($schema, AnthropicSchemaSanitizer::sanitize($schema));
    }

    public function testRecursesIntoNestedPropertiesAndArrayItems(): void
    {
        $result = AnthropicSchemaSanitizer::sanitize([
            'type' => 'object',
            'properties' => [
                'tags' => [
                    'type' => 'array',
                    'maxItems' => 3,
                    'items' => ['type' => 'string', 'maxLength' => 20],
                ],
                'score' => ['type' => 'integer', 'minimum' => 0],
            ],
            'additionalProperties' => false,
        ]);

        $this->assertArrayNotHasKey('maxItems', $result['properties']['tags']);
        $this->assertSame('Must contain at most 3 items.', $result['properties']['tags']['description']);
        $this->assertArrayNotHasKey('maxLength', $result['properties']['tags']['items']);
        $this->assertSame('Must be at most 20 characters.', $result['properties']['tags']['items']['description']);
        $this->assertArrayNotHasKey('minimum', $result['properties']['score']);
        $this->assertSame('Must be at least 0.', $result['properties']['score']['description']);
    }

    #[DataProvider('combinators')]
    public function testRecursesIntoEveryCombinatorBranch(string $combinator): void
    {
        $result = AnthropicSchemaSanitizer::sanitize([
            $combinator => [
                ['type' => 'integer', 'minimum' => 1],
                ['type' => 'string', 'maxLength' => 5],
            ],
        ]);

        $this->assertArrayNotHasKey('minimum', $result[$combinator][0]);
        $this->assertSame('Must be at least 1.', $result[$combinator][0]['description']);
        $this->assertArrayNotHasKey('maxLength', $result[$combinator][1]);
        $this->assertSame('Must be at most 5 characters.', $result[$combinator][1]['description']);
    }

    /**
     * Provide recursive schema combinators.
     */
    public static function combinators(): array
    {
        return [['anyOf'], ['allOf']];
    }

    #[DataProvider('definitionKeywords')]
    public function testRecursesIntoReusableDefinitions(string $keyword): void
    {
        $result = AnthropicSchemaSanitizer::sanitize([
            'type' => 'object',
            $keyword => [
                'Score' => ['type' => 'integer', 'minimum' => 1],
            ],
            'properties' => [
                'score' => ['$ref' => '#/' . $keyword . '/Score'],
            ],
        ]);

        $this->assertArrayNotHasKey('minimum', $result[$keyword]['Score']);
        $this->assertSame('Must be at least 1.', $result[$keyword]['Score']['description']);
        $this->assertSame(['$ref' => '#/' . $keyword . '/Score'], $result['properties']['score']);
    }

    /**
     * Provide reusable definition keywords.
     */
    public static function definitionKeywords(): array
    {
        return [['$defs'], ['definitions']];
    }

    public function testRecursesIntoTupleFormItems(): void
    {
        $result = AnthropicSchemaSanitizer::sanitize([
            'type' => 'array',
            'items' => [
                ['type' => 'integer', 'minimum' => 1],
                ['type' => 'string', 'maxLength' => 5],
            ],
        ]);

        $this->assertSame('Must be at least 1.', $result['items'][0]['description']);
        $this->assertSame('Must be at most 5 characters.', $result['items'][1]['description']);
    }

    public function testSanitizesTheSchemaFromTheIssueReproduction(): void
    {
        $sanitized = AnthropicSchemaSanitizer::sanitize(
            (new ObjectSchema([
                'score' => JsonSchema::integer()->required()->min(1)->max(10),
                'summary' => JsonSchema::string()->required()->min(1)->max(280),
                'tags' => JsonSchema::array()->required()->items(JsonSchema::string())->min(1)->max(5),
            ]))->toSchema()
        );

        $this->assertSame([
            'type' => 'integer',
            'description' => 'Must be at least 1. Must be at most 10.',
        ], $sanitized['properties']['score']);
        $this->assertSame([
            'type' => 'string',
            'description' => 'Must be at least 1 character. Must be at most 280 characters.',
        ], $sanitized['properties']['summary']);
        $this->assertSame([
            'minItems' => 1,
            'items' => ['type' => 'string'],
            'type' => 'array',
            'description' => 'Must contain at most 5 items.',
        ], $sanitized['properties']['tags']);
        $this->assertFalse($sanitized['additionalProperties']);
        $this->assertSame(['score', 'summary', 'tags'], $sanitized['required']);
    }

    public function testSanitizesANullablePropertyBuiltByTheHypervelJsonSchemaBuilder(): void
    {
        $sanitized = AnthropicSchemaSanitizer::sanitize(
            (new ObjectSchema([
                'nickname' => JsonSchema::string()->max(20)->nullable(),
            ]))->toSchema()
        );

        $this->assertArrayNotHasKey('type', $sanitized['properties']['nickname']);
        $this->assertSame([
            ['type' => 'string', 'description' => 'Must be at most 20 characters.'],
            ['type' => 'null'],
        ], $sanitized['properties']['nickname']['anyOf']);
    }
}
