<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\DiscardedDates;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use Hypervel\Support\CarbonImmutable as HypervelCarbonImmutable;

/**
 * @method static addBusinessDays(int $days)
 */
class ShiftDate extends CarbonImmutable
{
    public function addDay(): static
    {
        return $this->addDays(1);
    }
}

class Schedule
{
    public function immutable(CarbonImmutable $date, HypervelCarbonImmutable $hypervel, ?CarbonImmutable $optional): void
    {
        $date->addDay();
        $date->subMonths(2);
        $date->startOfDay();
        $date->endOfMonth();
        $date->next('Monday');
        $date->previousWeekday();
        $date->nthOfMonth(2, 1);
        $date->firstOfQuarter();
        $date->lastOfYear();
        $date->roundHour();
        $date->floorDay();
        $date->ceilMinute();
        $date->modify('+1 day');
        $date->setTimezone('UTC');
        $date->year(2030);
        $date->setMicroseconds(5);
        $hypervel->addDays(2);
        $hypervel->plus(days: 1);
        $optional?->utc();
        $date->addDay()->startOfDay();
        $date = $date->addDay();
        $date->format('Y-m-d');
        $date->locale('fr');
        $date->settings(['monthOverflow' => false]);
    }

    public function getterSetters(CarbonImmutable $date, ?int $day, array $arguments): void
    {
        $date->dayOfYear(5);
        $date->dayOfYear(value: 5);
        $date->dayOfWeek(3);
        $date->tz('UTC');
        $date->dayOfYear($day);
        $date->week(...$arguments);
        $date->dayOfYear();
        $date->dayOfYear(null);
        $date->tz();
        $date->weekOfYear();
    }

    public function contracts(CarbonInterface $date, Carbon|CarbonImmutable $either): void
    {
        $date->addDay();
        $date->dayOfYear(5);
        $either->setTime(9, 0);
    }

    public function mutable(Carbon $date, DateTimeImmutable $native): void
    {
        $date->addDay();
        $date->dayOfYear(5);
        $native->modify('+1 day');
    }

    public function userMethods(ShiftDate $date): void
    {
        $date->addDay();
        $date->addBusinessDays(2);
        $date->subDay();
    }

    public function firstClassCallables(CarbonImmutable $date): void
    {
        $date->addDay(...);
        $date->dayOfYear(...);
    }
}
