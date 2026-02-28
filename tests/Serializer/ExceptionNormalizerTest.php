<?php

declare(strict_types=1);

namespace Hypervel\Tests\Serializer;

use Hypervel\Serializer\ExceptionNormalizer;
use Hypervel\Tests\Serializer\Stub\FooException;
use Hypervel\Tests\Serializer\Stub\SerializableException;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class ExceptionNormalizerTest extends TestCase
{
    public function testNormalizeAndDenormalizeStandardException()
    {
        $normalizer = new ExceptionNormalizer();
        $exception = new InvalidArgumentException('invalid param foo');

        $result = $normalizer->normalize($exception);

        $this->assertIsArray($result);
        $this->assertSame('invalid param foo', $result['message']);
        $this->assertSame(0, $result['code']);

        $restored = $normalizer->denormalize($result, InvalidArgumentException::class);

        $this->assertInstanceOf(InvalidArgumentException::class, $restored);
        $this->assertSame($exception->getMessage(), $restored->getMessage());
        $this->assertSame($exception->getCode(), $restored->getCode());
    }

    public function testNormalizeAndDenormalizeExceptionWithCustomSerialization()
    {
        $normalizer = new ExceptionNormalizer();
        $exception = new SerializableException('serializable error');

        // SerializableException has __serialize/__unserialize but doesn't implement the Serializable interface,
        // so normalize() returns an array (not a serialized string)
        $result = $normalizer->normalize($exception);

        $this->assertIsArray($result);

        $restored = $normalizer->denormalize($result, SerializableException::class);

        $this->assertInstanceOf(SerializableException::class, $restored);
        $this->assertSame($exception->getMessage(), $restored->getMessage());
        $this->assertSame($exception->getCode(), $restored->getCode());
    }

    public function testNormalizeAndDenormalizeCustomException()
    {
        $normalizer = new ExceptionNormalizer();
        $exception = new FooException(1000, 'custom error');

        $result = $normalizer->normalize($exception);

        $this->assertIsArray($result);
        $this->assertSame('custom error', $result['message']);
        $this->assertSame(1000, $result['code']);

        $restored = $normalizer->denormalize($result, FooException::class);

        $this->assertInstanceOf(FooException::class, $restored);
        $this->assertSame($exception->getMessage(), $restored->getMessage());
        $this->assertSame($exception->getCode(), $restored->getCode());
    }

    public function testDenormalizeInvalidDataReturnsRuntimeException()
    {
        $normalizer = new ExceptionNormalizer();

        $result = $normalizer->denormalize(12345, RuntimeException::class);

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertStringContainsString('Bad data data:', $result->getMessage());
    }

    public function testSupportsNormalization()
    {
        $normalizer = new ExceptionNormalizer();

        $this->assertTrue($normalizer->supportsNormalization(new RuntimeException()));
        $this->assertTrue($normalizer->supportsNormalization(new InvalidArgumentException()));
        $this->assertFalse($normalizer->supportsNormalization('not an exception'));
        $this->assertFalse($normalizer->supportsNormalization(42));
        $this->assertFalse($normalizer->supportsNormalization(null));
    }

    public function testSupportsDenormalization()
    {
        $normalizer = new ExceptionNormalizer();

        $this->assertTrue($normalizer->supportsDenormalization(null, RuntimeException::class));
        $this->assertTrue($normalizer->supportsDenormalization(null, InvalidArgumentException::class));
        $this->assertFalse($normalizer->supportsDenormalization(null, 'NonExistentClass'));
        $this->assertFalse($normalizer->supportsDenormalization(null, 'stdClass'));
    }

    public function testGetSupportedTypes()
    {
        $normalizer = new ExceptionNormalizer();

        $types = $normalizer->getSupportedTypes(null);

        $this->assertArrayHasKey('object', $types);
        $this->assertTrue($types['object']);
    }

    public function testNormalizePreservesFileAndLine()
    {
        $normalizer = new ExceptionNormalizer();
        $exception = new RuntimeException('test');

        $result = $normalizer->normalize($exception);

        $this->assertSame(__FILE__, $result['file']);
        $this->assertIsInt($result['line']);
    }
}
