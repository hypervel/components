<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Carbon\CarbonImmutable;
use Carbon\Factory;
use DateTime;
use Hypervel\Support\Carbon;
use Hypervel\Support\DateFactory;
use Hypervel\Support\Facades\Date;
use Hypervel\Tests\Support\Fixtures\CustomDateClass;
use InvalidArgumentException;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class DateFacadeTest extends TestCase
{
    protected function tearDown(): void
    {
        DateFactory::use(Carbon::class);

        parent::tearDown();
    }

    protected static function assertBetweenStartAndNow($start, $actual)
    {
        static::assertThat(
            $actual,
            static::logicalAnd(
                static::greaterThanOrEqual($start),
                static::lessThanOrEqual(Carbon::now()->getTimestamp())
            )
        );
    }

    public function testUseClosure()
    {
        $start = Carbon::now()->getTimestamp();
        $this->assertSame(Carbon::class, get_class(Date::now()));
        $this->assertBetweenStartAndNow($start, Date::now()->getTimestamp());
        DateFactory::use(function (Carbon $date) {
            return new DateTime($date->format('Y-m-d H:i:s.u'), $date->getTimezone());
        });
        $start = Carbon::now()->getTimestamp();
        $this->assertSame(DateTime::class, get_class(Date::now()));
        $this->assertBetweenStartAndNow($start, Date::now()->getTimestamp());
    }

    public function testUseClassName()
    {
        $start = Carbon::now()->getTimestamp();
        $this->assertSame(Carbon::class, get_class(Date::now()));
        $this->assertBetweenStartAndNow($start, Date::now()->getTimestamp());
        DateFactory::use(DateTime::class);
        $start = Carbon::now()->getTimestamp();
        $this->assertSame(DateTime::class, get_class(Date::now()));
        $this->assertBetweenStartAndNow($start, Date::now()->getTimestamp());
    }

    public function testCarbonImmutable()
    {
        DateFactory::use(CarbonImmutable::class);
        $this->assertSame(CarbonImmutable::class, get_class(Date::now()));
        DateFactory::use(Carbon::class);
        $this->assertSame(Carbon::class, get_class(Date::now()));
        DateFactory::use(function (Carbon $date) {
            return $date->toImmutable();
        });
        $this->assertSame(CarbonImmutable::class, get_class(Date::now()));
        DateFactory::use(function ($date) {
            return $date;
        });
        $this->assertSame(Carbon::class, get_class(Date::now()));

        DateFactory::use(new Factory([
            'locale' => 'fr',
        ]));
        $this->assertSame('fr', Date::now()->locale);
        DateFactory::use(Carbon::class);
        $this->assertSame('en', Date::now()->locale);
        DateFactory::use(CustomDateClass::class);
        $this->assertInstanceOf(CustomDateClass::class, Date::now());
        $this->assertInstanceOf(Carbon::class, Date::now()->getOriginal());
        DateFactory::use(Carbon::class);
    }

    public function testUseInvalidHandler()
    {
        $this->expectException(InvalidArgumentException::class);

        DateFactory::use(42);
    }

    public function testMacro()
    {
        Date::macro('returnNonDate', function () {
            return 'string';
        });

        $this->assertSame('string', Date::returnNonDate());
    }
}
