<?php

declare(strict_types=1);

namespace Hypervel\Tests\JsonSchema;

use Hypervel\JsonSchema\JsonSchema;
use Hypervel\Tests\TestCase;

class IntegerTypeTest extends TestCase
{
    public function testItMaySetMinValue(): void
    {
        $type = JsonSchema::integer()->title('Age')->min(5);

        $this->assertEquals([
            'type' => 'integer',
            'title' => 'Age',
            'minimum' => 5,
        ], $type->toArray());
    }

    public function testItMaySetMaxValue(): void
    {
        $type = JsonSchema::integer()->description('Max age')->max(10);

        $this->assertEquals([
            'type' => 'integer',
            'description' => 'Max age',
            'maximum' => 10,
        ], $type->toArray());
    }

    public function testItMaySetDefaultValue(): void
    {
        $type = JsonSchema::integer()->default(18);

        $this->assertEquals([
            'type' => 'integer',
            'default' => 18,
        ], $type->toArray());
    }

    public function testItDistinguishesAnExplicitNullDefaultFromAnUnsetDefault(): void
    {
        $this->assertArrayNotHasKey('default', JsonSchema::integer()->toArray());
        $this->assertSame([
            'default' => null,
            'type' => 'integer',
        ], JsonSchema::integer()->default(null)->toArray());
    }

    public function testItMaySetMultipleOf(): void
    {
        $type = JsonSchema::integer()->multipleOf(5);

        $this->assertEquals([
            'type' => 'integer',
            'multipleOf' => 5,
        ], $type->toArray());
    }

    public function testItMayCombineMultipleOfWithMinAndMax(): void
    {
        $type = JsonSchema::integer()->min(0)->max(100)->multipleOf(10);

        $this->assertEquals([
            'type' => 'integer',
            'minimum' => 0,
            'maximum' => 100,
            'multipleOf' => 10,
        ], $type->toArray());
    }

    public function testItMaySetEnum(): void
    {
        $type = JsonSchema::integer()->enum([1, 2, 3]);

        $this->assertEquals([
            'type' => 'integer',
            'enum' => [1, 2, 3],
        ], $type->toArray());
    }
}
