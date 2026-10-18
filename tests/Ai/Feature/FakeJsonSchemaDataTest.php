<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Hypervel\JsonSchema\JsonSchemaTypeFactory;
use Hypervel\JsonSchema\Types\ObjectType;
use Hypervel\JsonSchema\Types\Type;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

use function Hypervel\Ai\generate_fake_data_for_json_schema_type;

class FakeJsonSchemaDataTest extends TestCase
{
    public function testStructuredDataCanBeFaked(): void
    {
        $schema = new JsonSchemaTypeFactory;

        $response = generate_fake_data_for_json_schema_type((new ObjectType([
            'name' => $schema->string()->required(),
            'age' => $schema->integer()->required()->min(1)->max(120),
            'address' => $schema->object([
                'line_one' => $schema->string(),
                'line_two' => $schema->string(),
            ])->withoutAdditionalProperties(),
            'role' => $schema->string()->required()->enum(['admin', 'editor']),
            'skills' => $schema->array()->required()->min(5)->items(
                $schema->string()->required(),
            ),
            'active' => $schema->boolean(),
        ]))->withoutAdditionalProperties());

        $this->assertIsString($response['name']);
        $this->assertIsNumeric($response['age']);
        $this->assertIsArray($response['address']);
        $this->assertContains($response['role'], ['admin', 'editor']);
        $this->assertIsList($response['skills']);
        $this->assertIsBool($response['active']);
    }

    public function testNestedSchemasSupportUnionsAnyOfAndExplicitNullDefaults(): void
    {
        $schema = new JsonSchemaTypeFactory;
        $response = generate_fake_data_for_json_schema_type($schema->object([
            'union' => $schema->union(['integer', 'string', 'null']),
            'choice' => $schema->anyOf([$schema->object(['value' => $schema->string()->default(null)]), $schema->string()]),
            'empty' => $schema->object()->default([]),
            'numeric_keys' => $schema->object()->default(['value']),
            'null_union' => $schema->union(['string', 'null'])->default(null),
            'null_only' => $schema->union(['null']),
            'array_enum' => $schema->array()->enum(['choice']),
        ]));

        $this->assertIsInt($response['union']);
        $this->assertSame(['value' => null], $response['choice']);
        $this->assertSame([], $response['empty']);
        $this->assertSame(['value'], $response['numeric_keys']);
        $this->assertNull($response['null_union']);
        $this->assertNull($response['null_only']);
        $this->assertSame(['choice'], $response['array_enum']);
    }

    #[DataProvider('maximumOnlySchemas')]
    public function testMaximumOnlySchemasDoNotUseAnIncompatibleDefaultMinimum(Type $schema, mixed $expected): void
    {
        $this->assertSame($expected, generate_fake_data_for_json_schema_type($schema));
    }

    /**
     * Provide upper bounds below the usual fake-data minimum.
     */
    public static function maximumOnlySchemas(): array
    {
        $schema = new JsonSchemaTypeFactory;

        return [
            'empty array' => [$schema->array()->max(0)->items($schema->string()), []],
            'empty string' => [$schema->string()->max(0), ''],
            'negative integer' => [$schema->integer()->max(-1), -1],
            'negative number' => [$schema->number()->max(-1.0), -1.0],
        ];
    }
}
