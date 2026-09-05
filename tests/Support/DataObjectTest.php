<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use BackedEnum;
use Carbon\Carbon as BaseCarbon;
use Carbon\CarbonImmutable as BaseCarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Hypervel\Contracts\Container\Transient;
use Hypervel\Contracts\Http\RequestCastable;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Support\Carbon;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\DataObject;
use Hypervel\Support\DateFactory;
use Hypervel\Support\Http\DataObjectRequestCast;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use JsonException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Stringable;
use TypeError;
use ValueError;

class DataObjectTest extends TestCase
{
    public function testItConstructsFromExactKeysAndIgnoresUnknownKeys(): void
    {
        $data = ScalarDataObject::from([
            'unknown' => 'ignored',
            'note' => null,
            'tags' => ['framework'],
            'score' => 9.5,
            'active' => true,
            'age' => 37,
            'name' => 'Taylor',
        ]);

        $this->assertSame([
            'name' => 'Taylor',
            'age' => 37,
            'active' => true,
            'score' => 9.5,
            'tags' => ['framework'],
            'note' => null,
        ], $data->toArray());
    }

    public function testExactPropertyNamesAreNotMapped(): void
    {
        $data = CamelCaseDataObject::from(['displayName' => 'Taylor']);

        $this->assertSame('Taylor', $data->displayName);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('required property [displayName] is missing');

        CamelCaseDataObject::from(['display_name' => 'Taylor']);
    }

    public function testDefaultsNullableValuesAndExplicitNullUseNativePrecedence(): void
    {
        $data = DefaultsDataObject::from([]);

        $this->assertSame('default', $data->name);
        $this->assertNull($data->note);

        $this->expectException(TypeError::class);

        DefaultsDataObject::from(['name' => null]);
    }

    public function testMissingNullableValueWithoutDefaultReceivesNull(): void
    {
        $data = NullableDataObject::from([]);

        $this->assertNull($data->note);
    }

    public function testMissingRequiredValueNamesTheClassAndProperty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(RequiredDataObject::class);
        $this->expectExceptionMessage('required property [name] is missing');

