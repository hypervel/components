<?php

declare(strict_types=1);

use Hypervel\Cache\Repository;
use Hypervel\Support\CarbonImmutable;

use function PHPStan\Testing\assertType;

/** @var Repository $cache */
$cache = resolve(Repository::class);

assertType('mixed', $cache->get('key'));
assertType('mixed', $cache->get('cache', 27));
assertType('mixed', $cache->get('cache', function (): int {
    return 26;
}));

assertType('mixed', $cache->pull('key'));
assertType('mixed', $cache->pull('cache', 28));
assertType('mixed', $cache->pull('cache', function (): int {
    return 30;
}));
assertType('33', $cache->sear('cache', function (): int {
    return 33;
}));
assertType('36', $cache->remember('cache', CarbonImmutable::now(), function (): int {
    return 36;
}));
assertType('array{36, bool}', $cache->rememberWithWarmth('cache', CarbonImmutable::now(), function (): int {
    return 36;
}));
assertType('36', $cache->rememberForever('cache', function (): int {
    return 36;
}));
