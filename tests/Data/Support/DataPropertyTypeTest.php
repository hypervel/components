<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support;

use BackedEnum;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Contracts\Database\Eloquent\Castable;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use Hypervel\Contracts\Support\Responsable;
use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Contracts\AppendableData;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Contracts\EmptyData;
use Hypervel\Data\Contracts\IncludeableData;
use Hypervel\Data\Contracts\ResponsableData;
use Hypervel\Data\Contracts\TransformableData;
use Hypervel\Data\Contracts\ValidateableData;
use Hypervel\Data\Contracts\WrappableData;
use Hypervel\Data\CursorPaginatedDataCollection;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\Enums\DataTypeKind;
use Hypervel\Data\Lazy;
use Hypervel\Data\Optional;
use Hypervel\Data\PaginatedDataCollection;
use Hypervel\Data\Support\Annotations\DataIterableAnnotationReader;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\DataPropertyType;
use Hypervel\Data\Support\Factories\DataClassFactory;
use Hypervel\Data\Support\Factories\DataMethodFactory;
use Hypervel\Data\Support\Factories\DataParameterFactory;
use Hypervel\Data\Support\Factories\DataPropertyFactory;
use Hypervel\Data\Support\Factories\DataTypeFactory;
use Hypervel\Data\Support\Lazy\ClosureLazy;
use Hypervel\Data\Support\Lazy\ConditionalLazy;
use Hypervel\Data\Support\Lazy\InertiaLazy;
use Hypervel\Data\Support\Lazy\RelationalLazy;
use Hypervel\Data\Support\NameMapperResolver;
use Hypervel\Data\Support\Types\IntersectionType;
use Hypervel\Data\Support\Types\NamedType;
use Hypervel\Data\Support\Types\PhpDocTypeNameResolver;
use Hypervel\Data\Support\Types\UnionType;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Pagination\CursorPaginator;
use Hypervel\Pagination\LengthAwarePaginator;
use Hypervel\Support\Collection;
use Hypervel\Tests\Data\Fixtures\Collections\SimpleDataCollectionWithAnotations;
use Hypervel\Tests\Data\Fixtures\ComplicatedData;
use Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum;
use Hypervel\Tests\Data\Fixtures\SimpleData;
use Hypervel\Tests\Data\Fixtures\SimpleDataWithMappedProperty;
use Hypervel\Tests\TestCase;
use JsonSerializable;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use UnitEnum;

class DataPropertyTypeTest extends TestCase
{
    // Spatie's property type drops null, Optional and Lazy from its type and copies the remaining type's kind, data
    // class and iterable class onto itself. Hypervel keeps the whole declared type, and each named type carries its
    // own metadata, so these cases assert the one named type that holds values.

    public function testCanDeduceATypeWithoutDefinition(): void
    {
        $type = $this->resolveDataType(new class {
            public $property;
        });

        $this->assertFalse($type->isOptional);
        $this->assertTrue($type->isNullable);
        $this->assertTrue($type->isMixed);
        $this->assertNull($type->lazyType);
        $this->assertValueType($type, 'mixed', true, DataTypeKind::Default);
    }

    public function testCanDeduceATypeWithDefinition(): void
    {
        $type = $this->resolveDataType(new class {
            public string $property;
        });

        $this->assertFalse($type->isOptional);
        $this->assertFalse($type->isNullable);
        $this->assertFalse($type->isMixed);
        $this->assertNull($type->lazyType);
        $this->assertValueType($type, 'string', true, DataTypeKind::Default);
    }

    public function testCanDeduceANullableTypeWithDefinition(): void
    {
        $type = $this->resolveDataType(new class {
            public ?string $property;
        });

        $this->assertFalse($type->isOptional);
        $this->assertTrue($type->isNullable);
        $this->assertFalse($type->isMixed);
        $this->assertNull($type->lazyType);
        $this->assertValueType($type, 'string', true, DataTypeKind::Default);
    }

    public function testCanDeduceAUnionTypeDefinition(): void
    {
        $type = $this->resolveDataType(new class {
            public string|int $property;
        });

        $this->assertFalse($type->isOptional);
        $this->assertFalse($type->isNullable);
        $this->assertFalse($type->isMixed);
        $this->assertNull($type->lazyType);
        $this->assertSame(['string', 'int'], $this->valueTypeNames($type));
        $this->assertInstanceOf(UnionType::class, $type->type);
    }

