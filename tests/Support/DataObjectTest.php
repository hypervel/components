<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Carbon\Carbon as BaseCarbon;
use Carbon\CarbonImmutable as BaseCarbonImmutable;
use Carbon\CarbonInterface;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Hypervel\Support\Carbon;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\DataObject;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Str;
use Hypervel\Tests\TestCase;
use LogicException;
use OutOfBoundsException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;
use stdClass;
use TypeError;

class DataObjectTest extends TestCase
{
    /**
     * Test creating a data object with valid data.
     */
    public function testMakeWithValidData(): void
    {
        $data = [
            'string_value' => 'test',
            'int_value' => '42', // String that should be converted to int
            'float_value' => '3.14', // String that should be converted to float
            'bool_value' => 1, // Int that should be converted to bool
            'array_value' => ['item1', 'item2'],
            'object_value' => new stdClass,
        ];

        $object = TestDataObject::make($data);

        $this->assertInstanceOf(TestDataObject::class, $object);
        $this->assertSame('test', $object->stringValue);
        $this->assertSame(42, $object->intValue);
        $this->assertSame(3.14, $object->floatValue);
        $this->assertTrue($object->boolValue);
        $this->assertSame(['item1', 'item2'], $object->arrayValue);
        $this->assertInstanceOf(stdClass::class, $object->objectValue);
        $this->assertSame('default value', $object->withDefaultValue);
        $this->assertNull($object->nullableValue);
    }

    /**
     * Test mutating a data object and refreshing data.
     */
    public function testMutationAndRefreshData(): void
    {
        $data = [
            'string_value' => 'test',
            'int_value' => '42', // String that should be converted to int
            'float_value' => '3.14', // String that should be converted to float
            'bool_value' => 1, // Int that should be converted to bool
            'array_value' => ['item1', 'item2'],
            'object_value' => new stdClass,
        ];

        $object = TestDataObject::make($data);
        $object->stringValue = 'test_changed';
        $object->intValue = 100;
        $object->floatValue = 6.28;
        $object->boolValue = false;
        $object->arrayValue = ['item3', 'item4'];

        $object->refresh();

        $this->assertInstanceOf(TestDataObject::class, $object);
        $this->assertSame('test_changed', $object->stringValue);
        $this->assertSame(100, $object->intValue);
        $this->assertSame(6.28, $object->floatValue);
        $this->assertFalse($object->boolValue);
        $this->assertSame(['item3', 'item4'], $object->arrayValue);
        $this->assertInstanceOf(stdClass::class, $object->objectValue);
        $this->assertSame('default value', $object->withDefaultValue);
        $this->assertNull($object->nullableValue);
    }

    /**
     * Test mutating a data object and refreshing data.
     */
    public function testUpdate(): void
    {
        $data = [
            'string_value' => 'test',
            'int_value' => '42', // String that should be converted to int
            'float_value' => '3.14', // String that should be converted to float
            'bool_value' => 1, // Int that should be converted to bool
            'array_value' => ['item1', 'item2'],
            'object_value' => new stdClass,
        ];

        $object = TestDataObject::make($data);
        $object->update([
            'string_value' => 'test_changed',
            'int_value' => 100,
            'float_value' => 6.28,
            'bool_value' => false,
            'array_value' => ['item3', 'item4'],
        ]);

        $this->assertInstanceOf(TestDataObject::class, $object);
        $this->assertSame('test_changed', $object->stringValue);
        $this->assertSame(100, $object->intValue);
        $this->assertSame(6.28, $object->floatValue);
        $this->assertFalse($object->boolValue);
        $this->assertSame(['item3', 'item4'], $object->arrayValue);
        $this->assertInstanceOf(stdClass::class, $object->objectValue);
        $this->assertSame('default value', $object->withDefaultValue);
        $this->assertNull($object->nullableValue);
    }

    /**
     * Test creating a data object with massing data.
     */
    public function testMakeWithMissingConfig(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing required property `stringValue` in `Hypervel\Tests\Support\TestDataObject`');

        TestDataObject::make([]);
    }

