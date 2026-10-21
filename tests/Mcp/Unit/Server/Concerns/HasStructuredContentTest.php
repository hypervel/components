<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Server\Concerns;

use Hypervel\Mcp\Server\Concerns\HasStructuredContent;
use Hypervel\Tests\TestCase;

class HasStructuredContentTest extends TestCase
{
    public function testCanSetStructuredContentWithArray(): void
    {
        $object = new class {
            use HasStructuredContent;

            /**
             * Get the stored structured content.
             */
            public function getStructuredContent(): ?array
            {
                return $this->structuredContent;
            }
        };

        $object->setStructuredContent(['key1' => 'value1', 'key2' => 'value2']);

        $this->assertSame([
            'key1' => 'value1',
            'key2' => 'value2',
        ], $object->getStructuredContent());
    }

    public function testMergesStructuredContentIntoBaseArray(): void
    {
        $object = new class {
            use HasStructuredContent;
        };

        $object->setStructuredContent(['temperature' => 22.5, 'humidity' => 65]);

        $result = $object->mergeStructuredContent([
            'content' => [['type' => 'text', 'text' => 'Weather data']],
            'isError' => false,
        ]);

        $this->assertSame([
            'content' => [['type' => 'text', 'text' => 'Weather data']],
            'isError' => false,
            'structuredContent' => [
                'temperature' => 22.5,
                'humidity' => 65,
            ],
        ], $result);
    }

    public function testReturnsBaseArrayWhenStructuredContentIsNull(): void
    {
        $object = new class {
            use HasStructuredContent;
        };

        $result = $object->mergeStructuredContent([
            'content' => [['type' => 'text', 'text' => 'Weather data']],
            'isError' => false,
        ]);

        $this->assertSame([
            'content' => [['type' => 'text', 'text' => 'Weather data']],
            'isError' => false,
        ], $result);
        $this->assertArrayNotHasKey('structuredContent', $result);
    }

    public function testMergesMultipleSetStructuredContentCallsWithArrays(): void
    {
        $object = new class {
            use HasStructuredContent;

            /**
             * Get the stored structured content.
             */
            public function getStructuredContent(): ?array
            {
                return $this->structuredContent;
            }
        };

        $object->setStructuredContent(['key1' => 'value1']);
        $object->setStructuredContent(['key2' => 'value2', 'key3' => 'value3']);

        $this->assertSame([
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ], $object->getStructuredContent());
    }

    public function testOverwritesExistingKeysWhenSettingStructuredContent(): void
    {
        $object = new class {
            use HasStructuredContent;

            /**
             * Get the stored structured content.
             */
            public function getStructuredContent(): ?array
            {
                return $this->structuredContent;
            }
        };

        $object->setStructuredContent(['key1' => 'value1']);
        $object->setStructuredContent(['key1' => 'value2']);

        $this->assertSame([
            'key1' => 'value2',
        ], $object->getStructuredContent());
    }
}