    public function testCanDeduceANullableUnionTypeDefinition(): void
    {
        $type = $this->resolveDataType(new class {
            public string|int|null $property;
        });

        $this->assertFalse($type->isOptional);
        $this->assertTrue($type->isNullable);
        $this->assertFalse($type->isMixed);
        $this->assertNull($type->lazyType);
        $this->assertSame(['string', 'int'], $this->valueTypeNames($type));
        $this->assertInstanceOf(UnionType::class, $type->type);
    }

    public function testCanDeduceAnIntersectionTypeDefinition(): void
    {
        $type = $this->resolveDataType(new class {
            public DateTime&DateTimeImmutable $property;
        });

        $this->assertFalse($type->isOptional);
        $this->assertFalse($type->isNullable);
        $this->assertFalse($type->isMixed);
        $this->assertNull($type->lazyType);
        $this->assertSame([DateTime::class, DateTimeImmutable::class], $this->valueTypeNames($type));
        $this->assertInstanceOf(IntersectionType::class, $type->type);
    }

    public function testCanDeduceANullableIntersectionTypeDefinition(): void
    {
        $type = $this->resolveDataType(new class {
            public (DateTime&DateTimeImmutable)|null $property;
        });

        // The declared union keeps null beside the intersection, where Spatie's type is the intersection alone.
        $this->assertFalse($type->isOptional);
        $this->assertTrue($type->isNullable);
        $this->assertFalse($type->isMixed);
        $this->assertNull($type->lazyType);
        $this->assertSame([DateTime::class, DateTimeImmutable::class], $this->valueTypeNames($type));
        $this->assertInstanceOf(UnionType::class, $type->type);
        $this->assertInstanceOf(IntersectionType::class, $type->type->types[0]);
    }

    public function testCanDeduceAMixedType(): void
    {
        $type = $this->resolveDataType(new class {
            public mixed $property;
        });

        $this->assertFalse($type->isOptional);
        $this->assertTrue($type->isNullable);
        $this->assertTrue($type->isMixed);
        $this->assertNull($type->lazyType);
        $this->assertValueType($type, 'mixed', true, DataTypeKind::Default);
    }

    public function testCanDeduceALazyType(): void
    {
        $type = $this->resolveDataType(new class {
            public string|Lazy $property;
        });

        $this->assertFalse($type->isOptional);
        $this->assertFalse($type->isNullable);
        $this->assertFalse($type->isMixed);
        $this->assertSame(Lazy::class, $type->lazyType);
        $this->assertValueType($type, 'string', true, DataTypeKind::Default);
    }

    public function testCanDeduceAnOptionalType(): void
    {
        $type = $this->resolveDataType(new class {
            public string|Optional $property;
        });

        $this->assertTrue($type->isOptional);
        $this->assertFalse($type->isNullable);
        $this->assertFalse($type->isMixed);
        $this->assertNull($type->lazyType);
        $this->assertValueType($type, 'string', true, DataTypeKind::Default);
    }

    public function testCanDeduceADataType(): void
    {
        $type = $this->resolveDataType(new class {
            public SimpleData $property;
        });

        $this->assertFalse($type->isOptional);
        $this->assertFalse($type->isNullable);
        $this->assertFalse($type->isMixed);
        $this->assertNull($type->lazyType);
        $this->assertValueType($type, SimpleData::class, false, DataTypeKind::DataObject, SimpleData::class);
    }

    public function testCanDeduceADataUnionType(): void
    {
        $type = $this->resolveDataType(new class {
            public SimpleData|Lazy $property;
        });

        $this->assertFalse($type->isOptional);
        $this->assertFalse($type->isNullable);
        $this->assertFalse($type->isMixed);
        $this->assertSame(Lazy::class, $type->lazyType);
        $this->assertValueType($type, SimpleData::class, false, DataTypeKind::DataObject, SimpleData::class);
    }

