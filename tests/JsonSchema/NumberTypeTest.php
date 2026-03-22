<?php

declare(strict_types=1);

namespace Hypervel\Tests\JsonSchema;

use Hypervel\JsonSchema\JsonSchema;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class NumberTypeTest extends TestCase
{
    public function testItMaySetMinValueAsFloat()
    {
        $type = JsonSchema::number()->title('Price')->min(5.5);

        $this->assertEquals([
            'type' => 'number',
            'title' => 'Price',
            'minimum' => 5.5,
        ], $type->toArray());
    }

    public function testItMaySetMinValueAsInt()
    {
        $type = JsonSchema::number()->title('Price')->min(5);

        $this->assertEquals([
            'type' => 'number',
            'title' => 'Price',
            'minimum' => 5,
        ], $type->toArray());
    }

    public function testItMaySetMaxValueAsFloat()
    {
        $type = JsonSchema::number()->description('Max price')->max(10.75);

        $this->assertEquals([
            'type' => 'number',
            'description' => 'Max price',
            'maximum' => 10.75,
        ], $type->toArray());
    }

    public function testItMaySetMaxValueAsInt()
    {
        $type = JsonSchema::number()->description('Max price')->max(10);

        $this->assertEquals([
            'type' => 'number',
            'description' => 'Max price',
            'maximum' => 10,
        ], $type->toArray());
    }

    public function testItMaySetDefaultValue()
    {
        $type = JsonSchema::number()->default(9.99);

        $this->assertEquals([
            'type' => 'number',
            'default' => 9.99,
        ], $type->toArray());
    }

    public function testItMaySetMultipleOfAsFloat()
    {
        $type = JsonSchema::number()->multipleOf(0.5);

        $this->assertEquals([
            'type' => 'number',
            'multipleOf' => 0.5,
        ], $type->toArray());
    }

    public function testItMaySetMultipleOfAsInt()
    {
        $type = JsonSchema::number()->multipleOf(3);

        $this->assertEquals([
            'type' => 'number',
            'multipleOf' => 3,
        ], $type->toArray());
    }

    public function testItMayCombineMultipleOfWithMinAndMax()
    {
        $type = JsonSchema::number()->min(0.0)->max(10.0)->multipleOf(0.25);

        $this->assertEquals([
            'type' => 'number',
            'minimum' => 0.0,
            'maximum' => 10.0,
            'multipleOf' => 0.25,
        ], $type->toArray());
    }

    public function testItMaySetEnum()
    {
        $type = JsonSchema::number()->enum([1, 2.5, 3]);

        $this->assertEquals([
            'type' => 'number',
            'enum' => [1, 2.5, 3],
        ], $type->toArray());
    }
}
