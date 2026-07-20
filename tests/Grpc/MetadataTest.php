<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Hypervel\Grpc\Metadata;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class MetadataTest extends TestCase
{
    public function testNormalizesKeysAndPreservesValueOrder(): void
    {
        $metadata = Metadata::make([
            'X-Tag' => ['one', 'two'],
            'empty' => '',
            'trace-bin' => "\x00\xff raw ",
        ]);

        $this->assertSame([
            'x-tag' => ['one', 'two'],
            'empty' => [''],
            'trace-bin' => ["\x00\xff raw "],
        ], $metadata->all());
        $this->assertSame($metadata->all(), iterator_to_array($metadata));
        $this->assertSame(3, $metadata->count());
        $this->assertFalse($metadata->isEmpty());
    }

    public function testReadsExistingAndMissingValues(): void
    {
        $metadata = Metadata::make(['X-Tag' => ['one', 'two']]);

        $this->assertTrue($metadata->has('x-tag'));
        $this->assertSame('one', $metadata->first('X-TAG'));
        $this->assertSame(['one', 'two'], $metadata->values('x-tag'));
        $this->assertFalse($metadata->has('missing'));
        $this->assertSame('fallback', $metadata->first('missing', 'fallback'));
        $this->assertSame([], $metadata->values('missing'));
    }

    public function testWithWithoutAndMergeReturnIndependentCollections(): void
    {
        $original = Metadata::make([
            'x-tag' => 'one',
            'x-remove' => 'value',
        ]);

        $modified = $original
            ->with('X-Tag', 'two', 'three')
            ->merge(['x-tag' => 'four', 'x-added' => ['a', 'b']])
            ->merge(Metadata::make(['x-added' => 'c']))
            ->without('X-Remove');

        $this->assertSame([
            'x-tag' => ['one'],
            'x-remove' => ['value'],
        ], $original->all());
        $this->assertSame([
            'x-tag' => ['one', 'two', 'three', 'four'],
            'x-added' => ['a', 'b', 'c'],
        ], $modified->all());
        $this->assertSame($modified, $modified->without('missing'));
    }

    public function testEmptyCollectionHasNoValues(): void
    {
        $metadata = Metadata::make();

        $this->assertTrue($metadata->isEmpty());
        $this->assertSame(0, $metadata->count());
        $this->assertSame([], iterator_to_array($metadata));
    }

    public function testRejectsInvalidAndReservedKeysWithoutBlockingAuthorization(): void
    {
        foreach ([
            '',
            ':path',
            'has space',
            'grpc-status',
            ...Metadata::OWNED_KEYS,
        ] as $key) {
            try {
                Metadata::make([$key => 'value']);
                $this->fail("Expected metadata key [{$key}] to be rejected.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(
            ['authorization' => ['Bearer token']],
            Metadata::make(['Authorization' => 'Bearer token'])->all(),
        );
    }

    public function testRejectsInvalidValueCollections(): void
    {
        foreach ([
            ['x-tag' => []],
            ['x-tag' => [1 => 'value']],
            ['x-tag' => [123]],
        ] as $values) {
            try {
                Metadata::make($values);
                $this->fail('Expected the metadata value collection to be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testRejectsInvalidAsciiValuesButAllowsArbitraryBinaryValues(): void
    {
        foreach (["\n", "\t", "\xff", ' leading', 'trailing '] as $value) {
            try {
                Metadata::make(['x-tag' => $value]);
                $this->fail('Expected the ASCII metadata value to be rejected.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(
            ['trace-bin' => ["\n\t\xff surrounded "]],
            Metadata::make(['trace-bin' => "\n\t\xff surrounded "])->all(),
        );
    }

    public function testWithRequiresAtLeastOneValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('At least one gRPC metadata value is required.');

        Metadata::make()->with('x-tag');
    }
}