    public function testCanDeduceADataCollectionType(): void
    {
        $type = $this->resolveDataType(new class {
            #[DataCollectionOf(SimpleData::class)]
            public DataCollection $property;
        });

        $this->assertFalse($type->isOptional);
        $this->assertFalse($type->isNullable);
        $this->assertFalse($type->isMixed);
        $this->assertNull($type->lazyType);
        $this->assertValueType($type, DataCollection::class, false, DataTypeKind::DataCollection, SimpleData::class, DataCollection::class);
    }

    public function testCanDeduceADataCollectionUnionType(): void
    {
        $type = $this->resolveDataType(new class {
            #[DataCollectionOf(SimpleData::class)]
            public DataCollection|Lazy $property;
        });

        $this->assertFalse($type->isOptional);
        $this->assertFalse($type->isNullable);
        $this->assertFalse($type->isMixed);
        $this->assertSame(Lazy::class, $type->lazyType);
        $this->assertValueType($type, DataCollection::class, false, DataTypeKind::DataCollection, SimpleData::class, DataCollection::class);
    }

    public function testCanDeduceAPaginatedDataCollectionType(): void
    {
        $type = $this->resolveDataType(new class {
            #[DataCollectionOf(SimpleData::class)]
            public PaginatedDataCollection $property;
        });

        $this->assertFalse($type->isOptional);
        $this->assertFalse($type->isNullable);
        $this->assertFalse($type->isMixed);
        $this->assertNull($type->lazyType);
        $this->assertValueType($type, PaginatedDataCollection::class, false, DataTypeKind::DataPaginatedCollection, SimpleData::class, PaginatedDataCollection::class);
    }

    public function testCanDeduceAPaginatedDataCollectionUnionType(): void
    {
        $type = $this->resolveDataType(new class {
            #[DataCollectionOf(SimpleData::class)]
            public PaginatedDataCollection|Lazy $property;
        });

        $this->assertFalse($type->isOptional);
        $this->assertFalse($type->isNullable);
        $this->assertFalse($type->isMixed);
        $this->assertSame(Lazy::class, $type->lazyType);
        $this->assertValueType($type, PaginatedDataCollection::class, false, DataTypeKind::DataPaginatedCollection, SimpleData::class, PaginatedDataCollection::class);
    }

    public function testCanDeduceACursorPaginatedDataCollectionType(): void
    {
        $type = $this->resolveDataType(new class {
            #[DataCollectionOf(SimpleData::class)]
            public CursorPaginatedDataCollection $property;
        });

        $this->assertFalse($type->isOptional);
        $this->assertFalse($type->isNullable);
        $this->assertFalse($type->isMixed);
        $this->assertNull($type->lazyType);
        $this->assertValueType($type, CursorPaginatedDataCollection::class, false, DataTypeKind::DataCursorPaginatedCollection, SimpleData::class, CursorPaginatedDataCollection::class);
    }

    public function testCanDeduceACursorPaginatedDataCollectionUnionType(): void
    {
        $type = $this->resolveDataType(new class {
            #[DataCollectionOf(SimpleData::class)]
            public CursorPaginatedDataCollection|Lazy $property;
        });

        $this->assertFalse($type->isOptional);
        $this->assertFalse($type->isNullable);
        $this->assertFalse($type->isMixed);
        $this->assertSame(Lazy::class, $type->lazyType);
        $this->assertValueType($type, CursorPaginatedDataCollection::class, false, DataTypeKind::DataCursorPaginatedCollection, SimpleData::class, CursorPaginatedDataCollection::class);
    }

    public function testCanDeduceAnArrayDataCollectionType(): void
    {
        $type = $this->resolveDataType(new class {
            #[DataCollectionOf(SimpleData::class)]
            public array $property;
        });

        $this->assertFalse($type->isOptional);
        $this->assertFalse($type->isNullable);
        $this->assertFalse($type->isMixed);
        $this->assertNull($type->lazyType);
        $this->assertValueType($type, 'array', true, DataTypeKind::DataArray, SimpleData::class, 'array');
    }

    public function testCanDeduceAnArrayDataCollectionUnionType(): void
    {
        $type = $this->resolveDataType(new class {
            #[DataCollectionOf(SimpleData::class)]
            public array|Lazy $property;
        });

        $this->assertFalse($type->isOptional);
        $this->assertFalse($type->isNullable);
        $this->assertFalse($type->isMixed);
        $this->assertSame(Lazy::class, $type->lazyType);
        $this->assertValueType($type, 'array', true, DataTypeKind::DataArray, SimpleData::class, 'array');
    }

