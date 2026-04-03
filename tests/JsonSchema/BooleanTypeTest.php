<?php

declare(strict_types=1);

namespace Hypervel\Tests\JsonSchema;

use Hypervel\JsonSchema\JsonSchema;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class BooleanTypeTest extends TestCase
{
    public function testSerializesAsBooleanWithMetadata()
    {
        $type = JsonSchema::boolean()->title('Enabled')->description('Feature flag');

        $this->assertEquals([
            'type' => 'boolean',
            'title' => 'Enabled',
            'description' => 'Feature flag',
        ], $type->toArray());
    }

    public function testMaySetDefaultTrueViaDefault()
    {
        $type = JsonSchema::boolean()->default(true);

        $this->assertEquals([
            'type' => 'boolean',
            'default' => true,
        ], $type->toArray());
    }

    public function testMaySetDefaultFalseViaDefault()
    {
        $type = JsonSchema::boolean()->default(false);

        $this->assertEquals([
            'type' => 'boolean',
            'default' => false,
        ], $type->toArray());
    }

    public function testMaySetEnum()
    {
        $type = JsonSchema::boolean()->enum([true, false]);

        $this->assertEquals([
            'type' => 'boolean',
            'enum' => [true, false],
        ], $type->toArray());
    }
}
