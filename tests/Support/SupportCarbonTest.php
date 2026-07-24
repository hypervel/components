<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use BadMethodCallException;
use Carbon\Carbon as BaseCarbon;
use Carbon\CarbonImmutable as BaseCarbonImmutable;
use DateTimeInterface;
use Hypervel\Support\Carbon;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class SupportCarbonTest extends TestCase
{
    protected Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow($this->now = Carbon::create(2017, 6, 27, 13, 14, 15, 'UTC'));
    }

    public function testInstance(): void
    {
        $this->assertInstanceOf(Carbon::class, $this->now);
        $this->assertInstanceOf(DateTimeInterface::class, $this->now);
        $this->assertInstanceOf(BaseCarbon::class, $this->now);
        $this->assertInstanceOf(Carbon::class, $this->now);
    }

    public function testCarbonIsMacroableWhenNotCalledStatically(): void
    {
        Carbon::macro('diffInDecades', function (?Carbon $date = null, bool $absolute = true): int {
            return (int) ($this->diffInYears($date, $absolute) / 10);
        });

        $this->assertSame(2, $this->now->diffInDecades(Carbon::now()->addYears(25)));
    }

    public function testCarbonIsMacroableWhenCalledStatically(): void
    {
        Carbon::macro('twoDaysAgoAtNoon', function (): Carbon {
            return Carbon::now()->subDays(2)->setTime(12, 0, 0);
        });

        $this->assertSame('2017-06-25 12:00:00', Carbon::twoDaysAgoAtNoon()->toDateTimeString());
    }

    public function testCarbonRaisesExceptionWhenStaticMacroIsNotFound(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('nonExistingStaticMacro does not exist.');

        Carbon::nonExistingStaticMacro();
    }

    public function testCarbonRaisesExceptionWhenMacroIsNotFound(): void
    {
        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('nonExistingMacro does not exist.');

        Carbon::now()->nonExistingMacro();
    }

    public function testCarbonAllowsCustomSerializer(): void
    {
        Carbon::serializeUsing(function (Carbon $carbon): int {
            return $carbon->getTimestamp();
        });

        $result = json_decode(json_encode($this->now), true);

        $this->assertSame(1498569255, $result);
    }

    public function testCarbonCanSerializeToJson(): void
    {
        $this->assertSame('2017-06-27T13:14:15.000000Z', $this->now->jsonSerialize());
    }

    public function testSetStateReturnsCorrectType(): void
    {
        $carbon = Carbon::__set_state([
            'date' => '2017-06-27 13:14:15.000000',
            'timezone_type' => 3,
            'timezone' => 'UTC',
        ]);

        $this->assertInstanceOf(Carbon::class, $carbon);
    }

    public function testDeserializationOccursCorrectly(): void
    {
        $carbon = new Carbon('2017-06-27 13:14:15.000000');
        $serialized = 'return ' . var_export($carbon, true) . ';';
        $deserialized = eval($serialized);

        $this->assertInstanceOf(Carbon::class, $deserialized);
    }

    public function testSetTestNowWillPersistBetweenImmutableAndMutableInstance(): void
    {
        Carbon::setTestNow(new Carbon('2017-06-27 13:14:15.000000'));

        $this->assertSame('2017-06-27 13:14:15', Carbon::now()->toDateTimeString());
        $this->assertSame('2017-06-27 13:14:15', BaseCarbon::now()->toDateTimeString());
        $this->assertSame('2017-06-27 13:14:15', BaseCarbonImmutable::now()->toDateTimeString());
    }

    public function testCarbonIsConditionable(): void
    {
        $this->assertTrue(Carbon::now()->when(null, fn (Carbon $carbon) => $carbon->addDays(1))->isToday());
        $this->assertTrue(Carbon::now()->when(true, fn (Carbon $carbon) => $carbon->addDays(1))->isTomorrow());
    }

    public function testCreateFromId(): void
    {
        $ulid = Carbon::createFromId('01DXH9C4P0ED4AGJJP9CRKQ55C');
        $this->assertEquals('2020-01-01 19:30:00.000000', $ulid->toDateTimeString('microsecond'));

        $uuidv1 = Carbon::createFromId('71513cb4-f071-11ed-a0cf-325096b39f47');
        $this->assertEquals('2023-05-12 03:02:34.147346', $uuidv1->toDateTimeString('microsecond'));

        $uuidv6 = Carbon::createFromId('1edf0746-5d1c-6ce8-88ad-e0cb4effa035');
        $this->assertEquals('2023-05-12 03:23:43.347428', $uuidv6->toDateTimeString('microsecond'));

        $uuidv7 = Carbon::createFromId('01880dfa-2825-72e4-acbb-b1e4981cf8af');
        $this->assertEquals('2023-05-12 03:21:18.117185', $uuidv7->toDateTimeString('microsecond'));
    }

    public function testCreateFromIdRejectsNonTimeBasedUuid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The given UUID is not time-based and cannot be converted to a date.');

        Carbon::createFromId('a0a2a2d2-0b87-4a18-83f2-2529882be2de'); // v4 UUID
    }

    public function testConversionsPreserveHypervelClassesAndDateState(): void
    {
        $mutable = Carbon::parse('2026-07-22 12:34:56.123456', 'Pacific/Auckland')
            ->locale('fr');

        $mutableCopy = $mutable->toMutable();
        $immutable = $mutable->toImmutable();

        $this->assertSame(Carbon::class, $mutableCopy::class);
        $this->assertNotSame($mutable, $mutableCopy);
        $this->assertSame(CarbonImmutable::class, $immutable::class);
        $this->assertSame($mutable->format('Y-m-d H:i:s.u e'), $mutableCopy->format('Y-m-d H:i:s.u e'));
        $this->assertSame($mutable->format('Y-m-d H:i:s.u e'), $immutable->format('Y-m-d H:i:s.u e'));
        $this->assertSame('fr', $mutableCopy->locale());
        $this->assertSame('fr', $immutable->locale());
        $this->assertTrue(method_exists($immutable, 'plus'));
    }

    public function testMutableSubclassRetainsLateStaticTypeWhenConvertedToMutable(): void
    {
        $date = MutableCarbonSubclass::parse('2026-07-22 12:34:56');

        $this->assertSame(MutableCarbonSubclass::class, $date->toMutable()::class);
    }
}

class MutableCarbonSubclass extends Carbon
{
}
