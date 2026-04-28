<?php

declare(strict_types=1);

namespace Hypervel\Tests\JsonSchema;

use Hypervel\JsonSchema\JsonSchema;
use Hypervel\Tests\TestCase;

class ArrayTypeTest extends TestCase
{
    public function testItMaySetMinItems()
    {
        $type = JsonSchema::array()->title('Tags')->min(1);

        $this->assertEquals([
            'type' => 'array',
            'title' => 'Tags',
            'minItems' => 1,
        ], $type->toArray());
    }

    public function testItMaySetMaxItems()
    {
        $type = JsonSchema::array()->description('A list of tags')->max(10);

        $this->assertEquals([
            'type' => 'array',
            'description' => 'A list of tags',
            'maxItems' => 10,
        ], $type->toArray());
    }

    public function testItMaySetItemsType()
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

    public function testItMaySetDefaultValue()
    {
        $type = JsonSchema::array()->default(['a', 'b']);

        $this->assertEquals([
            'type' => 'array',
            'default' => ['a', 'b'],
        ], $type->toArray());
    }

    public function testItMaySetEnum()
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
