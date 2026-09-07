<?php

declare(strict_types=1);

use Hypervel\Support\CarbonImmutable;

use function PHPStan\Testing\assertType;

$date = CarbonImmutable::parse('2026-08-24 12:34:56.123456');

assertType(CarbonImmutable::class, $date->addMicrosecond());
assertType(CarbonImmutable::class, $date->addMicroseconds());
assertType(CarbonImmutable::class, $date->addMicroseconds(1.5));
assertType(CarbonImmutable::class, $date->addMinute());
assertType(CarbonImmutable::class, $date->addMinutes());
assertType(CarbonImmutable::class, $date->addMinutes(1.5));
assertType(CarbonImmutable::class, $date->addSecond());
assertType(CarbonImmutable::class, $date->addSeconds());
assertType(CarbonImmutable::class, $date->addSeconds(1.5));
assertType(CarbonImmutable::class, $date->ceilSecond());
assertType(CarbonImmutable::class, $date->ceilSecond(1.0));
assertType(CarbonImmutable::class, $date->ceilSeconds());
assertType(CarbonImmutable::class, $date->ceilSeconds(1.0));
assertType(CarbonImmutable::class, $date->subDay());
assertType(CarbonImmutable::class, $date->subMicrosecond());
assertType(CarbonImmutable::class, $date->subMinutes());
assertType(CarbonImmutable::class, $date->subMinutes(1.5));
assertType(CarbonImmutable::class, $date->subSeconds());
assertType(CarbonImmutable::class, $date->subSeconds(1.5));