    /**
     * Test overriding the default conversion functions.
     */
    public function testOverrideConvertFunctions(): void
    {
        $data = [
            'snakeCaseParam' => 'foo',
            'multiWordParameterName' => 'bar',
        ];

        $object = TestOverrideDataObject::make($data);

        $this->assertSame('foo', $object->snake_case_param);
        $this->assertSame('bar', $object->multi_word_parameter_name);
    }

    /**
     * Test type conversion for different parameter types.
     */
    public function testTypeConversion(): void
    {
        $data = [
            'string_value' => 123, // Int that should be converted to string
            'int_value' => '42.99', // String that should be converted to int (truncated)
            'float_value' => '3.14', // String that should be converted to float
            'bool_value' => '0', // String that should be converted to bool
            'array_value' => 'single item', // String that should be wrapped in array
        ];

        $object = TestDataObject::make($data);

        $this->assertSame('123', $object->stringValue);
        $this->assertSame(42, $object->intValue);
        $this->assertSame(3.14, $object->floatValue);
        $this->assertFalse($object->boolValue);
        $this->assertSame(['single item'], $object->arrayValue);
    }

    /**
     * Test ArrayAccess implementation - offsetExists and offsetGet.
     */
    public function testArrayAccess(): void
    {
        $object = TestDataObject::make(
            array_merge($this->getData(), [
                'nullable_value' => null,
            ])
        );

        // Test offsetExists
        $this->assertTrue(isset($object['string_value']));
        $this->assertTrue(isset($object['int_value']));
        $this->assertFalse(isset($object['non_existent']));
        $this->assertTrue(isset($object['nullable_value']));

        // Test offsetGet
        $this->assertSame('test', $object['string_value']);
        $this->assertSame(42, $object['int_value']);
        $this->assertNull($object['nullable_value']);

        // Test accessing properties that don't exist
        $this->expectException(OutOfBoundsException::class);
        $object['non_existent'];
    }

    /**
     * Test the immutability of DataObject - offsetSet and offsetUnset.
     */
    public function testImmutability(): void
    {
        $object = TestDataObject::make($this->getData());

        // Test offsetSet
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Data object may not be mutated using array access.');
        $object['string_value'] = 'changed';
    }

    /**
     * Test offsetUnset throws exception.
     */
    public function testOffsetUnset(): void
    {
        $object = TestDataObject::make($this->getData());

        // Test offsetUnset
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Data object may not be mutated using array access.');
        unset($object['string_value']);
    }

    /**
     * Test toArray method.
     */
    public function testToArray(): void
    {
        $object = TestDataObject::make($this->getData());
        $array = $object->toArray();

        $this->assertIsArray($array);
        $this->assertSame('test', $array['string_value']);
        $this->assertSame(42, $array['int_value']);
        $this->assertSame(['item1', 'item2'], $array['array_value']);
    }

    /**
     * Test JsonSerialize implementation.
     */
    public function testJsonSerialize(): void
    {
        $object = TestDataObject::make($this->getData());
        $json = json_encode($object);
        $decoded = json_decode($json, true);

        $this->assertIsString($json);
        $this->assertIsArray($decoded);
        $this->assertSame('test', $decoded['string_value']);
        $this->assertSame(42, $decoded['int_value']);
        $this->assertSame(['item1', 'item2'], $decoded['array_value']);
    }

