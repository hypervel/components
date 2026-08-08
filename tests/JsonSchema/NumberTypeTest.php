<?php

declare(strict_types=1);

namespace Hypervel\Tests\JsonSchema;

use Hypervel\JsonSchema\JsonSchema;
use Hypervel\Tests\TestCase;

class NumberTypeTest extends TestCase
{
    public function testItMaySetMinValueAsFloat(): void
    {
        $type = JsonSchema::number()->title('Price')->min(5.5);

        $this->assertEquals([
            'type' => 'number',
            'title' => 'Price',
            'minimum' => 5.5,
        ], $type->toArray());
    }

    public function testItMaySetMinValueAsInt(): void
    {
        $type = JsonSchema::number()->title('Price')->min(5);

        $this->assertEquals([
            'type' => 'number',
            'title' => 'Price',
            'minimum' => 5,
        ], $type->toArray());
    }

    public function testItMaySetMaxValueAsFloat(): void
    {
        $type = JsonSchema::number()->description('Max price')->max(10.75);

        $this->assertEquals([
            'type' => 'number',
            'description' => 'Max price',
            'maximum' => 10.75,
        ], $type->toArray());
    }

    public function testItMaySetMaxValueAsInt(): void
    {
        $type = JsonSchema::number()->description('Max price')->max(10);

        $this->assertEquals([
            'type' => 'number',
            'description' => 'Max price',
            'maximum' => 10,
        ], $type->toArray());
    }

    public function testItMaySetDefaultValue(): void
    {
        $type = JsonSchema::number()->default(9.99);

        $this->assertEquals([
            'type' => 'number',
            'default' => 9.99,
        ], $type->toArray());
    }

    public function testItDistinguishesAnExplicitNullDefaultFromAnUnsetDefault(): void
    {
        $this->assertArrayNotHasKey('default', JsonSchema::number()->toArray());
        $this->assertSame([
            'default' => null,
            'type' => 'number',
        ], JsonSchema::number()->default(null)->toArray());
    }

    public function testItMaySetMultipleOfAsFloat(): void
    {
        $type = JsonSchema::number()->multipleOf(0.5);

        $this->assertEquals([
            'type' => 'number',
            'multipleOf' => 0.5,
        ], $type->toArray());
    }

    public function testItMaySetMultipleOfAsInt(): void
    {
        $type = JsonSchema::number()->multipleOf(3);

        $this->assertEquals([
            'type' => 'number',
            'multipleOf' => 3,
        ], $type->toArray());
    }

    public function testItMayCombineMultipleOfWithMinAndMax(): void
    {
        $type = JsonSchema::number()->min(0.0)->max(10.0)->multipleOf(0.25);

        $this->assertEquals([
            'type' => 'number',
            'minimum' => 0.0,
            'maximum' => 10.0,
            'multipleOf' => 0.25,
        ], $type->toArray());
    }

    public function testItMaySetEnum(): void
    {
        $type = JsonSchema::number()->enum([1, 2.5, 3]);

        $this->assertEquals([
            'type' => 'number',
            'enum' => [1, 2.5, 3],
        ], $type->toArray());
    }
}
