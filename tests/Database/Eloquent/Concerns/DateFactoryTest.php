<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Eloquent\Concerns;

use Carbon\Carbon as BaseCarbon;
use Carbon\CarbonImmutable as BaseCarbonImmutable;
use DateTime;
use DateTimeImmutable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphPivot;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Support\Carbon;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Date;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class DateFactoryTest extends TestCase
{
    #[DataProvider('dateInputProvider')]
    public function testDatetimeCastUsesImmutableDefault(mixed $input, string $expected): void
    {
        $model = new DateFactoryTestModel;
        $model->setRawAttributes(['published_at' => $input]);

        $date = $model->published_at;

        $this->assertSame(CarbonImmutable::class, $date::class);
        $this->assertSame($expected, $date->format('Y-m-d H:i:s'));
    }

    public static function dateInputProvider(): array
    {
        return [
            'base mutable Carbon' => [BaseCarbon::parse('2024-01-15 10:30:00'), '2024-01-15 10:30:00'],
            'base immutable Carbon' => [BaseCarbonImmutable::parse('2024-01-15 10:30:00'), '2024-01-15 10:30:00'],
            'native mutable' => [new DateTime('2024-01-15 10:30:00'), '2024-01-15 10:30:00'],
            'native immutable' => [new DateTimeImmutable('2024-01-15 10:30:00'), '2024-01-15 10:30:00'],
            'timestamp' => [1705314600, '2024-01-15 10:30:00'],
            'database format' => ['2024-01-15 10:30:00', '2024-01-15 10:30:00'],
            'parse fallback' => ['January 15, 2024 10:30:00', '2024-01-15 10:30:00'],
        ];
    }

    public function testDateAndDatetimeCastsRespectMutableOptOut(): void
    {
        Date::use(Carbon::class);

        $model = new DateFactoryDateCastModel;
        $model->setRawAttributes([
            'event_date' => '2024-01-15',
            'event_datetime' => '2024-01-15 10:30:00',
        ]);

        $this->assertSame(Carbon::class, $model->event_date::class);
        $this->assertSame(Carbon::class, $model->event_datetime::class);
        $this->assertSame('00:00:00', $model->event_date->format('H:i:s'));
    }

    public function testImmutableCastsRemainHypervelImmutableDuringMutableOptOut(): void
    {
        Date::use(Carbon::class);

        $model = new DateFactoryDateCastModel;
        $model->setRawAttributes([
            'immutable_event_date' => '2024-01-15',
            'immutable_event_datetime' => '2024-01-15 10:30:00',
        ]);

        $this->assertSame(CarbonImmutable::class, $model->immutable_event_date::class);
        $this->assertSame(CarbonImmutable::class, $model->immutable_event_datetime::class);
    }

    public function testFreshTimestampsUseConfiguredFactory(): void
    {
        $model = new DateFactoryTestModel;

        $this->assertSame(CarbonImmutable::class, $model->freshTimestamp()::class);
        $this->assertSame(CarbonImmutable::class, (new DateFactoryTestPivot)->freshTimestamp()::class);
        $this->assertSame(CarbonImmutable::class, (new DateFactoryTestMorphPivot)->freshTimestamp()::class);

        Date::use(Carbon::class);

        $this->assertSame(Carbon::class, $model->freshTimestamp()::class);
    }

    public function testAsDateTimeWithNullReturnsNull(): void
    {
        $model = new DateFactoryTestModel;
        $model->setRawAttributes(['published_at' => null]);

        $this->assertNull($model->published_at);
    }
}

class DateFactoryTestModel extends Model
{
    protected ?string $table = 'test_models';

    protected array $casts = ['published_at' => 'datetime'];
}

class DateFactoryDateCastModel extends Model
{
    protected ?string $table = 'test_models';

    protected array $casts = [
        'event_date' => 'date',
        'event_datetime' => 'datetime',
        'immutable_event_date' => 'immutable_date',
        'immutable_event_datetime' => 'immutable_datetime',
    ];
}

class DateFactoryTestPivot extends Pivot
{
    protected ?string $table = 'test_pivots';
}

class DateFactoryTestMorphPivot extends MorphPivot
{
    protected ?string $table = 'test_morph_pivots';
}
