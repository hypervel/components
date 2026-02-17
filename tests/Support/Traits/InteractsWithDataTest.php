<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support\Traits;

use Hypervel\Container\Container;
use Hypervel\Support\Carbon;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Traits\InteractsWithData;
use Hypervel\Tests\Foundation\Concerns\HasMockedApplication;
use PHPUnit\Framework\TestCase;

enum InteractsWithDataTestStringEnum: string
{
    case UTC = 'UTC';
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

/**
 * @internal
 * @coversNothing
 */
class InteractsWithDataTest extends TestCase
{
    use HasMockedApplication;

    protected function setUp(): void
    {
        parent::setUp();

        Container::setInstance($this->getApplication());
        Date::clearResolvedInstances();
    }

    protected function tearDown(): void
    {
        Date::clearResolvedInstances();

        parent::tearDown();
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
