<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Creation;

use Hypervel\Data\Normalizers\Normalized\Normalized;
use Hypervel\Data\Normalizers\Normalized\UnknownProperty;
use Hypervel\Data\Support\Creation\SourceReader;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Tests\TestCase;

class SourceReaderTest extends TestCase
{
    /**
     * Test present and missing array values remain distinct.
     */
    public function testReadsArraySourcesWithoutConflatingNullAndMissing(): void
    {
        $property = $this->property();

        $this->assertSame('Hello', SourceReader::read(['title' => 'Hello'], ['title'], $property));
        $this->assertNull(SourceReader::read(['title' => null], ['title'], $property));
        $this->assertSame(UnknownProperty::create(), SourceReader::read([], ['title'], $property));
    }

    /**
     * Test mapped dot paths traverse arrays and normalized root values.
     */
    public function testReadsMappedDotPaths(): void
    {
        $property = $this->property();
        $normalized = new class implements Normalized {
            public function getProperty(string $name, DataProperty $dataProperty): mixed
            {
                return $name === 'profile'
                    ? ['contact' => ['email' => null]]
                    : UnknownProperty::create();
            }
        };

        $this->assertSame('Taylor', SourceReader::read(
            ['people' => [['name' => 'Taylor']]],
            ['people', '0', 'name'],
            $property,
        ));
        $this->assertNull(SourceReader::read($normalized, ['profile', 'contact', 'email'], $property));
        $this->assertSame(
            UnknownProperty::create(),
            SourceReader::read($normalized, ['profile', 'contact', 'phone'], $property),
        );
    }

    /**
     * Test mapped path segments are read literally.
     */
    public function testReadsSpecialPathSegmentsAsLiteralKeys(): void
    {
        $property = $this->property();
        $source = [
            'values' => [
                '*' => 'asterisk',
                '{first}' => 'first',
                '{last}' => 'last',
            ],
        ];

        $this->assertSame('asterisk', SourceReader::read($source, ['values', '*'], $property));
        $this->assertSame('first', SourceReader::read($source, ['values', '{first}'], $property));
        $this->assertSame('last', SourceReader::read($source, ['values', '{last}'], $property));
    }

    /**
     * Test nested object reads preserve public and magic null boundaries.
     */
    public function testReadsAccessibleObjectPropertiesWithoutExposingOtherState(): void
    {
        $property = $this->property();
        $object = new class {
            public ?string $publicNull = null;

            public string $uninitialized;

            protected ?string $protectedNull = null;

            public function __isset(string $name): bool
            {
                return $name === 'magicNull';
            }

            public function __get(string $name): mixed
            {
                return null;
            }
        };
        $source = ['object' => $object];

        $this->assertNull(SourceReader::read($source, ['object', 'publicNull'], $property));
        $this->assertNull(SourceReader::read($source, ['object', 'magicNull'], $property));
        $this->assertSame(
            UnknownProperty::create(),
            SourceReader::read($source, ['object', 'protectedNull'], $property),
        );
        $this->assertSame(
            UnknownProperty::create(),
            SourceReader::read($source, ['object', 'uninitialized'], $property),
        );
    }

    /**
     * Test normalized sources receive the property metadata.
     */
    public function testReadsNormalizedSources(): void
    {
        $property = $this->property();
        $normalized = new class($property) implements Normalized {
            public function __construct(
                private readonly DataProperty $expectedProperty,
            ) {
            }

            public function getProperty(string $name, DataProperty $dataProperty): mixed
            {
                if ($dataProperty !== $this->expectedProperty) {
                    return UnknownProperty::create();
                }

                return $name === 'title' ? 'Hello' : UnknownProperty::create();
            }
        };

        $this->assertSame('Hello', SourceReader::read($normalized, ['title'], $property));
        $this->assertSame(UnknownProperty::create(), SourceReader::read($normalized, ['missing'], $property));
    }

    /**
     * Create property metadata opaque to the reader.
     */
    protected function property(): DataProperty
    {
        return $this->createStub(DataProperty::class);
    }
}