    /**
     * Test nested DataObject serialization.
     */
    public function testNestedObjectSerialization(): void
    {
        $nestedObject = TestDataObject::make(
            array_merge($this->getData(), [
                'string_value' => 'nested',
            ])
        );

        $object = TestDataObject::make(
            array_merge($this->getData(), [
                'string_value' => 'parent',
                'object_value' => $nestedObject,
            ])
        );

        // Test toArray with nested objects
        $array = $object->toArray();
        $this->assertIsArray($array);
        $this->assertIsArray($array['object_value']);
        $this->assertSame('nested', $array['object_value']['string_value']);

        // Test JSON serialization with nested objects
        $json = json_encode($object);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded['object_value']);
        $this->assertSame('nested', $decoded['object_value']['string_value']);
    }

    /**
     * Test autoResolve = false (default behavior).
     */
    public function testMakeWithoutAutoResolve(): void
    {
        $data = [
            'name' => 'John Doe',
            'address' => [
                'street' => '123 Main St',
                'city' => 'New York',
                'zipCode' => '10001',
            ],
            'gender' => TestGenderEnum::Male,
            'created_at' => '2023-01-01 12:00:00',
        ];

        $user = TestUserDataObject::make($data, false);

        $this->assertSame('John Doe', $user->name);
        $this->assertIsArray($user->address);
        $this->assertSame(['street' => '123 Main St', 'city' => 'New York', 'zipCode' => '10001'], $user->address);
        $this->assertIsString($user->createdAt);
        $this->assertSame('2023-01-01 12:00:00', $user->createdAt);
        $this->assertSame(TestGenderEnum::Male, $user->gender);
    }

    /**
     * Test autoResolve = true with nested DataObject conversion.
     */
    public function testMakeWithAutoResolveDataObject(): void
    {
        $data = [
            'name' => 'John Doe',
            'address' => [
                'street' => '123 Main St',
                'city' => 'New York',
                'zip_code' => '10001',
            ],
            'gender' => 'male',
            'created_at' => '2023-01-01 12:00:00',
        ];

        $user = TestUserDataObject::make($data, true);

        $this->assertSame('John Doe', $user->name);
        $this->assertInstanceOf(TestAddressDataObject::class, $user->address);
        $this->assertSame('123 Main St', $user->address->street);
        $this->assertSame('New York', $user->address->city);
        $this->assertSame('10001', $user->address->zipCode);
        $this->assertInstanceOf(DateTime::class, $user->createdAt);
        $this->assertSame('2023-01-01 12:00:00', $user->createdAt->format('Y-m-d H:i:s'));
        $this->assertSame(TestGenderEnum::Male, $user->gender);
    }

    #[DataProvider('dateInputProvider')]
    public function testAutoResolveHydratesEveryDeclaredDateTarget(
        mixed $input,
        DateTimeImmutable $expected
    ): void {
        $object = DateTargetDataObject::make(array_fill_keys([
            'date_time_interface',
            'carbon_interface',
            'date_time',
            'date_time_immutable',
            'carbon',
            'carbon_immutable',
            'base_carbon',
            'base_carbon_immutable',
        ], $input), true);

        $expectedClasses = [
            'dateTimeInterface' => CarbonImmutable::class,
            'carbonInterface' => CarbonImmutable::class,
            'dateTime' => DateTime::class,
            'dateTimeImmutable' => DateTimeImmutable::class,
            'carbon' => Carbon::class,
            'carbonImmutable' => CarbonImmutable::class,
            'baseCarbon' => BaseCarbon::class,
            'baseCarbonImmutable' => BaseCarbonImmutable::class,
        ];

        foreach ($expectedClasses as $property => $expectedClass) {
            $date = $object->{$property};

            $this->assertSame($expectedClass, $date::class);
            $this->assertSame(
                $expected->format('Y-m-d H:i:s.u e'),
                $date->format('Y-m-d H:i:s.u e')
            );

            if ($input instanceof CarbonInterface && $date instanceof CarbonInterface) {
                $this->assertSame($input->locale(), $date->locale());
            }
        }
    }

    public static function dateInputProvider(): array
    {
        $defaultTimezone = new DateTimeZone(date_default_timezone_get());
        $auckland = new DateTimeZone('Pacific/Auckland');
        $instant = new DateTimeImmutable('2026-07-22 12:34:56.123456', $auckland);
        $epoch = (new DateTimeImmutable('@0'))->setTimezone($defaultTimezone);

        return [
            'database format' => [
                '2026-07-22 12:34:56',
                new DateTimeImmutable('2026-07-22 12:34:56', $defaultTimezone),
            ],
            'standard date' => [
                '2026-07-22',
                new DateTimeImmutable('2026-07-22 00:00:00', $defaultTimezone),
            ],
            'integer epoch' => [0, $epoch],
            'string epoch' => ['0', $epoch],
            'native mutable' => [DateTime::createFromInterface($instant), $instant],
            'native immutable' => [$instant, $instant],
            'base mutable Carbon' => [BaseCarbon::instance($instant)->locale('fr'), $instant],
            'base immutable Carbon' => [BaseCarbonImmutable::instance($instant)->locale('fr'), $instant],
            'Hypervel mutable Carbon' => [Carbon::instance($instant)->locale('fr'), $instant],
            'Hypervel immutable Carbon' => [CarbonImmutable::instance($instant)->locale('fr'), $instant],
        ];
    }

    public function testAutoResolveSupportsFormatModifiersAndTrailingData(): void
    {
        $keys = [
            'date_time_interface',
            'carbon_interface',
            'date_time',
            'date_time_immutable',
            'carbon',
            'carbon_immutable',
            'base_carbon',
            'base_carbon_immutable',
        ];

        $this->setDataObjectStaticProperty('dateFormat', '!Y-d-m \Y');
        $object = DateTargetDataObject::make(array_fill_keys($keys, '2017-05-11 Y'), true);

        $this->assertSame(CarbonImmutable::class, $object->carbonInterface::class);
        $this->assertSame('2017-11-05 00:00:00.000000', $object->carbonInterface->format('Y-m-d H:i:s.u'));

        $this->setDataObjectStaticProperty('dateFormat', '!Y-m-d+');
        $object = DateTargetDataObject::make(array_fill_keys($keys, '2020-09-11 trailing data'), true);

        $this->assertSame(CarbonImmutable::class, $object->carbonInterface::class);
        $this->assertSame('2020-09-11 00:00:00.000000', $object->carbonInterface->format('Y-m-d H:i:s.u'));
    }

    public function testInterfaceDateTargetsFollowConfiguredFactoryWithoutChangingConcreteTargets(): void
    {
        Date::use(Carbon::class);

        $object = DateTargetDataObject::make(array_fill_keys([
            'date_time_interface',
            'carbon_interface',
            'date_time',
            'date_time_immutable',
            'carbon',
            'carbon_immutable',
            'base_carbon',
            'base_carbon_immutable',
        ], '2026-07-22 12:34:56'), true);

        $this->assertSame(Carbon::class, $object->dateTimeInterface::class);
        $this->assertSame(Carbon::class, $object->carbonInterface::class);
        $this->assertSame(DateTime::class, $object->dateTime::class);
        $this->assertSame(DateTimeImmutable::class, $object->dateTimeImmutable::class);
        $this->assertSame(Carbon::class, $object->carbon::class);
        $this->assertSame(CarbonImmutable::class, $object->carbonImmutable::class);
        $this->assertSame(BaseCarbon::class, $object->baseCarbon::class);
        $this->assertSame(BaseCarbonImmutable::class, $object->baseCarbonImmutable::class);
    }

    public function testConcreteDateTargetsRemainExactWithConfiguredCarbonSubclasses(): void
    {
        foreach ([
            DataObjectMutableCarbonSubclass::class,
            DataObjectImmutableCarbonSubclass::class,
        ] as $dateClass) {
            Date::use($dateClass);

            $object = DateTargetDataObject::make(array_fill_keys([
                'date_time_interface',
                'carbon_interface',
                'date_time',
                'date_time_immutable',
                'carbon',
                'carbon_immutable',
                'base_carbon',
                'base_carbon_immutable',
            ], '2026-07-22 12:34:56'), true);

            $this->assertSame($dateClass, $object->dateTimeInterface::class);
            $this->assertSame($dateClass, $object->carbonInterface::class);
            $this->assertSame(Carbon::class, $object->carbon::class);
            $this->assertSame(CarbonImmutable::class, $object->carbonImmutable::class);
            $this->assertSame(BaseCarbon::class, $object->baseCarbon::class);
            $this->assertSame(BaseCarbonImmutable::class, $object->baseCarbonImmutable::class);
        }
    }

    public function testExplicitNullForRequiredDateReachesConstructorAsNull(): void
    {
        $data = array_fill_keys([
            'date_time_interface',
            'carbon_interface',
            'date_time',
            'date_time_immutable',
            'carbon',
            'carbon_immutable',
            'base_carbon',
            'base_carbon_immutable',
        ], '2026-07-22 12:34:56');
        $data['date_time_interface'] = null;

        try {
            DateTargetDataObject::make($data, true);

            $this->fail('Expected the required date constructor argument to reject null.');
        } catch (TypeError $exception) {
            $this->assertStringContainsString('$dateTimeInterface', $exception->getMessage());
            $this->assertStringContainsString('null given', $exception->getMessage());
        }
    }

    public function testEveryDeclaredDateTargetSerializesThroughDateTimeInterface(): void
    {
        $input = new DateTimeImmutable(
            '2026-07-22 12:34:56',
            new DateTimeZone('Pacific/Auckland')
        );
        $object = DateTargetDataObject::make(array_fill_keys([
            'date_time_interface',
            'carbon_interface',
            'date_time',
            'date_time_immutable',
            'carbon',
            'carbon_immutable',
            'base_carbon',
            'base_carbon_immutable',
        ], $input), true);
        $array = $object->toArray();

        foreach ($array as $value) {
            $this->assertSame('2026-07-22T12:34:56+12:00', $value);
        }

        $this->assertSame($array, json_decode(json_encode($object), true));
    }

    public function testCustomNonDateSerializerRemainsAvailableWithDateSerialization(): void
    {
        $object = CustomSerializerDataObject::make([
            'date' => CarbonImmutable::parse('2026-07-22 12:34:56', 'UTC'),
            'value' => new ResolverValue('custom'),
        ]);

        $this->assertSame([
            'date' => '2026-07-22T12:34:56+00:00',
            'value' => 'serialized:custom',
        ], $object->toArray());
    }

    /**
     * Test autoResolve with deep nesting.
     */
    public function testMakeWithAutoResolveDeepNesting(): void
    {
        $data = [
            'name' => 'Company Inc',
            'employee' => [
                'name' => 'Jane Smith',
                'address' => [
                    'street' => '456 Oak Ave',
                    'city' => 'Boston',
                    'zip_code' => '02101',
                ],
                'gender' => 'male',
                'created_at' => '2023-06-15 09:30:00',
            ],
        ];

        $company = TestCompanyDataObject::make($data, true);

        $this->assertSame('Company Inc', $company->name);
        $this->assertInstanceOf(TestUserDataObject::class, $company->employee);
        $this->assertSame('Jane Smith', $company->employee->name);
        $this->assertInstanceOf(TestAddressDataObject::class, $company->employee->address);
        $this->assertSame('456 Oak Ave', $company->employee->address->street);
        $this->assertSame('Boston', $company->employee->address->city);
        $this->assertInstanceOf(DateTime::class, $company->employee->createdAt);
        $this->assertSame(TestGenderEnum::Male, $company->employee->gender);
    }

    /**
     * Test autoResolve with null values.
     */
    public function testMakeWithAutoResolveNullValues(): void
    {
        $data = [
            'name' => 'John Doe',
            'address' => null,
            'created_at' => null,
            'gender' => 'male',
        ];

        $user = TestUserDataObject::make($data, true);

        $this->assertSame('John Doe', $user->name);
        $this->assertNull($user->address);
        $this->assertNull($user->createdAt);
        $this->assertSame(TestGenderEnum::Male, $user->gender);
    }

    public function testEmptyDataObjectCanBeCreatedAndSerialized(): void
    {
        $object = EmptyDataObject::make([], true);

        $this->assertSame([], $object->toArray());
    }

    public function testAutoResolveSkipsUntypedAndIntersectionProperties(): void
    {
        $untyped = UntypedDataObject::make([], true);
        $intersectionValue = new IntersectionValue;
        $intersection = IntersectionDataObject::make(['value' => $intersectionValue], true);

        $this->assertSame('default', $untyped->value);
        $this->assertSame($intersectionValue, $intersection->value);
    }

    public function testAutoResolveSkipsUnsupportedScalarUnions(): void
    {
        $object = ScalarUnionDataObject::make(['value' => 'value'], true);

        $this->assertSame('value', $object->value);
    }

    public function testAutoResolveSkipsIntersectionMembersInDnfUnions(): void
    {
        $address = new TestAddressDataObject('123 Main St', 'New York', '10001');
        $object = DnfUnionDataObject::make(['value' => $address], true);

        $this->assertSame($address, $object->value);
    }

    public function testAutoResolvePreservesMissingDefaultsAndExplicitNulls(): void
    {
        $defaulted = DefaultedDependencyDataObject::make([], true);
        $explicitNull = DefaultedDependencyDataObject::make(['gender' => null], true);

        $this->assertSame(TestGenderEnum::Female, $defaulted->gender);
        $this->assertNull($explicitNull->gender);
    }

    public function testAutoResolvePreservesExistingNestedDataObject(): void
    {
        $address = new TestAddressDataObject('123 Main St', 'New York', '10001');

        $user = TestUserDataObject::make([
            'name' => 'John Doe',
            'gender' => TestGenderEnum::Male,
            'address' => $address,
            'created_at' => null,
        ], true);

        $this->assertSame($address, $user->address);
    }

    public function testNestedDependenciesUseEachOwningClassKeyConvention(): void
    {
        $object = OwnerKeyDataObject::make([
            'owner_child' => [
                'child_value' => 'nested',
            ],
        ], true);

        $this->assertSame('nested', $object->child->value);
    }

    public function testNestedDependenciesUseTheNestedClassResolver(): void
    {
        $object = RootResolverDataObject::make([
            'child' => [
                'value' => 'resolved',
            ],
        ], true);

        $this->assertSame('child:resolved', $object->child->value->value);
    }

    public function testEmptyDependencyMapsAreCached(): void
    {
        DependencylessDataObject::$dependencyLookups = 0;

        DependencylessDataObject::make([], true);
        DependencylessDataObject::make([], true);

        $this->assertSame(1, DependencylessDataObject::$dependencyLookups);
    }

    public function testFlushStateRestoresStaticDefaults(): void
    {
        TestDataObject::make($this->getData());
        TestDataObject::disableAutoCasting();
        $this->setDataObjectStaticProperty('dateFormat', 'Y/m/d');

        $this->assertNotSame([], $this->getDataObjectStaticProperty('reflectionParametersCache'));
        $this->assertFalse(TestDataObject::isAutoCasting());

        TestDataObject::flushState();

        $this->assertSame([], $this->getDataObjectStaticProperty('reflectionParametersCache'));
        $this->assertSame([], $this->getDataObjectStaticProperty('propertyMapCache'));
        $this->assertSame([], $this->getDataObjectStaticProperty('reversedPropertyMapCache'));
        $this->assertSame([], $this->getDataObjectStaticProperty('dependenciesMapCache'));
        $this->assertTrue(TestDataObject::isAutoCasting());
        $this->assertSame('Y-m-d H:i:s', $this->getDataObjectStaticProperty('dateFormat'));
    }

    protected function getData(): array
    {
        return [
            'string_value' => 'test',
            'int_value' => 42,
            'float_value' => 3.14,
            'bool_value' => true,
            'array_value' => ['item1', 'item2'],
            'object_value' => new stdClass,
        ];
    }

    private function getDataObjectStaticProperty(string $name): mixed
    {
        return (new ReflectionClass(DataObject::class))->getStaticPropertyValue($name);
    }

    private function setDataObjectStaticProperty(string $name, mixed $value): void
    {
        (new ReflectionClass(DataObject::class))->setStaticPropertyValue($name, $value);
    }
}

