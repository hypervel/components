<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Carbon\CarbonImmutable as BaseCarbonImmutable;
use DateTimeInterface;
use Hypervel\Support\Carbon;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\VarDumper\VarDumper;

class SupportCarbonImmutableTest extends TestCase
{
    public function testConstructionAndSerializationRetainHypervelClass(): void
    {
        $date = CarbonImmutable::parse('2026-07-22 12:34:56.123456', 'Pacific/Auckland');
        $serialized = 'return ' . var_export($date, true) . ';';
        $deserialized = eval($serialized);

        $this->assertSame(CarbonImmutable::class, $date::class);
        $this->assertInstanceOf(BaseCarbonImmutable::class, $date);
        $this->assertInstanceOf(DateTimeInterface::class, $date);
        $this->assertSame(CarbonImmutable::class, $deserialized::class);
        $this->assertSame('2026-07-22T12:34:56.123456+12:00', $date->format('Y-m-d\TH:i:s.uP'));
        $this->assertSame('2026-07-22T00:34:56.123456Z', $date->jsonSerialize());
    }

    public function testConditionableBehaviorRetainsImmutability(): void
    {
        $date = CarbonImmutable::parse('2026-07-22 12:34:56');
        $tomorrow = $date->when(true, fn (CarbonImmutable $value): CarbonImmutable => $value->addDay());

        $this->assertSame('2026-07-22', $date->toDateString());
        $this->assertSame('2026-07-23', $tomorrow->toDateString());
    }

    public function testDumpReturnsTheSameInstance(): void
    {
        $date = CarbonImmutable::parse('2026-07-22 12:34:56');
        $dumped = [];

        VarDumper::setHandler(function (mixed $value) use (&$dumped): void {
            $dumped[] = $value;
        });

        try {
            $result = $date->dump('context');
        } finally {
            VarDumper::setHandler(null);
        }

        $this->assertSame($date, $result);
        $this->assertSame([$date, 'context'], $dumped);
    }

    #[DataProvider('timeBasedIdProvider')]
    public function testCreateFromId(string $id, string $expected): void
    {
        $date = CarbonImmutable::createFromId($id);

        $this->assertSame(CarbonImmutable::class, $date::class);
        $this->assertSame($expected, $date->toDateTimeString('microsecond'));
    }

    public static function timeBasedIdProvider(): array
    {
        return [
            'ulid' => ['01DXH9C4P0ED4AGJJP9CRKQ55C', '2020-01-01 19:30:00.000000'],
            'uuid v1' => ['71513cb4-f071-11ed-a0cf-325096b39f47', '2023-05-12 03:02:34.147346'],
            'uuid v6' => ['1edf0746-5d1c-6ce8-88ad-e0cb4effa035', '2023-05-12 03:23:43.347428'],
            'uuid v7' => ['01880dfa-2825-72e4-acbb-b1e4981cf8af', '2023-05-12 03:21:18.117185'],
        ];
    }

    public function testCreateFromIdRejectsNonTimeBasedUuid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The given UUID is not time-based and cannot be converted to a date.');

        CarbonImmutable::createFromId('a0a2a2d2-0b87-4a18-83f2-2529882be2de');
    }

    #[DataProvider('dateUnitProvider')]
    public function testPlusAndMinusSupportEveryNamedUnit(string $unit): void
    {
        $date = CarbonImmutable::parse('2026-07-22 12:34:56.123456');
        $method = ucfirst($unit);
        $plus = $date->plus(...[$unit => 1]);
        $minus = $date->minus(...[$unit => 1]);

        $this->assertSame($date->{'add' . $method}()->format('Y-m-d H:i:s.u'), $plus->format('Y-m-d H:i:s.u'));
        $this->assertSame($date->{'sub' . $method}()->format('Y-m-d H:i:s.u'), $minus->format('Y-m-d H:i:s.u'));
        $this->assertSame('2026-07-22 12:34:56.123456', $date->format('Y-m-d H:i:s.u'));
    }

    public static function dateUnitProvider(): array
    {
        return [
            'years' => ['years'],
            'months' => ['months'],
            'weeks' => ['weeks'],
            'days' => ['days'],
            'hours' => ['hours'],
            'minutes' => ['minutes'],
            'seconds' => ['seconds'],
            'microseconds' => ['microseconds'],
        ];
    }

    public function testConversionsPreserveHypervelClassesAndDateState(): void
    {
        $immutable = CarbonImmutable::parse('2026-07-22 12:34:56.123456', 'Pacific/Auckland')
            ->locale('fr');

        $mutable = $immutable->toMutable();

        $this->assertSame(Carbon::class, $mutable::class);
        $this->assertSame($immutable, $immutable->toImmutable());
        $this->assertSame($immutable->format('Y-m-d H:i:s.u e'), $mutable->format('Y-m-d H:i:s.u e'));
        $this->assertSame('fr', $mutable->locale());
        $this->assertTrue(method_exists($mutable, 'plus'));
    }

    public function testImmutableSubclassRetainsLateStaticTypeAndIdentity(): void
    {
        $date = ImmutableCarbonSubclass::parse('2026-07-22 12:34:56');

        $this->assertSame($date, $date->toImmutable());
        $this->assertSame(ImmutableCarbonSubclass::class, $date->toImmutable()::class);
    }
}

class ImmutableCarbonSubclass extends CarbonImmutable
{
}
