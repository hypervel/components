<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Container\Container;
use Hypervel\Foundation\Application;
use Hypervel\Support\Carbon;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Stringable as HypervelStringable;
use Hypervel\Support\Traits\InteractsWithData;
use Hypervel\Tests\Support\Concerns\TestsInteractsWithDataContract;
use Hypervel\Tests\TestCase;
use stdClass;
use Stringable;

enum InteractsWithDataTestStringEnum: string
{
    case Utc = 'UTC';
    case NewYork = 'America/New_York';
}

enum InteractsWithDataTestIntEnum: int
{
    case One = 1;
    case Two = 2;
}

enum InteractsWithDataTestUnitEnum
{
    case UTC;
    case NewYork;
}

class SupportInteractsWithDataTest extends TestCase
{
    use TestsInteractsWithDataContract;

    protected function setUp(): void
    {
        parent::setUp();

        Container::setInstance(new Application);
    }

    public function testExistsMethod(): void
    {
        $this->assertDataExistsContract();
        $this->assertDataExistsNestedContract();
    }

    public function testHasMethod(): void
    {
        $this->assertDataHasContract();
        $this->assertDataHasNestedContract();
    }

    public function testHasAnyMethod(): void
    {
        $this->assertDataHasAnyContract();
        $this->assertDataHasAnyNestedContract();
    }

    public function testWhenHasMethod(): void
    {
        $this->assertDataWhenHasContract();
        $this->assertDataWhenHasNestedContract();
    }

    public function testFilledMethod(): void
    {
        $this->assertDataFilledContract();
        $this->assertDataFilledNestedContract();
    }

    public function testFilledHandlesTypedValuesWithoutStringifyingObjects(): void
    {
        $instance = static::arrayToDataFactory([
            'null' => null,
            'blank' => ' ',
            'blank_stringable' => new HypervelStringable(' '),
            'boolean' => false,
            'array' => [],
            'integer' => 0,
            'enum' => InteractsWithDataTestIntEnum::One,
            'object' => new stdClass,
        ]);

        $this->assertFalse($instance->filled('null'));
        $this->assertFalse($instance->filled('blank'));
        $this->assertFalse($instance->filled('blank_stringable'));
        $this->assertTrue($instance->filled('boolean'));
        $this->assertTrue($instance->filled('array'));
        $this->assertTrue($instance->filled('integer'));
        $this->assertTrue($instance->filled('enum'));
        $this->assertTrue($instance->filled('object'));
    }

    public function testIsNotFilledMethod(): void
    {
        $this->assertDataIsNotFilledContract();
        $this->assertDataIsNotFilledNestedContract();
    }

    public function testAnyFilledMethod(): void
    {
        $this->assertDataAnyFilledContract();
        $this->assertDataAnyFilledNestedContract();
    }

    public function testWhenFilledMethod(): void
    {
        $this->assertDataWhenFilledContract();
        $this->assertDataWhenFilledNestedContract();
    }

    public function testWhenEnumMethod(): void
    {
        $this->assertDataWhenEnumContract();

        $number = false;

        static::arrayToDataFactory(['number' => '1'])->whenEnum('number', InteractsWithDataTestIntEnum::class, function (InteractsWithDataTestIntEnum $value) use (&$number): void {
            $number = $value;
        });

        $this->assertSame(InteractsWithDataTestIntEnum::One, $number);
    }

    public function testMissingMethod(): void
    {
        $this->assertDataMissingContract();
        $this->assertDataMissingNestedContract();
    }

    public function testWhenMissingMethod(): void
    {
        $this->assertDataWhenMissingContract();
        $this->assertDataWhenMissingNestedContract();
    }

    public function testStrMethod(): void
    {
        $this->assertDataStrContract();
    }

    public function testStringMethod(): void
    {
        $this->assertDataStringContract();
        $this->assertDataStringStrictContract();
    }

    public function testBooleanMethod(): void
    {
        $this->assertDataBooleanContract();
        $this->assertDataBooleanStrictContract();
        $this->assertDataBooleanStrictNativeValuesContract();
    }