/**
 * Concrete implementation of DataObject for testing.
 */
class TestDataObject extends DataObject
{
    public function __construct(
        public string $stringValue,
        public int $intValue,
        public float $floatValue,
        public bool $boolValue,
        public array $arrayValue,
        public ?object $objectValue,
        public string $withDefaultValue = 'default value',
        public ?string $nullableValue = null
    ) {
    }
}

/**
 * Concrete implementation of DataObject for testing.
 */
class TestOverrideDataObject extends DataObject
{
    public function __construct(
        public string $snake_case_param,
        public string $multi_word_parameter_name,
    ) {
    }

    /**
     * Convert the property name to the data key format.
     * It converts camelCase to snake_case by default.
     */
    public static function convertPropertyToDataKey(string $input): string
    {
        return Str::camel($input);
    }

    /**
     * Convert the data key to the property name format.
     * It converts snake_case to camelCase by default.
     */
    public static function convertDataKeyToProperty(string $input): string
    {
        return Str::snake($input);
    }
}

/**
 * Test DataObject for address.
 */
class TestAddressDataObject extends DataObject
{
    public function __construct(
        public string $street,
        public string $city,
        public string $zipCode,
    ) {
    }
}

/**
 * Test DataObject for user with nested address and DateTime.
 */
class TestUserDataObject extends DataObject
{
    public function __construct(
        public string $name,
        public TestGenderEnum $gender,
        public TestAddressDataObject|array|null $address,
        public DateTime|string|null $createdAt,
    ) {
    }
}

