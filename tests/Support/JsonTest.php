<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use Hypervel\Support\Json;
use Hypervel\Tests\TestCase;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use ValueError;

class JsonTest extends TestCase
{
    public function testEncodeArray(): void
    {
        $this->assertSame('{"name":"test"}', Json::encode(['name' => 'test']));
    }

    public function testEncodeString(): void
    {
        $this->assertSame('"hello"', Json::encode('hello'));
    }

    public function testEncodeInteger(): void
    {
        $this->assertSame('42', Json::encode(42));
    }

    public function testEncodeNull(): void
    {
        $this->assertSame('null', Json::encode(null));
    }

    public function testEncodeUnicode(): void
    {
        $result = Json::encode(['name' => '日本語']);

        $this->assertSame('{"name":"日本語"}', $result);
    }

    public function testEncodeJsonable(): void
    {
        $jsonable = new class implements Jsonable {
            public int $options = 0;

            public function toJson(int $options = 0): string
            {
                $this->options = $options;

                return json_encode(['name' => '日本語'], $options);
            }
        };

        $this->assertSame('{"name":"日本語"}', Json::encode($jsonable));
        $this->assertSame(JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR, $jsonable->options);

        Json::encode($jsonable, JSON_PRETTY_PRINT);

        $this->assertSame(JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR, $jsonable->options);
    }

    public function testEncodeArrayable(): void
    {
        $arrayable = new class implements Arrayable {
            public function toArray(): array
            {
                return ['key' => 'value'];
            }
        };

        $this->assertSame('{"key":"value"}', Json::encode($arrayable));
    }

    public function testDecodeReturnsArray(): void
    {
        $result = Json::decode('{"name":"test","count":5}');

        $this->assertSame(['name' => 'test', 'count' => 5], $result);
    }

    public function testDecodeReturnsObject(): void
    {
        $result = Json::decode('{"name":"test"}', false);

        $this->assertIsObject($result);
        $this->assertSame('test', $result->name);
    }

    public function testDecodePassesCallerFlagsToNativeJsonDecoder(): void
    {
        $this->assertSame(
            ['number' => '12345678901234567890'],
            Json::decode('{"number":12345678901234567890}', flags: JSON_BIGINT_AS_STRING),
        );
    }

    public function testDecodeThrowsOnInvalidJson(): void
    {
        $this->expectException(JsonException::class);

        Json::decode('{invalid}');
    }

    public function testEncodeThrowsOnInvalidValue(): void
    {
        $this->expectException(JsonException::class);

        Json::encode(NAN);
    }

    public function testDefaultMaximumNestingDepthRoundTrips(): void
    {
        $value = $this->nestedValue(Json::MAXIMUM_NESTING_DEPTH);
        $json = Json::encode($value);

        $this->assertSame($value, Json::decode($json));
        $this->assertTrue(Json::validate($json));
    }

    public function testExplicitMaximumNestingDepthRoundTrips(): void
    {
        $value = $this->nestedValue(8);
        $json = Json::encode($value, depth: 8);

        $this->assertSame($value, Json::decode($json, depth: 8));
        $this->assertTrue(Json::validate($json, depth: 8));
    }

    public function testOneLevelOverMaximumFailsEncoding(): void
    {
        $this->expectException(JsonException::class);

        Json::encode($this->nestedValue(Json::MAXIMUM_NESTING_DEPTH + 1));
    }

    public function testDecodeAndValidateRejectOneLevelOverMaximum(): void
    {
        $json = json_encode(
            $this->nestedValue(Json::MAXIMUM_NESTING_DEPTH + 1),
            JSON_THROW_ON_ERROR,
            Json::MAXIMUM_NESTING_DEPTH + 1
        );

        $this->assertFalse(Json::validate($json));

        $this->expectException(JsonException::class);

        Json::decode($json);
    }

    #[DataProvider('invalidDepths')]
    public function testDecodeRetainsNativeValueErrorsForInvalidPublicDepths(int $depth): void
    {
        $this->expectException(ValueError::class);

        Json::decode('null', depth: $depth);
    }

    #[DataProvider('invalidDepths')]
    public function testValidateRetainsNativeValueErrorsForInvalidPublicDepths(int $depth): void
    {
        $this->expectException(ValueError::class);

        Json::validate('null', depth: $depth);
    }

    public static function invalidDepths(): array
    {
        return [[0], [-1], [PHP_INT_MAX]];
    }

    public function testValidateReturnsFalseForMalformedJson(): void
    {
        $this->assertFalse(Json::validate('{invalid}'));
    }

    public function testValidateSupportsInvalidUtf8Ignore(): void
    {
        $this->assertFalse(Json::validate("\"\xB1\""));
        $this->assertTrue(Json::validate("\"\xB1\"", flags: JSON_INVALID_UTF8_IGNORE));
    }

    public function testValidateRejectsUnsupportedFlags(): void
    {
        $this->expectException(ValueError::class);

        Json::validate('null', flags: JSON_THROW_ON_ERROR);
    }

    private function nestedValue(int $containers): array|string
    {
        $value = 'leaf';

        for ($index = 0; $index < $containers; ++$index) {
            $value = ['value' => $value];
        }

        return $value;
    }
}
