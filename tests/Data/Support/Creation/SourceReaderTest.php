<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Creation;

use Hypervel\Data\Normalizers\Normalized\Normalized;
use Hypervel\Data\Normalizers\Normalized\UnknownProperty;
use Hypervel\Data\Optional;
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

        $this->assertSame('Hello', SourceReader::read(['title' => 'Hello'], 'title', $property));
        $this->assertNull(SourceReader::read(['title' => null], 'title', $property));
        $this->assertSame(UnknownProperty::create(), SourceReader::read([], 'title', $property));
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
            'people.0.name',
            $property,
        ));
        $this->assertNull(SourceReader::read($normalized, 'profile.contact.email', $property));
        $this->assertSame(
            UnknownProperty::create(),
            SourceReader::read($normalized, 'profile.contact.phone', $property),
        );
    }

    /**
     * Test normalized sources receive the property metadata.
     */
    public function testReadsNormalizedSources(): void
    {
        $property = $this->property();
        $normalized = new class ($property) implements Normalized {
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

        $this->assertSame('Hello', SourceReader::read($normalized, 'title', $property));
        $this->assertSame(UnknownProperty::create(), SourceReader::read($normalized, 'missing', $property));
    }

    /**
     * Test the first source containing a key owns its value.
     */
    public function testFirstPresentSourceWinsIncludingNullAndOptional(): void
    {
        $property = $this->property();
        $optional = Optional::create();

        $this->assertSame('First', SourceReader::readFromMany(
            [[], ['title' => 'First'], ['title' => 'Second']],
            'title',
            $property,
        ));
        $this->assertNull(SourceReader::readFromMany(
            [['title' => null], ['title' => 'Second']],
            'title',
            $property,
        ));
        $this->assertSame($optional, SourceReader::readFromMany(
            [['title' => $optional], ['title' => 'Second']],
            'title',
            $property,
        ));
        $this->assertSame(UnknownProperty::create(), SourceReader::readFromMany(
            [[], []],
            'title',
            $property,
        ));
    }

    /**
     * Create property metadata opaque to the reader.
     */
    protected function property(): DataProperty
    {
        return $this->createStub(DataProperty::class);
    }
}