    public function testIntegerMethod(): void
    {
        $this->assertDataIntegerContract();
        $this->assertDataIntegerStrictContract();
    }

    public function testFloatMethod(): void
    {
        $this->assertDataFloatContract();
        $this->assertDataFloatStrictContract();
    }

    public function testStrictNumbersAcceptNumericStringables(): void
    {
        $data = static::arrayToDataFactory([
            'integer' => new HypervelStringable('12'),
            'float' => new HypervelStringable('12.5'),
        ]);

        $this->assertSame(12, $data->integer('integer', null, true));
        $this->assertSame(12.5, $data->float('float', null, true));
    }

    public function testStrictDefaultsAreResolvedOnce(): void
    {
        $data = static::arrayToDataFactory([]);
        $calls = 0;
        $default = function () use (&$calls): null {
            ++$calls;

            return null;
        };

        $this->assertSame('', $data->string('missing', $default, true)->value());
        $this->assertSame(1, $calls);

        $this->assertNull($data->integer('missing', $default, true));
        $this->assertSame(2, $calls);

        $this->assertNull($data->float('missing', $default, true));
        $this->assertSame(3, $calls);

        $this->assertNull($data->boolean('missing', $default, true));
        $this->assertSame(4, $calls);
    }

    public function testClampMethod(): void
    {
        $this->assertDataClampContract();

        $instance = static::arrayToDataFactory([
            'per_page' => '100',
            'float' => '9.24',
        ]);

        $this->assertSame(100, $instance->clamp('per_page', 100, 101));
        $this->assertSame(10, $instance->clamp('per_page', -10, 10));
        $this->assertSame(100, $instance->clamp('per_page', 1, 250, 99));
        $this->assertSame(9.24, $instance->clamp('float', 1, 10));
    }

    public function testDateMethod(): void
    {
        $this->assertDataDateContract();
    }

    public function testDateParsesWithoutFormat(): void
    {
        $instance = static::arrayToDataFactory(['date' => '2024-01-15 10:30:00']);

        $result = $instance->date('date');

        $this->assertSame(CarbonImmutable::class, $result::class);
        $this->assertEquals('2024-01-15 10:30:00', $result->format('Y-m-d H:i:s'));
    }

    public function testDateHonorsTheMutableDateFactoryOptOut(): void
    {
        Date::use(Carbon::class);
        $instance = static::arrayToDataFactory(['date' => '2024-01-15 10:30:00']);

        $result = $instance->date('date');

        $this->assertSame(Carbon::class, $result::class);
    }

    public function testDateParsesWithFormat(): void
    {
        $instance = static::arrayToDataFactory(['date' => '15/01/2024']);

        $result = $instance->date('date', 'd/m/Y');

        $this->assertSame(CarbonImmutable::class, $result::class);
        $this->assertEquals('2024-01-15', $result->format('Y-m-d'));
    }

    public function testDateWithStringTimezone(): void
    {
        $instance = static::arrayToDataFactory(['date' => '2024-01-15 10:30:00']);

        $result = $instance->date('date', null, 'America/New_York');

        $this->assertSame(CarbonImmutable::class, $result::class);
        $this->assertEquals('America/New_York', $result->timezone->getName());
    }

    public function testDateWithStringBackedEnumTimezone(): void
    {
        $instance = static::arrayToDataFactory(['date' => '2024-01-15 10:30:00']);

        $result = $instance->date('date', null, InteractsWithDataTestStringEnum::NewYork);

        $this->assertSame(CarbonImmutable::class, $result::class);
        $this->assertEquals('America/New_York', $result->timezone->getName());
    }

    public function testDateWithUnitEnumTimezone(): void
    {
        $instance = static::arrayToDataFactory(['date' => '2024-01-15 10:30:00']);

        // UnitEnum uses ->name, so 'UTC' will be the timezone
        $result = $instance->date('date', null, InteractsWithDataTestUnitEnum::UTC);

        $this->assertSame(CarbonImmutable::class, $result::class);
        $this->assertEquals('UTC', $result->timezone->getName());
    }