    public function testCanDeduceAnEnumerableDataCollectionType(): void
    {
        $type = $this->resolveDataType(new class {
            #[DataCollectionOf(SimpleData::class)]
            public Collection $property;
        });

        $this->assertFalse($type->isOptional);
        $this->assertFalse($type->isNullable);
        $this->assertFalse($type->isMixed);
        $this->assertNull($type->lazyType);
        $this->assertValueType($type, Collection::class, false, DataTypeKind::DataEnumerable, SimpleData::class, Collection::class);
    }

    public function testCanDeduceAnEnumerableDataCollectionUnionType(): void
    {
        $type = $this->resolveDataType(new class {
            #[DataCollectionOf(SimpleData::class)]
            public Collection|Lazy $property;
        });

        $this->assertFalse($type->isOptional);
        $this->assertFalse($type->isNullable);
        $this->assertFalse($type->isMixed);
        $this->assertSame(Lazy::class, $type->lazyType);
        $this->assertValueType($type, Collection::class, false, DataTypeKind::DataEnumerable, SimpleData::class, Collection::class);
    }

    public function testCanDeduceAnEnumerableDataCollectionTypeFromCollection(): void
    {
        $type = $this->resolveDataType(new class {
            public SimpleDataCollectionWithAnotations $property;
        });

        $this->assertFalse($type->isOptional);
        $this->assertFalse($type->isNullable);
        $this->assertFalse($type->isMixed);
        $this->assertNull($type->lazyType);
        $this->assertValueType($type, SimpleDataCollectionWithAnotations::class, false, DataTypeKind::DataEnumerable, SimpleData::class, SimpleDataCollectionWithAnotations::class);
    }

    public function testCanDeduceAnEnumerableDataCollectionUnionTypeFromCollection(): void
    {
        $type = $this->resolveDataType(new class {
            public SimpleDataCollectionWithAnotations|Lazy $property;
        });

        $this->assertFalse($type->isOptional);
        $this->assertFalse($type->isNullable);
        $this->assertFalse($type->isMixed);
        $this->assertSame(Lazy::class, $type->lazyType);
        $this->assertValueType($type, SimpleDataCollectionWithAnotations::class, false, DataTypeKind::DataEnumerable, SimpleData::class, SimpleDataCollectionWithAnotations::class);
    }

    public function testCanDeduceAPaginatorDataCollectionType(): void
    {
        $type = $this->resolveDataType(new class {
            #[DataCollectionOf(SimpleData::class)]
            public LengthAwarePaginator $property;
        });

        $this->assertFalse($type->isOptional);
        $this->assertFalse($type->isNullable);
        $this->assertFalse($type->isMixed);
        $this->assertNull($type->lazyType);
        $this->assertValueType($type, LengthAwarePaginator::class, false, DataTypeKind::DataPaginator, SimpleData::class, LengthAwarePaginator::class);
    }

    public function testCanDeduceAPaginatorDataCollectionUnionType(): void
    {
        $type = $this->resolveDataType(new class {
            #[DataCollectionOf(SimpleData::class)]
            public LengthAwarePaginator|Lazy $property;
        });

        $this->assertFalse($type->isOptional);
        $this->assertFalse($type->isNullable);
        $this->assertFalse($type->isMixed);
        $this->assertSame(Lazy::class, $type->lazyType);
        $this->assertValueType($type, LengthAwarePaginator::class, false, DataTypeKind::DataPaginator, SimpleData::class, LengthAwarePaginator::class);
    }

    public function testCanDeduceACursorPaginatorDataCollectionType(): void
    {
        $type = $this->resolveDataType(new class {
            #[DataCollectionOf(SimpleData::class)]
            public CursorPaginator $property;
        });

        $this->assertFalse($type->isOptional);
        $this->assertFalse($type->isNullable);
        $this->assertFalse($type->isMixed);
        $this->assertNull($type->lazyType);
        $this->assertValueType($type, CursorPaginator::class, false, DataTypeKind::DataCursorPaginator, SimpleData::class, CursorPaginator::class);
    }