        RequiredDataObject::from([]);
    }

    public function testObjectDefaultsAreEvaluatedForEveryConstruction(): void
    {
        $first = ObjectDefaultDataObject::from([]);
        $second = ObjectDefaultDataObject::from([]);

        $this->assertNotSame($first->marker, $second->marker);
    }

    public function testReadonlyPropertiesAreSupported(): void
    {
        $data = ReadonlyDataObject::from(['name' => 'Taylor']);

        $this->assertSame('Taylor', $data->name);
    }

    public function testDataObjectsAreAlwaysTransient(): void
    {
        $this->assertInstanceOf(Transient::class, RequiredDataObject::from(['name' => 'Taylor']));
    }

    public function testDataObjectsProvideARequestCasterForTheirConcreteClass(): void
    {
        $caster = RequiredDataObject::castRequestUsing([]);

        $this->assertInstanceOf(RequestCastable::class, RequiredDataObject::from(['name' => 'Taylor']));
        $this->assertInstanceOf(DataObjectRequestCast::class, $caster);
        $this->assertEquals(
            RequiredDataObject::from(['name' => 'Taylor']),
            $caster->cast('contact', ['name' => 'Taylor'], []),
        );
        $this->assertNull($caster->cast('contact', null, []));
    }

    public function testDataObjectRequestCastsRejectArguments(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Data object request cast [' . RequiredDataObject::class . '] does not accept arguments.',
        );

        RequiredDataObject::castRequestUsing(['unsupported']);
    }

    public function testDirectConstructionTransformsBeforeRecipeCompilation(): void
    {
        DirectConstructionDataObject::flushState();
        $data = new DirectConstructionDataObject('Taylor', 37);

        $this->assertSame(['name' => 'Taylor', 'age' => 37], $data->toArray());
    }

    public function testMutationIsVisibleInEveryTransformation(): void
    {
        $data = MutableDataObject::from(['name' => 'Taylor']);
        $this->assertSame(['name' => 'Taylor'], $data->toArray());

        $data->name = 'Abigail';

        $this->assertSame(['name' => 'Abigail'], $data->toArray());
        $this->assertSame('{"name":"Abigail"}', $data->toJson());
    }

    #[DataProvider('validIntegerProvider')]
    public function testItConvertsValidIntegers(mixed $value, int $expected): void
    {
        $this->assertSame($expected, IntegerDataObject::from(['value' => $value])->value);
    }

    public static function validIntegerProvider(): iterable
    {
        yield 'native' => [42, 42];
        yield 'zero' => [0, 0];
        yield 'negative' => [-42, -42];
        yield 'trimmed string' => [' 42 ', 42];
        yield 'whole float' => [42.0, 42];
    }

    #[DataProvider('invalidIntegerProvider')]
    public function testItRejectsInvalidIntegers(mixed $value): void
    {
        $this->assertInvalidScalar(IntegerDataObject::class, 'int', $value);
    }

    public static function invalidIntegerProvider(): iterable
    {
        yield 'fractional float' => [1.5];
        yield 'decimal string' => ['1.5'];
        yield 'text' => ['one'];
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'empty string' => [''];
        yield 'array' => [[]];
        yield 'object' => [new stdClass];
        yield 'numeric stringable' => [new NumericDataObjectStringable];
    }

    #[DataProvider('validFloatProvider')]
    public function testItConvertsValidFloats(mixed $value, float $expected): void
    {
        $this->assertSame($expected, FloatDataObject::from(['value' => $value])->value);
    }

    public static function validFloatProvider(): iterable
    {
        yield 'native' => [1.5, 1.5];
        yield 'integer' => [2, 2.0];
        yield 'decimal string' => ['1.5', 1.5];
        yield 'scientific notation' => ['1e3', 1000.0];
        yield 'trimmed string' => [' 2.5 ', 2.5];
    }

    #[DataProvider('invalidFloatProvider')]
    public function testItRejectsInvalidFloats(mixed $value): void
    {
        $this->assertInvalidScalar(FloatDataObject::class, 'float', $value);
    }

    public static function invalidFloatProvider(): iterable
    {
        yield 'text' => ['one'];
        yield 'true' => [true];
        yield 'false' => [false];
        yield 'empty string' => [''];
        yield 'array' => [[]];
        yield 'object' => [new stdClass];
        yield 'numeric stringable' => [new NumericDataObjectStringable];
    }

    #[DataProvider('validBooleanProvider')]
    public function testItConvertsValidBooleans(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, BooleanDataObject::from(['value' => $value])->value);
    }

    public static function validBooleanProvider(): iterable
    {
        yield 'native true' => [true, true];
        yield 'native false' => [false, false];
        yield 'integer one' => [1, true];
        yield 'integer zero' => [0, false];
        yield 'true' => ['true', true];
        yield 'uppercase true' => ['TRUE', true];
        yield 'false' => ['false', false];
        yield 'yes' => ['yes', true];
        yield 'uppercase yes' => ['YES', true];
        yield 'no' => ['no', false];
        yield 'on' => ['on', true];
        yield 'off' => ['off', false];
        yield 'empty string' => ['', false];
    }

    #[DataProvider('invalidBooleanProvider')]
    public function testItRejectsInvalidBooleans(mixed $value): void
    {
        $this->assertInvalidScalar(BooleanDataObject::class, 'bool', $value);
    }

    public static function invalidBooleanProvider(): iterable
    {
        yield 'integer two' => [2];
        yield 'text' => ['sometimes'];
        yield 'array' => [[]];
        yield 'object' => [new stdClass];
    }

    #[DataProvider('validStringProvider')]
    public function testItConvertsValidStrings(mixed $value, string $expected): void
    {
        $this->assertSame($expected, StringDataObject::from(['value' => $value])->value);
    }

    public static function validStringProvider(): iterable
    {
        yield 'native' => ['value', 'value'];
        yield 'integer' => [42, '42'];
        yield 'float' => [1.5, '1.5'];
        yield 'true' => [true, '1'];
        yield 'false' => [false, ''];
        yield 'stringable' => [new DataObjectStringable, 'stringable'];
    }

    #[DataProvider('invalidStringProvider')]
    public function testItRejectsInvalidStrings(mixed $value): void
    {
        $this->assertInvalidScalar(StringDataObject::class, 'string', $value);
    }

    public static function invalidStringProvider(): iterable
    {
        yield 'array' => [[]];
        yield 'object' => [new stdClass];
    }

    public function testArraysAreAcceptedWithoutItemInference(): void
    {
        $items = [['name' => 'Taylor'], ['name' => 'Abigail']];
        $data = ArrayDataObject::from(['items' => $items]);

        $this->assertSame($items, $data->items);
    }

    public function testNonArrayInputIsRejectedForArrayProperty(): void
    {
        try {
            ArrayDataObject::from(['items' => 'item']);
            $this->fail('Invalid array input was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString(ArrayDataObject::class, $exception->getMessage());
            $this->assertStringContainsString('property [items]', $exception->getMessage());
            $this->assertStringContainsString('expects array', $exception->getMessage());
        }
    }

    public function testInvalidScalarMessageContainsItsContext(): void
    {
        try {
            IntegerDataObject::from(['value' => 'invalid']);
            $this->fail('Invalid integer input was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString(IntegerDataObject::class, $exception->getMessage());
            $this->assertStringContainsString('property [value]', $exception->getMessage());
            $this->assertStringContainsString('expects int', $exception->getMessage());
            $this->assertStringContainsString('invalid', $exception->getMessage());
        }
    }

    public function testBackedEnumsAcceptCasesAndBackingValues(): void
    {
        $existing = EnumDataObject::from([
            'stringStatus' => DataObjectStringStatus::Ready,
            'integerStatus' => DataObjectIntegerStatus::Ready,
        ]);
        $converted = EnumDataObject::from([
            'stringStatus' => 'ready',
            'integerStatus' => '1',
        ]);

        $this->assertSame(DataObjectStringStatus::Ready, $existing->stringStatus);
        $this->assertSame(DataObjectIntegerStatus::Ready, $existing->integerStatus);
        $this->assertSame(DataObjectStringStatus::Ready, $converted->stringStatus);
        $this->assertSame(DataObjectIntegerStatus::Ready, $converted->integerStatus);
    }

    public function testInvalidBackedEnumValuePreservesValueError(): void
    {
        $this->expectException(ValueError::class);

        EnumDataObject::from([
            'stringStatus' => 'missing',
            'integerStatus' => 1,
        ]);
    }

    public function testEnumInterfacesUsePassThroughBehavior(): void
    {
        $existing = DataObjectStringStatus::Ready;

        $this->assertSame($existing, EnumInterfaceDataObject::from(['value' => $existing])->value);

        $this->expectException(TypeError::class);

        EnumInterfaceDataObject::from(['value' => 'ready']);
    }

    public function testNestedDataObjectsAcceptArraysAndExistingInstances(): void
    {
        $existing = new NestedDataObject('existing');
        $data = NestedContainerDataObject::from([
            'first' => ['name' => 'created'],
            'second' => $existing,
        ]);

        $this->assertSame('created', $data->first->name);
        $this->assertSame($existing, $data->second);
    }

    public function testNestedDataObjectsLeaveUnsupportedValuesToPhp(): void
    {
        $this->expectException(TypeError::class);

        NestedContainerDataObject::from([
            'first' => 'text',
            'second' => new NestedDataObject('existing'),
        ]);
    }

    public function testNullableSelfTypeHydratesRecursiveValues(): void
    {
        $node = RecursiveDataObject::from([
            'name' => 'one',
            'next' => [
                'name' => 'two',
                'next' => [
                    'name' => 'three',
                    'next' => [
                        'name' => 'four',
                    ],
                ],
            ],
        ]);

        $this->assertSame('one', $node->name);
        $this->assertSame('two', $node->next?->name);
        $this->assertSame('three', $node->next?->next?->name);
        $this->assertSame('four', $node->next?->next?->next?->name);
    }

    public function testInheritedSelfTypeRetainsItsDeclarationScope(): void
    {
        $node = RecursiveChildDataObject::from([
            'name' => 'child',
            'next' => ['name' => 'parent'],
        ]);

        $this->assertInstanceOf(RecursiveParentDataObject::class, $node->next);
        $this->assertNotInstanceOf(RecursiveChildDataObject::class, $node->next);
    }

    public function testParentTypeHydratesItsDeclaringParent(): void
    {
        $data = RelativeParentDataObject::from(['value' => []]);

        $this->assertInstanceOf(RelativeBaseDataObject::class, $data->value);
    }

    public function testUnionTypesPassExistingValuesThrough(): void
    {
        $value = new stdClass;
        $data = UnionDataObject::from(['value' => $value]);

        $this->assertSame($value, $data->value);
    }

    public function testUnionTypesDoNotGuessAnObjectArmForArrays(): void
    {
        $this->expectException(TypeError::class);

        UnionDataObject::from(['value' => ['name' => 'Taylor']]);
    }

    public function testUnknownObjectsAreNotConstructedFromArrays(): void
    {
        UnknownObject::$constructions = 0;

        try {
            UnknownTargetDataObject::from(['value' => []]);
            $this->fail('Unknown object target accepted an array.');
        } catch (TypeError) {
            $this->assertSame(0, UnknownObject::$constructions);
        }
    }

    public function testConfiguredDateFactoryOwnsInterfaceTargets(): void
    {
        DateFactory::use(Carbon::class);

        $data = DateInterfaceDataObject::from([
            'date' => '2026-09-05 12:34:56',
            'carbon' => '2026-09-05 12:34:56',
        ]);

        $this->assertSame(Carbon::class, $data->date::class);
        $this->assertSame(Carbon::class, $data->carbon::class);
    }

    public function testDateInterfacesPassThroughMatchingValuesAndAdaptNativeValuesToCarbon(): void
    {
        DateFactory::use(Carbon::class);
        $native = new DateTimeImmutable('2026-09-05T12:34:56.123456+02:00');

        $data = DateInterfaceDataObject::from([
            'date' => $native,
            'carbon' => $native,
        ]);

        $this->assertSame($native, $data->date);
        $this->assertSame(Carbon::class, $data->carbon::class);
        $this->assertSame($native->format('U.u'), $data->carbon->format('U.u'));
    }

    public function testConcreteDateTargetsPreserveTheirExactClasses(): void
    {
        $value = '2026-09-05 12:34:56';
        $data = DateTargetsDataObject::from([
            'nativeMutable' => $value,
            'nativeImmutable' => $value,
            'hypervelMutable' => $value,
            'hypervelImmutable' => $value,
            'baseMutable' => $value,
            'baseImmutable' => $value,
            'customNative' => $value,
            'customCarbon' => $value,
        ]);

        $this->assertSame(DateTime::class, $data->nativeMutable::class);
        $this->assertSame(DateTimeImmutable::class, $data->nativeImmutable::class);
        $this->assertSame(Carbon::class, $data->hypervelMutable::class);
        $this->assertSame(CarbonImmutable::class, $data->hypervelImmutable::class);
        $this->assertSame(BaseCarbon::class, $data->baseMutable::class);
        // Carbon retains the configured immutable subclass when adapting through its base type.
        $this->assertInstanceOf(BaseCarbonImmutable::class, $data->baseImmutable);
        $this->assertSame(CustomNativeDateTime::class, $data->customNative::class);
        $this->assertSame(CustomCarbonImmutable::class, $data->customCarbon::class);
    }

    public function testExistingAndCrossDateInstancesAreAdaptedCorrectly(): void
    {
        $existing = new CustomCarbonImmutable('2026-09-05 12:34:56');
        $data = DateAdaptationDataObject::from([
            'existing' => $existing,
            'native' => new DateTimeImmutable('2026-09-05 12:34:56'),
            'carbon' => new DateTimeImmutable('2026-09-05 12:34:56'),
        ]);

        $this->assertSame($existing, $data->existing);
        $this->assertSame(CustomNativeDateTime::class, $data->native::class);
        $this->assertSame(CustomCarbonImmutable::class, $data->carbon::class);
    }

    public function testApplicationDateSubclassesArePreservedForTimestamps(): void
    {
        $data = DateAdaptationDataObject::from([
            'existing' => 1_700_000_000,
            'native' => 1_700_000_000,
            'carbon' => 1_700_000_000,
        ]);

        $this->assertSame(CustomCarbonImmutable::class, $data->existing::class);
        $this->assertSame(CustomNativeDateTime::class, $data->native::class);
        $this->assertSame(CustomCarbonImmutable::class, $data->carbon::class);
    }

    public function testOnlyActualNumbersAreTimestamps(): void
    {
        $timestamp = 1_700_000_000;
        $numeric = DateValueDataObject::from(['date' => $timestamp]);
        $decimal = DateValueDataObject::from(['date' => $timestamp + 0.5]);
        $numericString = DateValueDataObject::from(['date' => '20240101']);

        $this->assertSame($timestamp, $numeric->date->getTimestamp());
        $this->assertSame('2024-01-01', $numericString->date->format('Y-m-d'));
        $this->assertSame('500000', $decimal->date->format('u'));
    }

    public function testTimestampConversionUsesThePhpDefaultTimezone(): void
    {
        $previous = date_default_timezone_get();

        try {
            date_default_timezone_set('America/Toronto');
            $data = DateValueDataObject::from(['date' => 1_700_000_000]);

            $this->assertSame('America/Toronto', $data->date->getTimezone()->getName());
            $this->assertSame(1_700_000_000, $data->date->getTimestamp());
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testInvalidDateStringsPreserveCarbonFailure(): void
    {
        $this->expectException(InvalidFormatException::class);

        DateValueDataObject::from(['date' => 'definitely not a date']);
    }

    public function testTransformationRecursivelyNormalizesSupportedValues(): void
    {
        $data = TransformationDataObject::from([
            'values' => [
                'nested' => new NestedDataObject('Taylor'),
                'enum' => DataObjectStringStatus::Ready,
                'date' => new DateTimeImmutable('2026-09-05T12:34:56+02:00'),
                'arrayable' => new NestedArrayable,
                'object' => $object = new stdClass,
            ],
        ]);

        $this->assertSame([
            'values' => [
                'nested' => ['name' => 'Taylor'],
                'enum' => 'ready',
                'date' => '2026-09-05T12:34:56+02:00',
                'arrayable' => [
                    'date' => '2026-09-05T10:00:00+00:00',
                    'enum' => 1,
                ],
                'object' => $object,
            ],
        ], $data->toArray());
    }

    public function testJsonSerializationMatchesArrayTransformationAndHonorsFlags(): void
    {
        $data = JsonDataObject::from(['url' => 'https://hypervel.org/data']);

        $this->assertSame($data->toArray(), $data->jsonSerialize());
        $this->assertSame('{"url":"https:\/\/hypervel.org\/data"}', $data->toJson());
        $this->assertSame('{"url":"https://hypervel.org/data"}', $data->toJson(JSON_UNESCAPED_SLASHES));
    }

    public function testJsonSerializationThrowsForUnsupportedValues(): void
    {
        $resource = fopen('php://memory', 'r');

        try {
            $this->expectException(JsonException::class);

            MixedDataObject::from(['value' => $resource])->toJson();
        } finally {
            fclose($resource);
        }
    }

    public function testProtectedConstructorsAreSupported(): void
    {
        $data = ProtectedConstructorDataObject::from(['name' => 'Taylor']);

        $this->assertSame('Taylor', $data->name);
    }

    public function testClassesWithoutConstructorsOrPropertiesAreSupported(): void
    {
        $this->assertSame([], EmptyDataObject::from([])->toArray());
    }

    public function testNonPromotedConstructorParametersAreRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('constructor parameter [name] must be a public promoted property');

        NonPromotedDataObject::from(['name' => 'Taylor']);
    }

    public function testNonPublicPromotedPropertiesAreRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('constructor parameter [name] must be a public promoted property');

        ProtectedPromotedDataObject::from(['name' => 'Taylor']);
    }

    public function testPrivatePromotedPropertiesAreRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('constructor parameter [name] must be a public promoted property');

        PrivatePromotedDataObject::from(['name' => 'Taylor']);
    }

    public function testInheritedPrivatePromotedPropertiesAreRejectedConsistently(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('constructor parameter [name] must be a public promoted property');

        InheritedPrivatePromotedDataObject::from(['name' => 'Taylor']);
    }

    public function testExtraPublicInstancePropertiesAreRejected(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('public property [extra] must be promoted by its constructor');

        ExtraPublicPropertyDataObject::from(['name' => 'Taylor']);
    }

    public function testPrivateAndProtectedStateIsExcluded(): void
    {
        $data = InternalStateDataObject::from(['name' => 'Taylor']);

        $this->assertSame(['name' => 'Taylor'], $data->toArray());
    }

    public function testPublicStaticStateIsExcludedAndCannotShadowTheBaseCache(): void
    {
        StaticStateDataObject::$recipes = ['application'];
        $data = StaticStateDataObject::from(['name' => 'Taylor']);

        $this->assertSame(['name' => 'Taylor'], $data->toArray());
        $this->assertSame(['application'], StaticStateDataObject::$recipes);
    }

    public function testInheritedPromotedPropertiesKeepConstructorOrder(): void
    {
        $data = InheritedDataObject::from(['first' => 'one', 'second' => 2]);

        $this->assertSame(['first' => 'one', 'second' => 2], $data->toArray());
    }

    public function testChildConstructorCannotLeaveInheritedPublicDataOutsideItsRecipe(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('public property [name] must be promoted by its constructor');

        InvalidChildDataObject::from(['id' => 1]);
    }

    private function assertInvalidScalar(string $class, string $expected, mixed $value): void
    {
        try {
            $class::from(['value' => $value]);
            $this->fail("Invalid {$expected} input was accepted.");
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString($class, $exception->getMessage());
            $this->assertStringContainsString('property [value]', $exception->getMessage());
            $this->assertStringContainsString("expects {$expected}", $exception->getMessage());
        }
    }
}