/**
 * Test DataObject for company with nested user.
 */
class TestCompanyDataObject extends DataObject
{
    public function __construct(
        public string $name,
        public TestUserDataObject|array $employee,
    ) {
    }
}

class DateTargetDataObject extends DataObject
{
    public function __construct(
        public DateTimeInterface $dateTimeInterface,
        public CarbonInterface $carbonInterface,
        public DateTime $dateTime,
        public DateTimeImmutable $dateTimeImmutable,
        public Carbon $carbon,
        public CarbonImmutable $carbonImmutable,
        public BaseCarbon $baseCarbon,
        public BaseCarbonImmutable $baseCarbonImmutable,
    ) {
    }
}

class DataObjectMutableCarbonSubclass extends Carbon
{
}

class DataObjectImmutableCarbonSubclass extends CarbonImmutable
{
}

enum TestGenderEnum: string
{
    case Male = 'male';
    case Female = 'female';
}

class EmptyDataObject extends DataObject
{
}

class UntypedDataObject extends DataObject
{
    public $value = 'default';
}

interface FirstIntersectionType
{
}

interface SecondIntersectionType
{
}

class IntersectionValue implements FirstIntersectionType, SecondIntersectionType
{
}

class IntersectionDataObject extends DataObject
{
    public function __construct(public FirstIntersectionType&SecondIntersectionType $value)
    {
    }
}

