<?php

declare(strict_types=1);

namespace Hypervel\Tests\JsonSchema;

use Hypervel\JsonSchema\Types\Type;
use Hypervel\Tests\TestCase;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class SerializerTest extends TestCase
{
    public function testItDoesNotKnowHowToSerializeUnknownTypes()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported [Hypervel\JsonSchema\Types\Type@anonymous');

        $type = new class extends Type {
            // anonymous type for triggering serializer failure
        };

        $type->toArray();
    }
}
