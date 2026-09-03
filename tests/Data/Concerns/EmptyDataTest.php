<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Concerns\EmptyDataTest;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\MapOutputName;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Exceptions\DataPropertyCanOnlyHaveOneType;
use Hypervel\Data\Lazy;
use Hypervel\Data\Resource;
use Hypervel\Support\Collection;
use Hypervel\Testbench\TestCase;

class EmptyDataTest extends TestCase
{
    /**
     * Get package providers for the empty data test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    /**
     * Test empty values derive from declared property types.
     */
    public function testCreatesEmptyRepresentation(): void
    {
        $this->assertSame([
            'property' => null,
            'lazyProperty' => null,
            'array' => [],
            'collection' => [],
            'data' => ['value' => null],
            'lazyData' => ['value' => null],
            'mapped_value' => null,
            'defaultProperty' => true,
        ], EmptyShapeData::empty());
    }

    /**
     * Test explicit values and a custom empty scalar are supported.
     */
    public function testOverridesEmptyValues(): void
    {
        $this->assertSame([
            'value' => 'supplied',
        ], SimpleEmptyData::empty(['value' => 'supplied'], '?'));

        $this->assertSame([
            'value' => '?',
        ], SimpleEmptyData::empty(replaceNullValuesWith: '?'));
    }

    /**
     * Test only and except filter the output shape.
     */
    public function testFiltersEmptyRepresentation(): void
    {
        $this->assertSame([
            'second' => null,
        ], FilteredEmptyData::empty(except: ['first'], only: ['first', 'second']));
    }

    /**
     * Test ambiguous property types require an explicit value.
     */
    public function testRejectsAmbiguousPropertyTypeWithoutOverride(): void
    {
        $this->expectException(DataPropertyCanOnlyHaveOneType::class);
        $this->expectExceptionMessageIsOrContains(AmbiguousEmptyData::class . '::$value');

        AmbiguousEmptyData::empty();
    }

    /**
     * Test explicit values resolve ambiguous property types.
     */
    public function testAcceptsOverrideForAmbiguousPropertyType(): void
    {
        $this->assertSame(['value' => 1], AmbiguousEmptyData::empty(['value' => 1]));
    }

    /**
     * Test constructor object defaults are fresh for each empty call.
     */
    public function testDoesNotRetainDefaultObjectsInMetadata(): void
    {
        $first = DefaultObjectData::empty()['value'];
        $second = DefaultObjectData::empty()['value'];

        $this->assertInstanceOf(EmptyDefaultObject::class, $first);
        $this->assertInstanceOf(EmptyDefaultObject::class, $second);
        $this->assertNotSame($first, $second);
    }

    /**
     * Test resources expose the empty representation capability.
     */
    public function testResourceCreatesEmptyRepresentation(): void
    {
        $this->assertSame(['value' => null], EmptyResource::empty());
    }
}

class EmptyShapeData extends Data
{
    public string $property;

    public string|Lazy $lazyProperty;

    public array $array;

    public Collection $collection;

    public SimpleEmptyData $data;

    public Lazy|SimpleEmptyData $lazyData;

    #[MapOutputName('mapped_value')]
    public string $mappedValue;

    public bool $defaultProperty = true;
}

class SimpleEmptyData extends Data
{
    public string $value;
}

class FilteredEmptyData extends Data
{
    public string $first;

    public string $second;
}

class AmbiguousEmptyData extends Data
{
    public int|string $value;
}

class DefaultObjectData extends Data
{
    public function __construct(public EmptyDefaultObject $value = new EmptyDefaultObject)
    {
    }
}

class EmptyDefaultObject
{
}

class EmptyResource extends Resource
{
    public string $value;
}