final class ScalarDataObject extends DataObject
{
    public function __construct(
        public string $name,
        public int $age,
        public bool $active,
        public float $score,
        public array $tags,
        public ?string $note = null,
    ) {
    }
}

final class CamelCaseDataObject extends DataObject
{
    public function __construct(public string $displayName)
    {
    }
}

final class DefaultsDataObject extends DataObject
{
    public function __construct(
        public string $name = 'default',
        public ?string $note = null,
    ) {
    }
}

final class NullableDataObject extends DataObject
{
    public function __construct(public ?string $note)
    {
    }
}

final class RequiredDataObject extends DataObject
{
    public function __construct(public string $name)
    {
    }
}

final class ObjectDefaultDataObject extends DataObject
{
    public function __construct(public stdClass $marker = new stdClass)
    {
    }
}

final class ReadonlyDataObject extends DataObject
{
    public function __construct(public readonly string $name)
    {
    }
}

final class DirectConstructionDataObject extends DataObject
{
    public function __construct(
        public string $name,
        public int $age,
    ) {
    }
}

final class MutableDataObject extends DataObject
{
    public function __construct(public string $name)
    {
    }
}

final class IntegerDataObject extends DataObject
{
    public function __construct(public int $value)
    {
    }
}

final class FloatDataObject extends DataObject
{
    public function __construct(public float $value)
    {
    }
}

