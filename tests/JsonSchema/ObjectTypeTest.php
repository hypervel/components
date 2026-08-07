<?php

declare(strict_types=1);

namespace Hypervel\Tests\JsonSchema;

use Hypervel\JsonSchema\JsonSchema;
use Hypervel\JsonSchema\JsonSchemaTypeFactory;
use Hypervel\Tests\TestCase;
use stdClass;

class ObjectTypeTest extends TestCase
{
    public function testItMayNotHaveProperties(): void
    {
        $type = JsonSchema::object()->title('Payload');

        $this->assertEquals([
            'type' => 'object',
            'title' => 'Payload',
        ], $type->toArray());
    }

    public function testItMayBeInitializedWithAClosureButWithoutProperties(): void
    {
        $type = JsonSchema::object(fn () => [])->title('Payload');

        $this->assertEquals([
            'type' => 'object',
            'title' => 'Payload',
        ], $type->toArray());
    }

    public function testItMayHaveProperties(): void
    {
        $type = JsonSchema::object([
            'age-a' => JsonSchema::integer()->min(0)->required(),
            'age-b' => JsonSchema::integer()->default(30)->max(45),
        ])->description('Root object');

        $this->assertEquals([
            'type' => 'object',
            'description' => 'Root object',
            'properties' => [
                'age-a' => [
                    'type' => 'integer',
                    'minimum' => 0,
                ],
                'age-b' => [
                    'type' => 'integer',
                    'default' => 30,
                    'maximum' => 45,
                ],
            ],
            'required' => ['age-a'],
        ], $type->toArray());
    }

    public function testItMayBeInitializedWithAClosureButMayHaveProperties(): void
    {
        $type = JsonSchema::object(fn (JsonSchemaTypeFactory $schema) => [
            'age-a' => $schema->integer()->min(0)->required(),
            'age-b' => $schema->integer()->default(30)->max(45),
        ])->description('Root object');

        $this->assertEquals([
            'type' => 'object',
            'description' => 'Root object',
            'properties' => [
                'age-a' => [
                    'type' => 'integer',
                    'minimum' => 0,
                ],
                'age-b' => [
                    'type' => 'integer',
                    'default' => 30,
                    'maximum' => 45,
                ],
            ],
            'required' => ['age-a'],
        ], $type->toArray());
    }

    public function testNumericStringPropertyNamesRemainStringsInRequiredArray(): void
    {
        $type = JsonSchema::object([
            '1' => JsonSchema::string()->required(),
            '4' => JsonSchema::string()->required(),
        ]);

        $array = $type->toArray();

        $this->assertSame(['1', '4'], $array['required']);
        $this->assertIsString($array['required'][0]);
        $this->assertIsString($array['required'][1]);
    }

    public function testListShapedPropertyMapsAreSerializedAsJsonObjects(): void
    {
        $zero = JsonSchema::object([
            '0' => JsonSchema::string()->required(),
        ])->toArray();
        $sequential = JsonSchema::object([
            '0' => JsonSchema::string(),
            '1' => JsonSchema::integer(),
        ])->toArray();

        $this->assertInstanceOf(stdClass::class, $zero['properties']);
        $this->assertSame(['0'], $zero['required']);
        $this->assertInstanceOf(stdClass::class, $sequential['properties']);
        $this->assertSame([0, 1], array_keys((array) $sequential['properties']));
    }

    public function testNonListPropertyMapsRemainArraysAndEncodeAsJsonObjects(): void
    {
        $array = JsonSchema::object([
            '1' => JsonSchema::string(),
            '4' => JsonSchema::integer(),
        ])->toArray();

        $this->assertIsArray($array['properties']);
        $this->assertSame([1, 4], array_keys($array['properties']));
        $this->assertInstanceOf(stdClass::class, json_decode(json_encode($array))->properties);
    }

    public function testItMayDisableAdditionalProperties(): void
    {
        $type = JsonSchema::object()->default(['age' => 1])->withoutAdditionalProperties();

        $this->assertEquals([
            'type' => 'object',
            'default' => ['age' => 1],
            'additionalProperties' => false,
        ], $type->toArray());
    }

    public function testListShapedObjectDefaultsAreSerializedAsJsonObjects(): void
    {
        $empty = JsonSchema::object()->default([])->toArray();
        $sequential = JsonSchema::object()->default(['first', 'second'])->toArray();
        $associative = JsonSchema::object()->default(['name' => 'Taylor'])->toArray();

        $this->assertInstanceOf(stdClass::class, $empty['default']);
        $this->assertSame([], (array) $empty['default']);
        $this->assertInstanceOf(stdClass::class, $sequential['default']);
        $this->assertSame(['first', 'second'], (array) $sequential['default']);
        $this->assertSame(['name' => 'Taylor'], $associative['default']);
    }

    public function testItDistinguishesAnExplicitNullDefaultFromAnUnsetDefault(): void
    {
        $this->assertArrayNotHasKey('default', JsonSchema::object()->toArray());
        $this->assertSame([
            'default' => null,
            'type' => 'object',
        ], JsonSchema::object()->default(null)->toArray());
    }

    public function testItMaySetEnum(): void
    {
        $type = JsonSchema::object()->enum([
            ['a' => 1],
            ['a' => 2],
        ]);

        $this->assertEquals([
            'type' => 'object',
            'enum' => [
                ['a' => 1],
                ['a' => 2],
            ],
        ], $type->toArray());
    }
}
