<?php

declare(strict_types=1);

use Hypervel\Support\Benchmark;

use function PHPStan\Testing\assertType;

assertType('float', Benchmark::measure(fn () => 1));
assertType("array<'first'|'second', float|int>", Benchmark::measure(['first' => fn () => 1, 'second' => fn () => 2]));
assertType('array<0|1, float|int>', Benchmark::measure([fn () => 1, fn () => 2]));
