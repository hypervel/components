<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\CreationTest;

use ArgumentCountError;
use Closure;
use Countable;
use DateTime;
use DateTimeImmutable;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Data\Attributes\AutoClosureLazy;
use Hypervel\Data\Attributes\AutoInertiaDeferred;
use Hypervel\Data\Attributes\AutoInertiaLazy;
use Hypervel\Data\Attributes\AutoLazy;
use Hypervel\Data\Attributes\AutoWhenLoadedLazy;
use Hypervel\Data\Attributes\Computed;
use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Attributes\PropertyForMorph;
use Hypervel\Data\Attributes\Validation\Min;
use Hypervel\Data\Attributes\WithCast;
use Hypervel\Data\Attributes\WithCastable;
use Hypervel\Data\Casts\Cast;
use Hypervel\Data\Casts\Castable;
use Hypervel\Data\Casts\DateTimeInterfaceCast;
use Hypervel\Data\Casts\Uncastable;
use Hypervel\Data\Contracts\PropertyMorphableData;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Exceptions\CannotCreateAbstractClass;
use Hypervel\Data\Exceptions\CannotCreateData;
use Hypervel\Data\Exceptions\CannotSetComputedValue;
use Hypervel\Data\Lazy;
use Hypervel\Data\Normalizers\Normalized\Normalized;
use Hypervel\Data\Normalizers\Normalizer;
use Hypervel\Data\Optional;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Lazy\ClosureLazy;
use Hypervel\Data\Support\Lazy\InertiaDeferred;
use Hypervel\Data\Support\Lazy\InertiaLazy;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Http\Response;
use Hypervel\Inertia\DeferProp;
use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\OptionalProp;
use Hypervel\Support\Carbon;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Collection;
use Hypervel\Support\Enumerable;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\LazyCollection;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\AbstractPropertyMorphableData;
use Hypervel\Tests\Data\Fixtures\Casts\ConfidentialDataCast;
use Hypervel\Tests\Data\Fixtures\Casts\MeaningOfLifeCast;
use Hypervel\Tests\Data\Fixtures\Casts\StringToUpperCast;
use Hypervel\Tests\Data\Fixtures\Collections\CustomCollection;
use Hypervel\Tests\Data\Fixtures\ComplicatedData;
use Hypervel\Tests\Data\Fixtures\EnumData;
use Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum;
use Hypervel\Tests\Data\Fixtures\Enums\PropertyMorphableEnum;
use Hypervel\Tests\Data\Fixtures\FakeNestedModelData;
use Hypervel\Tests\Data\Fixtures\Models\DummyModel;
use Hypervel\Tests\Data\Fixtures\Models\FakeModel;
use Hypervel\Tests\Data\Fixtures\Models\FakeNestedModel;
use Hypervel\Tests\Data\Fixtures\MultiData;
use Hypervel\Tests\Data\Fixtures\NestedData;
use Hypervel\Tests\Data\Fixtures\NestedLazyData;
use Hypervel\Tests\Data\Fixtures\PropertyMorphableDataA;
use Hypervel\Tests\Data\Fixtures\PropertyMorphableDataB;
use Hypervel\Tests\Data\Fixtures\SimpleData;
use Hypervel\Tests\Data\Fixtures\SimpleDataWithPropertyHooks;
use Hypervel\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use TypeError;

class CreationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    /**
     * Get the migration options.
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => __DIR__ . '/Fixtures/Migrations',
        ];
    }

    public function testCanUseDefaultTypesToCreateDataObjects(): void
    {
        $data = ComplicatedData::from([
            'withoutType' => 42,
            'int' => 42,
            'bool' => true,
            'float' => 3.14,
            'string' => 'Hello world',
            'array' => [1, 1, 2, 3, 5, 8],
            'nullable' => null,
            'mixed' => 42,
            'explicitCast' => '16-06-1994',
            'defaultCast' => '1994-05-16T12:00:00+01:00',
            'nestedData' => [
                'string' => 'hello',
            ],
            'nestedCollection' => [
                ['string' => 'never'],
                ['string' => 'gonna'],
                ['string' => 'give'],
                ['string' => 'you'],
                ['string' => 'up'],
            ],
            'nestedArray' => [
                ['string' => 'never'],
                ['string' => 'gonna'],
                ['string' => 'give'],
                ['string' => 'you'],
                ['string' => 'up'],
            ],
        ]);

        $this->assertInstanceOf(ComplicatedData::class, $data);
        $this->assertEquals(42, $data->withoutType);
        $this->assertEquals(42, $data->int);
        $this->assertTrue($data->bool);
        $this->assertEquals(3.14, $data->float);
        $this->assertEquals('Hello world', $data->string);
        $this->assertEquals([1, 1, 2, 3, 5, 8], $data->array);
        $this->assertNull($data->nullable);
        $this->assertInstanceOf(Optional::class, $data->undefinable);
        $this->assertEquals(42, $data->mixed);
        $this->assertEquals(DateTime::createFromFormat(DATE_ATOM, '1994-05-16T12:00:00+01:00'), $data->defaultCast);
        $this->assertEquals(CarbonImmutable::createFromFormat('d-m-Y', '16-06-1994'), $data->explicitCast);
        $this->assertEquals(SimpleData::from('hello'), $data->nestedData);
        $this->assertEquals(SimpleData::collect([
            SimpleData::from('never'),
            SimpleData::from('gonna'),
            SimpleData::from('give'),
            SimpleData::from('you'),
            SimpleData::from('up'),
        ], DataCollection::class), $data->nestedCollection);
        $this->assertEquals(SimpleData::collect([
            SimpleData::from('never'),
            SimpleData::from('gonna'),
            SimpleData::from('give'),
            SimpleData::from('you'),
            SimpleData::from('up'),
        ]), $data->nestedArray);
    }

    public function testWontCastAPropertyThatIsAlreadyInTheCorrectType(): void
    {
        $data = ComplicatedData::from([
            'withoutType' => 42,
            'int' => 42,
            'bool' => true,
            'float' => 3.14,
            'string' => 'Hello world',
            'array' => [1, 1, 2, 3, 5, 8],
            'nullable' => null,
            'mixed' => 42,
            'explicitCast' => DateTime::createFromFormat('d-m-Y', '16-06-1994'),
            'defaultCast' => DateTime::createFromFormat(DATE_ATOM, '1994-05-16T12:00:00+02:00'),
            'nestedData' => SimpleData::from('hello'),
            'nestedCollection' => SimpleData::collect([
                'never', 'gonna', 'give', 'you', 'up',
            ], DataCollection::class),
            'nestedArray' => SimpleData::collect([
                'never', 'gonna', 'give', 'you', 'up',
            ]),
        ]);

        $this->assertInstanceOf(ComplicatedData::class, $data);
        $this->assertEquals(42, $data->withoutType);
        $this->assertEquals(42, $data->int);
        $this->assertTrue($data->bool);
        $this->assertEquals(3.14, $data->float);
        $this->assertEquals('Hello world', $data->string);
        $this->assertEquals([1, 1, 2, 3, 5, 8], $data->array);
        $this->assertNull($data->nullable);
        $this->assertSame(42, $data->mixed);
        $this->assertEquals(DateTime::createFromFormat(DATE_ATOM, '1994-05-16T12:00:00+02:00'), $data->defaultCast);
        $this->assertEquals(DateTime::createFromFormat('d-m-Y', '16-06-1994'), $data->explicitCast);
        $this->assertEquals(SimpleData::from('hello'), $data->nestedData);
        $this->assertEquals(SimpleData::collect([
            SimpleData::from('never'),
            SimpleData::from('gonna'),
            SimpleData::from('give'),
            SimpleData::from('you'),
            SimpleData::from('up'),
        ], DataCollection::class), $data->nestedCollection);
        $this->assertEquals(SimpleData::collect([
            SimpleData::from('never'),
            SimpleData::from('gonna'),
            SimpleData::from('give'),
            SimpleData::from('you'),
            SimpleData::from('up'),
        ]), $data->nestedArray);
    }

    public function testAllowsCreatingDataObjectsFromNull(): void
    {
        $dataClass = new class extends Data {
            public ?string $name;
        };

        $data = $dataClass::from(null);

        $this->assertNull($data->name);
    }

    public function testAllowsCreatingDataObjectsUsingLazy(): void
    {
        $data = NestedLazyData::from([
            'simple' => Lazy::create(fn (): SimpleData => SimpleData::from('Hello')),
        ]);

        $this->assertInstanceOf(Lazy::class, $data->simple);
        $this->assertInstanceOf(SimpleData::class, $data->simple->resolve());
        $this->assertEquals('Hello', $data->simple->resolve()->string);
    }

    public function testCanSetACustomCast(): void
    {
        $dataClass = new class extends Data {
            #[WithCast(DateTimeInterfaceCast::class, format: 'Y-m-d')]
            public DateTimeImmutable $date;
        };

        $data = $dataClass::from([
            'date' => '2022-01-18',
        ]);

        $this->assertInstanceOf(DateTimeImmutable::class, $data->date);
        $this->assertEquals('2022-01-18', $data->date->format('Y-m-d'));
    }

    public function testAllowsCastingOfEnums(): void
    {
        $data = EnumData::from([
            'enum' => 'foo',
        ]);

        $this->assertInstanceOf(DummyBackedEnum::class, $data->enum);
        $this->assertEquals(DummyBackedEnum::FOO, $data->enum);
    }

    public function testCanOptionallyCreateData(): void
    {
        $this->assertNull(SimpleData::optional());
        $this->assertNull(SimpleData::optional(null));
        $this->assertEquals(
            new SimpleData('Hello world'),
            SimpleData::optional(['string' => 'Hello world'])
        );
    }

    public function testCanCreateADataModelWithoutConstructor(): void
    {
        $this->assertEquals(
            SimpleDataWithoutConstructor::fromString('Hello'),
            SimpleDataWithoutConstructor::from('Hello')
        );

        $this->assertEquals(
            SimpleDataWithoutConstructor::fromString('Hello'),
            SimpleDataWithoutConstructor::from([
                'string' => 'Hello',
            ])
        );

        $this->assertEquals(
            new DataCollection(SimpleDataWithoutConstructor::class, [
                SimpleDataWithoutConstructor::fromString('Hello'),
                SimpleDataWithoutConstructor::fromString('World'),
            ]),
            SimpleDataWithoutConstructor::collect(['Hello', 'World'], DataCollection::class)
        );
    }

    public function testCanCreateADataObjectFromAModel(): void
    {
        DummyModel::migrate();

        $model = DummyModel::create([
            'string' => 'test',
            'boolean' => true,
            'date' => CarbonImmutable::create(2020, 05, 16, 12, 00, 00),
            'nullable_date' => null,
            'nullable_optional_date' => null,
        ]);

        $dataClass = new class extends Data {
            public string $string;

            public bool $boolean;

            public Carbon $date;

            public ?Carbon $nullable_date;

            public Optional|Carbon $optional_date;

            public Optional|Carbon|null $nullable_optional_date;
        };

        // A column left out of the query is absent; a selected column holding null is a supplied null.
        $data = $dataClass::from(DummyModel::query()
            ->select(['id', 'string', 'boolean', 'date', 'nullable_date', 'nullable_optional_date'])
            ->findOrFail($model->id));

        $this->assertEquals('test', $data->string);
        $this->assertTrue($data->boolean);
        $this->assertNull($data->nullable_date);
        $this->assertInstanceOf(Optional::class, $data->optional_date);
        $this->assertNull($data->nullable_optional_date);
        $this->assertTrue(CarbonImmutable::create(2020, 05, 16, 12, 00, 00)->eq($data->date));

        $this->expectException(TypeError::class);

        $dataClass::from(DummyModel::findOrFail($model->id));
    }

    public function testCanCreateADataObjectFromAStdClassObject(): void
    {
        $object = (object) [
            'string' => 'test',
            'boolean' => true,
            'date' => CarbonImmutable::create(2020, 05, 16, 12, 00, 00),
            'nullable_date' => null,
        ];

        $dataClass = new class extends Data {
            public string $string;

            public bool $boolean;

            public CarbonImmutable $date;

            public ?Carbon $nullable_date;
        };

        $data = $dataClass::from($object);

        $this->assertEquals('test', $data->string);
        $this->assertTrue($data->boolean);
        $this->assertNull($data->nullable_date);
        $this->assertTrue(CarbonImmutable::create(2020, 05, 16, 12, 00, 00)->eq($data->date));
    }

    public function testHasSupportForReadonlyProperties(): void
    {
        $dataClass = new class('') extends Data {
            /**
             * Create the data object with a readonly property.
             */
            public function __construct(
                public readonly string $string
            ) {
            }
        };

        $data = $dataClass::from(['string' => 'Hello world']);

        $this->assertInstanceOf($dataClass::class, $data);
        $this->assertEquals('Hello world', $data->string);
    }

    public function testHasSupportForIntersectionTypes(): void
    {
        $collection = collect(['a', 'b', 'c']);

        $dataClass = new class extends Data {
            public Arrayable&Countable $intersection;
        };

        $data = $dataClass::from(['intersection' => $collection]);

        $this->assertInstanceOf($dataClass::class, $data);
        $this->assertEquals($collection, $data->intersection);
    }

    public function testCanConstructADataObjectWithBothConstructorPromotedAndDefaultProperties(): void
    {
        $dataClass = new class('') extends Data {
            public string $property;

            /**
             * Create the data object with a promoted property.
             */
            public function __construct(
                public string $promoted_property,
            ) {
            }
        };

        $data = $dataClass::from([
            'property' => 'A',
            'promoted_property' => 'B',
        ]);

        $this->assertEquals('A', $data->property);
        $this->assertEquals('B', $data->promoted_property);
    }

    public function testCanConstructADataObjectWithDefaultValues(): void
    {
        $dataClass = new class('', '') extends Data {
            public string $property;

            public string $default_property = 'Hello';

            /**
             * Create the data object with promoted defaults.
             */
            public function __construct(
                public string $promoted_property,
                public string $default_promoted_property = 'Hello Again',
            ) {
            }
        };

        $data = $dataClass::from([
            'property' => 'Test',
            'promoted_property' => 'Test Again',
        ]);

        $this->assertEquals('Test', $data->property);
        $this->assertEquals('Test Again', $data->promoted_property);
        $this->assertEquals('Hello', $data->default_property);
        $this->assertEquals('Hello Again', $data->default_promoted_property);
    }

    public function testCanConstructADataObjectWithDefaultValuesAndOverwriteThem(): void
    {
        $dataClass = new class('', '') extends Data {
            public string $property;

            public string $default_property = 'Hello';

            /**
             * Create the data object with promoted defaults.
             */
            public function __construct(
                public string $promoted_property,
                public string $default_promoted_property = 'Hello Again',
            ) {
            }
        };

        $data = $dataClass::from([
            'property' => 'Test',
            'default_property' => 'Test',
            'promoted_property' => 'Test Again',
            'default_promoted_property' => 'Test Again',
        ]);

        $this->assertEquals('Test', $data->property);
        $this->assertEquals('Test Again', $data->promoted_property);
        $this->assertEquals('Test', $data->default_property);
        $this->assertEquals('Test Again', $data->default_promoted_property);
    }

    public function testCanManuallySetValuesInTheConstructor(): void
    {
        $dataClass = new class('', '') extends Data {
            public string $member;

            public string $other_member;

            public string $member_with_default = 'default';

            public string $member_to_set;

            /**
             * Create the data object with a promoted property.
             */
            public function __construct(
                public string $promoted,
                string $non_promoted,
                string $non_promoted_with_default = 'default',
                public string $promoted_with_with_default = 'default',
            ) {
                $this->member = "changed_in_constructor: {$non_promoted}";
                $this->other_member = "changed_in_constructor: {$non_promoted_with_default}";
            }
        };

        $data = $dataClass::from([
            'promoted' => 'A',
            'non_promoted' => 'B',
            'non_promoted_with_default' => 'C',
            'promoted_with_with_default' => 'D',
            'member_to_set' => 'E',
            'member_with_default' => 'F',
        ]);

        $this->assertEquals([
            'member' => 'changed_in_constructor: B',
            'other_member' => 'changed_in_constructor: C',
            'member_with_default' => 'F',
            'promoted' => 'A',
            'promoted_with_with_default' => 'D',
            'member_to_set' => 'E',
        ], $data->toArray());

        $data = $dataClass::from([
            'promoted' => 'A',
            'non_promoted' => 'B',
            'member_to_set' => 'E',
        ]);

        $this->assertEquals([
            'member' => 'changed_in_constructor: B',
            'other_member' => 'changed_in_constructor: default',
            'member_with_default' => 'default',
            'promoted' => 'A',
            'promoted_with_with_default' => 'default',
            'member_to_set' => 'E',
        ], $data->toArray());
    }

    public function testCanCastDataObjectAndCollectablesUsingACustomCast(): void
    {
        $dataWithDefaultCastsClass = new class extends Data {
            public SimpleData $nestedData;

            #[DataCollectionOf(SimpleData::class)]
            public array $nestedDataCollection;
        };

        $dataWithCustomCastsClass = new class extends Data {
            #[WithCast(ConfidentialDataCast::class)]
            public SimpleData $nestedData;

            #[WithCast(ConfidentialDataCollectionCast::class)]
            #[DataCollectionOf(SimpleData::class)]
            public array $nestedDataCollection;
        };

        $dataWithDefaultCasts = $dataWithDefaultCastsClass::from([
            'nestedData' => 'a secret',
            'nestedDataCollection' => ['another secret', 'yet another secret'],
        ]);

        $dataWithCustomCasts = $dataWithCustomCastsClass::from([
            'nestedData' => 'a secret',
            'nestedDataCollection' => ['another secret', 'yet another secret'],
        ]);

        $this->assertEquals(SimpleData::from('a secret'), $dataWithDefaultCasts->nestedData);
        $this->assertEquals(SimpleData::collect(['another secret', 'yet another secret']), $dataWithDefaultCasts->nestedDataCollection);

        $this->assertEquals(SimpleData::from('CONFIDENTIAL'), $dataWithCustomCasts->nestedData);
        $this->assertEquals(SimpleData::collect(['CONFIDENTIAL', 'CONFIDENTIAL']), $dataWithCustomCasts->nestedDataCollection);
    }

    public function testCanCreateADataObjectWithDefaultsByCallingAnEmptyFrom(): void
    {
        $dataClass = new class('', '', '') extends Data {
            /**
             * Create the data object with nullable, optional, and default values.
             */
            public function __construct(
                public ?string $string,
                public Optional|string $optionalString,
                public string $stringWithDefault = 'Hi',
            ) {
            }
        };

        $this->assertEquals(new $dataClass(null, new Optional, 'Hi'), $dataClass::from([]));
    }

    public function testCanCastBuiltInTypesWithCustomCasts(): void
    {
        $dataClass = new class('', '') extends Data {
            /**
             * Create the data object with and without a custom cast.
             */
            public function __construct(
                public string $without_cast,
                #[WithCast(StringToUpperCast::class)]
                public string $with_cast
            ) {
            }
        };

        $data = $dataClass::from([
            'without_cast' => 'Hello World',
            'with_cast' => 'Hello World',
        ]);

        $this->assertEquals('Hello World', $data->without_cast);
        $this->assertEquals('HELLO WORLD', $data->with_cast);
    }

    public function testCanCastDataObjectWithACastablePropertyUsingAnonymousClass(): void
    {
        $dataWithCastablePropertyClass = new class(new SimpleCastable('')) extends Data {
            /**
             * Create the data object with a castable property.
             */
            public function __construct(
                #[WithCastable(SimpleCastable::class)]
                public SimpleCastable $castableData,
            ) {
            }
        };

        $dataWithCastableProperty = $dataWithCastablePropertyClass::from(['castableData' => 'HELLO WORLD']);

        $this->assertEquals(new SimpleCastable('HELLO WORLD'), $dataWithCastableProperty->castableData);
    }

    public function testCanAssignAFalseValueAndTheProcessWillContinue(): void
    {
        $dataClass = new class extends Data {
            public bool $false;

            public bool $true;
        };

        $data = $dataClass::from([
            'false' => false,
            'true' => true,
        ]);

        $this->assertFalse($data->false);
        $this->assertTrue($data->true);
    }

    public function testCanCreateAnPartialDataObjectUsingOptionalValues(): void
    {
        $dataClass = new class('', Optional::create(), Optional::create()) extends Data {
            /**
             * Create the data object with optional properties.
             */
            public function __construct(
                public string $string,
                public string|Optional $undefinable_string,
                #[WithCast(StringToUpperCast::class)]
                public string|Optional $undefinable_string_with_cast,
            ) {
            }
        };

        $partialData = $dataClass::from([
            'string' => 'Hello World',
        ]);

        $this->assertEquals('Hello World', $partialData->string);
        $this->assertEquals(Optional::create(), $partialData->undefinable_string);
        $this->assertEquals(Optional::create(), $partialData->undefinable_string_with_cast);

        $fullData = $dataClass::from([
            'string' => 'Hello World',
            'undefinable_string' => 'Hello World',
            'undefinable_string_with_cast' => 'Hello World',
        ]);

        $this->assertEquals('Hello World', $fullData->string);
        $this->assertEquals('Hello World', $fullData->undefinable_string);
        $this->assertEquals('HELLO WORLD', $fullData->undefinable_string_with_cast);
    }

    public function testCanUseContextInCastsBasedUponThePropertiesOfTheDataObject(): void
    {
        $dataClass = new class extends Data {
            public SimpleData $nested;

            public string $string;

            #[WithCast(ContextAwareCast::class)]
            public string $casted;
        };

        $data = $dataClass::from([
            'nested' => 'Hello',
            'string' => 'world',
            'casted' => 'json:',
        ]);

        $this->assertEquals('json:+{"nested":{"string":"Hello"},"string":"world","casted":"json:"}', $data->casted);
    }

    public function testWillThrowACustomExceptionWhenADataConstructorCannotBeCalledDueToMissingComponent(): void
    {
        $this->expectException(CannotCreateData::class);
        $this->expectExceptionMessage('its constructor requires 1 payload parameters');

        SimpleData::from([]);
    }

    public function testWillTakePropertiesFromABaseClassIntoAccountWhenCreatingADataObject(): void
    {
        $dataClass = new class('') extends SimpleData {
            public int $int;
        };

        $data = $dataClass::from(['string' => 'Hi', 'int' => 42]);

        $this->assertSame('Hi', $data->string);
        $this->assertSame(42, $data->int);
    }

    public function testCanSetADefaultValueForDataObjectWhichIsTakenIntoAccountWhenCreatingTheDataObject(): void
    {
        $dataObject = new class('', '') extends Data {
            #[Min(10)]
            public string|Optional $full_name;

            /**
             * Create the data object from its name parts.
             */
            public function __construct(
                public string $first_name,
                public string $last_name,
            ) {
                $this->full_name = "{$this->first_name} {$this->last_name}";
            }
        };

        $data = $dataObject::from(['first_name' => 'Ruben', 'last_name' => 'Van Assche']);

        $this->assertSame('Ruben', $data->first_name);
        $this->assertSame('Van Assche', $data->last_name);
        $this->assertSame('Ruben Van Assche', $data->full_name);

        $data = $dataObject::from(['first_name' => 'Ruben', 'last_name' => 'Van Assche', 'full_name' => 'Ruben Versieck']);

        $this->assertSame('Ruben', $data->first_name);
        $this->assertSame('Van Assche', $data->last_name);
        $this->assertSame('Ruben Versieck', $data->full_name);

        $data = $dataObject::validateAndCreate(['first_name' => 'Ruben', 'last_name' => 'Van Assche']);

        $this->assertSame('Ruben', $data->first_name);
        $this->assertSame('Van Assche', $data->last_name);
        $this->assertSame('Ruben Van Assche', $data->full_name);

        $this->expectException(ValidationException::class);

        $dataObject::validateAndCreate(['first_name' => 'Ruben', 'last_name' => 'Van Assche', 'full_name' => 'too short']);
    }

    #[DefineEnvironment('ignoreSuppliedComputedValues')]
    public function testCanHaveAComputedValueWhenCreatingTheDataObject(): void
    {
        $data = ComputedFullNameData::from(['first_name' => 'Ruben', 'last_name' => 'Van Assche', 'full_name' => 'Something to Be Ignored']);

        $this->assertSame('Ruben', $data->first_name);
        $this->assertSame('Van Assche', $data->last_name);
        $this->assertSame('Ruben Van Assche', $data->full_name);

        $data = ComputedFullNameData::validateAndCreate(['first_name' => 'Ruben', 'last_name' => 'Van Assche', 'full_name' => 'Something to Be Ignored']);

        $this->assertSame('Ruben', $data->first_name);
        $this->assertSame('Van Assche', $data->last_name);
        $this->assertSame('Ruben Van Assche', $data->full_name);
    }

    public function testCanHaveAComputedValueWhenCreatingTheDataObjectWhileRejectingSuppliedValues(): void
    {
        $data = ComputedFullNameData::from(['first_name' => 'Ruben', 'last_name' => 'Van Assche']);

        $this->assertSame('Ruben', $data->first_name);
        $this->assertSame('Van Assche', $data->last_name);
        $this->assertSame('Ruben Van Assche', $data->full_name);

        $data = ComputedFullNameData::validateAndCreate(['first_name' => 'Ruben', 'last_name' => 'Van Assche']);

        $this->assertSame('Ruben', $data->first_name);
        $this->assertSame('Van Assche', $data->last_name);
        $this->assertSame('Ruben Van Assche', $data->full_name);

        $this->expectException(CannotSetComputedValue::class);

        ComputedFullNameData::from(['first_name' => 'Ruben', 'last_name' => 'Van Assche', 'full_name' => 'Ruben Versieck']);
    }

    #[DefineEnvironment('ignoreSuppliedComputedValues')]
    public function testCanHaveAVirtualPropertyWhenCreatingTheDataObject(): void
    {
        $this->assertSame('virtual', SimpleDataWithPropertyHooks::from(['constructorProperty' => 'test', 'virtual' => 'Something to Be Ignored'])->virtual);
        $this->assertSame('virtual', SimpleDataWithPropertyHooks::validateAndCreate(['constructorProperty' => 'test', 'virtual' => 'Something to Be Ignored'])->virtual);
    }

    public function testCanHaveAVirtualPropertyWhenCreatingTheDataObjectWhileRejectingSuppliedValues(): void
    {
        $this->assertSame('virtual', SimpleDataWithPropertyHooks::from(['constructorProperty' => 'test'])->virtual);
        $this->assertSame('virtual', SimpleDataWithPropertyHooks::validateAndCreate(['constructorProperty' => 'test'])->virtual);

        $this->expectException(CannotSetComputedValue::class);

        SimpleDataWithPropertyHooks::from(['constructorProperty' => 'test', 'virtual' => 'other']);
    }

    public function testCanHaveANullableComputedValue(): void
    {
        $dataObject = new class('', '') extends Data {
            #[Computed]
            public ?string $upper_name;

            /**
             * Create the data object and compute its nullable upper-case name.
             */
            public function __construct(
                public ?string $name,
            ) {
                $this->upper_name = $name ? strtoupper($name) : null;
            }
        };

        $data = $dataObject::from(['name' => 'Ruben']);

        $this->assertSame('Ruben', $data->name);
        $this->assertSame('RUBEN', $data->upper_name);

        $data = $dataObject::from(['name' => null]);

        $this->assertNull($data->name);
        $this->assertNull($data->upper_name);

        $data = $dataObject::validateAndCreate(['name' => 'Ruben']);

        $this->assertSame('Ruben', $data->name);
        $this->assertSame('RUBEN', $data->upper_name);

        $data = $dataObject::validateAndCreate(['name' => null]);

        $this->assertNull($data->name);
        $this->assertNull($data->upper_name);

        foreach (['RUBEN', null] as $suppliedValue) {
            try {
                $dataObject::from(['name' => 'Ruben', 'upper_name' => $suppliedValue]);
                $this->fail('Expected the supplied computed value to be rejected.');
            } catch (CannotSetComputedValue) {
            }
        }
    }

    public function testCanHaveAnOptionalComputedValue(): void
    {
        $dataObject = new class('') extends Data {
            #[Computed]
            public string|Optional|null $upper_name;

            /**
             * Create the data object and compute its upper-case name when one is given.
             */
            public function __construct(
                public string|Optional $name = new Optional,
            ) {
                $this->upper_name = $name instanceof Optional ? null : strtoupper($name);
            }
        };

        $data = $dataObject::from(['name' => 'Ruben']);

        $this->assertSame('Ruben', $data->name);
        $this->assertSame('RUBEN', $data->upper_name);

        $data = $dataObject::from([]);

        $this->assertInstanceOf(Optional::class, $data->name);
        $this->assertNull($data->upper_name);

        $this->expectException(CannotSetComputedValue::class);

        $dataObject::from(['name' => 'Ruben', 'upper_name' => 'RUBEN']);
    }

    #[DataProvider('constructorFailureMessages')]
    public function testThrowsAReadableExceptionMessageWhenTheConstructorFails(array $data, string $message): void
    {
        try {
            MultiData::from($data);
            $this->fail('Expected the incomplete payload to be rejected.');
        } catch (CannotCreateData $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    /**
     * Provide incomplete constructor payloads and their messages.
     */
    public static function constructorFailureMessages(): iterable
    {
        yield 'no params' => [[], 'Could not create data class [' . MultiData::class . ']: its constructor requires 2 payload parameters and 0 were supplied. Parameters missing: first, second.'];
        yield 'one param' => [['first' => 'First'], 'Could not create data class [' . MultiData::class . ']: its constructor requires 2 payload parameters and 1 were supplied. Parameters supplied: first. Parameters missing: second.'];
    }

    public function testThrowsAReadableExceptionMessageWhenTheArgumentCountErrorExceptionIsThrownInTheConstructor(): void
    {
        try {
            DataWithArgumentCountErrorException::from(['string' => 'string']);
            $this->fail('Expected the constructor error to be thrown.');
        } catch (ArgumentCountError $e) {
            $constructor = new ReflectionMethod(DataWithArgumentCountErrorException::class, '__construct');

            $this->assertSame('This function expects exactly 2 arguments, 1 given.', $e->getMessage());
            $this->assertSame(__FILE__, $e->getFile());
            $this->assertGreaterThan($constructor->getStartLine(), $e->getLine());
            $this->assertLessThan($constructor->getEndLine(), $e->getLine());
        }
    }

    public function testThrowsAReadableExceptionMessageWhenTheConstructorOfANestedDataObjectFails(): void
    {
        try {
            NestedData::from([
                'simple' => [],
            ]);
            $this->fail('Expected the nested payload to be rejected.');
        } catch (CannotCreateData $exception) {
            $this->assertSame('Could not create data class [' . SimpleData::class . ']: its constructor requires 1 payload parameters and 0 were supplied. Parameters missing: string.', $exception->getMessage());
        }
    }

    public function testACanCreateACollectionOfDataObjects(): void
    {
        $collectionA = new DataCollection(SimpleData::class, [
            SimpleData::from('A'),
            SimpleData::from('B'),
        ]);

        $collectionB = SimpleData::collect([
            'A',
            'B',
        ], DataCollection::class);

        $this->assertEquals($collectionA->toArray(), $collectionB->toArray());
    }

    // REMOVED: Custom collection classes for the deprecated collection() method; use collect() with an explicit $into target.

    public function testWillAllowANestedDataObjectToCastPropertiesHoweverItWants(): void
    {
        $model = new DummyModel(['id' => 10]);

        $withoutModelData = NestedModelData::from([
            'model' => ['id' => 10],
        ]);

        $this->assertInstanceOf(NestedModelData::class, $withoutModelData);
        $this->assertEquals(10, $withoutModelData->model->id);

        $withModelData = NestedModelData::from([
            'model' => $model,
        ]);

        $this->assertInstanceOf(NestedModelData::class, $withModelData);
        $this->assertEquals(10, $withModelData->model->id);
    }

    public function testWillAllowANestedCollectionObjectToCastPropertiesHoweverItWants(): void
    {
        $data = NestedModelCollectionData::from([
            'models' => [['id' => 10], ['id' => 20]],
        ]);

        $this->assertInstanceOf(NestedModelCollectionData::class, $data);
        $this->assertEquals(ModelData::collect([['id' => 10], ['id' => 20]], DataCollection::class), $data->models);

        $data = NestedModelCollectionData::from([
            'models' => [new DummyModel(['id' => 10]), new DummyModel(['id' => 20])],
        ]);

        $this->assertInstanceOf(NestedModelCollectionData::class, $data);
        $this->assertEquals(ModelData::collect([['id' => 10], ['id' => 20]], DataCollection::class), $data->models);

        $data = NestedModelCollectionData::from([
            'models' => ModelData::collect([['id' => 10], ['id' => 20]], DataCollection::class),
        ]);

        $this->assertInstanceOf(NestedModelCollectionData::class, $data);
        $this->assertEquals(ModelData::collect([['id' => 10], ['id' => 20]], DataCollection::class), $data->models);
    }

    public function testWillIgnoreNullOrOptionalValuesWhichAreSetByDefaultInMultiplePayloads(): void
    {
        $dataClass = new class extends Data {
            public string $string;

            public ?string $nullable;

            public Optional|string $optional;
        };

        $data = $dataClass::from(
            ['string' => 'string'],
            ['nullable' => 'nullable'],
            ['optional' => 'optional']
        );

        $this->assertEquals('string', $data->string);
        $this->assertEquals('nullable', $data->nullable);
        $this->assertEquals('optional', $data->optional);

        $data = $dataClass::from(
            ['optional' => 'optional'],
            ['string' => 'string'],
            ['nullable' => 'nullable'],
        );

        $this->assertEquals('string', $data->string);
        $this->assertEquals('nullable', $data->nullable);
        $this->assertEquals('optional', $data->optional);

        $data = $dataClass::from(
            ['nullable' => 'nullable'],
            ['optional' => 'optional'],
            ['string' => 'string'],
        );

        $this->assertEquals('string', $data->string);
        $this->assertEquals('nullable', $data->nullable);
        $this->assertEquals('optional', $data->optional);
    }

    public function testACastCanReturnAnEmptyArray(): void
    {
        $dataclass = new class extends Data {
            #[WithCast(ValueDefinedCast::class, value: [])]
            public array $items;
        };

        $data = $dataclass::from([
            'items' => ['does not matter'],
        ]);

        $this->assertEquals([], $data->items);
    }

    public function testACastCanReturnNull(): void
    {
        $dataclass = new class extends Data {
            #[WithCast(ValueDefinedCast::class, value: null)]
            public ?array $items;
        };

        $data = $dataclass::from([
            'items' => ['does not matter'],
        ]);

        $this->assertNull($data->items);
    }

    public function testCanLoopThroughMultipleCastsUntilTheGoodOneIsFound(): void
    {
        $dataclass = new class extends Data {
            public Collection $items;
        };

        $data = $dataclass::factory()
            ->withCast(Collection::class, new ValueDefinedCast(Uncastable::create()))
            ->withCast(Enumerable::class, new ValueDefinedCast(collect(['Well, hello this cast is used!'])))
            ->from(['items' => ['not used']]);

        $this->assertEquals(collect(['Well, hello this cast is used!']), $data->items);
    }

    public function testCanInjectADataObjectInAController(): void
    {
        Route::post('test', TestControllerDataInjection::class);

        $this->postJson(action(TestControllerDataInjection::class), ['string' => 'Hello World'])->assertOk();
        $this->postJson(action(TestControllerDataInjection::class), ['string' => 'Hello World'])->assertOk(); // caused an infinite loop once
    }

    public function testCanCollectNullWhenAnOutputTypeIsDefined(): void
    {
        $this->assertSame([], SimpleData::collect(null, 'array'));
    }

    public function testWillCastArrayItemsWhenAnIterableTypeIsDefinedThatCanBeCast(): void
    {
        $dataClass = new class extends Data {
            /** @var array<string> */
            public array $array;

            /** @var Collection<int, string> */
            public Collection $collection;
        };

        $data = $dataClass::factory()
            ->withCast('string', StringToUpperCast::class)
            ->from([
                'array' => ['hello', 'world'],
                'collection' => ['this', 'is', 'great'],
            ]);

        $this->assertEquals(['HELLO', 'WORLD'], $data->array);
        $this->assertEquals(collect(['THIS', 'IS', 'GREAT']), $data->collection);
    }

    #[DefineEnvironment('useMeaningOfLifeStringCast')]
    public function testWillCastArrayItemsWhenAnIterableTypeIsDefinedAndPreferItOverAGlobalCast(): void
    {
        $dataClass = new class extends Data {
            /** @var array<string> */
            public array $array;

            /** @var Collection<int, string> */
            public Collection $collection;
        };

        $data = $dataClass::factory()
            ->withCast('string', StringToUpperCast::class)
            ->from([
                'array' => ['hello', 'world'],
                'collection' => ['this', 'is', 'great'],
            ]);

        $this->assertEquals(['HELLO', 'WORLD'], $data->array);
        $this->assertEquals(collect(['THIS', 'IS', 'GREAT']), $data->collection);
    }

    public function testWillCastArrayItemsWhenAnIterableInterfaceTypeIsDefinedThatCanBeCast(): void
    {
        $dataClass = new class extends Data {
            /** @var array<DateTime> */
            public array $dates;

            /** @var array<DummyBackedEnum> */
            public array $enums;
        };

        $data = $dataClass::factory()
            ->withCast('string', StringToUpperCast::class)
            ->from([
                'dates' => [
                    '2022-01-18T12:00:00Z',
                    '2022-01-19T12:00:00Z',
                ],
                'enums' => [
                    'boo',
                    'foo',
                ],
            ]);

        $this->assertEquals([
            DateTime::createFromFormat(DATE_ATOM, '2022-01-18T12:00:00Z'),
            DateTime::createFromFormat(DATE_ATOM, '2022-01-19T12:00:00Z'),
        ], $data->dates);

        $this->assertEquals([
            DummyBackedEnum::BOO,
            DummyBackedEnum::FOO,
        ], $data->enums);
    }

    public function testWillCastIterablesIntoTheCorrectType(): void
    {
        $dataClass = new class extends Data {
            public EloquentCollection $collection;

            public CustomCollection $customCollection;

            public array $array;
        };

        $data = $dataClass::from([
            'collection' => ['no', 'models', 'here'],
            'customCollection' => ['this', 'is', 'great'],
            'array' => collect(['a', 'collection']),
        ]);

        $this->assertInstanceOf(EloquentCollection::class, $data->collection);
        $this->assertEquals(new EloquentCollection(['no', 'models', 'here']), $data->collection);
        $this->assertInstanceOf(CustomCollection::class, $data->customCollection);
        $this->assertEquals(new CustomCollection(['this', 'is', 'great']), $data->customCollection);
        $this->assertSame(['a', 'collection'], $data->array);
    }

    public function testWillCastAnEmptyIterablesListIntoTheCorrectType(): void
    {
        $dataClass = new class extends Data {
            public EloquentCollection $collection;

            public CustomCollection $customCollection;

            public array $array;
        };

        $data = $dataClass::from([
            'collection' => [],
            'customCollection' => [],
            'array' => collect([]),
        ]);

        $this->assertInstanceOf(EloquentCollection::class, $data->collection);
        $this->assertEquals(new EloquentCollection([]), $data->collection);
        $this->assertInstanceOf(CustomCollection::class, $data->customCollection);
        $this->assertEquals(new CustomCollection([]), $data->customCollection);
        $this->assertSame([], $data->array);
    }

    public function testWillCastIterablesIntoDefaultTypes(): void
    {
        $dataClass = new class extends Data {
            /** @var array<int, string> */
            public array $strings;

            /** @var array<int, bool> */
            public array $bools;

            /** @var array<int, int> */
            public array $ints;

            /** @var array<int, float> */
            public array $floats;

            /** @var array<int, array> */
            public array $arrays;
        };

        // Upstream's explicit casts turn every item into some value; Hypervel converts with PHP's weak typing,
        // so items it cannot represent, such as 'Hello' for an int or an array for a bool, fail instead.
        $deprecations = [];

        set_error_handler(static function (int $severity, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, E_DEPRECATED);

        try {
            $data = $dataClass::from([
                'strings' => ['Hello', 42, 3.14, true, '0', 'false'],
                'bools' => ['Hello', 42, 3.14, true, '0', 'false'],
                'ints' => [42, '7', 3.14, true, '0'],
                'floats' => [42, 3.14, true, '0'],
                'arrays' => ['Hello', 42, 3.14, true, ['nested'], '0', 'false'],
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame(['Hello', '42', '3.14', '1', '0', 'false'], $data->strings);
        $this->assertSame([true, true, true, true, false, false], $data->bools);
        $this->assertSame([42, 7, 3, 1, 0], $data->ints);
        $this->assertSame([42.0, 3.14, 1.0, 0.0], $data->floats);
        $this->assertEquals([['Hello'], [42], [3.14], [true], ['nested'], ['0'], ['false']], $data->arrays);
        $this->assertSame(['Implicit conversion from float 3.14 to int loses precision'], $deprecations);

        $empty = ['strings' => [], 'bools' => [], 'ints' => [], 'floats' => [], 'arrays' => []];
        $rejections = [
            ['ints' => ['Hello']],
            ['ints' => [['nested']]],
            ['ints' => ['false']],
            ['floats' => ['Hello']],
            ['floats' => [['nested']]],
            ['floats' => ['false']],
            ['bools' => [['nested']]],
        ];
        $rejected = 0;

        foreach ($rejections as $items) {
            try {
                $dataClass::from([...$empty, ...$items]);
            } catch (TypeError) {
                ++$rejected;
            }
        }

        $this->assertSame(count($rejections), $rejected);
    }

    // REMOVED: EnumerableCast; the fixed engine converts iterable properties, so its cases below run without registering a cast.

    public function testWillNotCastAnObjectWhichIsAlreadyACollection(): void
    {
        $dataClass = new class extends Data {
            public Collection $collection;
        };

        $collection = collect(['a', 'b']);

        $this->assertSame($collection, $dataClass::from(['collection' => $collection])->collection);
    }

    public function testWillCastAnArrayToCollection(): void
    {
        $dataClass = new class extends Data {
            public Collection $collection;
        };

        $this->assertEquals(collect(['a', 'b']), $dataClass::from(['collection' => ['a', 'b']])->collection);
    }

    public function testWillCastAnArrayToTheSpecifiedCollectionType(): void
    {
        $dataClass = new class extends Data {
            public LazyCollection $collection;
        };

        $collection = $dataClass::from(['collection' => ['a', 'b']])->collection;

        $this->assertInstanceOf(LazyCollection::class, $collection);
        $this->assertSame(['a', 'b'], $collection->all());
    }

    public function testKeepsAnArrayAcceptedByAContainerUnion(): void
    {
        $dataClass = new class extends Data {
            public Collection|array $collection;
        };

        // An array already satisfies the declared union, so it is kept rather than converted.
        $this->assertSame(['a', 'b'], $dataClass::from(['collection' => ['a', 'b']])->collection);
        $this->assertEquals(collect(['a', 'b']), $dataClass::from(['collection' => collect(['a', 'b'])])->collection);
    }

    public function testWillNeverInterveneWithDataCollections(): void
    {
        $data = TestDataCollectionCastWithDataCollectable::from([
            'collection' => ['a', 'b'],
        ]);

        $this->assertEquals(collect([
            SimpleData::fromString('a'),
            SimpleData::fromString('b'),
        ]), $data->collection);
    }

    // REMOVED: Creation-context path tracking through custom data pipes; the fixed engine tracks paths internally (ConstructionStateTest).

    public function testIsPossibleToCreateAnUnionTypeDataObject(): void
    {
        $dataClass = new class extends Data {
            public string|SimpleData $property;
        };

        // An accepted scalar keeps its union arm instead of becoming a data object.
        $this->assertSame('Hello World', $dataClass::from(['property' => 'Hello World'])->property);

        $dataClass = new class extends Data {
            public int|SimpleData $property;
        };

        $this->assertIsInt($dataClass::from(['property' => 10])->property);
        $this->assertInstanceOf(SimpleData::class, $dataClass::from(['property' => 'Hello World'])->property);

        $dataClass = new class extends Data {
            public int|SimpleData|Optional|Lazy $property;
        };

        $this->assertIsInt($dataClass::from(['property' => 10])->property);
        $this->assertInstanceOf(SimpleData::class, $dataClass::from(['property' => 'Hello World'])->property);
        $this->assertInstanceOf(Lazy::class, $dataClass::from(['property' => Lazy::create(fn (): int => 10)])->property);
        $this->assertInstanceOf(Optional::class, $dataClass::from([])->property);
    }

    // REMOVED: 'is possible to create a union type data collectable'; unfinished upstream (->todo()), and creating items whose type mixes a Data class with other types is not supported. Transforming them is (DataTransformerTest).

    public function testCanBeCreatedWithoutOptionalValues(): void
    {
        $dataClass = new class extends Data {
            public string $name;

            public string|Optional|null $description;

            public int|Optional $year = 2025;

            public string|Optional $slug;
        };

        $data = $dataClass::factory()
            ->withoutOptionalValues()
            ->from([
                'name' => 'Ruben',
            ]);

        $this->assertSame('Ruben', $data->name);
        $this->assertNull($data->description);
        $this->assertSame(2025, $data->year);
        // Upstream leaves a property that cannot hold null uninitialized; Hypervel keeps it Optional.
        $this->assertInstanceOf(Optional::class, $data->slug);

        $this->assertSame([
            'name' => 'Ruben',
            'description' => null,
            'year' => 2025,
        ], $data->toArray());
    }

    public function testWithoutOptionalValuesAppliesToConstructorNestedAndCastInputs(): void
    {
        OptionalValuesCast::$received = null;

        $direct = OptionalValuesData::factory()
            ->withoutOptionalValues()
            ->from(['name' => 'Ruben', 'child' => []]);
        $general = OptionalValuesData::factory()
            ->withoutOptionalValues()
            ->beforeCreation(static fn (array $properties): array => $properties)
            ->from(['name' => 'Ruben', 'child' => []]);
        $cast = OptionalValuesCastData::factory()
            ->withoutOptionalValues()
            ->from(['name' => 'Ruben']);

        foreach ([$direct, $general] as $data) {
            $this->assertNull($data->description);
            $this->assertInstanceOf(Optional::class, $data->slug);
            $this->assertNull($data->child->note);
        }

        $this->assertSame('RUBEN', $cast->name);
        $this->assertNull(OptionalValuesCast::$received['description']);
        $this->assertInstanceOf(Optional::class, OptionalValuesData::from(['name' => 'Ruben', 'child' => []])->description);
    }

    public function testCanCreateADataObjectWithAutoLazyProperties(): void
    {
        $dataClass = new class extends Data {
            #[AutoLazy]
            public Lazy|SimpleData $data;

            /** @var Collection<int, SimpleData>|Lazy */
            #[AutoLazy]
            public Lazy|Collection $dataCollection;

            #[AutoLazy]
            public Lazy|string $string;

            #[AutoLazy]
            public Lazy|string $overwrittenLazy;

            #[AutoLazy]
            public Optional|Lazy|string $optionalLazy;

            #[AutoLazy]
            public string|Lazy|null $nullableLazy;
        };

        $data = $dataClass::from([
            'data' => 'Hello World',
            'dataCollection' => ['Hello', 'World'],
            'string' => 'Hello World',
            'overwrittenLazy' => Lazy::create(fn (): string => 'Overwritten Lazy'),
        ]);

        $this->assertInstanceOf(Lazy::class, $data->data);
        $this->assertInstanceOf(Lazy::class, $data->dataCollection);
        $this->assertInstanceOf(Lazy::class, $data->string);
        $this->assertInstanceOf(Lazy::class, $data->overwrittenLazy);
        $this->assertInstanceOf(Optional::class, $data->optionalLazy);
        $this->assertNull($data->nullableLazy);

        $this->assertSame([
            'nullableLazy' => null,
        ], $data->toArray());

        $this->assertSame([
            'data' => ['string' => 'Hello World'],
            'dataCollection' => [
                ['string' => 'Hello'],
                ['string' => 'World'],
            ],
            'string' => 'Hello World',
            'overwrittenLazy' => 'Overwritten Lazy',
            'nullableLazy' => null,
        ], $data->include('data', 'dataCollection', 'string', 'overwrittenLazy')->toArray());
    }

    public function testCanCreateAnAutoLazyClassLevelAttributeClass(): void
    {
        $data = TestAutoLazyClassAttributeData::from([
            'data' => 'Hello World',
            'dataCollection' => ['Hello', 'World'],
            'string' => 'Hello World',
            'overwrittenLazy' => Lazy::create(fn (): string => 'Overwritten Lazy'),
            'regularString' => 'Hello World',
        ]);

        $this->assertInstanceOf(Lazy::class, $data->data);
        $this->assertInstanceOf(Lazy::class, $data->dataCollection);
        $this->assertInstanceOf(Lazy::class, $data->string);
        $this->assertInstanceOf(Lazy::class, $data->overwrittenLazy);
        $this->assertInstanceOf(Optional::class, $data->optionalLazy);
        $this->assertNull($data->nullableLazy);
        $this->assertSame('Hello World', $data->regularString);

        $this->assertSame([
            'nullableLazy' => null,
            'regularString' => 'Hello World',
        ], $data->toArray());
        $this->assertSame([
            'data' => ['string' => 'Hello World'],
            'dataCollection' => [
                ['string' => 'Hello'],
                ['string' => 'World'],
            ],
            'string' => 'Hello World',
            'overwrittenLazy' => 'Overwritten Lazy',
            'nullableLazy' => null,
            'regularString' => 'Hello World',
        ], $data->include('data', 'dataCollection', 'string', 'overwrittenLazy')->toArray());
    }

    public function testCanUseAutoLazyToConstructAnInertiaLazy(): void
    {
        $dataClass = new class extends Data {
            #[AutoInertiaLazy]
            public string|Lazy $string;
        };

        $data = $dataClass::from(['string' => 'Hello World']);

        $this->assertInstanceOf(InertiaLazy::class, $data->string);
        $this->assertInstanceOf(OptionalProp::class, $data->toArray()['string']);
    }

    public function testCanUseAutoLazyToConstructAClosureLazy(): void
    {
        $dataClass = new class extends Data {
            #[AutoClosureLazy]
            public string|Lazy $string;
        };

        $data = $dataClass::from(['string' => 'Hello World']);

        $this->assertInstanceOf(ClosureLazy::class, $data->string);
        $this->assertInstanceOf(Closure::class, $data->toArray()['string']);
    }

    public function testCanUseAutoLazyToConstructAWhenLoadedLazy(): void
    {
        $dataClass = new class extends Data {
            #[AutoWhenLoadedLazy]
            /** @var array<int, FakeNestedModelData> */
            public array|Lazy $fakeNestedModels;
        };

        $model = FakeModel::factory()
            ->has(FakeNestedModel::factory()->count(2))
            ->create();

        $this->assertEmpty($dataClass::from($model)->all());

        $model->load('fakeNestedModels');

        $models = $dataClass::from($model)->all()['fakeNestedModels'];

        $this->assertIsArray($models);
        $this->assertCount(2, $models);
        $this->assertContainsOnlyInstancesOf(FakeNestedModelData::class, $models);
    }

    public function testCanUseAutoLazyToConstructAWhenLoadedLazyWithAManualDefinedRelation(): void
    {
        $dataClass = new class extends Data {
            #[AutoWhenLoadedLazy('fakeNestedModels')]
            /** @var array<int, FakeNestedModelData> */
            public array|Lazy $models;
        };

        $model = FakeModel::factory()
            ->has(FakeNestedModel::factory()->count(2))
            ->create();

        $this->assertEmpty($dataClass::from($model)->all());

        $model->load('fakeNestedModels');

        $models = $dataClass::from($model)->all()['models'];

        $this->assertIsArray($models);
        $this->assertCount(2, $models);
        $this->assertContainsOnlyInstancesOf(FakeNestedModelData::class, $models);
    }

    public function testCanUseAutoLazyToConstructADataObjectWithPropertyPromotion(): void
    {
        $dataClass = new class([]) extends Data {
            /**
             * @param array<int, FakeNestedModelData> $fakeNestedModels
             */
            public function __construct(
                #[AutoWhenLoadedLazy]
                public array|Lazy $fakeNestedModels
            ) {
            }
        };

        $model = FakeModel::factory()
            ->has(FakeNestedModel::factory()->count(2))
            ->create();

        $this->assertEmpty($dataClass::from($model)->all());

        $model->load('fakeNestedModels');

        $models = $dataClass::from($model)->all()['fakeNestedModels'];

        $this->assertIsArray($models);
        $this->assertCount(2, $models);
        $this->assertContainsOnlyInstancesOf(FakeNestedModelData::class, $models);
    }

    public function testCanCreateADataObjectWithInertiaDeferredProperties(): void
    {
        $dataClass = new class extends Data {
            public InertiaDeferred|string $deferred;

            public InertiaDeferred|string $deferredWithGroup;

            /**
             * Create the data object without constructor values.
             */
            public function __construct()
            {
            }
        };

        $data = $dataClass::from([
            'deferred' => Lazy::inertiaDeferred(Inertia::defer(fn (): string => 'Deferred Value')),
            'deferredWithGroup' => Lazy::inertiaDeferred(Inertia::defer(fn (): string => 'Deferred Value', 'deferred-group')),
        ]);

        $this->assertDeferredProperties($data);
    }

    public function testCanCreateADataObjectWithInertiaDeferredClosure(): void
    {
        $dataClass = new class extends Data {
            public InertiaDeferred|string $deferred;

            public InertiaDeferred|string $deferredWithGroup;

            /**
             * Create the data object without constructor values.
             */
            public function __construct()
            {
            }
        };

        $data = $dataClass::from([
            'deferred' => Lazy::inertiaDeferred(fn (): string => 'Deferred Value'),
            'deferredWithGroup' => Lazy::inertiaDeferred(fn (): string => 'Deferred Value', 'deferred-group'),
        ]);

        $this->assertDeferredProperties($data);
    }

    public function testCanCreateADataObjectWithInertiaDeferredValue(): void
    {
        $dataClass = new class extends Data {
            public InertiaDeferred|string $deferred;

            public InertiaDeferred|string $deferredWithGroup;

            /**
             * Create the data object without constructor values.
             */
            public function __construct()
            {
            }
        };

        $data = $dataClass::from([
            'deferred' => Lazy::inertiaDeferred('Deferred Value'),
            'deferredWithGroup' => Lazy::inertiaDeferred('Deferred Value', 'deferred-group'),
        ]);

        $this->assertDeferredProperties($data);
    }

    public function testCanUseAutoDeferredToConstructAInertiaDeferredProperty(): void
    {
        $dataClass = new class extends Data {
            #[AutoInertiaDeferred]
            public InertiaDeferred|string $string;

            #[AutoInertiaDeferred('deferred-group')]
            public InertiaDeferred|string $deferredWithGroup;
        };

        $data = $dataClass::from(['string' => 'Deferred Value', 'deferredWithGroup' => 'Deferred Value']);

        $this->assertInstanceOf(InertiaDeferred::class, $data->string);
        $this->assertInstanceOf(DeferProp::class, $data->all()['string']);
        $this->assertSame('Deferred Value', $data->all()['string']());

        $this->assertInstanceOf(InertiaDeferred::class, $data->deferredWithGroup);
        $this->assertInstanceOf(DeferProp::class, $data->all()['deferredWithGroup']);
        $this->assertSame('Deferred Value', $data->all()['deferredWithGroup']());
        $this->assertSame('deferred-group', $data->all()['deferredWithGroup']->group());
    }

    public function testCanUseClassLevelAutoDeferredToConstructAInertiaDeferredProperty(): void
    {
        $data = AutoDeferredData::from(['string' => 'Deferred Value']);

        $this->assertInstanceOf(InertiaDeferred::class, $data->string);
        $this->assertInstanceOf(DeferProp::class, $data->all()['string']);
        $this->assertSame('Deferred Value', $data->all()['string']());
    }

    public function testWillAllowPropertyMorphableDataToBeCreated(): void
    {
        $dataA = AbstractPropertyMorphableData::from([
            'variant' => 'a',
            'a' => 'foo',
            'enum' => 'foo',
        ]);

        $this->assertInstanceOf(PropertyMorphableDataA::class, $dataA);
        $this->assertEquals(PropertyMorphableEnum::A, $dataA->variant);
        $this->assertEquals('foo', $dataA->a);
        $this->assertEquals(DummyBackedEnum::FOO, $dataA->enum);

        $dataB = AbstractPropertyMorphableData::from([
            'variant' => 'b',
            'b' => 'bar',
        ]);

        $this->assertInstanceOf(PropertyMorphableDataB::class, $dataB);
        $this->assertEquals(PropertyMorphableEnum::B, $dataB->variant);
        $this->assertEquals('bar', $dataB->b);
    }

    public function testWillAllowPropertyMorphableDataToBeCreatedFromConcrete(): void
    {
        $dataA = PropertyMorphableDataA::from([
            'a' => 'foo',
            'enum' => 'foo',
        ]);

        $this->assertInstanceOf(PropertyMorphableDataA::class, $dataA);
        $this->assertEquals(PropertyMorphableEnum::A, $dataA->variant);
        $this->assertEquals('foo', $dataA->a);
        $this->assertEquals(DummyBackedEnum::FOO, $dataA->enum);
    }

    public function testConcretePropertyMorphableDataKeepsTheDiscriminatorItsConstructorAssigns(): void
    {
        $data = PropertyMorphableDataA::from([
            'variant' => 'b',
            'a' => 'foo',
            'enum' => 'foo',
        ]);

        $this->assertInstanceOf(PropertyMorphableDataA::class, $data);
        $this->assertSame(PropertyMorphableEnum::A, $data->variant);
    }

    public function testWillAllowPropertyMorphableDataToBeCreatedFromANestedCollection(): void
    {
        $data = NestedPropertyMorphableData::from([
            'nestedCollection' => [
                ['variant' => 'a', 'a' => 'foo', 'enum' => 'foo'],
                ['variant' => 'b', 'b' => 'bar'],
            ],
        ]);

        $this->assertInstanceOf(PropertyMorphableDataA::class, $data->nestedCollection[0]);
        $this->assertEquals(PropertyMorphableEnum::A, $data->nestedCollection[0]->variant);
        $this->assertEquals('foo', $data->nestedCollection[0]->a);
        $this->assertEquals(DummyBackedEnum::FOO, $data->nestedCollection[0]->enum);

        $this->assertInstanceOf(PropertyMorphableDataB::class, $data->nestedCollection[1]);
        $this->assertEquals(PropertyMorphableEnum::B, $data->nestedCollection[1]->variant);
        $this->assertEquals('bar', $data->nestedCollection[1]->b);
    }

    public function testWillAllowPropertyMorphableDataToBeCreatedAsACollection(): void
    {
        $collection = AbstractPropertyMorphableData::collect([
            ['variant' => 'a', 'a' => 'foo', 'enum' => DummyBackedEnum::FOO->value],
            ['variant' => 'b', 'b' => 'bar'],
        ]);

        $this->assertInstanceOf(PropertyMorphableDataA::class, $collection[0]);
        $this->assertEquals(PropertyMorphableEnum::A, $collection[0]->variant);
        $this->assertEquals('foo', $collection[0]->a);
        $this->assertEquals(DummyBackedEnum::FOO, $collection[0]->enum);

        $this->assertInstanceOf(PropertyMorphableDataB::class, $collection[1]);
        $this->assertEquals(PropertyMorphableEnum::B, $collection[1]->variant);
        $this->assertEquals('bar', $collection[1]->b);
    }

    public function testWillOnlyNormalizePayloadsWhenAPropertyMorphableDataClassIsSelected(): void
    {
        $data = TestMorphableDataWithSpecialNormalizerAbstract::from([
            'type' => 'specific',
            'name' => 'Hello World',
        ]);

        $this->assertInstanceOf(TestMorphableDataWithSpecialNormalizerSpecific::class, $data);
        $this->assertEquals('Hello World', $data->name);
        $this->assertEquals('specific', $data->type);
    }

    public function testWillAllowPropertyMorphableDataToBeCreatedFromADefault(): void
    {
        $dataA = TestAbstractPropertyMorphableDefaultData::from([
            'a' => 'foo',
            'enum' => 'foo',
        ]);

        $this->assertInstanceOf(TestPropertyMorphableDefaultDataA::class, $dataA);
        $this->assertEquals(PropertyMorphableEnum::A, $dataA->variant);
        $this->assertEquals('foo', $dataA->a);
        $this->assertEquals(DummyBackedEnum::FOO, $dataA->enum);
    }

    public function testWillThrowAnExceptionWhenAPropertyMorphableDataClassIsNotFound(): void
    {
        $this->expectException(CannotCreateAbstractClass::class);

        AbstractPropertyMorphableData::from([
            'variant' => 'c',
        ]);
    }

    /**
     * Assert the deferred properties resolve to grouped Inertia deferred props.
     */
    protected function assertDeferredProperties(Data $data): void
    {
        $this->assertInstanceOf(InertiaDeferred::class, $data->deferred);
        $this->assertInstanceOf(DeferProp::class, $data->all()['deferred']);
        $this->assertSame('Deferred Value', $data->all()['deferred']());

        $this->assertInstanceOf(InertiaDeferred::class, $data->deferredWithGroup);
        $this->assertInstanceOf(DeferProp::class, $data->all()['deferredWithGroup']);
        $this->assertSame('Deferred Value', $data->all()['deferredWithGroup']());
        $this->assertSame('deferred-group', $data->all()['deferredWithGroup']->group());
    }

    /**
     * Ignore supplied computed property values.
     */
    protected function ignoreSuppliedComputedValues(Application $app): void
    {
        $app->make('config')->set('data.features.ignore_exception_when_trying_to_set_computed_property_value', true);
    }

    /**
     * Register a global string cast.
     */
    protected function useMeaningOfLifeStringCast(Application $app): void
    {
        $app->make('config')->set('data.casts', [
            'string' => MeaningOfLifeCast::class,
        ]);
    }
}

class ComputedFullNameData extends Data
{
    #[Computed]
    public string $full_name;

    /**
     * Create a fixture with a computed full name.
     */
    public function __construct(
        public string $first_name,
        public string $last_name,
    ) {
        $this->full_name = "{$this->first_name} {$this->last_name}";
    }
}

class SimpleDataWithoutConstructor extends Data
{
    public string $string;

    /**
     * Create the fixture from a string.
     */
    public static function fromString(string $string): self
    {
        $data = new self;

        $data->string = $string;

        return $data;
    }
}

class ConfidentialDataCollectionCast implements Cast
{
    /**
     * Replace every item with confidential data.
     */
    public function cast(DataProperty $property, mixed $value, array $properties, CreationContext $context): array
    {
        return array_map(fn (): SimpleData => SimpleData::from('CONFIDENTIAL'), $value);
    }
}

class SimpleCastable implements Castable
{
    /**
     * Create a castable fixture.
     */
    public function __construct(public string $value)
    {
    }

    /**
     * Create the cast for this type.
     */
    public static function dataCastUsing(array $arguments): Cast
    {
        return new class implements Cast {
            /**
             * Cast the value into the castable type.
             */
            public function cast(DataProperty $property, mixed $value, array $properties, CreationContext $context): SimpleCastable
            {
                return new SimpleCastable($value);
            }
        };
    }
}

class ContextAwareCast implements Cast
{
    /**
     * Append the declared property values to the value.
     */
    public function cast(DataProperty $property, mixed $value, array $properties, CreationContext $context): string
    {
        return $value . '+' . json_encode($properties);
    }
}

class ValueDefinedCast implements Cast
{
    /**
     * Create a cast that always returns the given value.
     */
    public function __construct(
        private mixed $value
    ) {
    }

    /**
     * Return the defined value.
     */
    public function cast(DataProperty $property, mixed $value, array $properties, CreationContext $context): mixed
    {
        return $this->value;
    }
}

class DataWithArgumentCountErrorException extends Data
{
    /**
     * Create a fixture whose constructor throws an argument count error.
     */
    public function __construct(
        public string $string,
        public string $optional = 'default',
    ) {
        throw new ArgumentCountError('This function expects exactly 2 arguments, 1 given.');
    }
}

class ModelData extends Data
{
    /**
     * Create a model data fixture.
     */
    public function __construct(
        public int $id
    ) {
    }

    /**
     * Create the fixture from a dummy model.
     */
    public static function fromDummyModel(DummyModel $model): self
    {
        return new self($model->id);
    }
}

class NestedModelData extends Data
{
    /**
     * Create a fixture with nested model data.
     */
    public function __construct(
        public ModelData $model
    ) {
    }
}

class NestedModelCollectionData extends Data
{
    /**
     * Create a fixture with a nested model data collection.
     */
    public function __construct(
        /** @var ModelData[] */
        public DataCollection $models
    ) {
    }
}

class TestControllerDataInjection
{
    /**
     * Handle the request with an injected data object.
     */
    public function __invoke(SimpleData $data): Response
    {
        return response('ok');
    }
}

class TestDataCollectionCastWithDataCollectable extends Data
{
    /** @var Collection<SimpleData> */
    public Collection $collection;
}

#[AutoLazy]
class TestAutoLazyClassAttributeData extends Data
{
    public Lazy|SimpleData $data;

    /** @var Collection<int, SimpleData>|Lazy */
    public Lazy|Collection $dataCollection;

    public Lazy|string $string;

    public Lazy|string $overwrittenLazy;

    public Optional|Lazy|string $optionalLazy;

    public string|Lazy|null $nullableLazy;

    public string $regularString;
}

#[AutoInertiaDeferred]
class AutoDeferredData extends Data
{
    public InertiaDeferred|string $string;
}

class NestedPropertyMorphableData extends Data
{
    /**
     * Create a fixture with a nested property-morphable collection.
     */
    public function __construct(
        /** @var AbstractPropertyMorphableData[] */
        public ?DataCollection $nestedCollection,
    ) {
    }
}

abstract class TestMorphableDataWithSpecialNormalizerAbstract extends Data implements PropertyMorphableData
{
    /**
     * Create a property-morphable fixture with a class normalizer.
     */
    public function __construct(
        #[PropertyForMorph]
        public string $type,
    ) {
    }

    /**
     * Resolve the concrete class for the type.
     */
    public static function morph(array $properties): ?string
    {
        return match ($properties['type']) {
            'specific' => TestMorphableDataWithSpecialNormalizerSpecific::class,
            default => null,
        };
    }

    /**
     * Get the class-owned data normalizers.
     */
    public static function normalizers(): array
    {
        return [FakeNormalizer::returnsArrayItem()];
    }
}

class TestMorphableDataWithSpecialNormalizerSpecific extends TestMorphableDataWithSpecialNormalizerAbstract
{
    /**
     * Create the specific morph variant.
     */
    public function __construct(
        public string $name,
    ) {
        parent::__construct('specific');
    }
}

class FakeNormalizer implements Normalizer
{
    /**
     * Create a normalizer that reads properties from array items.
     */
    public static function returnsArrayItem(): self
    {
        return new self;
    }

    /**
     * Normalize an array into a property reader.
     */
    public function normalize(mixed $value): array|Normalized|null
    {
        return new class($value) implements Normalized {
            /**
             * Create the property reader for the array.
             */
            public function __construct(
                protected array $value,
            ) {
            }

            /**
             * Get a property value from the array.
             */
            public function getProperty(string $name, DataProperty $dataProperty): mixed
            {
                return $this->value[$name];
            }
        };
    }
}

abstract class TestAbstractPropertyMorphableDefaultData extends Data implements PropertyMorphableData
{
    /**
     * Create a property-morphable fixture with a default variant.
     */
    public function __construct(
        #[PropertyForMorph]
        public PropertyMorphableEnum $variant = PropertyMorphableEnum::A,
    ) {
    }

    /**
     * Resolve the concrete class for the variant.
     */
    public static function morph(array $properties): ?string
    {
        return match ($properties['variant'] ?? null) {
            PropertyMorphableEnum::A => TestPropertyMorphableDefaultDataA::class,
            default => null,
        };
    }
}

class TestPropertyMorphableDefaultDataA extends TestAbstractPropertyMorphableDefaultData
{
    /**
     * Create the default morph variant.
     */
    public function __construct(public string $a, public DummyBackedEnum $enum)
    {
        parent::__construct(PropertyMorphableEnum::A);
    }
}

class OptionalValuesData extends Data
{
    /**
     * Create a fixture with missing Optional constructor properties.
     */
    public function __construct(
        public string $name,
        public string|Optional|null $description,
        public string|Optional $slug,
        public OptionalValuesChildData $child,
    ) {
    }
}

class OptionalValuesChildData extends Data
{
    /**
     * Create a nested fixture with a missing nullable Optional property.
     */
    public function __construct(
        public string|Optional|null $note,
    ) {
    }
}

class OptionalValuesCastData extends Data
{
    /**
     * Create a fixture whose cast receives the declared property values.
     */
    public function __construct(
        #[WithCast(OptionalValuesCast::class)]
        public string $name,
        public string|Optional|null $description,
    ) {
    }
}

class OptionalValuesCast implements Cast
{
    /** @var null|array<string, mixed> */
    public static ?array $received = null;

    /**
     * Record the declared values and uppercase the value.
     */
    public function cast(DataProperty $property, mixed $value, array $properties, CreationContext $context): string
    {
        static::$received = $properties;

        return strtoupper($value);
    }
}
