<?php

declare(strict_types=1);

namespace Hypervel\Tests\JsonSchema;

use Hypervel\JsonSchema\JsonSchema;
use Hypervel\Tests\TestCase;

class ArrayTypeTest extends TestCase
{
    public function testItMaySetMinItems(): void
    {
        $type = JsonSchema::array()->title('Tags')->min(1);

        $this->assertEquals([
            'type' => 'array',
            'title' => 'Tags',
            'minItems' => 1,
        ], $type->toArray());
    }

    public function testItMaySetMaxItems(): void
    {
        $type = JsonSchema::array()->description('A list of tags')->max(10);

        $this->assertEquals([
            'type' => 'array',
            'description' => 'A list of tags',
            'maxItems' => 10,
        ], $type->toArray());
    }

    public function testItMaySetItemsType(): void
    {
        $type = JsonSchema::array()->items(
            JsonSchema::string()->max(20)
        );

        $this->assertEquals([
            'type' => 'array',
            'items' => [
                'type' => 'string',
                'maxLength' => 20,
            ],
        ], $type->toArray());
    }

    public function testItMaySetDefaultValue(): void
    {
        $type = JsonSchema::array()->default(['a', 'b']);

        $this->assertEquals([
            'type' => 'array',
            'default' => ['a', 'b'],
        ], $type->toArray());
    }

    public function testItDistinguishesAnExplicitNullDefaultFromAnUnsetDefault(): void
    {
        $this->assertArrayNotHasKey('default', JsonSchema::array()->toArray());
        $this->assertSame([
            'default' => null,
            'type' => 'array',
        ], JsonSchema::array()->default(null)->toArray());
    }

    public function testItMaySetUniqueItems(): void
    {
        $type = JsonSchema::array()->items(JsonSchema::string())->unique();

        $this->assertEquals([
            'type' => 'array',
            'items' => [
                'type' => 'string',
            ],
            'uniqueItems' => true,
        ], $type->toArray());
    }

    public function testItMayUnsetUniqueItems(): void
    {
        $type = JsonSchema::array()->unique()->unique(false);

        $this->assertEquals([
            'type' => 'array',
        ], $type->toArray());
    }

    public function testItMayCombineUniqueItemsWithMinAndMax(): void
    {
        $type = JsonSchema::array()->min(1)->max(5)->unique();

        $this->assertEquals([
            'type' => 'array',
            'minItems' => 1,
            'maxItems' => 5,
            'uniqueItems' => true,
        ], $type->toArray());
    }

    public function testItMaySetEnum(): void
    {
        $type = JsonSchema::array()->enum([
            ['a'],
            ['b', 'c'],
        ]);

        $this->assertEquals([
            'type' => 'array',
            'enum' => [
                ['a'],
                ['b', 'c'],
            ],
        ], $type->toArray());
    }
}
