<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Casts;

use Hypervel\Data\Casts\BuiltinTypeCast;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Support\Creation\ConstructionState;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class BuiltinTypeCastTest extends TestCase
{
    /**
     * Test built-in scalar and array casting.
     */
    #[DataProvider('castProvider')]
    public function testCastsBuiltInValues(string $type, mixed $value, mixed $expected): void
    {
        $context = new CreationContext(BuiltinTypeCastDataFixture::class);
        $state = ConstructionState::create($context, BuiltinTypeCastDataFixture::class);
        $property = $this->createStub(DataProperty::class);
        $cast = new BuiltinTypeCast($type);

        $this->assertSame($expected, $cast->cast($property, $value, $state, $context));
        $this->assertSame($expected, $cast->castIterableItem($property, $value, $state, $context));
    }

    /**
     * Provide built-in cast values.
     */
    public static function castProvider(): array
    {
        return [
            'true string' => ['bool', 'TRUE', true],
            'false string' => ['bool', 'False', false],
            'zero string' => ['bool', '0', false],
            'truthy string' => ['bool', 'yes', true],
            'integer' => ['int', '42', 42],
            'float' => ['float', '4.2', 4.2],
            'array' => ['array', 'value', ['value']],
            'string' => ['string', 42, '42'],
        ];
    }
}

abstract class BuiltinTypeCastDataFixture implements BaseData
{
}
