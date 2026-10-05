<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Casts;

use Hypervel\Data\Casts\BuiltinTypeCast;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use TypeError;

class BuiltinTypeCastTest extends TestCase
{
    #[DataProvider('castProvider')]
    public function testCanCastBuiltinTypes(string $type, mixed $value, mixed $expected): void
    {
        $context = new CreationContext(BuiltinTypeCastDataFixture::class);
        $property = $this->createStub(DataProperty::class);
        $cast = new BuiltinTypeCast($type);

        $this->assertSame($expected, $cast->cast($property, $value, [], $context));
        $this->assertSame($expected, $cast->castIterableItem($property, $value, [], $context));
    }

    /**
     * Provide built-in cast values.
     */
    public static function castProvider(): array
    {
        return [
            'string "true" to bool' => ['bool', 'true', true],
            'string "false" to bool' => ['bool', 'false', false],
            'string "TRUE" (uppercase) to bool' => ['bool', 'TRUE', true],
            'string "FALSE" (uppercase) to bool' => ['bool', 'FALSE', false],
            'mixed case string "TrUe" to bool' => ['bool', 'TrUe', true],
            'integer 1 to bool true' => ['bool', 1, true],
            'integer 0 to bool false' => ['bool', 0, false],
            'non-empty string to bool true' => ['bool', 'some text', true],
            'empty string to bool false' => ['bool', '', false],
            'string "0" to bool false' => ['bool', '0', false],
            'string "1" to bool true' => ['bool', '1', true],
            'int types' => ['int', '42', 42],
            'float types' => ['float', '42.5', 42.5],
            'string types' => ['string', 42, '42'],
            'array types' => ['array', (object) ['key' => 'value'], ['key' => 'value']],
            'scalar to array' => ['array', 'value', ['value']],
            'numeric string with whitespace and exponent to int' => ['int', ' 1e3 ', 1000],
            'boolean to int' => ['int', true, 1],
            'stringable to string' => ['string', new BuiltinTypeCastStringable, 'stringable'],
        ];
    }

    #[DataProvider('invalidValueProvider')]
    public function testRejectsValuesPhpWouldNotConvert(string $type, mixed $value): void
    {
        $context = new CreationContext(BuiltinTypeCastDataFixture::class);
        $property = $this->createStub(DataProperty::class);
        $cast = new BuiltinTypeCast($type);

        $rejected = 0;

        foreach ([$cast->cast(...), $cast->castIterableItem(...)] as $convert) {
            try {
                $convert($property, $value, [], $context);
            } catch (TypeError) {
                ++$rejected;
            }
        }

        $this->assertSame(2, $rejected);
    }

    /**
     * Provide values PHP's weak typing rejects for the target type.
     */
    public static function invalidValueProvider(): array
    {
        return [
            'non-numeric string to int' => ['int', 'abc'],
            'leading-numeric string to int' => ['int', '12abc'],
            'empty string to int' => ['int', ''],
            'not-a-number to int' => ['int', NAN],
            'non-numeric string to float' => ['float', 'x'],
            'array to int' => ['int', []],
            'array to string' => ['string', ['value']],
            'array to bool' => ['bool', []],
            'object to bool' => ['bool', new BuiltinTypeCastStringable],
        ];
    }
}

class BuiltinTypeCastStringable
{
    /**
     * Get the string form.
     */
    public function __toString(): string
    {
        return 'stringable';
    }
}

abstract class BuiltinTypeCastDataFixture implements BaseData
{
}
