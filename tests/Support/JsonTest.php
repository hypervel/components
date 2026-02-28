<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use Hypervel\Support\Json;
use Hypervel\Tests\TestCase;
use JsonException;

/**
 * @internal
 * @coversNothing
 */
class JsonTest extends TestCase
{
    public function testEncodeArray()
    {
        $this->assertSame('{"name":"test"}', Json::encode(['name' => 'test']));
    }

    public function testEncodeString()
    {
        $this->assertSame('"hello"', Json::encode('hello'));
    }

    public function testEncodeInteger()
    {
        $this->assertSame('42', Json::encode(42));
    }

    public function testEncodeNull()
    {
        $this->assertSame('null', Json::encode(null));
    }

    public function testEncodeUnicode()
    {
        $result = Json::encode(['name' => '日本語']);

        $this->assertSame('{"name":"日本語"}', $result);
    }

    public function testEncodeJsonable()
    {
        $jsonable = new class implements Jsonable {
            public function toJson(int $options = 0): string
            {
                return '{"custom":true}';
            }
        };

        $this->assertSame('{"custom":true}', Json::encode($jsonable));
    }

    public function testEncodeArrayable()
    {
        $arrayable = new class implements Arrayable {
            public function toArray(): array
            {
                return ['key' => 'value'];
            }
        };

        $this->assertSame('{"key":"value"}', Json::encode($arrayable));
    }

    public function testDecodeReturnsArray()
    {
        $result = Json::decode('{"name":"test","count":5}');

        $this->assertSame(['name' => 'test', 'count' => 5], $result);
    }

    public function testDecodeReturnsObject()
    {
        $result = Json::decode('{"name":"test"}', false);

        $this->assertIsObject($result);
        $this->assertSame('test', $result->name);
    }

    public function testDecodeThrowsOnInvalidJson()
    {
        $this->expectException(JsonException::class);

        Json::decode('{invalid}');
    }

    public function testEncodeThrowsOnInvalidValue()
    {
        $this->expectException(JsonException::class);

        Json::encode(NAN);
    }
}
