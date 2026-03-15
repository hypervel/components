<?php

declare(strict_types=1);

namespace Hypervel\Tests\Serializer;

use Hypervel\Serializer\ScalarNormalizer;
use Hypervel\Tests\TestCase;
use stdClass;

/**
 * @internal
 * @coversNothing
 */
class ScalarNormalizerTest extends TestCase
{
    public function testSupportsNormalizationForScalars()
    {
        $normalizer = new ScalarNormalizer();

        $this->assertTrue($normalizer->supportsNormalization(42));
        $this->assertTrue($normalizer->supportsNormalization('hello'));
        $this->assertTrue($normalizer->supportsNormalization(3.14));
        $this->assertTrue($normalizer->supportsNormalization(true));
        $this->assertTrue($normalizer->supportsNormalization(false));
    }

    public function testDoesNotSupportNormalizationForNonScalars()
    {
        $normalizer = new ScalarNormalizer();

        $this->assertFalse($normalizer->supportsNormalization(null));
        $this->assertFalse($normalizer->supportsNormalization([]));
        $this->assertFalse($normalizer->supportsNormalization(new stdClass()));
    }

    public function testNormalizeReturnsScalarUnchanged()
    {
        $normalizer = new ScalarNormalizer();

        $this->assertSame(42, $normalizer->normalize(42));
        $this->assertSame('hello', $normalizer->normalize('hello'));
        $this->assertSame(3.14, $normalizer->normalize(3.14));
        $this->assertTrue($normalizer->normalize(true));
    }

    public function testSupportsDenormalizationForKnownTypes()
    {
        $normalizer = new ScalarNormalizer();

        $this->assertTrue($normalizer->supportsDenormalization(null, 'int'));
        $this->assertTrue($normalizer->supportsDenormalization(null, 'string'));
        $this->assertTrue($normalizer->supportsDenormalization(null, 'float'));
        $this->assertTrue($normalizer->supportsDenormalization(null, 'bool'));
        $this->assertTrue($normalizer->supportsDenormalization(null, 'mixed'));
        $this->assertTrue($normalizer->supportsDenormalization(null, 'array'));
    }

    public function testDoesNotSupportDenormalizationForUnknownTypes()
    {
        $normalizer = new ScalarNormalizer();

        $this->assertFalse($normalizer->supportsDenormalization(null, 'object'));
        $this->assertFalse($normalizer->supportsDenormalization(null, stdClass::class));
        $this->assertFalse($normalizer->supportsDenormalization(null, 'SomeClass'));
    }

    public function testDenormalizeInt()
    {
        $normalizer = new ScalarNormalizer();

        $this->assertSame(42, $normalizer->denormalize('42', 'int'));
        $this->assertSame(0, $normalizer->denormalize('abc', 'int'));
    }

    public function testDenormalizeString()
    {
        $normalizer = new ScalarNormalizer();

        $this->assertSame('42', $normalizer->denormalize(42, 'string'));
    }

    public function testDenormalizeFloat()
    {
        $normalizer = new ScalarNormalizer();

        $this->assertSame(4.2, $normalizer->denormalize('4.2', 'float'));
    }

    public function testDenormalizeBool()
    {
        $normalizer = new ScalarNormalizer();

        $this->assertTrue($normalizer->denormalize(1, 'bool'));
        $this->assertFalse($normalizer->denormalize(0, 'bool'));
    }

    public function testDenormalizeUnknownTypeReturnsDataUnchanged()
    {
        $normalizer = new ScalarNormalizer();

        $this->assertSame('hello', $normalizer->denormalize('hello', 'unknown'));
    }

    public function testGetSupportedTypes()
    {
        $normalizer = new ScalarNormalizer();

        $types = $normalizer->getSupportedTypes(null);

        $this->assertArrayHasKey('*', $types);
        $this->assertTrue($types['*']);
    }
}