class DnfUnionDataObject extends DataObject
{
    public function __construct(public (FirstIntersectionType&SecondIntersectionType)|TestAddressDataObject $value)
    {
    }
}

class ScalarUnionDataObject extends DataObject
{
    public function __construct(public int|string $value)
    {
    }
}

class DefaultedDependencyDataObject extends DataObject
{
    public function __construct(public ?TestGenderEnum $gender = TestGenderEnum::Female)
    {
    }
}

class OwnerKeyDataObject extends DataObject
{
    public function __construct(public ChildKeyDataObject $child)
    {
    }

    public static function convertPropertyToDataKey(string $input): string
    {
        return 'owner_' . $input;
    }
}

class ChildKeyDataObject extends DataObject
{
    public function __construct(public string $value)
    {
    }

    public static function convertPropertyToDataKey(string $input): string
    {
        return 'child_' . $input;
    }
}

class RootResolverDataObject extends DataObject
{
    public function __construct(public ChildResolverDataObject $child)
    {
    }

    protected static function getCustomizedDependencies(): array
    {
        return [
            ResolverValue::class => fn (string $value) => new ResolverValue('root:' . $value),
        ];
    }
}

class ChildResolverDataObject extends DataObject
{
    public function __construct(public ResolverValue $value)
    {
    }

    protected static function getCustomizedDependencies(): array
    {
        return [
            ResolverValue::class => fn (string $value) => new ResolverValue('child:' . $value),
        ];
    }
}

class ResolverValue
{
    public function __construct(public string $value)
    {
    }
}

class CustomSerializerDataObject extends DataObject
{
    public function __construct(
        public CarbonInterface $date,
        public ResolverValue $value,
    ) {
    }

    protected static function getSerializers(): array
    {
        return parent::getSerializers() + [
            ResolverValue::class => static fn (ResolverValue $value): string => 'serialized:' . $value->value,
        ];
    }
}

class DependencylessDataObject extends DataObject
{
    public static int $dependencyLookups = 0;

    public function __construct(public string $value = 'default')
    {
    }

    protected static function getCustomizedDependencies(): array
    {
        ++static::$dependencyLookups;

        return parent::getCustomizedDependencies();
    }
}