    public function testCanDeduceACursorPaginatorDataCollectionUnionType(): void
    {
        $type = $this->resolveDataType(new class {
            #[DataCollectionOf(SimpleData::class)]
            public CursorPaginator|Lazy $property;
        });

        $this->assertFalse($type->isOptional);
        $this->assertFalse($type->isNullable);
        $this->assertFalse($type->isMixed);
        $this->assertSame(Lazy::class, $type->lazyType);
        $this->assertValueType($type, CursorPaginator::class, false, DataTypeKind::DataCursorPaginator, SimpleData::class, CursorPaginator::class);
    }

    // REMOVED: 'cannot have multiple data types', 'cannot combine a data object and another type' and 'cannot combine
    // a data collection and another type'; skipped upstream, and Hypervel declares these unions and resolves each
    // value against them (README).

    /**
     * @param array<string, list<string>> $expected
     */
    #[DataProvider('baseTypesForAcceptedTypes')]
    public function testWillResolveTheBaseTypesForAcceptedTypes(object $class, array $expected): void
    {
        $type = $this->resolveDataType($class);

        // Spatie stores each accepted type's parents and interfaces; Hypervel checks them with is_a(), so every
        // listed base type must lead back to its declared type. An empty map is a mixed declaration.
        $this->assertSame($expected === [], $type->isMixed);

        foreach ($expected as $acceptedType => $baseTypes) {
            foreach ([$acceptedType, ...$baseTypes] as $baseType) {
                $this->assertSame($acceptedType, $type->findAcceptedTypeForBaseType($baseType));
            }
        }
    }

    /**
     * Get declarations with the base types each accepted type resolves from.
     *
     * Spatie's ContextableData is not included in Hypervel (README).
     *
     * @return iterable<string, array{object, array<string, list<string>>}>
     */
    public static function baseTypesForAcceptedTypes(): iterable
    {
        yield 'no type' => [
            new class {
                public $property;
            },
            [],
        ];

        yield 'mixed' => [
            new class {
                public mixed $property;
            },
            [],
        ];

        yield 'single' => [
            new class {
                public string $property;
            },
            ['string' => []],
        ];

        yield 'multi' => [
            new class {
                public string|int|bool|float|array $property;
            },
            [
                'string' => [],
                'int' => [],
                'bool' => [],
                'float' => [],
                'array' => [],
            ],
        ];

        yield 'data' => [
            new class {
                public SimpleData $property;
            },
            [
                SimpleData::class => [
                    Data::class,
                    JsonSerializable::class,
                    Castable::class,
                    Jsonable::class,
                    Responsable::class,
                    Arrayable::class,
                    AppendableData::class,
                    BaseData::class,
                    IncludeableData::class,
                    ResponsableData::class,
                    TransformableData::class,
                    ValidateableData::class,
                    WrappableData::class,
                    EmptyData::class,
                ],
            ],
        ];

        yield 'enum' => [
            new class {
                public DummyBackedEnum $property;
            },
            [
                DummyBackedEnum::class => [
                    UnitEnum::class,
                    BackedEnum::class,
                ],
            ],
        ];
    }

    #[DataProvider('acceptedTypes')]
    public function testCanCheckIfADataTypeAcceptsAType(object $class, string $type, bool $accepts): void
    {
        $this->assertSame($accepts, $this->resolveDataType($class)->acceptsType($type));
    }

