<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Database\Eloquent\Concerns;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTime;
use DateTimeImmutable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\MorphPivot;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Support\DateFactory;
use Hypervel\Support\Facades\Date;
use Hypervel\Testbench\TestCase;

/**
 * Tests that Date::use() configuration is respected throughout the framework.
 *
 * When Date::use(CarbonImmutable::class) is called, all date-related operations
 * should return CarbonImmutable instances instead of mutable Carbon instances.
 *
 * @internal
 * @coversNothing
 */
class DateFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        // Reset the DateFactory to default state after each test
        DateFactory::useDefault();

        parent::tearDown();
    }

    // ==========================================
    // Date Facade Tests
    // ==========================================

    public function testDateFacadeReturnsDefaultCarbonByDefault(): void
    {
        $date = Date::now();

        $this->assertInstanceOf(Carbon::class, $date);
        $this->assertNotInstanceOf(CarbonImmutable::class, $date);
    }

    public function testDateFacadeReturnsCarbonImmutableWhenConfigured(): void
    {
        Date::use(CarbonImmutable::class);

        $date = Date::now();

        $this->assertInstanceOf(CarbonImmutable::class, $date);
    }

    public function testDateFacadeParseReturnsCarbonImmutableWhenConfigured(): void
    {
        Date::use(CarbonImmutable::class);

        $date = Date::parse('2024-01-15 10:30:00');

        $this->assertInstanceOf(CarbonImmutable::class, $date);
    }

    public function testDateFacadeCreateFromTimestampReturnsCarbonImmutableWhenConfigured(): void
    {
        Date::use(CarbonImmutable::class);

        $date = Date::createFromTimestamp(1705312200);

        $this->assertInstanceOf(CarbonImmutable::class, $date);
    }

    public function testDateFacadeCreateFromFormatReturnsCarbonImmutableWhenConfigured(): void
    {
        Date::use(CarbonImmutable::class);

        $date = Date::createFromFormat('Y-m-d', '2024-01-15');

        $this->assertInstanceOf(CarbonImmutable::class, $date);
    }

    public function testDateFacadeInstanceReturnsCarbonImmutableWhenConfigured(): void
    {
        Date::use(CarbonImmutable::class);

        $carbon = Carbon::now();
        $date = Date::instance($carbon);

        $this->assertInstanceOf(CarbonImmutable::class, $date);
    }

    public function testDateFacadeUseDefaultResetsToMutableCarbon(): void
    {
        Date::use(CarbonImmutable::class);
        $this->assertInstanceOf(CarbonImmutable::class, Date::now());

        DateFactory::useDefault();

        $this->assertInstanceOf(Carbon::class, Date::now());
        $this->assertNotInstanceOf(CarbonImmutable::class, Date::now());
    }

    // ==========================================
    // Model freshTimestamp() Tests
    // ==========================================

    public function testModelFreshTimestampReturnsDefaultCarbonByDefault(): void
    {
        $model = new DateFactoryTestModel();

        $timestamp = $model->freshTimestamp();

        $this->assertInstanceOf(CarbonInterface::class, $timestamp);
        $this->assertInstanceOf(Carbon::class, $timestamp);
        $this->assertNotInstanceOf(CarbonImmutable::class, $timestamp);
    }

    public function testModelFreshTimestampReturnsCarbonImmutableWhenConfigured(): void
    {
        Date::use(CarbonImmutable::class);

        $model = new DateFactoryTestModel();
        $timestamp = $model->freshTimestamp();

        $this->assertInstanceOf(CarbonImmutable::class, $timestamp);
    }

    // ==========================================
    // Model asDateTime() Tests
    // ==========================================

    public function testAsDateTimeReturnsCarbonImmutableFromCarbonInstance(): void
    {
        Date::use(CarbonImmutable::class);

        $model = new DateFactoryTestModel();
        $model->setRawAttributes(['published_at' => Carbon::parse('2024-01-15 10:30:00')]);

        $date = $model->published_at;

        $this->assertInstanceOf(CarbonImmutable::class, $date);
        $this->assertSame('2024-01-15 10:30:00', $date->format('Y-m-d H:i:s'));
    }

    public function testAsDateTimeReturnsCarbonImmutableFromDateTimeInterface(): void
    {
        Date::use(CarbonImmutable::class);

        $model = new DateFactoryTestModel();
        $model->setRawAttributes(['published_at' => new DateTime('2024-01-15 10:30:00')]);

        $date = $model->published_at;

        $this->assertInstanceOf(CarbonImmutable::class, $date);
        $this->assertSame('2024-01-15 10:30:00', $date->format('Y-m-d H:i:s'));
    }

    public function testAsDateTimeReturnsCarbonImmutableFromDateTimeImmutable(): void
    {
        Date::use(CarbonImmutable::class);

        $model = new DateFactoryTestModel();
        $model->setRawAttributes(['published_at' => new DateTimeImmutable('2024-01-15 10:30:00')]);

        $date = $model->published_at;

        $this->assertInstanceOf(CarbonImmutable::class, $date);
        $this->assertSame('2024-01-15 10:30:00', $date->format('Y-m-d H:i:s'));
    }

    public function testAsDateTimeReturnsCarbonImmutableFromTimestamp(): void
    {
        Date::use(CarbonImmutable::class);

        $model = new DateFactoryTestModel();
        // 1705312200 = 2024-01-15 10:30:00 UTC
        $model->setRawAttributes(['published_at' => 1705312200]);

        $date = $model->published_at;

        $this->assertInstanceOf(CarbonImmutable::class, $date);
    }

    public function testAsDateTimeReturnsCarbonImmutableFromStandardDateFormat(): void
    {
        Date::use(CarbonImmutable::class);

        $model = new DateFactoryTestModel();
        $model->setRawAttributes(['published_at' => '2024-01-15']);

        $date = $model->published_at;

        $this->assertInstanceOf(CarbonImmutable::class, $date);
        $this->assertSame('2024-01-15', $date->format('Y-m-d'));
        // Standard date format should start at beginning of day
        $this->assertSame('00:00:00', $date->format('H:i:s'));
    }

    public function testAsDateTimeReturnsCarbonImmutableFromString(): void
    {
        Date::use(CarbonImmutable::class);

        $model = new DateFactoryTestModel();
        $model->setRawAttributes(['published_at' => '2024-01-15 10:30:00']);

        $date = $model->published_at;

        $this->assertInstanceOf(CarbonImmutable::class, $date);
        $this->assertSame('2024-01-15 10:30:00', $date->format('Y-m-d H:i:s'));
    }

    // ==========================================
    // Model Date Cast Tests
    // ==========================================

    public function testDateCastReturnsCarbonImmutableWhenConfigured(): void
    {
        Date::use(CarbonImmutable::class);

        $model = new DateFactoryDateCastModel();
        $model->setRawAttributes(['event_date' => '2024-01-15']);

        $date = $model->event_date;

        $this->assertInstanceOf(CarbonImmutable::class, $date);
        // Date cast should return start of day
        $this->assertSame('00:00:00', $date->format('H:i:s'));
    }

    public function testDatetimeCastReturnsCarbonImmutableWhenConfigured(): void
    {
        Date::use(CarbonImmutable::class);

        $model = new DateFactoryDateCastModel();
        $model->setRawAttributes(['event_datetime' => '2024-01-15 10:30:00']);

        $date = $model->event_datetime;

        $this->assertInstanceOf(CarbonImmutable::class, $date);
        $this->assertSame('2024-01-15 10:30:00', $date->format('Y-m-d H:i:s'));
    }

    // ==========================================
    // Pivot Model Tests
    // ==========================================

    public function testPivotFreshTimestampReturnsCarbonImmutableWhenConfigured(): void
    {
        Date::use(CarbonImmutable::class);

        $pivot = new DateFactoryTestPivot();
        $timestamp = $pivot->freshTimestamp();

        $this->assertInstanceOf(CarbonImmutable::class, $timestamp);
    }

    public function testMorphPivotFreshTimestampReturnsCarbonImmutableWhenConfigured(): void
    {
        Date::use(CarbonImmutable::class);

        $pivot = new DateFactoryTestMorphPivot();
        $timestamp = $pivot->freshTimestamp();

        $this->assertInstanceOf(CarbonImmutable::class, $timestamp);
    }

    // ==========================================
    // Edge Cases
    // ==========================================

    public function testAsDateTimeWithNullReturnsNull(): void
    {
        Date::use(CarbonImmutable::class);

        $model = new DateFactoryTestModel();
        $model->setRawAttributes(['published_at' => null]);

        // The $dates array functionality will still return null for null values
        $this->assertNull($model->published_at);
    }

    public function testAsDateTimeHandlesCarbonImmutableInstanceDirectly(): void
    {
        Date::use(CarbonImmutable::class);

        $model = new DateFactoryTestModel();
        $immutable = CarbonImmutable::parse('2024-01-15 10:30:00');
        $model->setRawAttributes(['published_at' => $immutable]);

        $date = $model->published_at;

        $this->assertInstanceOf(CarbonImmutable::class, $date);
        $this->assertSame('2024-01-15 10:30:00', $date->format('Y-m-d H:i:s'));
    }

    public function testMultipleDateFieldsAllReturnCarbonImmutable(): void
    {
        Date::use(CarbonImmutable::class);

        $model = new DateFactoryMultipleDatesModel();
        $model->setRawAttributes([
            'created_at' => '2024-01-15 08:00:00',
            'updated_at' => '2024-01-15 09:00:00',
            'published_at' => '2024-01-15 10:00:00',
        ]);

        $this->assertInstanceOf(CarbonImmutable::class, $model->created_at);
        $this->assertInstanceOf(CarbonImmutable::class, $model->updated_at);
        $this->assertInstanceOf(CarbonImmutable::class, $model->published_at);
    }
}

// Test Model Classes

class DateFactoryTestModel extends Model
{
    protected ?string $table = 'test_models';

    protected array $dates = ['published_at'];
}

class DateFactoryDateCastModel extends Model
{
    protected ?string $table = 'test_models';

    protected array $casts = [
        'event_date' => 'date',
        'event_datetime' => 'datetime',
    ];
}

class DateFactoryMultipleDatesModel extends Model
{
    protected ?string $table = 'test_models';

    protected array $dates = ['published_at'];
}

class DateFactoryTestPivot extends Pivot
{
    protected ?string $table = 'test_pivots';
}

class DateFactoryTestMorphPivot extends MorphPivot
{
    protected ?string $table = 'test_morph_pivots';
}
