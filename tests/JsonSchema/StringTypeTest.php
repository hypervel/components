<?php

declare(strict_types=1);

namespace Hypervel\Tests\JsonSchema;

use Hypervel\JsonSchema\Types\StringType;
use Hypervel\Tests\TestCase;

class StringTypeTest extends TestCase
{
    public function testItSetsMinLength(): void
    {
        $type = (new StringType)->min(5);

        $this->assertEquals([
            'type' => 'string',
            'minLength' => 5,
        ], $type->toArray());
    }

    public function testItSetsMaxLength(): void
    {
        $type = (new StringType)->description('User handle')->max(10);

        $this->assertEquals([
            'type' => 'string',
            'description' => 'User handle',
            'maxLength' => 10,
        ], $type->toArray());
    }

    public function testItSetsPattern(): void
    {
        $type = (new StringType)->default('foo')->pattern('^foo.*$');

        $this->assertEquals([
            'type' => 'string',
            'default' => 'foo',
            'pattern' => '^foo.*$',
        ], $type->toArray());
    }

    public function testItSetsFormat(): void
    {
        $type = (new StringType)->default('foo')->format('date');

        $this->assertEquals([
            'type' => 'string',
            'default' => 'foo',
            'format' => 'date',
        ], $type->toArray());
    }

    public function testItDistinguishesAnExplicitNullDefaultFromAnUnsetDefault(): void
    {
        $this->assertArrayNotHasKey('default', (new StringType)->toArray());
        $this->assertSame([
            'default' => null,
            'type' => 'string',
        ], (new StringType)->default(null)->toArray());
    }

    public function testItSetsEnum(): void
    {
        $type = (new StringType)->enum(['draft', 'published']);

        $this->assertEquals([
            'type' => 'string',
            'enum' => ['draft', 'published'],
        ], $type->toArray());
    }
}
