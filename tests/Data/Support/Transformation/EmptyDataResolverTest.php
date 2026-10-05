<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Transformation;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Attributes\MapOutputName;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Exceptions\DataPropertyCanOnlyHaveOneType;
use Hypervel\Data\Lazy;
use Hypervel\Data\Optional;
use Hypervel\Data\Support\Transformation\EmptyDataResolver;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Support\Collection;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\SimpleData;

class EmptyDataResolverTest extends TestCase
{
    /**
     * Get package providers for the empty data test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testWillReturnNullIfThePropertyHasNoType(): void
    {
        $this->assertEmptyPropertyValue(null, new class extends Data {
            public $property;
        });
    }

    public function testWillReturnNullIfThePropertyHasABasicType(): void
    {
        $this->assertEmptyPropertyValue(null, new class extends Data {
            public int $property;
        });

        $this->assertEmptyPropertyValue(null, new class extends Data {
            public bool $property;
        });

        $this->assertEmptyPropertyValue(null, new class extends Data {
            public float $property;
        });

        $this->assertEmptyPropertyValue(null, new class extends Data {
            public string $property;
        });

        $this->assertEmptyPropertyValue(null, new class extends Data {
            public mixed $property;
        });
    }

    public function testWillReturnAnArrayForCollectionTypes(): void
    {
        $this->assertEmptyPropertyValue([], new class extends Data {
            public array $property;
        });

        $this->assertEmptyPropertyValue([], new class extends Data {
            public Collection $property;
        });

        $this->assertEmptyPropertyValue([], new class extends Data {
            public EloquentCollection $property;
        });

        $this->assertEmptyPropertyValue([], new class extends Data {
            #[DataCollectionOf(SimpleData::class)]
            public DataCollection $property;
        });
    }

    public function testWillFurtherTransformResources(): void
    {
        $this->assertEmptyPropertyValue(['string' => null], new class extends Data {
            public SimpleData $property;
        });
    }

    public function testWillReturnTheBaseTypeForLazyTypes(): void
    {
        $this->assertEmptyPropertyValue(null, new class extends Data {
            public Lazy|string $property;
        });

        $this->assertEmptyPropertyValue([], new class extends Data {
            public Lazy|array $property;
        });

        $this->assertEmptyPropertyValue(['string' => null], new class extends Data {
            public Lazy|SimpleData $property;
        });
    }

    public function testWillReturnTheBaseTypeForLazyTypesThatCanBeNull(): void
    {
        $this->assertEmptyPropertyValue(null, new class extends Data {
            public Lazy|string|null $property;
        });

        // Upstream comments out these two cases; they pass here because null is not counted as a type.
        $this->assertEmptyPropertyValue([], new class extends Data {
            public Lazy|array|null $property;
        });

        $this->assertEmptyPropertyValue(['string' => null], new class extends Data {
            public Lazy|SimpleData|null $property;
        });
    }

    public function testWillReturnTheBaseTypeForLazyTypesThatCanBeOptional(): void
    {
        $this->assertEmptyPropertyValue(null, new class extends Data {
            public Lazy|string|Optional $property;
        });

        $this->assertEmptyPropertyValue([], new class extends Data {
            public Lazy|array|Optional $property;
        });

        $this->assertEmptyPropertyValue(['string' => null], new class extends Data {
            public Lazy|SimpleData|Optional $property;
        });
    }

    public function testWillReturnTheBaseTypeForUndefinableTypes(): void
    {
        $this->assertEmptyPropertyValue(null, new class extends Data {
            public Optional|string $property;
        });

        $this->assertEmptyPropertyValue([], new class extends Data {
            public Optional|array $property;
        });

        $this->assertEmptyPropertyValue(['string' => null], new class extends Data {
            public Optional|SimpleData $property;
        });
    }

    public function testCannotHaveMultipleTypes(): void
    {
        $data = new class extends Data {
            public int|string $property;
        };

        $this->expectException(DataPropertyCanOnlyHaveOneType::class);
        $this->expectExceptionMessageIsOrContains($data::class . '::$property');

        $this->assertEmptyPropertyValue(null, $data);
    }

    public function testCannotHaveMultipleTypesWithALazy(): void
    {
        $this->expectException(DataPropertyCanOnlyHaveOneType::class);

        $this->assertEmptyPropertyValue(null, new class extends Data {
            public int|string|Lazy $property;
        });
    }

    public function testCannotHaveMultipleTypesWithANullableLazy(): void
    {
        $this->expectException(DataPropertyCanOnlyHaveOneType::class);

        $this->assertEmptyPropertyValue(null, new class extends Data {
            public int|string|Lazy|null $property;
        });
    }

    public function testCannotHaveMultipleTypesWithAnOptional(): void
    {
        $this->expectException(DataPropertyCanOnlyHaveOneType::class);

        $this->assertEmptyPropertyValue(null, new class extends Data {
            public int|string|Optional $property;
        });
    }

    public function testCannotHaveMultipleTypesWithANullableOptional(): void
    {
        $this->expectException(DataPropertyCanOnlyHaveOneType::class);

        $this->assertEmptyPropertyValue(null, new class extends Data {
            public int|string|Optional|null $property;
        });
    }

    public function testCanOverwriteEmptyProperties(): void
    {
        $this->assertEmptyPropertyValue('Hello', new class extends Data {
            public string $property;
        }, ['property' => 'Hello']);
    }

    public function testCanUseThePropertyDefaultValue(): void
    {
        $this->assertEmptyPropertyValue('Hello', new class extends Data {
            public string $property = 'Hello';
        });
    }

    public function testCanUseTheConstructorPropertyDefaultValue(): void
    {
        $this->assertEmptyPropertyValue('Hello', new class extends Data {
            /**
             * Create a data object with a defaulted property.
             */
            public function __construct(
                public string $property = 'Hello',
            ) {
            }
        });
    }

    public function testHasSupportForMappingPropertyNames(): void
    {
        $this->assertEmptyPropertyValue(null, new class extends Data {
            #[MapOutputName('other_property')]
            public string $property;
        }, propertyName: 'other_property');
    }

    public function testCanOverwriteEmptyValueToNullForAPropertyWithMultipleTypes(): void
    {
        $this->assertEmptyPropertyValue(null, new class extends Data {
            public int|string|null $property;
        }, ['property' => null]);
    }

    /**
     * Assert the empty value the resolver gives a property.
     */
    private function assertEmptyPropertyValue(
        mixed $expected,
        Data $data,
        array $extra = [],
        string $propertyName = 'property',
    ): void {
        $empty = $this->app->make(EmptyDataResolver::class)->execute($data::class, $extra);

        $this->assertSame([$propertyName], array_keys($empty));
        $this->assertSame($expected, $empty[$propertyName]);
    }
}
