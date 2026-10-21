<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\DataTest;

use Hypervel\Contracts\Database\Eloquent\Castable;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Http\RequestCastable;
use Hypervel\Data\Concerns\AppendableData;
use Hypervel\Data\Concerns\BaseData;
use Hypervel\Data\Concerns\EmptyData;
use Hypervel\Data\Concerns\IncludeableData;
use Hypervel\Data\Concerns\ResponsableData;
use Hypervel\Data\Concerns\TransformableData;
use Hypervel\Data\Concerns\ValidateableData;
use Hypervel\Data\Concerns\WrappableData;
use Hypervel\Data\Contracts\AppendableData as AppendableDataContract;
use Hypervel\Data\Contracts\BaseData as BaseDataContract;
use Hypervel\Data\Contracts\EmptyData as EmptyDataContract;
use Hypervel\Data\Contracts\IncludeableData as IncludeableDataContract;
use Hypervel\Data\Contracts\ResponsableData as ResponsableDataContract;
use Hypervel\Data\Contracts\TransformableData as TransformableDataContract;
use Hypervel\Data\Contracts\ValidateableData as ValidateableDataContract;
use Hypervel\Data\Contracts\WrappableData as WrappableDataContract;
use Hypervel\Data\CursorPaginatedDataCollection;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Dto;
use Hypervel\Data\PaginatedDataCollection;
use Hypervel\Data\Resource;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\SimpleDto;
use Hypervel\Tests\Data\Fixtures\SimpleResource;
use Hypervel\Validation\ValidationException;

class DataTest extends TestCase
{
    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testAlsoWorksByUsingTraitsAndInterfacesSkippingTheBaseDataClass(): void
    {
        // REMOVED: ContextableData; partial selections and wrapping live on IncludeableData and WrappableData.
        $data = new class('') implements AppendableDataContract, BaseDataContract, TransformableDataContract, IncludeableDataContract, ResponsableDataContract, ValidateableDataContract, WrappableDataContract, EmptyDataContract {
            use ResponsableData;
            use IncludeableData;
            use AppendableData;
            use ValidateableData;
            use WrappableData;
            use TransformableData;
            use BaseData;
            use EmptyData;

            /**
             * Create the data object from its string.
             */
            public function __construct(public string $string)
            {
            }

            /**
             * Create the data object from a string.
             */
            public static function fromString(string $string): static
            {
                return new self($string);
            }
        };

        $this->assertSame(['string' => 'Hi'], $data::from('Hi')->toArray());
        $this->assertEquals(new $data('Hi'), $data::from(['string' => 'Hi']));
        $this->assertEquals(new $data('Hi'), $data::from('Hi'));
    }

    public function testCanUseDataAsAnDto(): void
    {
        $dto = SimpleDto::from('Hello World');

        $this->assertInstanceOf(Dto::class, $dto);
        $this->assertNotInstanceOf(Data::class, $dto);
        $this->assertSame('Hello World', $dto->string);

        $methods = get_class_methods($dto);

        foreach (['toArray', 'toJson', 'toResponse', 'all', 'include', 'exclude', 'only', 'except', 'transform', 'with', 'jsonSerialize'] as $method) {
            $this->assertNotContains($method, $methods);
        }

        $this->assertNotInstanceOf(TransformableDataContract::class, $dto);
        $this->assertNotInstanceOf(ResponsableDataContract::class, $dto);
        $this->assertNotInstanceOf(Castable::class, $dto);
        $this->assertInstanceOf(RequestCastable::class, $dto);

        $this->expectException(ValidationException::class);

        SimpleDto::validate(['string' => null]);
    }

    public function testCanUseDataAsAnResource(): void
    {
        $resource = SimpleResource::from('Hello World');

        $this->assertInstanceOf(Resource::class, $resource);
        $this->assertNotInstanceOf(Data::class, $resource);
        $this->assertSame('Hello World', $resource->string);

        $methods = get_class_methods($resource);

        foreach (['toArray', 'toJson', 'toResponse', 'all', 'include', 'exclude', 'only', 'except', 'transform', 'with', 'jsonSerialize'] as $method) {
            $this->assertContains($method, $methods);
        }

        $this->assertNotContains('validate', $methods);
        $this->assertInstanceOf(TransformableDataContract::class, $resource);
        $this->assertInstanceOf(ResponsableDataContract::class, $resource);
        $this->assertInstanceOf(Castable::class, $resource);
        $this->assertInstanceOf(RequestCastable::class, $resource);
    }

    public function testDataHasEveryCapability(): void
    {
        $this->assertTrue(is_a(Data::class, TransformableDataContract::class, true));
        $this->assertTrue(is_a(Data::class, ResponsableDataContract::class, true));
        $this->assertTrue(is_a(Data::class, ValidateableDataContract::class, true));
        $this->assertTrue(is_a(Data::class, Castable::class, true));
        $this->assertTrue(is_a(Data::class, RequestCastable::class, true));
    }

    public function testPaginatedCollectionsAreTransformableButNotStoredByEloquent(): void
    {
        $this->assertTrue(is_a(DataCollection::class, TransformableDataContract::class, true));
        $this->assertTrue(is_a(DataCollection::class, Castable::class, true));
        $this->assertFalse(is_a(DataCollection::class, RequestCastable::class, true));
        $this->assertTrue(is_a(DataCollection::class, ResponsableDataContract::class, true));
        $this->assertTrue(is_a(PaginatedDataCollection::class, TransformableDataContract::class, true));
        $this->assertFalse(is_a(PaginatedDataCollection::class, Castable::class, true));
        $this->assertFalse(is_a(PaginatedDataCollection::class, RequestCastable::class, true));
        $this->assertTrue(is_a(PaginatedDataCollection::class, ResponsableDataContract::class, true));
        $this->assertTrue(is_a(CursorPaginatedDataCollection::class, TransformableDataContract::class, true));
        $this->assertFalse(is_a(CursorPaginatedDataCollection::class, Castable::class, true));
        $this->assertFalse(is_a(CursorPaginatedDataCollection::class, RequestCastable::class, true));
        $this->assertTrue(is_a(CursorPaginatedDataCollection::class, ResponsableDataContract::class, true));
    }
}
