<?php

declare(strict_types=1);

namespace Hypervel\Tests\Serializer;

use Hypervel\Serializer\SerializerFactory;
use Hypervel\Serializer\SymfonyNormalizer;
use Hypervel\Tests\Serializer\Stub\Foo;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

/**
 * @internal
 * @coversNothing
 */
class SymfonySerializerTest extends TestCase
{
    public function testNormalizeObject()
    {
        $serializer = $this->createSerializer();

        $object = new Foo();
        $object->int = 10;

        $result = $serializer->normalize([$object]);

        $this->assertEquals([[
            'int' => 10,
            'string' => null,
        ]], $result);
    }

    public function testNormalizeScalars()
    {
        $serializer = $this->createSerializer();

        $result = $serializer->normalize([1, '2']);

        $this->assertEquals([1, '2'], $result);
    }

    public function testDenormalizeObjectArray()
    {
        $serializer = $this->createSerializer();

        $result = $serializer->denormalize([[
            'int' => 10,
            'string' => null,
        ]], Foo::class . '[]');

        $this->assertInstanceOf(Foo::class, $result[0]);
        $this->assertSame(10, $result[0]->int);
    }

    public function testDenormalizeMixed()
    {
        $serializer = $this->createSerializer();

        $this->assertSame('1', $serializer->denormalize('1', 'mixed'));
        $this->assertSame(['1', 2, '03'], $serializer->denormalize(['1', 2, '03'], 'mixed[]'));
    }

    public function testDenormalizeScalarTypes()
    {
        $serializer = $this->createSerializer();

        $this->assertSame(1, $serializer->denormalize('1', 'int'));
        $this->assertSame([1, 2, 3], $serializer->denormalize(['1', 2, '03'], 'int[]'));
    }

    public function testNormalizeAndDenormalizeException()
    {
        $serializer = $this->createSerializer();
        $exception = new InvalidArgumentException('invalid param value foo');

        $normalized = $serializer->normalize($exception);
        $restored = $serializer->denormalize($normalized, InvalidArgumentException::class);

        $this->assertInstanceOf(InvalidArgumentException::class, $restored);
        $this->assertSame($exception->getMessage(), $restored->getMessage());
    }

    protected function createSerializer(): SymfonyNormalizer
    {
        return new SymfonyNormalizer((new SerializerFactory())->__invoke());
    }
}
