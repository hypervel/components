<?php

declare(strict_types=1);

use Hypervel\Support\CarbonImmutable;

use function PHPStan\Testing\assertType;

$date = CarbonImmutable::parse('2026-08-24 12:34:56.123456');

assertType(CarbonImmutable::class, $date->addMicrosecond());
assertType(CarbonImmutable::class, $date->subMicrosecond());
assertType(CarbonImmutable::class, $date->ceilSecond());
assertType(CarbonImmutable::class, $date->ceilSecond(1.0));