    /**
     * Get declarations with a type name and whether they accept it.
     *
     * @return iterable<array-key, array{object, string, bool}>
     */
    public static function acceptedTypes(): iterable
    {
        // Base types

        yield [
            new class {
                public $property;
            },
            'string',
            true,
        ];

        yield [
            new class {
                public mixed $property;
            },
            'string',
            true,
        ];

        yield [
            new class {
                public string $property;
            },
            'string',
            true,
        ];

        yield [
            new class {
                public bool $property;
            },
            'bool',
            true,
        ];

        yield [
            new class {
                public int $property;
            },
            'int',
            true,
        ];

        yield [
            new class {
                public float $property;
            },
            'float',
            true,
        ];

        yield [
            new class {
                public array $property;
            },
            'array',
            true,
        ];

        yield [
            new class {
                public string $property;
            },
            'array',
            false,
        ];

        // Objects

        yield [
            new class {
                public SimpleData $property;
            },
            SimpleData::class,
            true,
        ];

        yield [
            new class {
                public SimpleData $property;
            },
            ComplicatedData::class,
            false,
        ];

        // Objects with inheritance

        yield 'simple inheritance' => [
            new class {
                public Data $property;
            },
            SimpleData::class,
            true,
        ];

        yield 'reversed inheritance' => [
            new class {
                public SimpleData $property;
            },
            Data::class,
            false,
        ];

        yield 'false inheritance' => [
            new class {
                public Model $property;
            },
            SimpleData::class,
            false,
        ];

        // Objects with interfaces

        yield 'simple interface implementation' => [
            new class {
                public DateTimeInterface $property;
            },
            DateTime::class,
            true,
        ];

        yield 'reversed interface implementation' => [
            new class {
                public DateTime $property;
            },
            DateTimeInterface::class,
            false,
        ];

        yield 'false interface implementation' => [
            new class {
                public Model $property;
            },
            DateTime::class,
            false,
        ];

        // Enums

        yield [
            new class {
                public DummyBackedEnum $property;
            },
            DummyBackedEnum::class,
            true,
        ];
    }

    #[DataProvider('acceptedValues')]
    public function testCanCheckIfADataTypeAcceptsAValue(object $class, mixed $value, bool $accepts): void
    {
        $this->assertSame($accepts, $this->resolveDataType($class)->acceptsValue($value));
    }

    /**
     * Get declarations with a value and whether they accept it.
     *
     * @return iterable<int, array{object, mixed, bool}>
     */
    public static function acceptedValues(): iterable
    {
        yield [
            new class {
                public ?string $property;
            },
            null,
            true,
        ];

        yield [
            new class {
                public string $property;
            },
            'Hello',
            true,
        ];

        yield [
            new class {
                public string $property;
            },
            3.14,
            false,
        ];

        yield [
            new class {
                public mixed $property;
            },
            3.14,
            true,
        ];

        yield [
            new class {
                public Data $property;
            },
            new SimpleData('Hello'),
            true,
        ];

        yield [
            new class {
                public SimpleData $property;
            },
            new SimpleData('Hello'),
            true,
        ];

        yield [
            new class {
                public SimpleData $property;
            },
            new SimpleDataWithMappedProperty('Hello'),
            false,
        ];

        yield [
            new class {
                public DummyBackedEnum $property;
            },
            DummyBackedEnum::FOO,
            true,
        ];
    }

    #[DataProvider('acceptedTypesForBaseTypes')]
    public function testCanFindAcceptedTypeForABaseType(object $class, string $type, ?string $expectedType): void
    {
        $this->assertSame($expectedType, $this->resolveDataType($class)->findAcceptedTypeForBaseType($type));
    }

    /**
     * Get declarations with a base type and the declared type it finds.
     *
     * @return iterable<int, array{object, string, null|string}>
     */
    public static function acceptedTypesForBaseTypes(): iterable
    {
        yield [
            new class {
                public SimpleData $property;
            },
            SimpleData::class,
            SimpleData::class,
        ];

        yield [
            new class {
                public SimpleData $property;
            },
            Data::class,
            SimpleData::class,
        ];

        yield [
            new class {
                public DummyBackedEnum $property;
            },
            BackedEnum::class,
            DummyBackedEnum::class,
        ];

        yield [
            new class {
                public SimpleData $property;
            },
            DataCollection::class,
            null,
        ];
    }

    public function testCanAnnotateDataCollectionsUsingAttributes(): void
    {
        $type = $this->resolveDataType(new class {
            #[DataCollectionOf(SimpleData::class)]
            public DataCollection $property;
        });

        $this->assertValueType($type, DataCollection::class, false, DataTypeKind::DataCollection, SimpleData::class, DataCollection::class);
    }

    public function testCanAnnotateDataCollectionsUsingVarAnnotations(): void
    {
        $type = $this->resolveDataType(new class {
            /** @var DataCollection<SimpleData> */
            public DataCollection $property;
        });

        $this->assertValueType($type, DataCollection::class, false, DataTypeKind::DataCollection, SimpleData::class, DataCollection::class);
    }

    public function testCanAnnotateDataCollectionsUsingPropertyAnnotations(): void
    {
        $type = $this->resolveDataType(new TestDataTypeWithClassAnnotatedProperty([]));

        $this->assertValueType($type, 'array', true, DataTypeKind::DataArray, SimpleData::class, 'array');
    }

