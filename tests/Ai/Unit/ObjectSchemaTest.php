<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit;

use Hypervel\Ai\ObjectSchema;
use Hypervel\JsonSchema\JsonSchemaTypeFactory;
use Hypervel\Tests\TestCase;

class ObjectSchemaTest extends TestCase
{
    public function testNestedObjectsIncludeAdditionalPropertiesFalse(): void
    {
        $schema = new JsonSchemaTypeFactory;

        $objectSchema = new ObjectSchema([
            'name' => $schema->string()->required(),
            'address' => $schema->object([
                'street' => $schema->string()->required(),
                'city' => $schema->string()->required(),
            ])->required(),
        ]);

        $result = $objectSchema->toSchema();

        $this->assertFalse($result['additionalProperties']);
        $this->assertFalse($result['properties']['address']['additionalProperties']);
    }

    public function testObjectsNestedInArraysIncludeAdditionalPropertiesFalse(): void
    {
        $schema = new JsonSchemaTypeFactory;

        $objectSchema = new ObjectSchema([
            'items' => $schema->array()->items(
                $schema->object([
                    'action' => $schema->string()->required(),
                    'amount' => $schema->integer()->required(),
                ])
            )->required(),
        ]);

        $result = $objectSchema->toSchema();

        $this->assertFalse($result['additionalProperties']);
        $this->assertFalse($result['properties']['items']['items']['additionalProperties']);
    }

    public function testDeeplyNestedObjectsIncludeAdditionalPropertiesFalse(): void
    {
        $schema = new JsonSchemaTypeFactory;

        $objectSchema = new ObjectSchema([
            'user' => $schema->object([
                'name' => $schema->string()->required(),
                'contact' => $schema->object([
                    'email' => $schema->string()->required(),
                    'phone' => $schema->string()->required(),
                ])->required(),
            ])->required(),
        ]);

        $result = $objectSchema->toSchema();

        $this->assertFalse($result['additionalProperties']);
        $this->assertFalse($result['properties']['user']['additionalProperties']);
        $this->assertFalse($result['properties']['user']['properties']['contact']['additionalProperties']);
    }

    public function testObjectsInNestedArraysIncludeAdditionalPropertiesFalse(): void
    {
        $schema = new JsonSchemaTypeFactory;

        $objectSchema = new ObjectSchema([
            'matrix' => $schema->array()->items(
                $schema->array()->items(
                    $schema->object([
                        'value' => $schema->integer()->required(),
                    ])
                )
            )->required(),
        ]);

        $result = $objectSchema->toSchema();

        $this->assertFalse($result['properties']['matrix']['items']['items']['additionalProperties']);
    }

    public function testNullableNestedObjectsIncludeAdditionalPropertiesFalse(): void
    {
        $schema = new JsonSchemaTypeFactory;

        $objectSchema = new ObjectSchema([
            'name' => $schema->string()->required(),
            'address' => $schema->object([
                'street' => $schema->string()->required(),
                'city' => $schema->string()->required(),
            ])->nullable(),
        ]);

        $result = $objectSchema->toSchema();

        $this->assertFalse($result['additionalProperties']);
        $this->assertFalse($result['properties']['address']['additionalProperties']);
    }

    public function testRenamingPreservesObjectSchemaRulesWithoutChangingTheOriginal(): void
    {
        $schema = new JsonSchemaTypeFactory;
        $original = new ObjectSchema([
            'address' => $schema->object(['city' => $schema->string()]),
        ], name: 'original', strict: true);

        $renamed = $original->withName('renamed');

        $this->assertSame('original', $original->name());
        $this->assertSame('renamed', $renamed->name());
        $this->assertTrue($renamed->strict);
        $this->assertSame($original->toSchema(), $renamed->toSchema());
    }
}