final class BooleanDataObject extends DataObject
{
    public function __construct(public bool $value)
    {
    }
}

final class StringDataObject extends DataObject
{
    public function __construct(public string $value)
    {
    }
}

final class ArrayDataObject extends DataObject
{
    public function __construct(public array $items)
    {
    }
}

final class DataObjectStringable implements Stringable
{
    public function __toString(): string
    {
        return 'stringable';
    }
}

final class NumericDataObjectStringable implements Stringable
{
    public function __toString(): string
    {
        return '42';
    }
}

enum DataObjectStringStatus: string
{
    case Ready = 'ready';
}

enum DataObjectIntegerStatus: int
{
    case Ready = 1;
}

final class EnumDataObject extends DataObject
{
    public function __construct(
        public DataObjectStringStatus $stringStatus,
        public DataObjectIntegerStatus $integerStatus,
    ) {
    }
}

final class EnumInterfaceDataObject extends DataObject
{
    public function __construct(public BackedEnum $value)
    {
    }
}

class NestedDataObject extends DataObject
{
    public function __construct(public string $name)
    {
    }
}

final class NestedContainerDataObject extends DataObject
{
    public function __construct(
        public NestedDataObject $first,
        public NestedDataObject $second,
    ) {
    }
}

class RecursiveDataObject extends DataObject
{
    public function __construct(
        public string $name,
        public ?self $next = null,
    ) {
    }
}