    public function testCanAnnotateDataCollectionsUsingConstructorParameterAnnotations(): void
    {
        $type = $this->resolveDataType(new TestDataTypeWithClassAnnotatedConstructorParam([]));

        $this->assertValueType($type, 'array', true, DataTypeKind::DataArray, SimpleData::class, 'array');
    }

    public function testCanDeduceTheTypesOfLazy(): void
    {
        $type = $this->resolveDataType(new class {
            public SimpleData|Lazy $property;
        });

        $this->assertSame(Lazy::class, $type->lazyType);

        $type = $this->resolveDataType(new class {
            public SimpleData|ClosureLazy $property;
        });

        $this->assertSame(ClosureLazy::class, $type->lazyType);

        $type = $this->resolveDataType(new class {
            public SimpleData|InertiaLazy $property;
        });

        $this->assertSame(InertiaLazy::class, $type->lazyType);

        $type = $this->resolveDataType(new class {
            public SimpleData|ConditionalLazy $property;
        });

        $this->assertSame(ConditionalLazy::class, $type->lazyType);

        $type = $this->resolveDataType(new class {
            public SimpleData|RelationalLazy $property;
        });

        $this->assertSame(RelationalLazy::class, $type->lazyType);
    }

    public function testWillMarkAnArrayCollectionAndPaginatorsAsAnIterableTypeKindWhenNoDataCollectionWasSpecified(): void
    {
        $type = $this->resolveDataType(new class {
            public array $property;
        });

        $this->assertSame(DataTypeKind::Array, $this->valueType($type)->kind);

        $type = $this->resolveDataType(new class {
            public Collection $property;
        });

        $this->assertSame(DataTypeKind::Enumerable, $this->valueType($type)->kind);

        $type = $this->resolveDataType(new class {
            public LengthAwarePaginator $property;
        });

        $this->assertSame(DataTypeKind::Paginator, $this->valueType($type)->kind);

        $type = $this->resolveDataType(new class {
            public CursorPaginator $property;
        });

        $this->assertSame(DataTypeKind::CursorPaginator, $this->valueType($type)->kind);
    }

    public function testCanAnnotateIterablesUsingAttributes(): void
    {
        $type = $this->resolveDataType(new class {
            #[DataCollectionOf(SimpleData::class)]
            public DataCollection $property;
        });

        $this->assertValueType($type, DataCollection::class, false, DataTypeKind::DataCollection, SimpleData::class, DataCollection::class);
    }

    public function testCanAnnotateIterablesUsingVarAnnotations(): void
    {
        $type = $this->resolveDataType(new class {
            /** @var array<string> */
            public array $property;
        });

        $this->assertValueType($type, 'array', true, DataTypeKind::Array, null, 'array');
        $this->assertSame('string', $this->iterableItemTypeName($type));
    }

    public function testCanAnnotateIterablesUsingPropertyAnnotations(): void
    {
        $type = $this->resolveDataType(new TestDataTypeWithClassAnnotatedNonDataProperty(new Collection));

        $this->assertValueType($type, Collection::class, false, DataTypeKind::Enumerable, null, Collection::class);
        $this->assertSame('string', $this->iterableItemTypeName($type));
    }

    public function testCanAnnotateIterablesUsingConstructorParameterAnnotations(): void
    {
        $type = $this->resolveDataType(new TestDataTypeWithClassAnnotatedNonDataConstructorParam([]));

        $this->assertValueType($type, 'array', true, DataTypeKind::Array, null, 'array');
        $this->assertSame('string', $this->iterableItemTypeName($type));
    }

    // Iterable key types are not tracked: they only fed Spatie's TypeScript transformer, which is not included, so
    // the two key cases below assert the item types their annotations give.

    public function testCanAnnotateDataCollectionKeysUsingVarAnnotations(): void
    {
        $type = $this->resolveDataType(new class {
            /** @var array<string, SimpleData> */
            public array $property;
        });

        $this->assertValueType($type, 'array', true, DataTypeKind::DataArray, SimpleData::class, 'array');
        $this->assertSame(SimpleData::class, $this->iterableItemTypeName($type));
    }

