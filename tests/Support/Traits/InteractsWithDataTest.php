<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support\Traits;

use Carbon\CarbonInterval;
use Carbon\Unit;
use Hypervel\Container\Container;
use Hypervel\Foundation\Application;
use Hypervel\Support\Carbon;
use Hypervel\Support\Collection;
use Hypervel\Support\Traits\InteractsWithData;
use PHPUnit\Framework\TestCase;

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

class InteractsWithDataTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Container::setInstance(new Application);
    }

    public function testDateReturnsNullWhenKeyIsNotFilled(): void
    {
        $instance = new TestInteractsWithDataClass(['date' => '']);

        $this->assertNull($instance->date('date'));
    }

    public function testDateParsesWithoutFormat(): void
    {
        $instance = new TestInteractsWithDataClass(['date' => '2024-01-15 10:30:00']);

        $result = $instance->date('date');

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('2024-01-15 10:30:00', $result->format('Y-m-d H:i:s'));
    }

    public function testDateParsesWithFormat(): void
    {
        $instance = new TestInteractsWithDataClass(['date' => '15/01/2024']);

        $result = $instance->date('date', 'd/m/Y');

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('2024-01-15', $result->format('Y-m-d'));
    }

    public function testDateWithStringTimezone(): void
    {
        $instance = new TestInteractsWithDataClass(['date' => '2024-01-15 10:30:00']);

        $result = $instance->date('date', null, 'America/New_York');

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('America/New_York', $result->timezone->getName());
    }

    public function testDateWithStringBackedEnumTimezone(): void
    {
        $instance = new TestInteractsWithDataClass(['date' => '2024-01-15 10:30:00']);

        $result = $instance->date('date', null, InteractsWithDataTestStringEnum::NewYork);

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('America/New_York', $result->timezone->getName());
    }

    public function testDateWithUnitEnumTimezone(): void
    {
        $instance = new TestInteractsWithDataClass(['date' => '2024-01-15 10:30:00']);

        // UnitEnum uses ->name, so 'UTC' will be the timezone
        $result = $instance->date('date', null, InteractsWithDataTestUnitEnum::UTC);

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('UTC', $result->timezone->getName());
    }

    public function testDateWithIntBackedEnumTimezoneUsesEnumValue(): void
    {
        $instance = new TestInteractsWithDataClass(['date' => '2024-01-15 10:30:00']);

        // Int-backed enum will return int (1), which Carbon interprets as a UTC offset
        // This tests that enum_value() is called and passes the value to Carbon
        $result = $instance->date('date', null, InteractsWithDataTestIntEnum::One);

        $this->assertInstanceOf(Carbon::class, $result);
        // Carbon interprets int as UTC offset, so timezone offset will be +01:00
        $this->assertEquals('+01:00', $result->timezone->getName());
    }

    public function testDateWithNullTimezone(): void
    {
        $instance = new TestInteractsWithDataClass(['date' => '2024-01-15 10:30:00']);

        $result = $instance->date('date', null, null);

        $this->assertInstanceOf(Carbon::class, $result);
    }

    public function testIntervalMethod(): void
    {
        $instance = new TestInteractsWithDataClass([
            'as_null' => null,
            'as_empty' => '',
            'as_iso' => 'P1Y2M3DT4H5M6S',
            'as_human' => '2 hours 30 minutes',
            'as_seconds' => '90',
            'as_minutes' => '45',
        ]);

        $this->assertNull($instance->interval('as_null'));
        $this->assertNull($instance->interval('as_empty'));
        $this->assertNull($instance->interval('doesnt_exist'));

        $interval = $instance->interval('as_iso');
        $this->assertInstanceOf(CarbonInterval::class, $interval);
        $this->assertSame(1, $interval->years);
        $this->assertSame(2, $interval->months);
        $this->assertSame(3, $interval->dayz);
        $this->assertSame(4, $interval->hours);
        $this->assertSame(5, $interval->minutes);
        $this->assertSame(6, $interval->seconds);

        $interval = $instance->interval('as_human');
        $this->assertInstanceOf(CarbonInterval::class, $interval);
        $this->assertSame(2, $interval->hours);
        $this->assertSame(30, $interval->minutes);

        $interval = $instance->interval('as_seconds', 'second');
        $this->assertInstanceOf(CarbonInterval::class, $interval);
        $this->assertSame(90, $interval->seconds);

        $this->assertSame(90, $instance->interval('as_seconds', 'minute')->minutes);
        $this->assertSame(90, $instance->interval('as_seconds', 'hour')->hours);
        $this->assertSame(90, $instance->interval('as_seconds', 'day')->dayz);

        $this->assertSame(45, $instance->interval('as_minutes', Unit::Minute)->minutes);
        $this->assertSame(45, $instance->interval('as_minutes', Unit::Second)->seconds);
    }

    public function testIntervalMethodWithScientificNotationFloats(): void
    {
        $instance = new TestInteractsWithDataClass([
            'small_float' => '0.000053',
            'very_small' => '0.000001',
            'scientific' => '5.3E-5',
            'normal_float' => '1.5',
            'integer' => '90',
        ]);

        // Small floats that PHP would render in scientific notation (e.g. 5.3E-5)
        $interval = $instance->interval('small_float', Unit::Millisecond);
        $this->assertInstanceOf(CarbonInterval::class, $interval);

        $interval = $instance->interval('very_small', Unit::Second);
        $this->assertInstanceOf(CarbonInterval::class, $interval);

        // Scientific notation string passed directly
        $interval = $instance->interval('scientific', Unit::Millisecond);
        $this->assertInstanceOf(CarbonInterval::class, $interval);

        // Normal values should still work
        $interval = $instance->interval('normal_float', Unit::Hour);
        $this->assertInstanceOf(CarbonInterval::class, $interval);
        $this->assertSame(1, $interval->hours);

        $interval = $instance->interval('integer', Unit::Minute);
        $this->assertInstanceOf(CarbonInterval::class, $interval);
        $this->assertSame(90, $interval->minutes);
    }
}

class TestInteractsWithDataClass
{
    use InteractsWithData;

    public function __construct(
        protected array $data = []
    ) {
    }

    public function all(mixed $keys = null): array
    {
        return $this->data;
    }

    protected function data(?string $key = null, mixed $default = null): mixed
    {
        if (is_null($key)) {
            return $this->data;
        }

        return $this->data[$key] ?? $default;
    }

    public function collect(array|string|null $key = null): Collection
    {
        return new Collection(is_array($key) ? $this->only($key) : $this->data($key));
    }
}
