<?php

declare(strict_types=1);

namespace Hypervel\Tests\Serializer;

use Hypervel\Serializer\JsonDeNormalizer;
use Hypervel\Support\Json;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class JsonDeNormalizerTest extends TestCase
{
    public function testJsonDeSerializable()
    {
        $foo = new Foo(1, 'Hypervel');

        $this->assertSame($json = '{"id":1,"name":"Hypervel"}', Json::encode($foo));

        $foo = Foo::jsonDeSerialize(Json::decode($json));

        $this->assertSame(1, $foo->id);
        $this->assertSame('Hypervel', $foo->name);
    }

    public function testDenormalizeWithJsonDeSerializeMethod()
    {
        $normalizer = new JsonDeNormalizer();

        $json = '{"id":1,"name":"Hypervel"}';

        $foo = $normalizer->denormalize(Json::decode($json), Foo::class);

        $this->assertInstanceOf(Foo::class, $foo);
        $this->assertSame(1, $foo->id);
        $this->assertSame('Hypervel', $foo->name);
    }

    public function testNormalizeReturnsObjectUnchanged()
    {
        $normalizer = new JsonDeNormalizer();

        $data = ['key' => 'value'];
        $this->assertSame($data, $normalizer->normalize($data));

        $this->assertSame('hello', $normalizer->normalize('hello'));
        $this->assertSame(42, $normalizer->normalize(42));
    }

    public function testDenormalizeScalarTypes()
    {
        $normalizer = new JsonDeNormalizer();

        $this->assertSame(42, $normalizer->denormalize('42', 'int'));
        $this->assertSame('42', $normalizer->denormalize(42, 'string'));
        $this->assertSame(4.2, $normalizer->denormalize('4.2', 'float'));
        $this->assertSame(true, $normalizer->denormalize(1, 'bool'));
        $this->assertSame(false, $normalizer->denormalize(0, 'bool'));
        $this->assertSame(['value'], $normalizer->denormalize('value', 'array'));
    }

    public function testDenormalizeMixedReturnsDataUnchanged()
    {
        $normalizer = new JsonDeNormalizer();

        $this->assertSame('hello', $normalizer->denormalize('hello', 'mixed'));
        $this->assertSame(42, $normalizer->denormalize(42, 'mixed'));
        $this->assertSame(['a' => 'b'], $normalizer->denormalize(['a' => 'b'], 'mixed'));
    }

    public function testDenormalizeUnknownClassWithoutJsonDeSerializeReturnsData()
    {
        $normalizer = new JsonDeNormalizer();

        $data = ['key' => 'value'];
        $result = $normalizer->denormalize($data, 'NonExistentClass');

        $this->assertSame($data, $result);
    }
}
