<?php

declare(strict_types=1);

use Hypervel\Foundation\Testing\Wormhole;

use function PHPStan\Testing\assertType;

$wormhole = new Wormhole(1);

$voidFunction = function (): void {
};

assertType('42', $wormhole->seconds(fn () => 42));
assertType('42', $wormhole->second(fn () => 42));
assertType('42', $wormhole->minutes(fn () => 42));
assertType('42', $wormhole->minute(fn () => 42));
assertType('42', $wormhole->hours(fn () => 42));
assertType('42', $wormhole->hour(fn () => 42));
assertType('42', $wormhole->days(fn () => 42));
assertType('42', $wormhole->day(fn () => 42));
assertType('42', $wormhole->weeks(fn () => 42));
assertType('42', $wormhole->week(fn () => 42));
assertType('42', $wormhole->months(fn () => 42));
assertType('42', $wormhole->month(fn () => 42));
assertType('42', $wormhole->years(fn () => 42));
assertType('42', $wormhole->year(fn () => 42));
assertType('42', $wormhole->microseconds(fn () => 42));
assertType('42', $wormhole->microsecond(fn () => 42));
assertType('42', $wormhole->milliseconds(fn () => 42));
assertType('42', $wormhole->millisecond(fn () => 42));

/* @phpstan-ignore method.void */
assertType('null', $wormhole->seconds($voidFunction));
/* @phpstan-ignore method.void */
assertType('null', $wormhole->second($voidFunction));
/* @phpstan-ignore method.void */
assertType('null', $wormhole->minutes($voidFunction));
/* @phpstan-ignore method.void */
assertType('null', $wormhole->minute($voidFunction));
/* @phpstan-ignore method.void */
assertType('null', $wormhole->hours($voidFunction));
/* @phpstan-ignore method.void */
assertType('null', $wormhole->hour($voidFunction));
/* @phpstan-ignore method.void */
assertType('null', $wormhole->days($voidFunction));
/* @phpstan-ignore method.void */
assertType('null', $wormhole->day($voidFunction));
/* @phpstan-ignore method.void */
assertType('null', $wormhole->weeks($voidFunction));
/* @phpstan-ignore method.void */
assertType('null', $wormhole->week($voidFunction));
/* @phpstan-ignore method.void */
assertType('null', $wormhole->months($voidFunction));
/* @phpstan-ignore method.void */
assertType('null', $wormhole->month($voidFunction));
/* @phpstan-ignore method.void */
assertType('null', $wormhole->years($voidFunction));
/* @phpstan-ignore method.void */
assertType('null', $wormhole->year($voidFunction));
/* @phpstan-ignore method.void */
assertType('null', $wormhole->microseconds($voidFunction));
/* @phpstan-ignore method.void */
assertType('null', $wormhole->microsecond($voidFunction));
/* @phpstan-ignore method.void */
assertType('null', $wormhole->milliseconds($voidFunction));
/* @phpstan-ignore method.void */
assertType('null', $wormhole->millisecond($voidFunction));

assertType('null', $wormhole->seconds());
assertType('null', $wormhole->second());
assertType('null', $wormhole->minutes());
assertType('null', $wormhole->minute());
assertType('null', $wormhole->hours());
assertType('null', $wormhole->hour());
assertType('null', $wormhole->days());
assertType('null', $wormhole->day());
assertType('null', $wormhole->weeks());
assertType('null', $wormhole->week());
assertType('null', $wormhole->months());
assertType('null', $wormhole->month());
assertType('null', $wormhole->years());
assertType('null', $wormhole->year());
assertType('null', $wormhole->microseconds());
assertType('null', $wormhole->microsecond());
assertType('null', $wormhole->milliseconds());
assertType('null', $wormhole->millisecond());
