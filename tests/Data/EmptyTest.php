<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Lazy;
use Hypervel\Support\Collection;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\MultiData;
use Hypervel\Tests\Data\Fixtures\SimpleData;

class EmptyTest extends TestCase
{
    /**
     * Get package providers for the empty test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testCanGetTheEmptyVersionOfADataObject(): void
    {
        $dataClass = new class extends Data {
            public string $property;

            public string|Lazy $lazyProperty;

            public array $array;

            public Collection $collection;

            #[DataCollectionOf(SimpleData::class)]
            public DataCollection $dataCollection;

            public SimpleData $data;

            public Lazy|SimpleData $lazyData;

            public bool $defaultProperty = true;
        };

        $this->assertSame([
            'property' => null,
            'lazyProperty' => null,
            'array' => [],
            'collection' => [],
            'dataCollection' => [],
            'data' => [
                'string' => null,
            ],
            'lazyData' => [
                'string' => null,
            ],
            'defaultProperty' => true,
        ], $dataClass::empty());
    }

    public function testCanOverwritePropertiesInAnEmptyVersionOfADataObject(): void
    {
        $this->assertSame([
            'string' => null,
        ], SimpleData::empty());

        $this->assertSame([
            'string' => 'Ruben',
        ], SimpleData::empty(['string' => 'Ruben']));
    }

    public function testCanUseExceptToFilterOutPropertiesInAnEmptyVersionOfADataObject(): void
    {
        $this->assertSame(['first' => null], MultiData::empty(except: ['second']));
    }

    public function testCanUseOnlyToFilterOutPropertiesInAnEmptyVersionOfADataObject(): void
    {
        $this->assertSame(['second' => null], MultiData::empty(only: ['second']));
    }
}