    public function testCanAnnotateIterableCollectionKeysUsingVarAnnotations(): void
    {
        $type = $this->resolveDataType(new class {
            /** @var array<string, string> */
            public array $property;
        });

        $this->assertValueType($type, 'array', true, DataTypeKind::Array, null, 'array');
        $this->assertSame('string', $this->iterableItemTypeName($type));
    }

    /**
     * Resolve the type of one property of a fake class.
     */
    protected function resolveDataType(object $class, string $property = 'property'): DataPropertyType
    {
        return $this->factory()->build(new ReflectionClass($class))->properties[$property]->type;
    }

    /**
     * Assert the one declared type that holds values.
     */
    protected function assertValueType(
        DataPropertyType $type,
        string $name,
        bool $builtIn,
        DataTypeKind $kind,
        ?string $dataClass = null,
        ?string $iterableClass = null,
    ): void {
        $valueType = $this->valueType($type);

        $this->assertSame($name, $valueType->name);
        $this->assertSame($builtIn, $valueType->builtIn);
        $this->assertSame($kind, $valueType->kind);
        $this->assertSame($dataClass, $valueType->dataClass);
        $this->assertSame($iterableClass, $valueType->iterableClass);
    }

    /**
     * Get the one declared type that holds values, leaving out null, Optional and Lazy.
     */
    protected function valueType(DataPropertyType $type): NamedType
    {
        $valueTypes = $this->valueTypes($type);

        $this->assertCount(1, $valueTypes);

        return $valueTypes[0];
    }

    /**
     * Get the names of the declared types that hold values.
     *
     * @return list<string>
     */
    protected function valueTypeNames(DataPropertyType $type): array
    {
        return array_map(fn (NamedType $namedType): string => $namedType->name, $this->valueTypes($type));
    }

    /**
     * Get the declared types that hold values, leaving out null, Optional and Lazy.
     *
     * @return list<NamedType>
     */
    protected function valueTypes(DataPropertyType $type): array
    {
        return array_values(array_filter(
            $type->getNamedTypes(),
            fn (NamedType $namedType): bool => $namedType->name !== 'null'
                && ! is_a($namedType->name, Optional::class, true)
                && ! is_a($namedType->name, Lazy::class, true),
        ));
    }

    /**
     * Get the item type name of the one iterable declaration.
     */
    protected function iterableItemTypeName(DataPropertyType $type): ?string
    {
        return $this->valueType($type)->iterableItemType?->getNamedTypes()[0]->name;
    }

    /**
     * Create the data class metadata factory.
     */
    protected function factory(): DataClassFactory
    {
        $defaults = require __DIR__ . '/../../../src/data/config/data.php';
        $config = new DataConfig(new Repository(['data' => $defaults]));
        $nameMapperResolver = new NameMapperResolver(new Container);
        $typeFactory = new DataTypeFactory(new PhpDocTypeNameResolver, new DataIterableAnnotationReader);
        $parameterFactory = new DataParameterFactory($typeFactory);

        return new DataClassFactory(
            new DataPropertyFactory($typeFactory, $config, $nameMapperResolver),
            new DataMethodFactory($parameterFactory, $typeFactory),
            $parameterFactory,
            new DataIterableAnnotationReader,
            $nameMapperResolver,
            $config,
        );
    }
}

/**
 * @property DataCollection<SimpleData> $property
 */
class TestDataTypeWithClassAnnotatedProperty
{
    /**
     * Create a fake whose property is annotated on the class.
     */
    public function __construct(
        public array $property,
    ) {
    }
}

class TestDataTypeWithClassAnnotatedConstructorParam
{
    /**
     * Create a fake whose property is annotated on the constructor.
     *
     * @param array<SimpleData> $property
     */
    public function __construct(
        public array $property,
    ) {
    }
}

/**
 * @property Collection<string> $property
 */
class TestDataTypeWithClassAnnotatedNonDataProperty
{
    /**
     * Create a fake whose non-data property is annotated on the class.
     */
    public function __construct(
        public Collection $property,
    ) {
    }
}

class TestDataTypeWithClassAnnotatedNonDataConstructorParam
{
    /**
     * Create a fake whose non-data property is annotated on the constructor.
     *
     * @param array<string> $property
     */
    public function __construct(
        public array $property,
    ) {
    }
}
