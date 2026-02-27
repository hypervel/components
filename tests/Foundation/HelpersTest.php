<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Carbon\Carbon;
use DateTimeZone;
use Hypervel\Support\Facades\Event;
use Hypervel\Testbench\TestCase;

enum HelpersTestStringEnum: string
{
    case UTC = 'UTC';
    case NewYork = 'America/New_York';
}

enum HelpersTestIntEnum: int
{
    case One = 1;
    case Two = 2;
}

enum HelpersTestUnitEnum
{
    case UTC;
    case EST;
}

/**
 * @internal
 * @coversNothing
 */
class HelpersTest extends TestCase
{
    public function testNowReturnsCarbon(): void
    {
        $result = now();

        $this->assertInstanceOf(Carbon::class, $result);
    }

    public function testNowWithStringTimezone(): void
    {
        $result = now('America/New_York');

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('America/New_York', $result->timezone->getName());
    }

    public function testNowWithDateTimeZone(): void
    {
        $tz = new DateTimeZone('America/New_York');
        $result = now($tz);

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('America/New_York', $result->timezone->getName());
    }

    public function testNowWithStringBackedEnum(): void
    {
        $result = now(HelpersTestStringEnum::NewYork);

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('America/New_York', $result->timezone->getName());
    }

    public function testNowWithUnitEnum(): void
    {
        $result = now(HelpersTestUnitEnum::UTC);

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('UTC', $result->timezone->getName());
    }

    public function testNowWithIntBackedEnum(): void
    {
        // Int-backed enum returns int, Carbon interprets as UTC offset
        $result = now(HelpersTestIntEnum::One);

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('+01:00', $result->timezone->getName());
    }

    public function testNowWithNull(): void
    {
        $result = now(null);

        $this->assertInstanceOf(Carbon::class, $result);
    }

    public function testTodayReturnsCarbon(): void
    {
        $result = today();

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('00:00:00', $result->format('H:i:s'));
    }

    public function testTodayWithStringTimezone(): void
    {
        $result = today('America/New_York');

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('America/New_York', $result->timezone->getName());
        $this->assertEquals('00:00:00', $result->format('H:i:s'));
    }

    public function testTodayWithDateTimeZone(): void
    {
        $tz = new DateTimeZone('America/New_York');
        $result = today($tz);

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('America/New_York', $result->timezone->getName());
    }

    public function testTodayWithStringBackedEnum(): void
    {
        $result = today(HelpersTestStringEnum::NewYork);

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('America/New_York', $result->timezone->getName());
    }

    public function testTodayWithUnitEnum(): void
    {
        $result = today(HelpersTestUnitEnum::UTC);

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('UTC', $result->timezone->getName());
    }

    public function testTodayWithIntBackedEnum(): void
    {
        // Int-backed enum returns int, Carbon interprets as UTC offset
        $result = today(HelpersTestIntEnum::One);

        $this->assertInstanceOf(Carbon::class, $result);
        $this->assertEquals('+01:00', $result->timezone->getName());
    }

    public function testTodayWithNull(): void
    {
        $result = today(null);

        $this->assertInstanceOf(Carbon::class, $result);
    }

    public function testEventHelperReturnsArrayForNormalDispatch()
    {
        Event::listen('test.event', function () {
            return 'response';
        });

        $result = event('test.event');

        $this->assertIsArray($result);
        $this->assertSame(['response'], $result);
    }

    public function testEventHelperReturnsNonArrayForHaltedDispatch()
    {
        Event::listen('test.halted', function () {
            return 42;
        });

        $result = event('test.halted', [], true);

        $this->assertSame(42, $result);
    }

    public function testEventHelperReturnsNullWhenNoListenersAndHalted()
    {
        $result = event('test.no-listeners', [], true);

        $this->assertNull($result);
    }

    public function testEventHelperReturnsEmptyArrayWhenNoListeners()
    {
        $result = event('test.no-listeners');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
