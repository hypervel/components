<?php

declare(strict_types=1);

namespace Hypervel\Tests\JsonSchema;

use Hypervel\JsonSchema\JsonSchema;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class AnyOfTypeTest extends TestCase
{
    public function testItMayDescribeAnyOfMultipleSchemas(): void
    {
        $type = JsonSchema::anyOf([
            JsonSchema::string(),
            JsonSchema::integer(),
        ])->title('Identifier');

        $this->assertEquals([
            'title' => 'Identifier',
            'anyOf' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
        ], $type->toArray());
    }

    public function testItMayBeInitializedWithAClosure(): void
    {
        $type = JsonSchema::anyOf(fn (JsonSchema $schema): array => [
            $schema->string(),
            $schema->integer(),
        ]);

        $this->assertEquals([
            'anyOf' => [
                ['type' => 'string'],
                ['type' => 'integer'],
            ],
        ], $type->toArray());
    }

    public function testItMayBeNullable(): void
    {
        $type = JsonSchema::anyOf([
            JsonSchema::string(),
            JsonSchema::integer(),
        ])->nullable();

        $this->assertEquals([
            'anyOf' => [
                ['type' => 'string'],
                ['type' => 'integer'],
                ['type' => 'null'],
            ],
        ], $type->toArray());
    }

    public function testEmptyAnyOfMayBecomeNullable(): void
    {
        $this->assertSame([
            'anyOf' => [
                ['type' => 'null'],
            ],
        ], JsonSchema::anyOf([])->nullable()->toArray());
    }

    public function testFinallyEmptyAnyOfIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A JSON Schema anyOf must contain at least one schema.');

        JsonSchema::anyOf([])->toArray();
    }

    public function testItDistinguishesAnExplicitNullDefaultFromAnUnsetDefault(): void
    {
        $this->assertArrayNotHasKey('default', JsonSchema::anyOf([JsonSchema::string()])->toArray());
        $this->assertSame([
            'default' => null,
            'anyOf' => [
                ['type' => 'string'],
            ],
        ], JsonSchema::anyOf([JsonSchema::string()])->default(null)->toArray());
    }

    public function testItMayDescribeObjectUnions(): void
    {
        $type = JsonSchema::anyOf([
            JsonSchema::object([
                'type' => JsonSchema::string()->enum(['article'])->required(),
                'title' => JsonSchema::string()->required(),
                'content' => JsonSchema::string()->required(),
            ]),
            JsonSchema::object([
                'type' => JsonSchema::string()->enum(['image'])->required(),
                'url' => JsonSchema::string()->required(),
                'caption' => JsonSchema::string(),
            ]),
        ]);

        $this->assertEquals([
            'anyOf' => [
                [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => ['article']],
                        'title' => ['type' => 'string'],
                        'content' => ['type' => 'string'],
                    ],
                    'required' => ['type', 'title', 'content'],
                ],
                [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string', 'enum' => ['image']],
                        'url' => ['type' => 'string'],
                        'caption' => ['type' => 'string'],
                    ],
                    'required' => ['type', 'url'],
                ],
            ],
        ], $type->toArray());
    }
}
