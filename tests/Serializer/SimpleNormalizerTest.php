<?php

declare(strict_types=1);

namespace Hypervel\Tests\Serializer;

use Hypervel\Serializer\SimpleNormalizer;
use Hypervel\Tests\TestCase;
use stdClass;

/**
 * @internal
 * @coversNothing
 */
class SimpleNormalizerTest extends TestCase
{
    public function testNormalizeReturnsInputUnchanged()
    {
        $normalizer = new SimpleNormalizer();

        $this->assertSame('hello', $normalizer->normalize('hello'));
        $this->assertSame(42, $normalizer->normalize(42));
        $this->assertSame(3.14, $normalizer->normalize(3.14));
        $this->assertSame(true, $normalizer->normalize(true));
        $this->assertNull($normalizer->normalize(null));

        $array = ['key' => 'value'];
        $this->assertSame($array, $normalizer->normalize($array));

        $object = new stdClass();
        $this->assertSame($object, $normalizer->normalize($object));
    }

    public function testDenormalizeInt()
    {
        $normalizer = new SimpleNormalizer();

        $this->assertSame(42, $normalizer->denormalize('42', 'int'));
        $this->assertSame(0, $normalizer->denormalize('0', 'int'));
        $this->assertSame(1, $normalizer->denormalize(1.9, 'int'));
        $this->assertSame(1, $normalizer->denormalize(true, 'int'));
    }

    public function testDenormalizeString()
    {
        $normalizer = new SimpleNormalizer();

        $this->assertSame('42', $normalizer->denormalize(42, 'string'));
        $this->assertSame('3.14', $normalizer->denormalize(3.14, 'string'));
        $this->assertSame('1', $normalizer->denormalize(true, 'string'));
        $this->assertSame('', $normalizer->denormalize(false, 'string'));
    }

    public function testDenormalizeFloat()
    {
        $normalizer = new SimpleNormalizer();

        $this->assertSame(4.2, $normalizer->denormalize('4.2', 'float'));
        $this->assertSame(42.0, $normalizer->denormalize(42, 'float'));
        $this->assertSame(1.0, $normalizer->denormalize(true, 'float'));
    }

    public function testDenormalizeArray()
    {
        $normalizer = new SimpleNormalizer();

        $this->assertSame(['hello'], $normalizer->denormalize('hello', 'array'));
        $this->assertSame([42], $normalizer->denormalize(42, 'array'));
        $this->assertSame(['a', 'b'], $normalizer->denormalize(['a', 'b'], 'array'));
    }

    public function testDenormalizeBool()
    {
        $normalizer = new SimpleNormalizer();

        $this->assertTrue($normalizer->denormalize(1, 'bool'));
        $this->assertTrue($normalizer->denormalize('yes', 'bool'));
        $this->assertFalse($normalizer->denormalize(0, 'bool'));
        $this->assertFalse($normalizer->denormalize('', 'bool'));
    }

    public function testDenormalizeUnknownTypeReturnsDataUnchanged()
    {
        $normalizer = new SimpleNormalizer();

        $data = ['key' => 'value'];
        $this->assertSame($data, $normalizer->denormalize($data, 'SomeClass'));
        $this->assertSame('hello', $normalizer->denormalize('hello', 'mixed'));
        $this->assertSame(42, $normalizer->denormalize(42, 'object'));
    }
}