class RecursiveParentDataObject extends DataObject
{
    public function __construct(
        public string $name,
        public ?self $next = null,
    ) {
    }
}

final class RecursiveChildDataObject extends RecursiveParentDataObject
{
}

class RelativeBaseDataObject extends DataObject
{
}

final class RelativeParentDataObject extends RelativeBaseDataObject
{
    public function __construct(public parent $value)
    {
    }
}

final class UnionDataObject extends DataObject
{
    public function __construct(public NestedDataObject|stdClass $value)
    {
    }
}

final class UnknownTargetDataObject extends DataObject
{
    public function __construct(public UnknownObject $value)
    {
    }
}

final class UnknownObject
{
    public static int $constructions = 0;

    public function __construct()
    {
        ++self::$constructions;
    }
}

final class DateInterfaceDataObject extends DataObject
{
    public function __construct(
        public DateTimeInterface $date,
        public CarbonInterface $carbon,
    ) {
    }
}

final class DateTargetsDataObject extends DataObject
{
    public function __construct(
        public DateTime $nativeMutable,
        public DateTimeImmutable $nativeImmutable,
        public Carbon $hypervelMutable,
        public CarbonImmutable $hypervelImmutable,
        public BaseCarbon $baseMutable,
        public BaseCarbonImmutable $baseImmutable,
        public CustomNativeDateTime $customNative,
        public CustomCarbonImmutable $customCarbon,
    ) {
    }
}

