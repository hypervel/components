<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Server\Concerns;

use Hypervel\Mcp\Server\Concerns\HasMeta;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class HasMetaTest extends TestCase
{
    public function testCanSetMetaWithAnArray(): void
    {
        $object = new class {
            use HasMeta;

            /**
             * Get the stored metadata.
             */
            public function getMeta(): ?array
            {
                return $this->meta;
            }
        };

        $object->setMeta(['key1' => 'value1', 'key2' => 'value2']);

        $this->assertSame([
            'key1' => 'value1',
            'key2' => 'value2',
        ], $object->getMeta());
    }

    public function testCanSetMetaWithAKeyValueSignature(): void
    {
        $object = new class {
            use HasMeta;

            /**
             * Get the stored metadata.
             */
            public function getMeta(): ?array
            {
                return $this->meta;
            }
        };

        $object->setMeta('key1', 'value1');
        $object->setMeta('key2', 'value2');

        $this->assertSame([
            'key1' => 'value1',
            'key2' => 'value2',
        ], $object->getMeta());
    }

    public function testThrowsExceptionWhenUsingKeyValueSignatureWithoutValue(): void
    {
        $object = new class {
            use HasMeta;
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Value is required when using key-value signature.');
        $object->setMeta('key1');
    }

    public function testMergesMetaIntoBaseArray(): void
    {
        $object = new class {
            use HasMeta;
        };

        $object->setMeta(['key1' => 'value1']);

        $result = $object->mergeMeta([
            'name' => 'test',
            'description' => 'A test',
        ]);

        $this->assertSame([
            'name' => 'test',
            'description' => 'A test',
            '_meta' => [
                'key1' => 'value1',
            ],
        ], $result);
    }

    public function testReturnsBaseArrayWhenMetaIsNull(): void
    {
        $object = new class {
            use HasMeta;
        };

        $result = $object->mergeMeta([
            'name' => 'test',
            'description' => 'A test',
        ]);

        $this->assertSame([
            'name' => 'test',
            'description' => 'A test',
        ], $result);
        $this->assertArrayNotHasKey('_meta', $result);
    }

    public function testMergesMultipleSetMetaCallsWithArrays(): void
    {
        $object = new class {
            use HasMeta;

            /**
             * Get the stored metadata.
             */
            public function getMeta(): ?array
            {
                return $this->meta;
            }
        };

        $object->setMeta(['key1' => 'value1']);
        $object->setMeta(['key2' => 'value2', 'key3' => 'value3']);

        $this->assertSame([
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ], $object->getMeta());
    }

    public function testOverwritesExistingKeysWhenSettingMeta(): void
    {
        $object = new class {
            use HasMeta;

            /**
             * Get the stored metadata.
             */
            public function getMeta(): ?array
            {
                return $this->meta;
            }
        };

        $object->setMeta(['key1' => 'value1']);
        $object->setMeta(['key1' => 'value2']);

        $this->assertSame([
            'key1' => 'value2',
        ], $object->getMeta());
    }
}
