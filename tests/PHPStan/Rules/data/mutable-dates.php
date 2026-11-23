<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\MutableDates;

use Carbon\Carbon as BaseCarbon;
use Carbon\CarbonImmutable;
use Hypervel\Support\Carbon;
use Hypervel\Support\Facades\Date;

class LocalCarbon extends Carbon
{
    public static function tomorrowAtNoon(): static
    {
        return static::tomorrow()->midDay();
    }
}

class Billing
{
    public function dates(?string $value): void
    {
        $dates = [
            new Carbon('2030-01-01'),
            new BaseCarbon,
            Carbon::now(),
            BaseCarbon::parse('2030-01-01'),
            Carbon::createFromFormat('Y-m-d', '2030-01-01'),
            Carbon::make($value),
            Carbon::hasTestNow(),
            Carbon::getTestNow(),
            LocalCarbon::now(),
            new LocalCarbon,
            CarbonImmutable::now(),
            Date::now(),
            now(),
            Carbon::create(...),
        ];

        Carbon::setTestNow();
    }
}
