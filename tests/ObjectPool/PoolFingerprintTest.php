<?php

declare(strict_types=1);

namespace Hypervel\Tests\ObjectPool;

use Hypervel\ObjectPool\PoolFingerprint;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use stdClass;

enum FingerprintBackedEnum: string
{
    case First = 'value';
}

enum FingerprintUnitEnum
{
    case First;
}

class PoolFingerprintTest extends TestCase
{
    public function testMapOrderDoesNotAffectTheFingerprint(): void
    {
        $this->assertSame(
            PoolFingerprint::fromConfig(['alpha' => 1, 'beta' => 2]),
            PoolFingerprint::fromConfig(['beta' => 2, 'alpha' => 1])
        );
    }

    public function testNestedMapOrderDoesNotAffectTheFingerprint(): void
    {
        $this->assertSame(
            PoolFingerprint::fromConfig([
                'outer' => ['alpha' => 1, 'beta' => ['first' => true, 'second' => null]],
            ]),
            PoolFingerprint::fromConfig([
                'outer' => ['beta' => ['second' => null, 'first' => true], 'alpha' => 1],
            ])
        );
    }

    public function testListOrderAffectsTheFingerprint(): void
    {
        $this->assertNotSame(
            PoolFingerprint::fromConfig(['values' => ['first', 'second']]),
            PoolFingerprint::fromConfig(['values' => ['second', 'first']])
        );
    }

    public function testListsAndMapsHaveDistinctFingerprints(): void
    {
        $this->assertNotSame(
            PoolFingerprint::fromConfig(['values' => ['first', 'second']]),
            PoolFingerprint::fromConfig(['values' => [0 => 'first', 2 => 'second']])
        );
    }

    public function testScalarTypesHaveDistinctFingerprints(): void
    {
        $fingerprints = array_map(
            static fn (mixed $value): string => PoolFingerprint::fromConfig(['value' => $value]),
            [null, false, 0, 0.0, '0']
        );

        $this->assertCount(count($fingerprints), array_unique($fingerprints));
    }

    public function testIntegerAndStringMapKeysAreTagged(): void
    {
        $this->assertNotSame(
            PoolFingerprint::fromConfig([1 => 'value']),
            PoolFingerprint::fromConfig(['01' => 'value'])
        );
    }

    public function testMixedMapKeyOrderIsCanonical(): void
    {
        $this->assertSame(
            PoolFingerprint::fromConfig([1 => 'integer', '01' => 'string']),
            PoolFingerprint::fromConfig(['01' => 'string', 1 => 'integer'])
        );
    }

    public function testEnumsAreTaggedByClassAndValue(): void
    {
        $backed = PoolFingerprint::fromConfig(['value' => FingerprintBackedEnum::First]);
        $unit = PoolFingerprint::fromConfig(['value' => FingerprintUnitEnum::First]);

        $this->assertNotSame($backed, $unit);
        $this->assertNotSame(
            $backed,
            PoolFingerprint::fromConfig([
                'value' => [FingerprintBackedEnum::class, FingerprintBackedEnum::First->value],
            ])
        );
    }

    public function testExplicitAndAutomaticFingerprintsUseDistinctDomains(): void
    {
        $automatic = PoolFingerprint::fromConfig(['value' => 'same-input']);
        $explicit = PoolFingerprint::fromExplicit('same-input');

        $this->assertStringStartsWith('auto:', $automatic);
        $this->assertStringStartsWith('explicit:', $explicit);
        $this->assertNotSame($automatic, $explicit);
    }

    public function testCanonicalizationDoesNotMutateItsInput(): void
    {
        $config = ['nested' => ['beta' => 2, 'alpha' => 1]];
        $original = $config;

        PoolFingerprint::fromConfig($config);

        $this->assertSame($original, $config);
    }

    public function testObjectsAreRejectedWithTheirKeyPath(): void
    {
        try {
            PoolFingerprint::fromConfig(['outer' => ['inner' => new stdClass]]);
            $this->fail('Expected the object to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('at [$.outer.inner] is of type [stdClass]', $exception->getMessage());
            $this->assertStringContainsString('pool config\'s "fingerprint" key', $exception->getMessage());
        }
    }

    public function testClosuresAreRejectedWithTheirListPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at [$.values[1]] is of type [Closure]');

        PoolFingerprint::fromConfig(['values' => ['first', static fn (): null => null]]);
    }

    public function testResourcesAreRejectedWithTheirKeyPath(): void
    {
        $stream = fopen('php://temp', 'r+');
        $this->assertIsResource($stream);

        try {
            PoolFingerprint::fromConfig(['stream' => $stream]);
            $this->fail('Expected the resource to be rejected.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('at [$.stream] is of type [resource (stream)]', $exception->getMessage());
        } finally {
            fclose($stream);
        }
    }
}
