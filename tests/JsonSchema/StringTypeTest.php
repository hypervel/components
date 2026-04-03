<?php

declare(strict_types=1);

namespace Hypervel\Tests\JsonSchema;

use Hypervel\JsonSchema\Types\StringType;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class StringTypeTest extends TestCase
{
    public function testItSetsMinLength()
    {
        $type = (new StringType())->min(5);

        $this->assertEquals([
            'type' => 'string',
            'minLength' => 5,
        ], $type->toArray());
    }

    public function testItSetsMaxLength()
    {
        $type = (new StringType())->description('User handle')->max(10);

        $this->assertEquals([
            'type' => 'string',
            'description' => 'User handle',
            'maxLength' => 10,
        ], $type->toArray());
    }

    public function testItSetsPattern()
    {
        $type = (new StringType())->default('foo')->pattern('^foo.*$');

        $this->assertEquals([
            'type' => 'string',
            'default' => 'foo',
            'pattern' => '^foo.*$',
        ], $type->toArray());
    }

    public function testItSetsFormat()
    {
        $type = (new StringType())->default('foo')->format('date');

        $this->assertEquals([
            'type' => 'string',
            'default' => 'foo',
            'format' => 'date',
        ], $type->toArray());
    }

    public function testItSetsEnum()
    {
        $type = (new StringType())->enum(['draft', 'published']);

        $this->assertEquals([
            'type' => 'string',
            'enum' => ['draft', 'published'],
        ], $type->toArray());
    }
}