    public function testDateWithIntBackedEnumTimezoneUsesEnumValue(): void
    {
        $instance = static::arrayToDataFactory(['date' => '2024-01-15 10:30:00']);

        // Int-backed enum will return int (1), which Carbon interprets as a UTC offset
        // This tests that enum_value() is called and passes the value to Carbon
        $result = $instance->date('date', null, InteractsWithDataTestIntEnum::One);

        $this->assertSame(CarbonImmutable::class, $result::class);
        // Carbon interprets int as UTC offset, so timezone offset will be +01:00
        $this->assertEquals('+01:00', $result->timezone->getName());
    }

    public function testDateWithNullTimezone(): void
    {
        $instance = static::arrayToDataFactory(['date' => '2024-01-15 10:30:00']);

        $result = $instance->date('date', null, null);

        $this->assertSame(CarbonImmutable::class, $result::class);
    }

    public function testIntervalMethod(): void
    {
        $this->assertDataIntervalContract();
    }

    public function testEnumMethod(): void
    {
        $this->assertDataEnumContract();
        $this->assertDataEnumNestedContract();
    }

    public function testStringBackedEnumAcceptsAnyStringableValue(): void
    {
        $instance = static::arrayToDataFactory([
            'timezone' => new class implements Stringable {
                public function __toString(): string
                {
                    return 'UTC';
                }
            },
        ]);

        $this->assertSame(
            InteractsWithDataTestStringEnum::Utc,
            $instance->enum('timezone', InteractsWithDataTestStringEnum::class),
        );
    }

    public function testEnumMethodsAcceptNumericStringsAndExistingCases(): void
    {
        $instance = static::arrayToDataFactory([
            'numeric' => '1',
            'case' => InteractsWithDataTestIntEnum::Two,
            'cases' => ['1', InteractsWithDataTestIntEnum::Two, 'invalid'],
        ]);

        $this->assertSame(
            InteractsWithDataTestIntEnum::One,
            $instance->enum('numeric', InteractsWithDataTestIntEnum::class),
        );
        $this->assertSame(
            InteractsWithDataTestIntEnum::Two,
            $instance->enum('case', InteractsWithDataTestIntEnum::class),
        );
        $this->assertSame(
            [InteractsWithDataTestIntEnum::One, InteractsWithDataTestIntEnum::Two],
            $instance->enums('cases', InteractsWithDataTestIntEnum::class),
        );
    }

    public function testEnumsMethod(): void
    {
        $this->assertDataEnumsContract();
    }

    public function testArrayMethod(): void
    {
        $this->assertDataArrayContract();
        $this->assertDataArrayArrayableContract();
    }

    public function testCollectMethod(): void
    {
        $this->assertDataCollectContract();
        $this->assertDataCollectNestedContract();
    }

    public function testOnlyMethod(): void
    {
        $this->assertDataOnlyContract();
        $this->assertDataOnlyNestedContract();
    }

    public function testExceptMethod(): void
    {
        $this->assertDataExceptContract();
        $this->assertDataExceptNestedContract();
    }

    public function testNullValues(): void
    {
        $this->assertDataNullValuesContract();
        $this->assertDataNullValuesIgnoreDefaultsContract();
        $this->assertDataNestedNullValuesContract();
    }

    protected static function arrayToDataFactory(array $data): object
    {
        return new class($data) {
            use InteractsWithData;

            public function __construct(protected array $attributes)
            {
            }

            public function all(mixed $keys = null): array
            {
                if (! $keys) {
                    return $this->attributes;
                }

                $results = [];

                foreach (is_array($keys) ? $keys : func_get_args() as $key) {
                    data_set($results, $key, data_get($this->attributes, $key));
                }

                return $results;
            }

            protected function data(?string $key = null, mixed $default = null): mixed
            {
                return data_get($this->attributes, $key, $default);
            }
        };
    }
}