final class DateAdaptationDataObject extends DataObject
{
    public function __construct(
        public CustomCarbonImmutable $existing,
        public CustomNativeDateTime $native,
        public CustomCarbonImmutable $carbon,
    ) {
    }
}

final class DateValueDataObject extends DataObject
{
    public function __construct(public DateTimeInterface $date)
    {
    }
}

final class CustomNativeDateTime extends DateTimeImmutable
{
}

final class CustomCarbonImmutable extends CarbonImmutable
{
}

final class TransformationDataObject extends DataObject
{
    public function __construct(public array $values)
    {
    }
}

final class NestedArrayable implements Arrayable
{
    public function toArray(): array
    {
        return [
            'date' => new DateTimeImmutable('2026-09-05T10:00:00+00:00'),
            'enum' => DataObjectIntegerStatus::Ready,
        ];
    }
}

final class JsonDataObject extends DataObject
{
    public function __construct(public string $url)
    {
    }
}

final class MixedDataObject extends DataObject
{
    public function __construct(public mixed $value)
    {
    }
}

final class ProtectedConstructorDataObject extends DataObject
{
    protected function __construct(public string $name)
    {
    }
}

final class EmptyDataObject extends DataObject
{
}

final class NonPromotedDataObject extends DataObject
{
    public function __construct(string $name)
    {
    }
}

final class ProtectedPromotedDataObject extends DataObject
{
    public function __construct(protected string $name)
    {
    }
}

class PrivatePromotedDataObject extends DataObject
{
    public function __construct(private string $name)
    {
    }
}

final class InheritedPrivatePromotedDataObject extends PrivatePromotedDataObject
{
}

final class ExtraPublicPropertyDataObject extends DataObject
{
    public string $extra = 'extra';

    public function __construct(public string $name)
    {
    }
}

final class InternalStateDataObject extends DataObject
{
    protected string $protectedState = 'protected';

    private string $privateState = 'private';

    public function __construct(public string $name)
    {
    }
}

final class StaticStateDataObject extends DataObject
{
    public static array $recipes = [];

    public function __construct(public string $name)
    {
    }
}

class InheritedParentDataObject extends DataObject
{
    public function __construct(
        public string $first,
        public int $second,
    ) {
    }
}

final class InheritedDataObject extends InheritedParentDataObject
{
}

class InvalidParentDataObject extends DataObject
{
    public function __construct(public string $name)
    {
    }
}

final class InvalidChildDataObject extends InvalidParentDataObject
{
    public function __construct(public int $id)
    {
        parent::__construct('hidden');
    }
}
